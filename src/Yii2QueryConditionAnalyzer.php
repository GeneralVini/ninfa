<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Valida aridade de operadores em condições array literais de Query/ActiveQuery Yii2.
 *
 * A regra nativa cobre inicialmente chains `ActiveRecord::find()->where()/andWhere()/orWhere()`
 * cuja classe local pode ser provada como ActiveRecord Yii2. Somente arrays literais com
 * operador string estático são interpretados; hash conditions, spread, variáveis e calls
 * em receivers sem tipo demonstrável permanecem fora para preservar baixo falso positivo.
 */
final class Yii2QueryConditionAnalyzer
{
    /** @var list<string> Bases ActiveRecord reconhecidas para DB, Redis e MongoDB. */
    private const ACTIVE_RECORD_BASES = [
        'yii\\db\\ActiveRecord',
        'yii\\db\\BaseActiveRecord',
        'yii\\redis\\ActiveRecord',
        'yii\\mongodb\\ActiveRecord',
    ];

    /** @var list<string> Operadores cujos operandos podem conter conditions recursivas. */
    private const CONJUNCTION_OPERATORS = ['AND', 'OR', 'NOT'];

    /** @var array<string,array{exact:int}|array{at_least:int}> Aridade aceita por operador Yii2. */
    private const OPERAND_REQUIREMENTS = [
        'AND' => ['at_least' => 1],
        'OR' => ['at_least' => 1],
        'NOT' => ['exact' => 1],
        'BETWEEN' => ['at_least' => 3],
        'NOT BETWEEN' => ['at_least' => 3],
        'IN' => ['at_least' => 2],
        'NOT IN' => ['at_least' => 2],
        'LIKE' => ['at_least' => 2],
        'NOT LIKE' => ['at_least' => 2],
        'OR LIKE' => ['at_least' => 2],
        'OR NOT LIKE' => ['at_least' => 2],
        'EXISTS' => ['at_least' => 1],
        'NOT EXISTS' => ['at_least' => 1],
        '=' => ['exact' => 2],
        '!=' => ['exact' => 2],
        '<>' => ['exact' => 2],
        '>' => ['exact' => 2],
        '>=' => ['exact' => 2],
        '<' => ['exact' => 2],
        '<=' => ['exact' => 2],
    ];

    /**
     * Localiza conditions literais com quantidade inválida de operandos.
     *
     * Conditions aninhadas em `AND`, `OR` e `NOT` também são verificadas quando o
     * operand é outro array literal. A linha reportada é a linha da condition externa;
     * o metadata preserva o operador inválido e a aridade observada.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita root e paths analisáveis.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   model:string,
     *   method:'where'|'andWhere'|'orWhere',
     *   operator:string,
     *   actual_operands:int,
     *   expectation:'exact'|'at_least',
     *   expected_operands:int
     * }> Violações estáticas em ordem de arquivo/ocorrência.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var list<array{file:string,line:int,model:string,method:'where'|'andWhere'|'orWhere',operator:string,actual_operands:int,expectation:'exact'|'at_least',expected_operands:int}> $references */
        $references = [];
        $pattern = '/(?P<class>[^\s;(){}]+)::find\s*\(\s*\)\s*->\s*(?P<method>where|andWhere|orWhere)\s*\(/';

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $model = $this->resolveName((string) $match['class'][0], $uses, $namespace);
                if (!$this->isActiveRecord($model, $classes, [])) {
                    continue;
                }

                $openingParen = (int) $match[0][1] + strlen((string) $match[0][0]) - 1;
                $arrayStart = $this->nextNonWhitespace($source, $openingParen + 1);
                if ($arrayStart === null || ($source[$arrayStart] ?? '') !== '[') {
                    continue;
                }
                $array = $this->balancedArray($source, $arrayStart);
                if ($array === null) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $arrayStart), "\n") + 1;
                $method = (string) $match['method'][0];
                foreach ($this->conditionErrors($array) as $error) {
                    $references[] = [
                        'file' => $this->relativePath($context->root(), $file),
                        'line' => $line,
                        'model' => $model,
                        'method' => $method,
                        'operator' => $error['operator'],
                        'actual_operands' => $error['actual_operands'],
                        'expectation' => $error['expectation'],
                        'expected_operands' => $error['expected_operands'],
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * Valida um array literal e, para conjunctions, percorre conditions filhas literais.
     *
     * @param string $array Expressão incluindo os colchetes externos.
     * @return list<array{operator:string,actual_operands:int,expectation:'exact'|'at_least',expected_operands:int}> Erros encontrados.
     */
    private function conditionErrors(string $array): array
    {
        $items = $this->topLevelItems($array);
        if ($items === null || $items === []) {
            return [];
        }
        // Operator format não possui key explícita nem unpack; esses formatos são deixados para o runtime/PHPStan.
        foreach ($items as $item) {
            if (str_starts_with(ltrim($item), '...') || $this->containsTopLevelArrow($item)) {
                return [];
            }
        }

        $operator = $this->literalString($items[0]);
        if ($operator === null) {
            return [];
        }
        $operator = strtoupper($operator);
        $requirement = self::OPERAND_REQUIREMENTS[$operator] ?? null;
        if ($requirement === null) {
            return [];
        }

        $operandItems = array_slice($items, 1);
        $actual = count($operandItems);
        /** @var list<array{operator:string,actual_operands:int,expectation:'exact'|'at_least',expected_operands:int}> $errors */
        $errors = [];
        if (isset($requirement['exact']) && $actual !== $requirement['exact']) {
            $errors[] = [
                'operator' => $operator,
                'actual_operands' => $actual,
                'expectation' => 'exact',
                'expected_operands' => $requirement['exact'],
            ];
        } elseif (isset($requirement['at_least']) && $actual < $requirement['at_least']) {
            $errors[] = [
                'operator' => $operator,
                'actual_operands' => $actual,
                'expectation' => 'at_least',
                'expected_operands' => $requirement['at_least'],
            ];
        }

        if (!in_array($operator, self::CONJUNCTION_OPERATORS, true)) {
            return $errors;
        }

        // Apenas operandos que são integralmente arrays literais são recursivos; demais expressões ficam unknown.
        foreach ($operandItems as $operand) {
            $trimmed = trim($operand);
            if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
                foreach ($this->conditionErrors($trimmed) as $nested) {
                    $errors[] = $nested;
                }
            }
        }
        return $errors;
    }

    /**
     * Separa elementos do array no primeiro nível respeitando strings e estruturas aninhadas.
     *
     * @param string $array Expressão completa `[ ... ]`.
     * @return list<string>|null Itens ou null quando a expressão não é um array balanceado simples.
     */
    private function topLevelItems(string $array): ?array
    {
        $trimmed = trim($array);
        if (!str_starts_with($trimmed, '[') || !str_ends_with($trimmed, ']')) {
            return null;
        }
        $inner = substr($trimmed, 1, -1);
        /** @var list<string> $items Elementos top-level acumulados. */
        $items = [];
        $start = 0;
        $square = 0;
        $round = 0;
        $curly = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '[') {
                $square++;
            } elseif ($char === ']') {
                $square--;
            } elseif ($char === '(') {
                $round++;
            } elseif ($char === ')') {
                $round--;
            } elseif ($char === '{') {
                $curly++;
            } elseif ($char === '}') {
                $curly--;
            } elseif ($char === ',' && $square === 0 && $round === 0 && $curly === 0) {
                $items[] = trim(substr($inner, $start, $i - $start));
                $start = $i + 1;
            }
        }
        if ($quote !== null || $square !== 0 || $round !== 0 || $curly !== 0) {
            return null;
        }
        $tail = trim(substr($inner, $start));
        if ($tail !== '') {
            $items[] = $tail;
        }
        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Detecta `=>` apenas no primeiro nível de um item para reconhecer hash condition.
     *
     * @param string $item Elemento top-level individual.
     * @return bool True quando o elemento possui key explícita fora de estruturas internas.
     */
    private function containsTopLevelArrow(string $item): bool
    {
        $quote = null;
        $escaped = false;
        $depth = 0;
        $length = strlen($item);
        for ($i = 0; $i < $length - 1; $i++) {
            $char = $item[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if (in_array($char, ['[', '(', '{'], true)) {
                $depth++;
                continue;
            }
            if (in_array($char, [']', ')', '}'], true)) {
                $depth--;
                continue;
            }
            if ($depth === 0 && $char === '=' && $item[$i + 1] === '>') {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve operador somente quando o primeiro item é string literal sem interpolação.
     *
     * @param string $item Primeiro elemento do condition array.
     * @return string|null Valor literal normalizado ou null.
     */
    private function literalString(string $item): ?string
    {
        $trimmed = trim($item);
        if (strlen($trimmed) < 2) {
            return null;
        }
        $quote = $trimmed[0];
        if (($quote !== "'" && $quote !== '"') || $trimmed[strlen($trimmed) - 1] !== $quote) {
            return null;
        }
        $value = substr($trimmed, 1, -1);
        if ($quote === '"' && str_contains($value, '$')) {
            return null;
        }
        return stripcslashes($value);
    }

    /**
     * Extrai um array `[...]` balanceado a partir de um offset conhecido.
     *
     * @param string $source Código-fonte completo.
     * @param int $openingOffset Offset do primeiro `[`.
     * @return string|null Expressão completa ou null quando não fecha com segurança.
     */
    private function balancedArray(string $source, int $openingOffset): ?string
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($source);
        for ($cursor = $openingOffset; $cursor < $length; $cursor++) {
            $char = $source[$cursor];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }
            if ($char === '[') {
                $depth++;
                continue;
            }
            if ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $openingOffset, $cursor - $openingOffset + 1);
                }
            }
        }
        return null;
    }

    /**
     * Localiza o próximo caractere não whitespace.
     *
     * @param string $source Código-fonte completo.
     * @param int $offset Offset inicial inclusivo.
     * @return int|null Offset encontrado ou null no EOF.
     */
    private function nextNonWhitespace(string $source, int $offset): ?int
    {
        $length = strlen($source);
        for ($cursor = $offset; $cursor < $length; $cursor++) {
            if (!ctype_space($source[$cursor])) {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * Lista arquivos PHP apenas nos paths autorizados pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto que fornece root e paths do consumidor.
     * @return list<string> Arquivos PHP absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos elegíveis à validação de conditions. */
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
     * Indexa classes locais e seus parents usando tail de declaração sem executar código.
     *
     * @param list<string> $files Arquivos PHP candidatos.
     * @return array<string,array{parent:string|null}> Parent resolvido por FQCN local.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes Índice de herança local. */
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
     * Prova descendência ActiveRecord usando apenas bases conhecidas e parents locais.
     *
     * @param string $class FQCN candidato.
     * @param array<string,array{parent:string|null}> $classes Índice de herança local.
     * @param array<string,bool> $visited Proteção contra ciclos inválidos.
     * @return bool True somente para cadeia conclusiva.
     */
    private function isActiveRecord(string $class, array $classes, array $visited): bool
    {
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return false;
        }
        $parent = $classes[$class]['parent'];
        if ($parent === null) {
            return false;
        }
        if (in_array($parent, self::ACTIVE_RECORD_BASES, true)) {
            return true;
        }
        $visited[$class] = true;
        return $this->isActiveRecord($parent, $classes, $visited);
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
     * Extrai imports de classe anteriores à primeira declaração de classe.
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
     * Resolve nome relativo/importado para FQCN sem barra inicial.
     *
     * @param string $name Nome observado no source.
     * @param array<string,string> $uses Imports normalizados.
     * @param string $namespace Namespace atual.
     * @return string FQCN resolvido.
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
     * Extrai último segmento de um FQCN.
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
     * Converte path absoluto para relativo estável do consumidor.
     *
     * @param string $root Raiz absoluta do consumidor.
     * @param string $file Arquivo absoluto observado.
     * @return string Path relativo com `/`.
     */
    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }
}
