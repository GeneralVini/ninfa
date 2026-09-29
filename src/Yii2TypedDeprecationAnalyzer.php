<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta depreciações de método Yii2 quando o tipo do receiver pode ser provado localmente.
 *
 * A análise é deliberadamente restrita a parâmetros tipados, propriedades tipadas e variáveis
 * locais inicializadas com `new`. Isso permite modernizar aliases de `yii\caching\Cache` e
 * `yii\caching\Dependency::getHasChanged()` sem inferir tipos a partir de nomes de variável,
 * PHPDoc solto ou estado runtime do container de dependências.
 */
final class Yii2TypedDeprecationAnalyzer
{
    /** @var array<string,string> Aliases deprecated de Cache para métodos canônicos. */
    private const CACHE_METHODS = [
        'mget' => 'multiGet',
        'mset' => 'multiSet',
        'madd' => 'multiAdd',
    ];

    /** @var list<string> Bases Yii2 aceitas para prova de tipo de cache. */
    private const CACHE_BASES = ['yii\\caching\\Cache'];

    /** @var list<string> Bases Yii2 aceitas para prova de tipo de dependency. */
    private const DEPENDENCY_BASES = ['yii\\caching\\Dependency'];

    /**
     * Localiza chamadas deprecated com receiver de tipo comprovado e replacement exato.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita root e paths analisáveis.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   rule:'NINFA-YII2-DEP-004'|'NINFA-YII2-DEP-005',
     *   kind:'cache-method'|'dependency-method',
     *   problem:string,
     *   replacement:string,
     *   offset:int,
     *   length:int
     * }> Patches SAFE em ordem de arquivo e offset.
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

            // Cada método é analisado isoladamente para impedir vazamento de tipos entre escopos.
            foreach ($this->methodBlocks($source) as $method) {
                $types = $this->parameterTypes($method['params'], $namespace, $uses);
                foreach ($this->localNewTypes($method['body'], $namespace, $uses) as $variable => $type) {
                    $types[$variable] = $type;
                }

                foreach ($this->deprecatedCalls($method['body']) as $call) {
                    $type = $this->receiverType($call['receiver'], $types, $properties);
                    if ($type === null) {
                        continue;
                    }

                    $methodName = $call['method'];
                    if (isset(self::CACHE_METHODS[$methodName]) && $this->isType($type, self::CACHE_BASES, $classes, [])) {
                        $references[] = $this->reference(
                            source: $source,
                            file: $relative,
                            bodyOffset: $method['body_offset'],
                            call: $call,
                            rule: 'NINFA-YII2-DEP-004',
                            kind: 'cache-method',
                            problem: 'Alias deprecated de yii\\caching\\Cache deve usar o método multi* canônico.',
                            replacement: self::CACHE_METHODS[$methodName],
                        );
                        continue;
                    }

                    if ($methodName === 'getHasChanged' && $this->isType($type, self::DEPENDENCY_BASES, $classes, [])) {
                        $references[] = $this->reference(
                            source: $source,
                            file: $relative,
                            bodyOffset: $method['body_offset'],
                            call: $call,
                            rule: 'NINFA-YII2-DEP-005',
                            kind: 'dependency-method',
                            problem: 'yii\\caching\\Dependency::getHasChanged() está deprecated; use isChanged().',
                            replacement: 'isChanged',
                        );
                    }
                }
            }
        }

        usort($references, static fn (array $left, array $right): int => [$left['file'], $left['offset']] <=> [$right['file'], $right['offset']]);
        return $references;
    }

    /**
     * Constrói a evidência comum preservando offsets do source original.
     *
     * @param string $source Source completo usado para calcular linha.
     * @param string $file Path relativo do consumidor.
     * @param int $bodyOffset Offset absoluto do início do corpo do método.
     * @param array{receiver:string,method:string,method_offset:int} $call Chamada observada no corpo.
     * @param 'NINFA-YII2-DEP-004'|'NINFA-YII2-DEP-005' $rule ID estável da regra.
     * @param 'cache-method'|'dependency-method' $kind Família da depreciação.
     * @param string $problem Mensagem normalizada.
     * @param string $replacement Novo nome de método.
     * @return array{file:string,line:int,rule:'NINFA-YII2-DEP-004'|'NINFA-YII2-DEP-005',kind:'cache-method'|'dependency-method',problem:string,replacement:string,offset:int,length:int}
     */
    private function reference(
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
     * Extrai chamadas candidatas e o offset do nome do método dentro do corpo.
     *
     * @param string $body Corpo de um método.
     * @return list<array{receiver:string,method:string,method_offset:int}> Chamadas candidatas.
     */
    private function deprecatedCalls(string $body): array
    {
        $pattern = '/(?P<receiver>\$this->[A-Za-z_][A-Za-z0-9_]*|\$[A-Za-z_][A-Za-z0-9_]*)\s*->\s*(?P<method>mget|mset|madd|getHasChanged)\s*(?=\()/';
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
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
     * Resolve receiver para FQCN usando os inventários do escopo atual.
     *
     * @param string $receiver `$var` ou `$this->property`.
     * @param array<string,string> $variables Tipos de parâmetros/locals por nome sem `$`.
     * @param array<string,string> $properties Tipos de propriedades por nome.
     * @return string|null Tipo comprovado ou null.
     */
    private function receiverType(string $receiver, array $variables, array $properties): ?string
    {
        if (str_starts_with($receiver, '$this->')) {
            return $properties[substr($receiver, 7)] ?? null;
        }
        return $variables[ltrim($receiver, '$')] ?? null;
    }

    /**
     * Extrai tipos simples de parâmetros; unions/intersections permanecem desconhecidos.
     *
     * @param string $params Texto entre parênteses da assinatura.
     * @param string $namespace Namespace atual.
     * @param array<string,string> $uses Imports disponíveis.
     * @return array<string,string> Variável sem `$` para FQCN.
     */
    private function parameterTypes(string $params, string $namespace, array $uses): array
    {
        /** @var array<string,string> $types */
        $types = [];
        $pattern = '/(?:^|,)\s*(?:public\s+|protected\s+|private\s+|readonly\s+)*(?P<type>\\?[A-Za-z_][A-Za-z0-9_\\]*)\s+(?:&\s*)?(?:\.\.\.\s*)?\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)/';
        if (preg_match_all($pattern, $params, $matches, PREG_SET_ORDER) === 0) {
            return $types;
        }
        foreach ($matches as $match) {
            $types[(string) $match['var']] = $this->resolveName((string) $match['type'], $uses, $namespace);
        }
        return $types;
    }

    /**
     * Extrai propriedades com type hint simples para reconhecer `$this->property`.
     *
     * @param string $source Source completo do arquivo.
     * @param string $namespace Namespace atual.
     * @param array<string,string> $uses Imports disponíveis.
     * @return array<string,string> Property para FQCN.
     */
    private function propertyTypes(string $source, string $namespace, array $uses): array
    {
        /** @var array<string,string> $types */
        $types = [];
        $pattern = '/\b(?:public|protected|private)\s+(?:readonly\s+)?(?P<type>\\?[A-Za-z_][A-Za-z0-9_\\]*)\s+\$(?P<property>[A-Za-z_][A-Za-z0-9_]*)\b/';
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
            return $types;
        }
        foreach ($matches as $match) {
            $types[(string) $match['property']] = $this->resolveName((string) $match['type'], $uses, $namespace);
        }
        return $types;
    }

    /**
     * Extrai atribuições locais diretas `$x = new Type(...)` dentro de um método.
     *
     * @param string $body Corpo do método.
     * @param string $namespace Namespace atual.
     * @param array<string,string> $uses Imports disponíveis.
     * @return array<string,string> Variável sem `$` para FQCN.
     */
    private function localNewTypes(string $body, string $namespace, array $uses): array
    {
        /** @var array<string,string> $types */
        $types = [];
        $pattern = '/\$(?P<var>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*new\s+(?P<type>\\?[A-Za-z_][A-Za-z0-9_\\]*)\s*\(/';
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) === 0) {
            return $types;
        }
        foreach ($matches as $match) {
            $types[(string) $match['var']] = $this->resolveName((string) $match['type'], $uses, $namespace);
        }
        return $types;
    }

    /**
     * Lista métodos com assinatura, corpo e offset absoluto do corpo.
     *
     * @param string $source Source completo do arquivo.
     * @return list<array{params:string,body:string,body_offset:int}> Métodos balanceados.
     */
    private function methodBlocks(string $source): array
    {
        $pattern = '/\bfunction\s+[A-Za-z_][A-Za-z0-9_]*\s*\((?P<params>[^)]*)\)[^{;]*\{/';
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
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
            $methods[] = [
                'params' => (string) $match['params'][0],
                'body' => $block['body'],
                'body_offset' => $block['body_offset'],
            ];
        }
        return $methods;
    }

    /**
     * Prova tipo exato ou descendência por classes locais.
     *
     * @param string $type FQCN do receiver.
     * @param list<string> $bases Bases Yii2 aceitas.
     * @param array<string,array{parent:string|null}> $classes Herança local.
     * @param array<string,bool> $visited Proteção contra ciclos.
     */
    private function isType(string $type, array $bases, array $classes, array $visited): bool
    {
        if (in_array($type, $bases, true)) {
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
        return $this->isType($parent, $bases, $classes, $visited);
    }

    /**
     * Indexa herança local para aceitar subclasses do Cache/Dependency.
     *
     * @param list<string> $files Arquivos PHP elegíveis.
     * @return array<string,array{parent:string|null}> Parent por FQCN local.
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
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($matches as $match) {
                $short = (string) $match[1];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $tail = (string) ($match['tail'] ?? '');
                $parentRaw = preg_match('/\bextends\s+([^\s{]+)/', $tail, $parentMatch) === 1
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
     * Lista arquivos PHP apenas dentro dos paths autorizados pelo contexto.
     *
     * @param ProjectContext $context Contexto que fornece root e paths.
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
     * Retorna conteúdo e offset absoluto de bloco delimitado por chaves balanceadas.
     *
     * @param string $source Source completo.
     * @param int $openingOffset Offset da chave de abertura.
     * @return array{body:string,body_offset:int}|null Bloco interno ou null.
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
                return [
                    'body' => substr($source, $openingOffset + 1, $cursor - $openingOffset - 1),
                    'body_offset' => $openingOffset + 1,
                ];
            }
        }
        return null;
    }

    /** Extrai namespace declarado no arquivo. */
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
        if (preg_match_all('/\buse\s+([^;]+);/', $prefix, $matches) === 0) {
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
     * Resolve nome relativo por imports/namespace.
     *
     * @param string $name Nome observado no source.
     * @param array<string,string> $uses Imports normalizados.
     * @param string $namespace Namespace atual.
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

    /** Retorna último segmento de um FQCN. */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /** Converte path absoluto em path relativo estável. */
    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }
}
