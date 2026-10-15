<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Indexa declarações nomeadas de classes e métodos do próprio plugin GLPI.
 *
 * Usa apenas token_get_all, sem autoload/require, e restringe a busca a
 * setup.php, hook.php e arquivos PHP locais em src/. Arquivos simbólicos,
 * classes anônimas, namespaces com bloco e classes condicionais não fornecem
 * evidência positiva. Não produz prova negativa de autoload ou herança.
 */
final class GlpiClassDeclarationIndex
{
    /**
     * Devolve declarações estáticas comprovadas, por nome de classe normalizado.
     *
     * @param ProjectContext $context Contexto GLPI previamente validado.
     * @return array<string,array{name:string,file:string,line:int,methods:list<string>}> Declarações locais, nunca inventário completo de autoload.
     */
    public function build(ProjectContext $context): array
    {
        if ($context->profile() !== 'glpi-plugin') {
            return [];
        }

        /** @var list<string> $files Arquivos de origem permitidos, sem links externos. */
        $files = [];
        foreach (['setup.php', 'hook.php'] as $name) {
            $path = $context->root() . '/' . $name;
            if (is_file($path) && !is_link($path)) {
                $files[] = $path;
            }
        }

        $src = $context->root() . '/src';
        if (is_dir($src) && !is_link($src)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $entry) {
                if ($entry->isFile() && !$entry->isLink() && strtolower($entry->getExtension()) === 'php') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        sort($files);

        /** @var array<string,array{name:string,file:string,line:int,methods:list<string>}> $classes Declarações estáticas pelo nome canônico minúsculo. */
        $classes = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }
            $relative = ltrim(substr($file, strlen($context->root())), '/');
            foreach ($this->extract($source, $relative) as $declaration) {
                $key = strtolower($declaration['name']);
                // Definição duplicada/condicional não fornece resolução unívoca.
                if (!isset($classes[$key])) {
                    $classes[$key] = $declaration;
                }
            }
        }
        ksort($classes);
        return $classes;
    }

    /**
     * Extrai classes de escopo raiz e métodos declarados diretamente no corpo.
     *
     * Permite namespace Foo; convencional. Namespace Foo {...}, traits,
     * interfaces, enums, classes locais a funções e classes anônimas ficam
     * fora do índice para não afirmar contratos ambíguos.
     *
     * @param string $source Fonte PHP analisada como texto.
     * @param string $relative Caminho do consumidor relativo à raiz.
     * @return list<array{name:string,file:string,line:int,methods:list<string>}> Declarações reconhecidas.
     */
    private function extract(string $source, string $relative): array
    {
        /** @var list<array{0:int,1:string,2:int}|string> $tokens Tokens PHP. */
        $tokens = token_get_all($source);
        $namespace = '';
        $depth = 0;
        /** @var array{name:string,file:string,line:int,methods:list<string>}|null $active Classe cujo corpo está sendo lido. */
        $active = null;
        /** @var array{name:string,file:string,line:int,methods:list<string>}|null $pending Classe aguardando a abertura do corpo. */
        $pending = null;
        /** @var list<array{name:string,file:string,line:int,methods:list<string>}> $found Declarações nomeadas globais. */
        $found = [];

        foreach ($tokens as $i => $token) {
            if ($token === '{') {
                $depth++;
                if ($pending !== null && $depth === 1) {
                    $active = $pending;
                    $pending = null;
                }
                continue;
            }
            if ($token === '}') {
                if ($depth === 1 && $active !== null) {
                    $active['methods'] = array_values(array_unique($active['methods']));
                    sort($active['methods']);
                    $found[] = $active;
                    $active = null;
                }
                $depth = max(0, $depth - 1);
                continue;
            }
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE && $depth === 0) {
                $name = '';
                for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
                    if ($tokens[$j] === '{') {
                        // Namespace em bloco necessita outra prova de escopo.
                        return [];
                    }
                    if ($tokens[$j] === ';') {
                        break;
                    }
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $name .= $tokens[$j][1];
                    }
                }
                $namespace = trim($name, '\\');
                continue;
            }

            if ($token[0] === T_CLASS && $depth === 0) {
                $previous = $this->significant($tokens, $i - 1, -1);
                $next = $this->significant($tokens, $i + 1, 1);
                // ::class, new class e declarações sem nome não são classes reais indexáveis.
                if (($previous !== null && is_array($tokens[$previous])
                    && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true))
                    || $next === null || !is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING) {
                    continue;
                }
                $name = ($namespace !== '' ? $namespace . '\\' : '') . $tokens[$next][1];
                $pending = ['name' => $name, 'file' => $relative, 'line' => $token[2], 'methods' => []];
                continue;
            }

            if ($token[0] === T_FUNCTION && $depth === 1 && $active !== null) {
                $next = $this->significant($tokens, $i + 1, 1);
                if ($next !== null && $tokens[$next] === '&') {
                    $next = $this->significant($tokens, $next + 1, 1);
                }
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $active['methods'][] = strtolower($tokens[$next][1]);
                }
            }
        }

        return $found;
    }

    /**
     * Obtém índice de token sintaticamente relevante, ignorando comentários.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens Tokens do arquivo PHP.
     * @param int $start Posição de partida.
     * @param int $direction Passo de leitura, -1 ou +1.
     * @return int|null Índice válido ou ausência de token.
     */
    private function significant(array $tokens, int $start, int $direction): ?int
    {
        for ($i = $start, $count = count($tokens); $i >= 0 && $i < $count; $i += $direction) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }
        return null;
    }
}
