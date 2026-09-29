<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta aliases deprecated de Cache/Dependency somente quando o receiver tem tipo comprovável.
 *
 * A prova de tipo é intencionalmente local: parâmetros tipados, propriedades tipadas e variáveis
 * inicializadas diretamente com `new`. Subclasses locais são seguidas até `yii\caching\Cache` ou
 * `yii\caching\Dependency`; receivers sem prova suficiente permanecem fora da regra.
 */
final class Yii2CachingDeprecationAnalyzer
{
    /** @var array<string,string> Aliases antigos de Cache e respectivos métodos canônicos. */
    private const CACHE_METHODS = ['mget' => 'multiGet', 'mset' => 'multiSet', 'madd' => 'multiAdd'];

    /**
     * Localiza patches SAFE para APIs deprecated de caching Yii2.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita os arquivos do consumidor.
     * @return list<array{file:string,line:int,rule:'NINFA-YII2-DEP-004'|'NINFA-YII2-DEP-005',kind:'cache-method'|'dependency-method',problem:string,replacement:string,offset:int,length:int}> Evidências em ordem estável.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var list<array{file:string,line:int,rule:'NINFA-YII2-DEP-004'|'NINFA-YII2-DEP-005',kind:'cache-method'|'dependency-method',problem:string,replacement:string,offset:int,length:int}> $references */
        $references = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $properties = $this->propertyTypes($source, $namespace, $uses);
            $relative = $this->relativePath($context->root(), $file);

            // Tipos de variáveis são reiniciados por método para não vazar fatos entre escopos.
            foreach ($this->methodBlocks($source) as $method) {
                $variables = $this->parameterTypes($method['params'], $namespace, $uses);
                foreach ($this->localNewTypes($method['body'], $namespace, $uses) as $name => $type) {
                    $variables[$name] = $type;
                }

                foreach ($this->candidateCalls($method['body']) as $call) {
                    $type = $this->receiverType($call['receiver'], $variables, $properties);
                    if ($type === null) {
                        continue;
                    }

                    if (isset(self::CACHE_METHODS[$call['method']]) && $this->isType($type, 'yii\\caching\\Cache', $classes, [])) {
                        $references[] = $this->buildReference(
                            $source,
                            $relative,
                            $method['body_offset'],
                            $call,
                            'NINFA-YII2-DEP-004',
                            'cache-method',
                            'Alias deprecated de yii\\caching\\Cache deve usar o método multi* canônico.',
                            self::CACHE_METHODS[$call['method']],
                        );
                        continue;
                    }

                    if ($call['method'] === 'getHasChanged' && $this->isType($type, 'yii\\caching\\Dependency', $classes, [])) {
                        $references[] = $this->buildReference(
                            $source,
                            $relative,
                            $method['body_offset'],
                            $call,
                            'NINFA-YII2-DEP-005',
                            'dependency-method',
                            'yii\\caching\\Dependency::getHasChanged() está deprecated; use isChanged().',
                            'isChanged',
                        );
                    }
                }
            }
        }

        usort($references, static fn (array $left, array $right): int => [$left['file'], $left['offset']] <=> [$right['file'], $right['offset']]);
        return $references;
    }

    /**
     * Constrói o shape comum de patch preservando offset do nome do método.
     *
     * @param array{receiver:string,method:string,method_offset:int} $call Chamada candidata.
     * @param 'NINFA-YII2-DEP-004'|'NINFA-YII2-DEP-005' $rule Identificador estável.
     * @param 'cache-method'|'dependency-method' $kind Família da depreciação.
     * @return array{file:string,line:int,rule:'NINFA-YII2-DEP-004'|'NINFA-YII2-DEP-005',kind:'cache-method'|'dependency-method',problem:string,replacement:string,offset:int,length:int}
     */
    private function buildReference(
        string $source,
        string $file,
        int $bodyOffset,
        array $call,
        string $rule,
        string $kind,
        string $problem,
        string $replacement,
    ): array {
        $offset = $bodyOffset + $call['method_offset'];
        return [
            'file' => $file,
            'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
            'rule' => $rule,
            'kind' => $kind,
            'problem' => $problem,
            'replacement' => $replacement,
            'offset' => $offset,
            'length' => strlen($call['method']),
        ];
    }

    /**
     * Extrai chamadas candidatas sobre variável ou propriedade de `$this`.
     *
     * @return list<array{receiver:string,method:string,method_offset:int}> Chamadas candidatas.
     */
    private function candidateCalls(string $body): array
    {
        $pattern = '/(?P<receiver>\$this->[A-Za-z_][A-Za-z0-9_]*|\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?P<method>mget|mset|madd|getHasChanged)\s*(?=\()/';
        $count = preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return [];
        }

        /** @var list<array{receiver:string,method:string,method_offset:int}> $calls */
        $calls = [];
        foreach ($matches as $match) {
            $calls[] = [
                'receiver' => (string) $match['receiver'][0],
                'method' => (string) $match['method'][0],
                'method_offset' => (int) $match['method'][1],
            ];
        }
        return $calls;
    }

    /**
     * Resolve o tipo comprovado do receiver dentro do escopo atual.
     *
     * @param array<string,string> $variables Tipos de parâmetros/locals por nome sem `$`.
     * @param array<string,string> $properties Tipos de propriedades por nome.
     */
    private function receiverType(string $receiver, array $variables, array $properties): ?string
    {
        if (str_starts_with($receiver, '$this->')) {
            return $properties[substr($receiver, 7)] ?? null;
        }
        return $variables[ltrim($receiver, '$')] ?? null;
    }

    /**
     * Extrai parâmetros com type hint nominal simples.
     *
     * Union/intersection/nullable complexo permanece desconhecido nesta tranche.
     *
     * @param array<string,string> $uses Imports por alias.
     * @return array<string,string> Variável para FQCN.
     */
    private function parameterTypes(string $params, string $namespace, array $uses): array
    {
        /** @var array<string,string> $types */
        $types = [];
        $name = '\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';
        $pattern = '/(?:^|,)\s*(?:public\s+|protected\s+|private\s+|readonly\s+)*(?P<type>' . $name . ')\s+(?:&\s*)?(?:\.\.\.\s*)?\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)/';
        $count = preg_match_all($pattern, $params, $matches, PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return $types;
        }
        foreach ($matches as $match) {
            $types[(string) $match['var']] = $this->resolveName((string) $match['type'], $uses, $namespace);
        }
        return $types;
    }

    /**
     * Extrai propriedades com type hint nominal simples.
     *
     * @param array<string,string> $uses Imports por alias.
     * @return array<string,string> Propriedade para FQCN.
     */
    private function propertyTypes(string $source, string $namespace, array $uses): array
    {
        /** @var array<string,string> $types */
        $types = [];
        $name = '\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';
        $pattern = '/\b(?:public|protected|private)\s+(?:readonly\s+)?(?P<type>' . $name . ')\s+\$(?P<property>[A-Za-z_][A-Za-z0-9_]*)\b/';
        $count = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return $types;
        }
        foreach ($matches as $match) {
            $types[(string) $match['property']] = $this->resolveName((string) $match['type'], $uses, $namespace);
        }
        return $types;
    }

    /**
     * Extrai `$var = new Type(...)` dentro do método.
     *
     * @param array<string,string> $uses Imports por alias.
     * @return array<string,string> Variável para FQCN.
     */
    private function localNewTypes(string $body, string $namespace, array $uses): array
    {
        /** @var array<string,string> $types */
        $types = [];
        $name = '\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*';
        $pattern = '/\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+(?P<type>' . $name . ')\s*\(/';
        $count = preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);
        if ($count === false || $count === 0) {
            return $types;
        }
        foreach ($matches as $match) {
            $types[(string) $match['var']] = $this->resolveName((string) $match['type'], $uses, $namespace);
        }
        return $types;
    }

    /**
     * Delimita corpos de métodos e preserva o offset absoluto do primeiro byte interno.
     *
     * @return list<array{params:string,body:string,body_offset:int}> Métodos balanceados.
     */
    private function methodBlocks(string $source): array
    {
        $pattern = '/\bfunction\s+[A-Za-z_][A-Za-z0-9_]*\s*\((?P<params>[^)]*)\)[^{;]*\{/';
        $count = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($count === false || $count === 0) {
            return [];
        }

        /** @var list<array{params:string,body:string,body_offset:int}> $methods */
        $methods = [];
        foreach ($matches as $match) {
            $opening = strpos($source, '{', (int) $match[0][1]);
            if ($opening === false) {
                continue;
            }
            $block = $this->balancedBlock($source, $opening);
            if ($block === null) {
                continue;
            }
            $methods[] = ['params' => (string) $match['params'][0], 'body' => $block['body'], 'body_offset' => $block['body_offset']];
        }
        return $methods;
    }

    /**
     * Prova tipo exato ou descendência exclusivamente através de classes locais.
     *
     * @param array<string,array{parent:string|null}> $classes Índice local de herança.
     * @param array<string,bool> $visited Proteção contra ciclos inválidos.
     */
    private function isType(string $type, string $base, array $classes, array $visited): bool
    {
        if ($type === $base) {
            return true;
        }
        if (isset($visited[$type]) || !isset($classes[$type])) {
            return false;
        }
        $parent = $classes[$type]['parent'];
        if ($parent === null) {
            return false;
        }
        $visited[$type] = true;
        return $this->isType($parent, $base, $classes, $visited);
    }

    /**
     * Indexa classes e parent nominal local para resolução de subclasses.
     *
     * @param list<string> $files Arquivos PHP elegíveis.
     * @return array<string,array{parent:string|null}> FQCN para parent resolvido.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes */
        $classes = [];
        $pattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)(?P<tail>[^{]*)\{/';
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $count = preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
            if ($count === false || $count === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $short = (string) $match[1];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $tail = (string) ($match['tail'] ?? '');
                $parentRaw = preg_match('/\bextends\s+([^\s{]+)/', $tail, $parentMatch) === 1 ? trim((string) $parentMatch[1]) : '';
                $classes[$class] = ['parent' => $parentRaw === '' ? null : $this->resolveName($parentRaw, $uses, $namespace)];
            }
        }
        return $classes;
    }

    /**
     * Lista PHP apenas nos paths autorizados por ProjectContext.
     *
     * @return list<string> Arquivos absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files */
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
     * Retorna corpo interno de chaves balanceadas e seu offset absoluto.
     *
     * @return array{body:string,body_offset:int}|null Bloco delimitado ou null.
     */
    private function balancedBlock(string $source, int $openingOffset): ?array
    {
        $depth = 0;
        for ($cursor = $openingOffset, $length = strlen($source); $cursor < $length; $cursor++) {
            if ($source[$cursor] === '{') {
                $depth++;
                continue;
            }
            if ($source[$cursor] !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return ['body' => substr($source, $openingOffset + 1, $cursor - $openingOffset - 1), 'body_offset' => $openingOffset + 1];
            }
        }
        return null;
    }

    /** Extrai namespace sem executar o consumidor. */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $match) === 1
            ? trim((string) $match[1], " \t\n\r\0\x0B\\")
            : '';
    }

    /**
     * Extrai imports de classe antes da primeira declaração de classe.
     *
     * @return array<string,string> Alias para FQCN.
     */
    private function importsOf(string $source): array
    {
        $classOffset = preg_match('/\b(?:abstract\s+|final\s+)?class\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classOffset);
        /** @var array<string,string> $imports */
        $imports = [];
        $count = preg_match_all('/\buse\s+([^;]+);/', $prefix, $matches);
        if ($count === false || $count === 0) {
            return $imports;
        }
        foreach ($matches[1] as $rawImport) {
            $import = trim((string) $rawImport);
            if (str_contains($import, '{') || str_starts_with(strtolower($import), 'function ') || str_starts_with(strtolower($import), 'const ')) {
                continue;
            }
            $parts = preg_split('/\s+as\s+/i', $import) ?: [];
            $fqcn = trim((string) ($parts[0] ?? ''), " \t\n\r\0\x0B\\");
            if ($fqcn === '') {
                continue;
            }
            $alias = isset($parts[1]) ? trim((string) $parts[1]) : $this->shortName($fqcn);
            $imports[$alias] = $fqcn;
        }
        return $imports;
    }

    /**
     * Resolve classe nominal por namespace e imports.
     *
     * @param array<string,string> $uses Imports por alias.
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $raw = trim($name);
        $trimmed = ltrim($raw, '\\');
        if (str_starts_with($raw, '\\')) {
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

    /** Retorna o último segmento de um FQCN. */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /** Converte path absoluto para relativo estável do consumidor. */
    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }
}
