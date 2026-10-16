<?php

declare(strict_types=1);

/**
 * Resolve constantes literais de Glpi\Plugin\Hooks no host GLPI 11 analisado.
 *
 * Lê apenas texto/token streams; não faz require/autoload do core nem executa
 * setup.php do consumidor. Na ausência do arquivo oficial, de import inequívoco
 * ou de valor literal, devolve null e mantém o registro em unknown.
 */
final class GlpiHookConstantResolver
{
    /** @var array<string,string> Valores públicos literais obtidos do host GLPI detectado. */
    private readonly array $constants;

    /**
     * Constrói o inventário do arquivo oficial sem executar seu conteúdo.
     *
     * @param string|null $hostRoot Diretório do host selecionado por ProjectContext.
     */
    public function __construct(?string $hostRoot)
    {
        /** @var array<string,string> $constants Valores imutáveis extraídos do host. */
        $constants = [];
        $file = is_string($hostRoot) ? $hostRoot . '/src/Glpi/Plugin/Hooks.php' : '';
        $source = $file !== '' && is_file($file) ? file_get_contents($file) : false;
        if (is_string($source)) {
            // Só reconhecemos as declarações públicas de uma string literal.
            // Expressões calculadas, aliases de constantes ou strings escapadas
            // não têm valor demonstrado e jamais entram no inventário.
            preg_match_all(
                '/^\h*public\h+const\h+([A-Z][A-Z0-9_]*)\h*=\h*([\\x27\\x22])([^\\x27\\x22\\\\\r\n]*)\2\h*;/m',
                $source,
                $matches,
                PREG_SET_ORDER,
            );
            foreach ($matches as $match) {
                $constants[$match[1]] = $match[3];
            }
        }
        $this->constants = $constants;
    }

    /**
     * Resolve exatamente uma expressão Hooks::CONST ou \Glpi\Plugin\Hooks::CONST.
     *
     * Para a forma curta, exige import explícito do arquivo, incluindo aliases
     * sem colisão. Namespaced/fully qualified precisam corresponder à classe
     * oficial. Referências e expressões parciais não são aceitas.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens de setup.php.
     * @param int $start Primeiro token dentro do índice de hook.
     * @param int $last Último token dentro do índice de hook.
     * @return string|null Chave real do hook, ou null quando não demonstrável.
     */
    public function resolve(array $tokens, int $start, int $last): ?string
    {
        /** @var list<array{0:int,1:string,2:int}|string> $expr Tokens relevantes sem whitespace/comentários. */
        $expr = [];
        for ($i = $start; $i <= $last; $i++) {
            $token = $tokens[$i] ?? null;
            if ($token === null || (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                continue;
            }
            $expr[] = $token;
        }
        if (count($expr) !== 3
            || !is_array($expr[0])
            || !is_array($expr[1])
            || $expr[1][0] !== T_DOUBLE_COLON
            || !is_array($expr[2])
            || $expr[2][0] !== T_STRING) {
            return null;
        }

        $class = strtolower(ltrim($expr[0][1], '\\'));
        $constant = $expr[2][1];
        if (!in_array($expr[0][0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STRING], true)) {
            return null;
        }

        if ($class !== 'glpi\\plugin\\hooks') {
            $alias = $this->importedHooksAlias($tokens);
            if ($alias === null || $class !== $alias) {
                return null;
            }
        } elseif ($expr[0][0] === T_NAME_QUALIFIED && $this->hasNamespace($tokens)) {
            // Sem \ inicial, Glpi\Plugin\Hooks resolve relativamente a namespaces.
            return null;
        }

        return $this->constants[$constant] ?? null;
    }

    /**
     * Busca o alias explícito de use Glpi\Plugin\Hooks no escopo de arquivo.
     *
     * Outros imports que ocupem o mesmo alias tornam a resolução ambígua.
     * Imports em classes (traits) ou fechamentos (closure use) são ignorados.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens do consumidor.
     * @return string|null Alias inequívoco convertido em lowercase, ou null.
     */
    private function importedHooksAlias(array $tokens): ?string
    {
        /** @var array<string,string> $imports Apelidos declarados em imports simples. */
        $imports = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            } elseif (!is_array($token) || $token[0] !== T_USE || $depth !== 0) {
                continue;
            } else {
                $statement = '';
                for ($j = $i + 1, $count = count($tokens); $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    $statement .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                if (preg_match('/^\s*(?:function|const)\s/i', $statement) === 1) {
                    continue;
                }
                if (preg_match('/^\s*\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)\s*(?:as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*$/i', $statement, $match) !== 1) {
                    continue;
                }
                $qualified = strtolower(ltrim($match[1], '\\'));
                $parts = explode('\\', $qualified);
                $alias = strtolower($match[2] ?? end($parts));
                if (isset($imports[$alias]) && $imports[$alias] !== $qualified) {
                    return null;
                }
                $imports[$alias] = $qualified;
            }
        }

        foreach ($imports as $alias => $qualified) {
            if ($qualified === 'glpi\\plugin\\hooks') {
                return $alias;
            }
        }
        return null;
    }

    /**
     * Marca arquivos que declaram namespace explícito.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens do consumidor.
     * @return bool Se uma forma relativa FQN poderia ser reinterpretada.
     */
    private function hasNamespace(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                return true;
            }
        }
        return false;
    }
}
