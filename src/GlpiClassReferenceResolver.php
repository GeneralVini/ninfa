<?php

declare(strict_types=1);

/**
 * Resolve tipos de Plugin::registerClass() sem carregar código do plugin.
 *
 * Reconhece strings simples, ClassName::class, aliases de import e nomes
 * totalmente qualificados em arquivos PHP de um único namespace convencional.
 * Imports em grupos, aliases duplicados, expressões compostas e namespace em
 * bloco ficam inconclusivos. A resolução de nome não prova autoload/runtime.
 */
final class GlpiClassReferenceResolver
{
    /**
     * Interpreta o primeiro argumento do registro até ',' ou ')' exclusivamente.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens do setup.php.
     * @param int $start Índice do primeiro token significativo do argumento.
     * @return string|null Nome canônico demonstrado ou unknown.
     */
    public function resolve(array $tokens, int $start): ?string
    {
        $first = $tokens[$start] ?? null;
        if (!is_array($first)) {
            return null;
        }

        $second = $this->next($tokens, $start + 1);
        if ($first[0] === T_CONSTANT_ENCAPSED_STRING) {
            if ($second === null || !in_array($tokens[$second], [',', ')'], true)) {
                return null;
            }
            $raw = $first[1];
            if (strlen($raw) < 3 || $raw[0] !== "'" || !str_ends_with($raw, "'")) {
                return null;
            }
            $literal = substr($raw, 1, -1);
            return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $literal) === 1 ? $literal : null;
        }

        if (!in_array($first[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            || $second === null || !is_array($tokens[$second]) || $tokens[$second][0] !== T_DOUBLE_COLON) {
            return null;
        }
        $classToken = $this->next($tokens, $second + 1);
        $delimiter = $classToken !== null ? $this->next($tokens, $classToken + 1) : null;
        if ($classToken === null || !is_array($tokens[$classToken]) || $tokens[$classToken][0] !== T_CLASS
            || $delimiter === null || !in_array($tokens[$delimiter], [',', ')'], true)) {
            return null;
        }

        $name = $first[1];
        // Uma referência absoluta é independente dos imports e do namespace.
        if ($first[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($name, '\\');
        }

        $imports = $this->imports($tokens);
        if ($imports === null) {
            return null;
        }
        $parts = explode('\\', $name);
        $alias = strtolower($parts[0]);
        if (array_key_exists($alias, $imports)) {
            $resolved = $imports[$alias];
            if ($resolved === null) {
                return null;
            }
            array_shift($parts);
            return $resolved . ($parts === [] ? '' : '\\' . implode('\\', $parts));
        }

        $namespace = $this->namespaceName($tokens);
        if ($namespace === null) {
            return null;
        }
        return $namespace !== '' ? $namespace . '\\' . $name : $name;
    }

    /**
     * Extrai imports simples de classe declarados fora de blocos.
     *
     * Um alias com mais de um destino resulta em null, não em escolha
     * arbitrária. Trait uses, closure uses e imports por grupos não entram.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
     * @return array<string,string|null>|null Aliases ou null quando há import complexo.
     */
    private function imports(array $tokens): ?array
    {
        /** @var array<string,string|null> $imports Mapa de aliases inequívocos. */
        $imports = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if (!is_array($token) || $token[0] !== T_USE || $depth !== 0) {
                continue;
            }

            $statement = '';
            for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    return null;
                }
                if ($tokens[$j] === ';') {
                    break;
                }
                $statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            }
            if (preg_match('/^\s*(?:function|const)\s/i', $statement) === 1) {
                continue;
            }
            if (preg_match('/^\s*\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/i', $statement, $m) !== 1) {
                return null;
            }

            $canonical = ltrim($m[1], '\\');
            $name = explode('\\', $canonical);
            $alias = strtolower($m[2] ?? end($name));
            if (array_key_exists($alias, $imports) && strcasecmp((string) $imports[$alias], $canonical) !== 0) {
                $imports[$alias] = null;
            } elseif (!array_key_exists($alias, $imports)) {
                $imports[$alias] = $canonical;
            }
        }
        return $imports;
    }

    /**
     * Lê namespace por ponto e vírgula, recusando namespace em bloco/múltiplo.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
     * @return string|null Namespace, vazio para global ou null quando ambíguo.
     */
    private function namespaceName(array $tokens): ?string
    {
        $namespace = '';
        $seen = false;
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }
            if ($seen) {
                return null;
            }
            $seen = true;
            $name = '';
            for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    return null;
                }
                if ($tokens[$j] === ';') {
                    break;
                }
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $name .= $tokens[$j][1];
                }
            }
            $namespace = trim($name, '\\');
        }
        return $namespace;
    }

    /**
     * Busca o próximo token significativo, sem comentários ou espaços.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
     * @param int $start Índice inicial.
     * @return int|null Próximo índice encontrado.
     */
    private function next(array $tokens, int $start): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }
        return null;
    }
}
