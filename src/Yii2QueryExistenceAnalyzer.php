<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta verificações de existência Yii2 que carregam linha inteira ou contam registros.
 *
 * A análise nativa é deliberadamente conservadora: nesta tranche somente chains estáticas
 * iniciadas em `ActiveRecord::find()` de classes locais comprovadamente herdadas de bases
 * ActiveRecord Yii2 são consideradas. Comparações suportadas precisam ser estritas ou
 * relacionais contra `null`, `0` ou `1`; variáveis tipadas como QueryInterface, factories,
 * closures e queries cujo tipo dependa de runtime ficam fora do scan em vez de receber
 * inferência heurística.
 */
final class Yii2QueryExistenceAnalyzer
{
    /** @var list<string> Bases ActiveRecord reconhecidas para DB, Redis e MongoDB. */
    private const ACTIVE_RECORD_BASES = [
        'yii\\db\\ActiveRecord',
        'yii\\db\\BaseActiveRecord',
        'yii\\redis\\ActiveRecord',
        'yii\\mongodb\\ActiveRecord',
    ];

    /**
     * Localiza comparações em que `one()`/`count()` são usados apenas como teste booleano.
     *
     * O resultado já normaliza comparações invertidas (`0 < Query::count()`) para a
     * perspectiva da query e informa se `exists()` precisa ser negado. Chamadas sem
     * comparação, thresholds diferentes dos equivalentes booleanos e classes não
     * comprovadas como ActiveRecord local não entram no resultado.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita root e paths elegíveis.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   model:string,
     *   source_method:'one'|'count',
     *   operator:'==='|'!=='|'>'|'<'|'>='|'<=',
     *   operand:'null'|'0'|'1',
     *   query_on_left:bool,
     *   negated:bool,
     *   replacement:'exists()'|'!exists()'
     * }> Comparações semanticamente equivalentes a `exists()`/`!exists()`.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var array<string,bool> $activeCache Cache de classificação ActiveRecord por FQCN. */
        $activeCache = [];
        /** @var list<array{file:string,line:int,model:string,source_method:'one'|'count',operator:'==='|'!=='|'>'|'<'|'>='|'<=',operand:'null'|'0'|'1',query_on_left:bool,negated:bool,replacement:'exists()'|'!exists()'}> $references */
        $references = [];

        $pattern = '/(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::find\s*\(\s*\)(?P<chain>(?:\s*->\s*[A-Za-z_][A-Za-z0-9_]*\s*\([^;{}]*?\))*)\s*->\s*(?P<method>one|count)\s*\(\s*\)/s';

        // Só o subconjunto com origem ActiveRecord e comparação literal inequívoca pode gerar finding de performance.
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $rawClass = (string) $match['class'][0];
                if (in_array(strtolower($rawClass), ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $class = $this->resolveName($rawClass, $uses, $namespace);
                if (!$this->isActiveRecord($class, $classes, $activeCache, [])) {
                    continue;
                }

                $offset = (int) $match[0][1];
                $endOffset = $offset + strlen((string) $match[0][0]);
                $method = strtolower((string) $match['method'][0]);
                $comparison = $this->comparisonAt($source, $offset, $endOffset, $method);
                if ($comparison === null) {
                    continue;
                }

                $references[] = [
                    'file' => $this->relativePath($context->root(), $file),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                    'model' => $class,
                    'source_method' => $method,
                    'operator' => $comparison['operator'],
                    'operand' => $comparison['operand'],
                    'query_on_left' => $comparison['query_on_left'],
                    'negated' => $comparison['negated'],
                    'replacement' => $comparison['negated'] ? '!exists()' : 'exists()',
                ];
            }
        }

        return $references;
    }

    /**
     * Lista arquivos PHP somente nos paths previamente autorizados pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto que fornece root e paths do consumidor.
     * @return list<string> Arquivos PHP absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos elegíveis à análise de queries. */
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
     * Indexa classes locais e seus parents para provar origem ActiveRecord sem reflection runtime.
     *
     * @param list<string> $files Arquivos PHP candidatos do consumidor.
     * @return array<string,array{parent:string|null}> Parent resolvido por FQCN local.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes Metadados mínimos por classe local. */
        $classes = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $pattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*))?/';
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $short = (string) $match[1];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $parentRaw = isset($match[2]) ? trim((string) $match[2]) : '';
                $classes[$class] = [
                    'parent' => $parentRaw === '' ? null : $this->resolveName($parentRaw, $uses, $namespace),
                ];
            }
        }

        return $classes;
    }

    /**
     * Decide se uma classe local termina em uma base ActiveRecord Yii2 conhecida.
     *
     * Parents externos não reconhecidos retornam false; esta regra de performance não
     * precisa transformar incerteza de tipo em finding. O cache evita repetir cadeias
     * de herança em arquivos com várias comparações.
     *
     * @param string $class FQCN candidato.
     * @param array<string,array{parent:string|null}> $classes Índice local de herança.
     * @param array<string,bool> $cache Cache mutável de decisões por classe.
     * @param array<string,bool> $visited Proteção contra ciclos inválidos.
     * @return bool True somente quando a herança ActiveRecord é comprovável.
     */
    private function isActiveRecord(string $class, array $classes, array &$cache, array $visited): bool
    {
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return false;
        }

        $parent = $classes[$class]['parent'];
        if ($parent === null) {
            $cache[$class] = false;
            return false;
        }
        if (in_array($parent, self::ACTIVE_RECORD_BASES, true)) {
            $cache[$class] = true;
            return true;
        }

        // Apenas herança local continua a prova; qualquer parent externo desconhecido encerra sem finding.
        $visited[$class] = true;
        $cache[$class] = $this->isActiveRecord($parent, $classes, $cache, $visited);
        return $cache[$class];
    }

    /**
     * Resolve a comparação imediatamente adjacente à chamada terminal `one()`/`count()`.
     *
     * Comparações no lado direito são invertidas para que o resultado sempre descreva
     * `query OP constante`. Somente equivalências booleanas reconhecidas são aceitas.
     *
     * @param string $source Código-fonte completo do arquivo.
     * @param int $start Offset inicial da chain de query.
     * @param int $end Offset imediatamente após `one()`/`count()`.
     * @param 'one'|'count' $method Método terminal observado.
     * @return array{operator:'==='|'!=='|'>'|'<'|'>='|'<=',operand:'null'|'0'|'1',query_on_left:bool,negated:bool}|null Comparação normalizada.
     */
    private function comparisonAt(string $source, int $start, int $end, string $method): ?array
    {
        $right = substr($source, $end, 48);
        if (preg_match('/^\s*(===|!==|>=|<=|>|<)\s*(null|0|1)\b/i', $right, $match) === 1) {
            $operator = (string) $match[1];
            $operand = strtolower((string) $match[2]);
            $negated = $this->negatedExistence($method, $operator, $operand);
            return $negated === null ? null : [
                'operator' => $operator,
                'operand' => $operand,
                'query_on_left' => true,
                'negated' => $negated,
            ];
        }

        $left = substr($source, max(0, $start - 48), min(48, $start));
        if (preg_match('/(null|0|1)\s*(===|!==|>=|<=|>|<)\s*$/i', $left, $match) !== 1) {
            return null;
        }

        $operand = strtolower((string) $match[1]);
        $operator = $this->invertOperator((string) $match[2]);
        $negated = $this->negatedExistence($method, $operator, $operand);
        return $negated === null ? null : [
            'operator' => $operator,
            'operand' => $operand,
            'query_on_left' => false,
            'negated' => $negated,
        ];
    }

    /**
     * Mapeia comparações reconhecidas para `exists()` ou `!exists()`.
     *
     * @param 'one'|'count' $method Método que originou o valor comparado.
     * @param '==='|'!=='|'>'|'<'|'>='|'<=' $operator Operador já normalizado com query à esquerda.
     * @param 'null'|'0'|'1' $operand Constante literal comparada.
     * @return bool|null True para `!exists()`, false para `exists()`, null para comparação não equivalente.
     */
    private function negatedExistence(string $method, string $operator, string $operand): ?bool
    {
        if ($method === 'one') {
            if ($operand !== 'null') {
                return null;
            }
            return match ($operator) {
                '===' => true,
                '!==' => false,
                default => null,
            };
        }

        if ($operand === '0') {
            return match ($operator) {
                '===' => true,
                '!==' => false,
                '>' => false,
                '<=' => true,
                default => null,
            };
        }

        if ($operand === '1') {
            return match ($operator) {
                '>=' => false,
                '<' => true,
                default => null,
            };
        }

        return null;
    }

    /**
     * Inverte operador relacional quando a query aparece no lado direito.
     *
     * @param '==='|'!=='|'>'|'<'|'>='|'<=' $operator Operador textual observado.
     * @return '==='|'!=='|'>'|'<'|'>='|'<=' Operador equivalente com operandos trocados.
     */
    private function invertOperator(string $operator): string
    {
        return match ($operator) {
            '>' => '<',
            '<' => '>',
            '>=' => '<=',
            '<=' => '>=',
            default => $operator,
        };
    }

    /**
     * Extrai namespace declarado no arquivo sem executar o consumidor.
     *
     * @param string $source Código-fonte PHP completo.
     * @return string Namespace sem barra inicial ou string vazia.
     */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $match) === 1
            ? trim((string) $match[1], " \\t\\n\\r\\0\\x0B\\\\")
            : '';
    }

    /**
     * Extrai imports de classe declarados antes da primeira classe do arquivo.
     *
     * @param string $source Código-fonte PHP completo.
     * @return array<string,string> Alias para FQCN sem barra inicial.
     */
    private function importsOf(string $source): array
    {
        $classOffset = preg_match('/\b(?:abstract\s+|final\s+)?class\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classOffset);
        /** @var array<string,string> $imports Imports normalizados por alias. */
        $imports = [];

        if (preg_match_all('/\buse\s+([^;]+);/', $prefix, $matches) === 0) {
            return $imports;
        }

        foreach ($matches[1] as $rawImport) {
            $import = trim((string) $rawImport);
            if (str_contains($import, '{') || str_starts_with(strtolower($import), 'function ') || str_starts_with(strtolower($import), 'const ')) {
                continue;
            }

            $parts = preg_split('/\s+as\s+/i', $import, 2) ?: [];
            $fqcn = trim((string) ($parts[0] ?? ''), " \\t\\n\\r\\0\\x0B\\\\");
            if ($fqcn === '') {
                continue;
            }
            $alias = isset($parts[1]) ? trim((string) $parts[1]) : basename(str_replace('\\\\', '/', $fqcn));
            $imports[$alias] = $fqcn;
        }

        return $imports;
    }

    /**
     * Resolve nome de classe usando namespace e imports estáticos do arquivo.
     *
     * @param string $name Nome literal observado no source.
     * @param array<string,string> $uses Imports alias => FQCN.
     * @param string $namespace Namespace atual sem barra inicial.
     * @return string FQCN normalizado sem barra inicial.
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $name = trim($name);
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $head = $segments[0] ?? '';
        if ($head !== '' && isset($uses[$head])) {
            array_shift($segments);
            return $uses[$head] . ($segments === [] ? '' : '\\' . implode('\\', $segments));
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * Converte path absoluto em relativo à raiz do consumidor.
     *
     * @param string $root Raiz física do projeto.
     * @param string $file Arquivo absoluto observado.
     * @return string Path relativo quando pertencente à raiz.
     */
    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
