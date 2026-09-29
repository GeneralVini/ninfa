<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta acesso global a request/response dentro de controllers Yii2 comprovados.
 *
 * Controllers expõem os mesmos objetos por `$this->request` e `$this->response`; portanto
 * `Yii::$app->request`/`response` dentro da própria classe é um smell de acoplamento global.
 * A regra é arquitetural/advisory: não representa vulnerabilidade ou erro funcional e não
 * é autoaplicada, mesmo quando a substituição textual parece simples.
 */
final class Yii2ControllerAccessAnalyzer
{
    /** @var list<string> Bases de controller Yii2 reconhecidas sem reflection runtime. */
    private const CONTROLLER_BASES = [
        'yii\\base\\Controller',
        'yii\\web\\Controller',
        'yii\\console\\Controller',
    ];

    /**
     * Localiza referências globais request/response em classes controller conclusivas.
     *
     * O scan limita-se ao corpo balanceado da classe. Parent externo desconhecido não é
     * presumido como controller, e referências em traits/helpers do mesmo arquivo só entram
     * se estiverem textualmente dentro do bloco da classe comprovada.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita os arquivos analisáveis.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   controller:string,
     *   property:'request'|'response',
     *   replacement:'$this->request'|'$this->response'
     * }> Ocorrências advisory em ordem de arquivo/linha.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var list<array{file:string,line:int,controller:string,property:'request'|'response',replacement:'$this->request'|'$this->response'}> $references */
        $references = [];

        // O advisory só entra depois de provar herança de controller; nome de arquivo/sufixo nunca é usado como evidência suficiente.
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $pattern = '/\\b(?:abstract\\s+|final\\s+)?class\\s+([A-Za-z_][A-Za-z0-9_]*)[^\\{]*\\{/';
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $short = (string) $match[1][0];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                if (!$this->isController($class, $classes, [])) {
                    continue;
                }
                $opening = strpos($source, '{', (int) $match[0][1]);
                $block = $opening === false ? null : $this->balancedBlock($source, $opening);
                if ($block === null) {
                    continue;
                }

                // Limitar o match ao bloco balanceado impede que helper adjacente herde falsamente a classificação do controller.
                if (preg_match_all('/(?:\\\\?Yii)::\\$app->(request|response)\\b/', $block['body'], $propertyMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }
                foreach ($propertyMatches as $propertyMatch) {
                    $property = (string) $propertyMatch[1][0];
                    $offset = $block['body_offset'] + (int) $propertyMatch[0][1];
                    $references[] = [
                        'file' => $this->relativePath($context->root(), $file),
                        'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                        'controller' => $class,
                        'property' => $property,
                        'replacement' => '$this->' . $property,
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * Lista arquivos PHP apenas nos paths autorizados pelo contexto.
     *
     * @param ProjectContext $context Contexto que fornece root e paths.
     * @return list<string> Arquivos absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos elegíveis à análise. */
        $files = [];
        foreach ($context->paths() as $path) {
            $absolute = $context->root() . '/' . $path;
            if (is_file($absolute) && strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'php') {
                $files[] = $absolute;
                continue;
            }
            if (!is_dir($absolute)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                    $files[] = $item->getPathname();
                }
            }
        }
        sort($files);
        return array_values(array_unique($files));
    }

    /**
     * Indexa classes locais e parents para prova de controller.
     *
     * @param list<string> $files Arquivos candidatos.
     * @return array<string,array{parent:string|null}> Índice local por FQCN.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes Metadados mínimos por classe. */
        $classes = [];
        $pattern = '/\\b(?:abstract\\s+|final\\s+)?class\\s+([A-Za-z_][A-Za-z0-9_]*)(?P<tail>[^{]*)\\{/';

        // Imports são resolvidos por arquivo antes de seguir herança local; parent externo não vira controller por convenção de nome.
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $short = (string) $match[1];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $tail = (string) ($match['tail'] ?? '');
                $parentRaw = preg_match('/\\bextends\\s+([^\\s{]+)/', $tail, $parentMatch) === 1
                    ? trim((string) $parentMatch[1])
                    : '';
                $classes[$class] = [
                    'parent' => $parentRaw === '' ? null : $this->resolveName($parentRaw, $uses, $namespace),
                ];
            }
        }
        return $classes;
    }

    /**
     * Prova descendência de controller seguindo apenas parents locais e bases oficiais.
     *
     * @param string $class FQCN candidato.
     * @param array<string,array{parent:string|null}> $classes Índice local.
     * @param array<string,bool> $visited Proteção de ciclos.
     * @return bool True somente quando a cadeia é conclusiva.
     */
    private function isController(string $class, array $classes, array $visited): bool
    {
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return false;
        }
        $parent = $classes[$class]['parent'];
        if ($parent === null) {
            return false;
        }
        if (in_array($parent, self::CONTROLLER_BASES, true)) {
            return true;
        }
        $visited[$class] = true;
        return $this->isController($parent, $classes, $visited);
    }

    /**
     * Delimita corpo de classe por chaves balanceadas e retorna seu offset absoluto.
     *
     * @param string $source Código-fonte completo.
     * @param int $openingOffset Offset da chave inicial.
     * @return array{body:string,body_offset:int}|null Bloco interno ou null quando incompleto.
     */
    private function balancedBlock(string $source, int $openingOffset): ?array
    {
        $depth = 0;
        $length = strlen($source);
        for ($cursor = $openingOffset; $cursor < $length; $cursor++) {
            if ($source[$cursor] === '{') {
                $depth++;
                continue;
            }
            if ($source[$cursor] !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return [
                    'body' => substr($source, $openingOffset + 1, $cursor - $openingOffset - 1),
                    'body_offset' => $openingOffset + 1,
                ];
            }
        }
        return null;
    }

    /**
     * Extrai namespace do arquivo sem executar código.
     *
     * @param string $source Código-fonte completo.
     * @return string Namespace sem barra inicial.
     */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\\bnamespace\\s+([^;{]+)\\s*[;{]/', $source, $match) === 1
            ? trim((string) $match[1], " \\t\\n\\r\\0\\x0B\\\\")
            : '';
    }

    /**
     * Extrai imports de classe anteriores à primeira declaração de classe.
     *
     * @param string $source Código-fonte completo.
     * @return array<string,string> Alias para FQCN.
     */
    private function importsOf(string $source): array
    {
        $classOffset = preg_match('/\\b(?:abstract\\s+|final\\s+)?class\\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classOffset);
        /** @var array<string,string> $imports Imports normalizados. */
        $imports = [];
        if (preg_match_all('/\\buse\\s+([^;]+);/', $prefix, $matches) === 0) {
            return $imports;
        }
        foreach ($matches[1] as $rawImport) {
            $import = trim((string) $rawImport);
            if (str_contains($import, '{') || str_starts_with(strtolower($import), 'function ') || str_starts_with(strtolower($import), 'const ')) {
                continue;
            }
            $parts = preg_split('/\\s+as\\s+/i', $import) ?: [];
            $fqcn = trim((string) ($parts[0] ?? ''), " \\t\\n\\r\\0\\x0B\\\\");
            if ($fqcn === '') {
                continue;
            }
            $alias = isset($parts[1]) ? trim((string) $parts[1]) : $this->shortName($fqcn);
            $imports[$alias] = $fqcn;
        }
        return $imports;
    }

    /**
     * Resolve nome relativo/importado para FQCN.
     *
     * @param string $name Nome observado.
     * @param array<string,string> $uses Imports normalizados.
     * @param string $namespace Namespace atual.
     * @return string FQCN sem barra inicial.
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $trimmed = ltrim(trim($name), '\\');
        if (str_starts_with(trim($name), '\\')) {
            return $trimmed;
        }
        $segments = explode('\\', $trimmed);
        $first = $segments[0] ?? '';
        if (isset($uses[$first])) {
            array_shift($segments);
            return $uses[$first] . ($segments === [] ? '' : '\\' . implode('\\', $segments));
        }
        return $namespace === '' ? $trimmed : $namespace . '\\' . $trimmed;
    }

    /**
     * Extrai o último segmento de um FQCN.
     *
     * @param string $fqcn Nome qualificado sem barra inicial.
     * @return string Nome curto.
     */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * Converte path absoluto para relativo estável.
     *
     * @param string $root Raiz absoluta.
     * @param string $file Arquivo absoluto.
     * @return string Path relativo com `/`.
     */
    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }
}
