<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta chains ActiveRecord equivalentes a `findOne()`/`findAll()` com condição hash literal.
 *
 * A transformação só é considerada SAFE quando a classe local é comprovadamente ActiveRecord
 * Yii2 e `where()` recebe um array associativo literal não vazio cujas chaves top-level são
 * strings literais. Listas, operator format, spreads, variáveis e expressions são ignorados,
 * porque `findOne()`/`findAll()` dão semântica de primary key a outros formatos de condição.
 */
final class Yii2FindShortcutAnalyzer
{
    /** @var list<string> Bases ActiveRecord reconhecidas para DB, Redis e MongoDB. */
    private const ACTIVE_RECORD_BASES = [
        'yii\\db\\ActiveRecord',
        'yii\\db\\BaseActiveRecord',
        'yii\\redis\\ActiveRecord',
        'yii\\mongodb\\ActiveRecord',
    ];

    /**
     * Localiza chains seguras e retorna replacement textual completo para remediação.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita root e paths elegíveis.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   model:string,
     *   terminal:'one'|'all',
     *   replacement_method:'findOne'|'findAll',
     *   replacement:string,
     *   offset:int,
     *   length:int
     * }> Ocorrências SAFE em ordem de arquivo/offset.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var list<array{file:string,line:int,model:string,terminal:'one'|'all',replacement_method:'findOne'|'findAll',replacement:string,offset:int,length:int}> $references */
        $references = [];
        $pattern = '/(?P<class>[^\\s;(){}]+)::find\\s*\\(\\s*\\)\\s*->\\s*where\\s*\\(/';

        // SAFE exige três provas independentes: tipo ActiveRecord, hash string-keyed e terminal one/all imediatamente após where().
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $rawClass = (string) $match['class'][0];
                $model = $this->resolveName($rawClass, $uses, $namespace);
                if (!$this->isActiveRecord($model, $classes, [])) {
                    continue;
                }

                $whereOpening = (int) $match[0][1] + strlen((string) $match[0][0]) - 1;
                $arrayStart = $this->nextNonWhitespace($source, $whereOpening + 1);
                if ($arrayStart === null || ($source[$arrayStart] ?? '') !== '[') {
                    continue;
                }
                $array = $this->balancedArray($source, $arrayStart);
                if ($array === null || !$this->isAssociativeStringKeyArray($array['text'])) {
                    continue;
                }

                // O terminal precisa seguir o where sem operações intermediárias, pois elas poderiam mudar a semântica da query.
                $suffix = substr($source, $array['end'] + 1, 64);
                if (preg_match('/^\\s*\\)\\s*->\\s*(one|all)\\s*\\(\\s*\\)/', $suffix, $terminalMatch) !== 1) {
                    continue;
                }
                $terminal = (string) $terminalMatch[1];
                $method = $terminal === 'one' ? 'findOne' : 'findAll';
                $offset = (int) $match[0][1];
                $length = ($array['end'] + 1 + strlen((string) $terminalMatch[0])) - $offset;
                $references[] = [
                    'file' => $this->relativePath($context->root(), $file),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                    'model' => $model,
                    'terminal' => $terminal,
                    'replacement_method' => $method,
                    'replacement' => $rawClass . '::' . $method . '(' . $array['text'] . ')',
                    'offset' => $offset,
                    'length' => $length,
                ];
            }
        }

        return $references;
    }

    /**
     * Verifica se o array é não vazio e todas as chaves top-level são strings literais.
     *
     * @param string $array Expressão completa incluindo `[` e `]`.
     * @return bool True somente para hash literal seguro para findOne/findAll.
     */
    private function isAssociativeStringKeyArray(string $array): bool
    {
        $items = $this->topLevelItems($array);
        if ($items === null || $items === []) {
            return false;
        }

        // Uma única key implícita, spread ou interpolação já muda o contrato para fora do subconjunto aceito por findOne/findAll.
        foreach ($items as $item) {
            if (str_starts_with(ltrim($item), '...')) {
                return false;
            }
            $arrow = $this->topLevelArrowOffset($item);
            if ($arrow === null) {
                return false;
            }
            $key = trim(substr($item, 0, $arrow));
            if (strlen($key) < 2) {
                return false;
            }
            $quote = $key[0];
            if (($quote !== "'" && $quote !== '"') || $key[strlen($key) - 1] !== $quote) {
                return false;
            }
            if ($quote === '"' && str_contains(substr($key, 1, -1), '$')) {
                return false;
            }
        }
        return true;
    }

    /**
     * Separa itens top-level preservando o source original de valores aninhados.
     *
     * @param string $array Expressão completa `[ ... ]`.
     * @return list<string>|null Itens balanceados ou null quando a estrutura não fecha.
     */
    private function topLevelItems(string $array): ?array
    {
        $inner = substr(trim($array), 1, -1);
        /** @var list<string> $items Elementos top-level. */
        $items = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($inner);

        // Vírgulas só separam itens quando não estamos dentro de string ou estrutura aninhada.
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
            if (in_array($char, ['[', '(', '{'], true)) {
                $depth++;
                continue;
            }
            if (in_array($char, [']', ')', '}'], true)) {
                $depth--;
                continue;
            }
            if ($char === ',' && $depth === 0) {
                $items[] = trim(substr($inner, $start, $i - $start));
                $start = $i + 1;
            }
        }
        if ($quote !== null || $depth !== 0) {
            return null;
        }
        $tail = trim(substr($inner, $start));
        if ($tail !== '') {
            $items[] = $tail;
        }
        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * Encontra `=>` no nível externo de um item de array.
     *
     * @param string $item Item individual sem vírgula top-level.
     * @return int|null Offset do `=` ou null quando não existe key explícita.
     */
    private function topLevelArrowOffset(string $item): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
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
                return $i;
            }
        }
        return null;
    }

    /**
     * Retorna um array balanceado e o offset absoluto do `]` final.
     *
     * @param string $source Código-fonte completo.
     * @param int $openingOffset Offset do primeiro `[`.
     * @return array{text:string,end:int}|null Array completo ou null quando não fecha.
     */
    private function balancedArray(string $source, int $openingOffset): ?array
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
                    return [
                        'text' => substr($source, $openingOffset, $cursor - $openingOffset + 1),
                        'end' => $cursor,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Localiza próximo caractere não whitespace.
     *
     * @param string $source Código-fonte completo.
     * @param int $offset Offset inicial.
     * @return int|null Offset encontrado ou null no EOF.
     */
    private function nextNonWhitespace(string $source, int $offset): ?int
    {
        for ($cursor = $offset, $length = strlen($source); $cursor < $length; $cursor++) {
            if (!ctype_space($source[$cursor])) {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * Lista arquivos PHP nos paths autorizados pelo contexto.
     *
     * @param ProjectContext $context Contexto que fornece root/paths.
     * @return list<string> Arquivos absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos candidatos. */
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
     * Indexa classes locais e parents para prova de ActiveRecord.
     *
     * @param list<string> $files Arquivos candidatos.
     * @return array<string,array{parent:string|null}> Índice de herança local.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes Metadados por FQCN. */
        $classes = [];
        $pattern = '/\\b(?:abstract\\s+|final\\s+)?class\\s+([A-Za-z_][A-Za-z0-9_]*)(?P<tail>[^{]*)\\{/';
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
     * Prova descendência ActiveRecord com bases conhecidas e parents locais.
     *
     * @param string $class FQCN candidato.
     * @param array<string,array{parent:string|null}> $classes Índice local.
     * @param array<string,bool> $visited Proteção de ciclo.
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

    /** Extrai namespace declarado no arquivo para resolver classes locais. */
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

    /** Extrai nome curto de um FQCN para indexação de imports. */
    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /** Converte path absoluto para path relativo estável do consumidor. */
    private function relativePath(string $root, string $file): string
    {
        return str_replace('\\', '/', substr($file, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }
}
