<?php

declare(strict_types=1);

/**
 * Indexa chamadas literais a Plugin::registerClass() no setup de plugins GLPI.
 *
 * O primeiro argumento precisa ser uma string isolada e o alvo Plugin precisa
 * ser inequivocamente o símbolo global do GLPI. Referências calculadas,
 * imports ambíguos e classes apenas carregadas por autoload ficam unknown.
 * Nenhum código de plugin é incluído ou executado.
 */
final class GlpiClassRegistrationIndex
{
    /**
     * Registra referências literais com evidência positiva do índice local.
     *
     * Ausência de classe no índice src/ não implica classe inexistente no
     * GLPI ou em outras pastas mapeadas pelo Composer. O campo declared nunca
     * se torna false nesta tranche.
     *
     * @param string $setup Conteúdo textual de setup.php.
     * @param array<string,array{name:string,file:string,line:int,methods:list<string>}> $classes Declarações locais comprovadas.
     * @return list<array{file:string,line:int,class:string,declared:bool|null,methods:list<string>}> Registro e evidência tri-state.
     */
    public function build(string $setup, array $classes): array
    {
        /** @var list<array{0:int,1:string,2:int}|string> $tokens PHP tokenizado sem execução. */
        $tokens = token_get_all($setup);
        $namespaced = false;
        $conflictingImport = false;

        // Em namespace explícito, Plugin:: sem barra pode apontar à classe local.
        // Aliases de 'Plugin' também invalidam a prova de identidade global.
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespaced = true;
            }
            if ($token[0] === T_USE) {
                $statement = '';
                for ($j = $i + 1, $count = count($tokens); $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    $statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                if (preg_match('/^\s*(?:\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\\\\Plugin|\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\s+as\s+Plugin)\s*$/i', $statement) === 1) {
                    $conflictingImport = true;
                }
            }
        }

        /** @var list<array{file:string,line:int,class:string,declared:bool|null,methods:list<string>}> $registrations Literal de classe e declaração comprovada. */
        $registrations = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token)
                || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                || !in_array(strtolower($token[1]), ['plugin', '\\plugin'], true)) {
                continue;
            }
            if ($token[0] === T_STRING && ($namespaced || $conflictingImport)) {
                continue;
            }
            $doubleColon = $this->next($tokens, $i + 1);
            $method = $doubleColon !== null ? $this->next($tokens, $doubleColon + 1) : null;
            $opening = $method !== null ? $this->next($tokens, $method + 1) : null;
            $firstArgument = $opening !== null ? $this->next($tokens, $opening + 1) : null;
            if ($doubleColon === null || !is_array($tokens[$doubleColon]) || $tokens[$doubleColon][0] !== T_DOUBLE_COLON
                || $method === null || !is_array($tokens[$method]) || strtolower($tokens[$method][1]) !== 'registerclass'
                || $opening === null || $tokens[$opening] !== '('
                || $firstArgument === null || !is_array($tokens[$firstArgument]) || $tokens[$firstArgument][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $after = $this->next($tokens, $firstArgument + 1);
            if ($after === null || !in_array($tokens[$after], [',', ')'], true)) {
                continue;
            }
            $raw = $tokens[$firstArgument][1];
            // Aspas simples sem escapes: forma mais segura para classes GLPI
            // legadas como 'PluginExampleProfile'. Demais expressões unknown.
            if (strlen($raw) < 3 || $raw[0] !== "'" || !str_ends_with($raw, "'")) {
                continue;
            }
            $class = substr($raw, 1, -1);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class) !== 1) {
                continue;
            }

            $declaration = $classes[strtolower($class)] ?? null;
            $registrations[] = [
                'file' => 'setup.php',
                'line' => $token[2],
                'class' => $class,
                'declared' => $declaration !== null ? true : null,
                'methods' => $declaration['methods'] ?? [],
            ];
        }

        return $registrations;
    }

    /**
     * Busca o próximo token relevante sem tomar comentários por código.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens de setup.php.
     * @param int $start Índice inicial inclusivo.
     * @return int|null Próximo índice válido.
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
