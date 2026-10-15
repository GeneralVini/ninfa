<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Inventaria registros literais de hooks no setup.php de plugins GLPI 11.
 *
 * O parser interpreta tokens PHP e nunca executa o consumidor. Apenas
 * atribuições simples a índices literais de $PLUGIN_HOOKS entram no inventário;
 * expressões/constantes de hook, owner dinâmico e arrays compostos não geram
 * prova negativa. O detector não atribui severidade nem oferece patches.
 */
final class GlpiHookRegistryAnalyzer
{
    /**
     * Registra atribuições literais de hooks GLPI com o nível de indexação provado.
     *
     * A forma com um índice pode ser válida quando recebe um mapa de plugins,
     * mas atribuir um escalar ao hook em vez de ao plugin é incompatível com
     * os hooks de escopo por plugin. O RuleEngine aplica essa política somente
     * para hooks conhecidos; o inventário permanece neutro.
     *
     * @param ProjectContext $context Contexto GLPI, sem executar setup.php.
     * @return list<array{file:string,line:int,hook:string,plugin:string|null,scope:string,scalar_value:bool}> Fatos literais, na ordem do source.
     */
    public function registrations(ProjectContext $context): array
    {
        if ($context->profile() !== 'glpi-plugin') {
            return [];
        }

        $file = $context->root() . '/setup.php';
        $source = is_file($file) ? file_get_contents($file) : false;
        if (!is_string($source)) {
            return [];
        }

        /** @var list<array{0:int,1:string,2:int}|string> $tokens Stream PHP sem executar o arquivo. */
        $tokens = token_get_all($source);
        /** @var list<array{file:string,line:int,hook:string,plugin:string|null,scope:string,scalar_value:bool}> $registrations Inventário limitado às atribuições literais. */
        $registrations = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$PLUGIN_HOOKS') {
                continue;
            }

            $first = $this->next($tokens, $index + 1);
            if ($first === null || $tokens[$first] !== '[') {
                continue;
            }
            $firstClose = $this->matchingSquare($tokens, $first);
            if ($firstClose === null) {
                continue;
            }
            $hook = $this->stringLiteral($tokens, $first + 1, $firstClose - 1);
            if ($hook === null) {
                continue;
            }

            $scope = 'hook';
            $plugin = null;
            $end = $firstClose;
            $next = $this->next($tokens, $end + 1);
            if ($next !== null && $tokens[$next] === '[') {
                $secondClose = $this->matchingSquare($tokens, $next);
                if ($secondClose === null) {
                    continue;
                }
                $plugin = $this->stringLiteral($tokens, $next + 1, $secondClose - 1);
                if ($plugin === null) {
                    continue;
                }
                $scope = 'plugin';
                $end = $secondClose;
                $next = $this->next($tokens, $end + 1);
            }
            // Três ou mais dimensões e operadores como [] +=/??= não são
            // registro completo de hook/plugin e não autorizam diagnósticos.
            if ($next === null || $tokens[$next] !== '=') {
                continue;
            }
            $value = $this->next($tokens, $next + 1);
            if ($value === null) {
                continue;
            }

            $registrations[] = [
                'file' => 'setup.php',
                'line' => $token[2],
                'hook' => $hook,
                'plugin' => $plugin,
                'scope' => $scope,
                'scalar_value' => $this->isStandaloneScalar($tokens, $value),
            ];
        }

        return $registrations;
    }

    /**
     * Encontra o próximo token não documental dentro do stream.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
     * @param int $offset Índice inicial inclusivo.
     * @param int|null $last Limite inclusivo ou fim da lista.
     * @return int|null Índice encontrado ou null.
     */
    private function next(array $tokens, int $offset, ?int $last = null): ?int
    {
        $end = min($last ?? (count($tokens) - 1), count($tokens) - 1);
        for ($cursor = $offset; $cursor <= $end; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $cursor;
        }
        return null;
    }

    /**
     * Obtém o índice do fechamento de colchetes respeitando arrays aninhados.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
     * @param int $opening Índice do '[' inicial.
     * @return int|null Fechamento correspondente ou null.
     */
    private function matchingSquare(array $tokens, int $opening): ?int
    {
        $depth = 0;
        for ($cursor = $opening, $count = count($tokens); $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if ($token === '[') {
                $depth++;
            } elseif ($token === ']') {
                $depth--;
                if ($depth === 0) {
                    return $cursor;
                }
            }
        }
        return null;
    }

    /**
     * Extrai uma string simples exclusivamente literal de um índice de array.
     *
     * Escapes, concatenações e interpolação são recusados porque o nome precisa
     * ser comprovável. Strings entre aspas duplas sem escapes são aceitas.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
     * @param int $start Primeiro token candidato do índice.
     * @param int $last Último token candidato do índice.
     * @return string|null Nome literal ou null quando dinâmico/ambíguo.
     */
    private function stringLiteral(array $tokens, int $start, int $last): ?string
    {
        $pos = $this->next($tokens, $start, $last);
        if ($pos === null || !is_array($tokens[$pos]) || $tokens[$pos][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        if ($this->next($tokens, $pos + 1, $last) !== null) {
            return null;
        }
        $raw = $tokens[$pos][1];
        if (strlen($raw) < 2 || !in_array($raw[0], ["'", '"'], true) || $raw[0] !== $raw[strlen($raw) - 1]) {
            return null;
        }
        $value = substr($raw, 1, -1);
        return str_contains($value, '\\') || str_contains($value, '$') ? null : $value;
    }

    /**
     * Prova que a expressão atribuída é um único escalar literal isolado.
     *
     * Um literal seguido por concatenação, ternário ou operação é dinâmico;
     * a checagem do ';' evita falso positivo a partir do primeiro token.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
     * @param int $value Índice do início da expressão de valor.
     * @return bool Verdadeiro somente para um escalar literal isolado.
     */
    private function isStandaloneScalar(array $tokens, int $value): bool
    {
        $token = $tokens[$value];
        if (!is_array($token)) {
            return false;
        }
        $literal = in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)
            || ($token[0] === T_STRING && in_array(strtolower($token[1]), ['true', 'false', 'null'], true));
        if (!$literal) {
            return false;
        }
        $after = $this->next($tokens, $value + 1);
        return $after !== null && $tokens[$after] === ';';
    }
}
