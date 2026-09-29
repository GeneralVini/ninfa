<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta depreciações Yii2 com substituição mecânica comprovável sem executar o consumidor.
 *
 * A camada cobre apenas padrões cuja classe ou contexto pode ser resolvido estaticamente:
 * `Yii::trace()`, constantes legadas do console controller e retornos literais 0/1 em
 * actions de controllers console. Cada ocorrência carrega offsets e replacement exato para
 * que uma camada de remediação possa aplicar somente transformações classificadas como SAFE.
 */
final class Yii2DeprecationAnalyzer
{
    /** @var list<string> Bases de controller console reconhecidas sem reflection runtime. */
    private const CONSOLE_CONTROLLER_BASES = ['yii\\console\\Controller'];

    /**
     * Localiza depreciações mecânicas no escopo Yii2 autorizado pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita os arquivos analisáveis.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   rule:'NINFA-YII2-DEP-001'|'NINFA-YII2-DEP-002'|'NINFA-YII2-DEP-003',
     *   kind:'trace'|'exit-constant'|'action-return',
     *   problem:string,
     *   replacement:string,
     *   offset:int,
     *   length:int
     * }> Ocorrências SAFE em ordem de arquivo e offset.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var list<array{file:string,line:int,rule:'NINFA-YII2-DEP-001'|'NINFA-YII2-DEP-002'|'NINFA-YII2-DEP-003',kind:'trace'|'exit-constant'|'action-return',problem:string,replacement:string,offset:int,length:int}> $references */
        $references = [];

        // Cada detector retorna apenas substituições locais de equivalência conhecida; nenhuma inferência heurística entra aqui.
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $relative = $this->relativePath($context->root(), $file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);

            foreach ($this->traceReferences($source, $relative) as $reference) {
                $references[] = $reference;
            }
            foreach ($this->exitConstantReferences($source, $relative, $namespace, $uses) as $reference) {
                $references[] = $reference;
            }
            foreach ($this->actionReturnReferences($source, $relative, $namespace, $classes) as $reference) {
                $references[] = $reference;
            }
        }

        usort($references, static fn (array $left, array $right): int => [$left['file'], $left['offset']] <=> [$right['file'], $right['offset']]);
        return $references;
    }

    /**
     * Lista arquivos PHP somente nos paths autorizados para o profile detectado.
     *
     * @param ProjectContext $context Contexto que fornece root e paths do consumidor.
     * @return list<string> Arquivos PHP absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos elegíveis à análise de depreciação. */
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

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );
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
     * Indexa classes locais e parents para provar controllers console através de herança local.
     *
     * A declaração de classe é delimitada até a primeira chave e o parent é extraído do
     * tail com uma expressão que não tenta modelar barras invertidas em character classes.
     * Isso evita ambiguidade de escape PCRE e ainda deixa a resolução real para importsOf().
     *
     * @param list<string> $files Arquivos PHP candidatos.
     * @return array<string,array{parent:string|null}> Parent resolvido por FQCN local.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes Metadados mínimos por classe. */
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
     * Detecta a API global deprecated `Yii::trace()` com equivalência direta para `Yii::debug()`.
     *
     * O match começa em `Yii` e deixa eventual barra global anterior intacta. Assim a
     * mesma replacement serve para `Yii::trace()` e `\\Yii::trace()` sem regex ambíguo.
     *
     * @param string $source Código-fonte completo do arquivo.
     * @param string $file Path relativo usado no finding.
     * @return list<array{file:string,line:int,rule:'NINFA-YII2-DEP-001',kind:'trace',problem:string,replacement:string,offset:int,length:int}> Ocorrências encontradas.
     */
    private function traceReferences(string $source, string $file): array
    {
        /** @var list<array{file:string,line:int,rule:'NINFA-YII2-DEP-001',kind:'trace',problem:string,replacement:string,offset:int,length:int}> $references */
        $references = [];
        if (preg_match_all('/\bYii::trace(?=\s*\()/', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $offset = (int) $match[0][1];
            $references[] = [
                'file' => $file,
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'rule' => 'NINFA-YII2-DEP-001',
                'kind' => 'trace',
                'problem' => 'Yii::trace() está deprecated; Yii2 recomenda Yii::debug().',
                'replacement' => 'Yii::debug',
                'offset' => $offset,
                'length' => strlen((string) $match[0][0]),
            ];
        }
        return $references;
    }

    /**
     * Detecta constantes legadas diretamente resolvidas para `yii\\console\\Controller`.
     *
     * O token textual anterior a `::` é capturado sem tentar interpretar FQCN no regex;
     * aliases e namespace são resolvidos depois por resolveName(), que é a fonte de verdade.
     *
     * @param string $source Código-fonte completo do arquivo.
     * @param string $file Path relativo usado no finding.
     * @param string $namespace Namespace do arquivo.
     * @param array<string,string> $uses Imports de classe normalizados por alias.
     * @return list<array{file:string,line:int,rule:'NINFA-YII2-DEP-002',kind:'exit-constant',problem:string,replacement:string,offset:int,length:int}> Ocorrências encontradas.
     */
    private function exitConstantReferences(string $source, string $file, string $namespace, array $uses): array
    {
        /** @var list<array{file:string,line:int,rule:'NINFA-YII2-DEP-002',kind:'exit-constant',problem:string,replacement:string,offset:int,length:int}> $references */
        $references = [];
        $pattern = '/(?P<class>[^\s;(){}]+)::(?P<constant>EXIT_CODE_NORMAL|EXIT_CODE_ERROR)\b/';
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $resolved = $this->resolveName((string) $match['class'][0], $uses, $namespace);
            if ($resolved !== 'yii\\console\\Controller') {
                continue;
            }
            $constant = (string) $match['constant'][0];
            $replacement = $constant === 'EXIT_CODE_NORMAL'
                ? '\\yii\\console\\ExitCode::OK'
                : '\\yii\\console\\ExitCode::UNSPECIFIED_ERROR';
            $offset = (int) $match[0][1];
            $references[] = [
                'file' => $file,
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'rule' => 'NINFA-YII2-DEP-002',
                'kind' => 'exit-constant',
                'problem' => 'Constante legada de yii\\console\\Controller deve usar yii\\console\\ExitCode.',
                'replacement' => $replacement,
                'offset' => $offset,
                'length' => strlen((string) $match[0][0]),
            ];
        }
        return $references;
    }

    /**
     * Detecta `return 0;`/`return 1;` dentro de actions de controllers console comprovados.
     *
     * @param string $source Código-fonte completo do arquivo.
     * @param string $file Path relativo usado no finding.
     * @param string $namespace Namespace do arquivo.
     * @param array<string,array{parent:string|null}> $classes Índice local de herança.
     * @return list<array{file:string,line:int,rule:'NINFA-YII2-DEP-003',kind:'action-return',problem:string,replacement:string,offset:int,length:int}> Ocorrências encontradas.
     */
    private function actionReturnReferences(string $source, string $file, string $namespace, array $classes): array
    {
        /** @var list<array{file:string,line:int,rule:'NINFA-YII2-DEP-003',kind:'action-return',problem:string,replacement:string,offset:int,length:int}> $references */
        $references = [];
        $classPattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)[^\{]*\{/';
        if (preg_match_all($classPattern, $source, $classMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($classMatches as $classMatch) {
            $short = (string) $classMatch[1][0];
            $class = $namespace === '' ? $short : $namespace . '\\' . $short;
            if (!$this->isConsoleController($class, $classes, [])) {
                continue;
            }
            $opening = strpos($source, '{', (int) $classMatch[0][1]);
            $classBlock = $opening === false ? null : $this->balancedBlock($source, $opening);
            if ($classBlock === null) {
                continue;
            }

            $methodPattern = '/\bfunction\s+(action[A-Z][A-Za-z0-9_]*)\s*\([^)]*\)[^{;]*\{/';
            if (preg_match_all($methodPattern, $classBlock['body'], $methodMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($methodMatches as $methodMatch) {
                $methodOpeningLocal = strpos($classBlock['body'], '{', (int) $methodMatch[0][1]);
                if ($methodOpeningLocal === false) {
                    continue;
                }
                $methodOpening = $classBlock['body_offset'] + $methodOpeningLocal;
                $methodBlock = $this->balancedBlock($source, $methodOpening);
                if ($methodBlock === null) {
                    continue;
                }
                if (preg_match_all('/\breturn\s+(0|1)\s*;/', $methodBlock['body'], $returns, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($returns as $returnMatch) {
                    $literal = (string) $returnMatch[1][0];
                    $offset = $methodBlock['body_offset'] + (int) $returnMatch[0][1];
                    $references[] = [
                        'file' => $file,
                        'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                        'rule' => 'NINFA-YII2-DEP-003',
                        'kind' => 'action-return',
                        'problem' => 'Action de console retorna magic number de exit code.',
                        'replacement' => $literal === '0'
                            ? 'return \\yii\\console\\ExitCode::OK;'
                            : 'return \\yii\\console\\ExitCode::UNSPECIFIED_ERROR;',
                        'offset' => $offset,
                        'length' => strlen((string) $returnMatch[0][0]),
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * Prova que uma classe local termina em `yii\\console\\Controller`.
     *
     * @param string $class FQCN candidato.
     * @param array<string,array{parent:string|null}> $classes Índice de herança local.
     * @param array<string,bool> $visited Proteção contra ciclos inválidos.
     * @return bool True somente quando a cadeia é conclusiva.
     */
    private function isConsoleController(string $class, array $classes, array $visited): bool
    {
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return false;
        }
        $parent = $classes[$class]['parent'];
        if ($parent === null) {
            return false;
        }
        if (in_array($parent, self::CONSOLE_CONTROLLER_BASES, true)) {
            return true;
        }

        // Parents locais podem transportar o contrato de controller console; externos desconhecidos encerram a prova.
        $visited[$class] = true;
        return $this->isConsoleController($parent, $classes, $visited);
    }

    /**
     * Retorna conteúdo e offset absoluto de um bloco delimitado por chaves balanceadas.
     *
     * @param string $source Código-fonte completo.
     * @param int $openingOffset Offset da chave de abertura.
     * @return array{body:string,body_offset:int}|null Bloco interno ou null quando não fecha.
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
     * Extrai namespace do arquivo sem executar PHP.
     *
     * @param string $source Código-fonte completo.
     * @return string Namespace sem barra inicial.
     */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $match) === 1
            ? trim((string) $match[1], " \t\n\r\0\x0B\\")
            : '';
    }

    /**
     * Extrai imports de classe declarados antes da primeira classe.
     *
     * @param string $source Código-fonte completo.
     * @return array<string,string> Alias para FQCN sem barra inicial.
     */
    private function importsOf(string $source): array
    {
        $classOffset = preg_match('/\b(?:abstract\s+|final\s+)?class\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classOffset);
        /** @var array<string,string> $imports Imports normalizados. */
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
     * Resolve nome de classe relativo usando imports e namespace do arquivo.
     *
     * @param string $name Nome observado no source.
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
     * Extrai nome curto de um FQCN sem depender de offsets artificiais.
     *
     * @param string $fqcn Nome qualificado sem barra inicial.
     * @return string Último segmento do nome.
     */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * Converte path absoluto para path relativo estável do consumidor.
     *
     * @param string $root Raiz absoluta do consumidor.
     * @param string $file Arquivo absoluto observado.
     * @return string Path relativo com separador `/`.
     */
    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }
}
