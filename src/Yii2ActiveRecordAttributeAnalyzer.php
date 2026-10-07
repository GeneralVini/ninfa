<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2ModelRulesAnalyzer.php';

/**
 * Valida chaves literais de atributos em chamadas estáticas de ActiveRecord Yii2.
 *
 * A regra só nega atributos quando Yii2ModelRulesAnalyzer fornece inventário completo
 * para um ActiveRecord, normalmente por override literal de attributes(). ActiveRecord
 * dependente de schema runtime, keys dinâmicas e arrays não-hash permanecem unknown.
 */
final class Yii2ActiveRecordAttributeAnalyzer
{
    /**
     * Métodos estáticos e argumentos que carregam attributes/conditions.
     *
     * @var array<string,list<array{index:int,role:'condition'|'attributes'|'counters'}>>
     */
    private const ARGUMENTS = [
        'findone' => [['index' => 0, 'role' => 'condition']],
        'findall' => [['index' => 0, 'role' => 'condition']],
        'deleteall' => [['index' => 0, 'role' => 'condition']],
        'updateall' => [
            ['index' => 0, 'role' => 'attributes'],
            ['index' => 1, 'role' => 'condition'],
        ],
        'updateallcounters' => [
            ['index' => 0, 'role' => 'counters'],
            ['index' => 1, 'role' => 'condition'],
        ],
    ];

    /**
     * Retorna atributos literais comprovadamente ausentes de chamadas ActiveRecord.
     *
     * @param ProjectContext $context Contexto Yii2 do consumidor.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   model:string,
     *   method:string,
     *   role:'condition'|'attributes'|'counters',
     *   attribute:string,
     *   attribute_inventory_complete:true
     * }> Evidências conclusivas.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $inventories = (new Yii2ModelRulesAnalyzer())->inventories($context);
        /** @var array<string,array{attributes:list<string>}> $activeRecords Inventários conclusivos por FQCN. */
        $activeRecords = [];
        // Só ActiveRecords com inventário conclusivo permitem provar ausência de atributo.
        foreach ($inventories as $class => $inventory) {
            if ($inventory['active_record'] && $inventory['complete']) {
                $activeRecords[$class] = ['attributes' => $inventory['attributes']];
            }
        }
        if ($activeRecords === []) {
            return [];
        }

        /** @var list<array{file:string,line:int,model:string,method:string,role:'condition'|'attributes'|'counters',attribute:string,attribute_inventory_complete:true}> $references */
        $references = [];
        $pattern = '/(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::(?P<method>findOne|findAll|deleteAll|updateAll|updateAllCounters)\s*\(/i';

        foreach ($this->phpFiles($context) as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            if (preg_match_all($pattern, $source, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($calls as $call) {
                $rawClass = (string) $call['class'][0];
                if (in_array(strtolower($rawClass), ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $class = $this->resolveName($rawClass, $uses, $namespace);
                $inventory = $activeRecords[$class] ?? null;
                if ($inventory === null) {
                    continue;
                }

                $method = (string) $call['method'][0];
                $rules = self::ARGUMENTS[strtolower($method)] ?? [];
                $openingParen = (int) $call[0][1] + strlen((string) $call[0][0]) - 1;
                $closingParen = $this->matchingDelimiter($source, $openingParen, '(', ')');
                if ($closingParen === null) {
                    continue;
                }
                $arguments = $this->topLevelRanges($source, $openingParen + 1, $closingParen - 1);

                // Cada argumento é validado isoladamente; ausência/dinamismo em um não contamina os demais.
                foreach ($rules as $rule) {
                    $range = $arguments[$rule['index']] ?? null;
                    if ($range === null) {
                        continue;
                    }
                    [$start, $end] = $range;
                    $first = $this->nextNonWhitespaceOffset($source, $start, $end);
                    if ($first === null || ($source[$first] ?? '') !== '[') {
                        continue;
                    }

                    foreach ($this->literalHashKeys($source, $first, $end) as $attribute) {
                        if (in_array($attribute['name'], $inventory['attributes'], true)) {
                            continue;
                        }
                        $references[] = [
                            'file' => $this->relativePath($context->root(), $file),
                            'line' => substr_count(substr($source, 0, $attribute['offset']), "\n") + 1,
                            'model' => $class,
                            'method' => $method,
                            'role' => $rule['role'],
                            'attribute' => $attribute['name'],
                            'attribute_inventory_complete' => true,
                        ];
                    }
                }
            }
        }

        usort(
            $references,
            static fn (array $left, array $right): int => [
                $left['file'],
                $left['line'],
                $left['method'],
                $left['role'],
                $left['attribute'],
            ] <=> [
                $right['file'],
                $right['line'],
                $right['method'],
                $right['role'],
                $right['attribute'],
            ],
        );

        return $references;
    }

    /**
     * Extrai apenas chaves string de um hash array literal.
     *
     * Entradas sem key, spread, keys dinâmicas e operator-format arrays não geram
     * afirmação de atributo. Isso permite validar o subconjunto objetivo sem tentar
     * interpretar conditions arbitrárias do Query Builder.
     *
     * @param string $source Código-fonte completo.
     * @param int $openingBracket Offset do colchete inicial.
     * @param int $rangeEnd Limite do argumento.
     * @return list<array{name:string,offset:int}> Chaves literais observadas.
     */
    private function literalHashKeys(string $source, int $openingBracket, int $rangeEnd): array
    {
        $closingBracket = $this->matchingDelimiter($source, $openingBracket, '[', ']');
        if ($closingBracket === null || $closingBracket > $rangeEnd) {
            return [];
        }

        /** @var list<array{name:string,offset:int}> $keys */
        $keys = [];
        // Apenas entries hash com key string literal entram; operator arrays e keys dinâmicas ficam unknown.
        foreach ($this->topLevelRanges($source, $openingBracket + 1, $closingBracket - 1) as [$start, $end]) {
            $first = $this->nextNonWhitespaceOffset($source, $start, $end);
            if ($first === null || substr($source, $first, 3) === '...') {
                continue;
            }
            $arrow = $this->topLevelArrowOffset($source, $start, $end);
            if ($arrow === null) {
                continue;
            }
            $literal = $this->literalRange($source, $start, $arrow - 1);
            if ($literal !== null && $literal['name'] !== '') {
                $keys[] = $literal;
            }
        }

        return $keys;
    }

    /**
     * Divide região por vírgulas de primeiro nível.
     *
     * @param string $source Código-fonte completo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return list<array{int,int}> Ranges não vazios.
     */
    private function topLevelRanges(string $source, int $start, int $end): array
    {
        if ($start > $end) {
            return [];
        }

        /** @var list<array{int,int}> $ranges */
        $ranges = [];
        $segment = $start;
        $round = $square = $curly = 0;
        $quote = null;
        $escaped = false;

        for ($cursor = $start; $cursor <= $end; $cursor++) {
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
            $round += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            $square += $char === '[' ? 1 : ($char === ']' ? -1 : 0);
            $curly += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
            if ($char === ',' && $round === 0 && $square === 0 && $curly === 0) {
                if ($this->nextNonWhitespaceOffset($source, $segment, $cursor - 1) !== null) {
                    $ranges[] = [$segment, $cursor - 1];
                }
                $segment = $cursor + 1;
            }
        }

        if ($this->nextNonWhitespaceOffset($source, $segment, $end) !== null) {
            $ranges[] = [$segment, $end];
        }
        return $ranges;
    }

    /**
     * Localiza seta de array no primeiro nível de uma entrada.
     *
     * @param string $source Código-fonte completo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return int|null Offset do sinal de igual.
     */
    private function topLevelArrowOffset(string $source, int $start, int $end): ?int
    {
        $round = $square = $curly = 0;
        $quote = null;
        $escaped = false;
        for ($cursor = $start; $cursor < $end; $cursor++) {
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
            $round += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
            $square += $char === '[' ? 1 : ($char === ']' ? -1 : 0);
            $curly += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
            if ($round === 0 && $square === 0 && $curly === 0 && $char === '=' && ($source[$cursor + 1] ?? '') === '>') {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * Resolve key string literal sem interpolação/expressão.
     *
     * @param string $source Código-fonte completo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return array{name:string,offset:int}|null Key normalizada.
     */
    private function literalRange(string $source, int $start, int $end): ?array
    {
        $first = $this->nextNonWhitespaceOffset($source, $start, $end);
        if ($first === null) {
            return null;
        }
        $last = $end;
        while ($last >= $first && ctype_space($source[$last])) {
            $last--;
        }

        $quote = $source[$first] ?? '';
        if (($quote !== "'" && $quote !== '"') || ($source[$last] ?? '') !== $quote || $last <= $first) {
            return null;
        }
        $value = substr($source, $first + 1, $last - $first - 1);
        if ($quote === '"' && (str_contains($value, '$') || str_contains($value, '\\'))) {
            return null;
        }
        if ($quote === "'") {
            $value = str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }

        return ['name' => $value, 'offset' => $first];
    }

    /**
     * Busca fechamento balanceado respeitando strings.
     *
     * @param string $source Código-fonte completo.
     * @param int $opening Offset da abertura.
     * @param string $open Delimitador de abertura.
     * @param string $close Delimitador de fechamento.
     * @return int|null Offset de fechamento.
     */
    private function matchingDelimiter(string $source, int $opening, string $open, string $close): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;

        for ($cursor = $opening, $length = strlen($source); $cursor < $length; $cursor++) {
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
            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $cursor;
                }
            }
        }

        return null;
    }

    /**
     * Localiza caractere não-whitespace no range.
     *
     * @param string $source Código-fonte completo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return int|null Offset encontrado.
     */
    private function nextNonWhitespaceOffset(string $source, int $start, int $end): ?int
    {
        for ($cursor = $start; $cursor <= $end; $cursor++) {
            if (!ctype_space($source[$cursor])) {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * Lista arquivos PHP nos paths autorizados pelo profile.
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
     * Extrai namespace do arquivo.
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
     * Extrai imports de classes simples anteriores à primeira declaração de classe.
     *
     * @param string $source Código-fonte completo.
     * @return array<string,string> Alias/nome curto => FQCN.
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
            if ($import === '' || str_contains($import, '{') || str_starts_with(strtolower($import), 'function ') || str_starts_with(strtolower($import), 'const ')) {
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
     * Resolve nome observado conforme imports/namespace.
     *
     * @param string $name Nome textual.
     * @param array<string,string> $uses Imports simples.
     * @param string $namespace Namespace do arquivo.
     * @return string FQCN sem barra inicial.
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $name = trim($name);
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $parts = explode('\\', $name, 2);
        $head = $parts[0];
        if (isset($uses[$head])) {
            return $uses[$head] . (isset($parts[1]) ? '\\' . $parts[1] : '');
        }
        if (str_contains($name, '\\')) {
            return $namespace === '' ? $name : $namespace . '\\' . $name;
        }
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * Retorna nome curto de classe.
     *
     * @param string $class FQCN.
     * @return string Último segmento.
     */
    private function shortName(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));
        return (string) end($parts);
    }

    /**
     * Relativiza path sob a raiz do consumidor.
     *
     * @param string $root Raiz física.
     * @param string $file Arquivo absoluto.
     * @return string Path relativo quando aplicável.
     */
    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalized = str_replace('\\', '/', $file);
        return str_starts_with($normalized, $prefix)
            ? substr($normalized, strlen($prefix))
            : $normalized;
    }
}
