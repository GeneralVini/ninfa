<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2SemanticModel.php';

/**
 * Resolve referências literais a relações em chains ActiveQuery de projetos Yii2.
 *
 * O analisador reutiliza o inventário `hasOne()`/`hasMany()` do Yii2SemanticModel e
 * só declara ausência quando consegue provar localmente a classe ActiveRecord e sua
 * cadeia de herança. Herança externa não reconhecida, traits, getters não classificáveis
 * e expressões dinâmicas degradam para `unknown` em vez de produzir falso positivo.
 */
final class Yii2RelationReferenceAnalyzer
{
    /** @var list<string> Bases Yii2 cujo contrato de relações herdadas é conhecido. */
    private const ACTIVE_RECORD_BASES = [
        'yii\\db\\ActiveRecord',
        'yii\\db\\BaseActiveRecord',
        'yii\\redis\\ActiveRecord',
        'yii\\mongodb\\ActiveRecord',
    ];

    /**
     * Localiza chamadas literais `Model::find()->with()/joinWith()/innerJoinWith()`.
     *
     * Strings literais, arrays literais e argumentos variádicos literais de with()
     * são avaliados. Variáveis, spreads e expressões dinâmicas permanecem fora. Para
     * classes com inventário completo,
     * cada segmento pontuado é validado contra as relações observadas, incluindo
     * relações herdadas de parents locais. Aliases de `joinWith`, como `items i`, são
     * normalizados apenas para a resolução e preservados na evidência original.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita root e paths elegíveis.
     * @param Yii2SemanticModel $model Snapshot que fornece as relações declaradas.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   method:string,
     *   model:string,
     *   relation_path:string,
     *   missing_relation:string|null,
     *   resolved_prefix:string,
     *   exists:bool|null,
     *   relation_inventory_complete:bool
     * }> Referências estáticas observadas em ordem de arquivo e ocorrência.
     */
    public function references(ProjectContext $context, Yii2SemanticModel $model): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        $relations = $this->relationMap($context, $model);

        /** @var list<array{file:string,line:int,method:string,model:string,relation_path:string,missing_relation:string|null,resolved_prefix:string,exists:bool|null,relation_inventory_complete:bool}> $references */
        $references = [];
        $pattern = '/(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::find\s*\(\s*\)\s*->\s*(?P<method>with|joinWith|innerJoinWith)\s*\(\s*(?P<quote>[\'\"])(?P<path>[^\'\"]+)\k<quote>/i';

        // O scan é textual por desenho: somente o subconjunto literal comprovável entra na regra nativa.
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
                $path = (string) $match['path'][0];
                $complete = $this->classInventoryComplete($class, $classes, []);
                $resolution = $complete
                    ? $this->resolvePath($class, $path, $relations, $classes)
                    : ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => ''];

                $offset = (int) $match['path'][1];
                $references[] = [
                    'file' => $this->relativePath($context->root(), $file),
                    'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                    'method' => (string) $match['method'][0],
                    'model' => $class,
                    'relation_path' => $path,
                    'missing_relation' => $resolution['missing_relation'],
                    'resolved_prefix' => $resolution['resolved_prefix'],
                    'exists' => $resolution['exists'],
                    'relation_inventory_complete' => $complete,
                ];
            }
        }

        // O regex acima cobre a forma simples com primeiro argumento string.
        // Esta segunda passagem acrescenta arrays e argumentos variádicos sem duplicar
        // a primeira referência literal já observada.
        $callPattern = '/(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::find\s*\(\s*\)\s*->\s*(?P<method>with|joinWith|innerJoinWith)\s*\(/i';
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            if (preg_match_all($callPattern, $source, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($calls as $call) {
                $rawClass = (string) $call['class'][0];
                if (in_array(strtolower($rawClass), ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $class = $this->resolveName($rawClass, $uses, $namespace);
                $complete = $this->classInventoryComplete($class, $classes, []);
                $openingParen = (int) $call[0][1] + strlen((string) $call[0][0]) - 1;
                $method = (string) $call['method'][0];

                foreach ($this->additionalLiteralPaths($source, $openingParen, $method) as $entry) {
                    $resolution = $complete
                        ? $this->resolvePath($class, $entry['path'], $relations, $classes)
                        : ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => ''];

                    $references[] = [
                        'file' => $this->relativePath($context->root(), $file),
                        'line' => substr_count(substr($source, 0, $entry['offset']), "\n") + 1,
                        'method' => $method,
                        'model' => $class,
                        'relation_path' => $entry['path'],
                        'missing_relation' => $resolution['missing_relation'],
                        'resolved_prefix' => $resolution['resolved_prefix'],
                        'exists' => $resolution['exists'],
                        'relation_inventory_complete' => $complete,
                    ];
                }
            }
        }

        usort(
            $references,
            static fn (array $left, array $right): int => [
                $left['file'],
                $left['line'],
                $left['method'],
                $left['relation_path'],
            ] <=> [
                $right['file'],
                $right['line'],
                $right['method'],
                $right['relation_path'],
            ],
        );

        return $references;
    }

    /**
     * Extrai relation paths adicionais de uma chamada direta de ActiveQuery.
     *
     * A primeira string literal simples e ignorada porque ja e coberta pelo regex
     * principal. with() aceita argumentos variadicos; joinWith()/innerJoinWith()
     * usam apenas o primeiro argumento. Arrays aceitam valores string ou chaves
     * string quando o valor e closure/configuracao da relacao.
     *
     * @param string $source Codigo-fonte completo.
     * @param int $openingParen Offset do parentese inicial da chamada.
     * @param string $method Metodo ActiveQuery observado.
     * @return list<array{path:string,offset:int}> Paths adicionais com offset absoluto.
     */
    private function additionalLiteralPaths(string $source, int $openingParen, string $method): array
    {
        $closingParen = $this->matchingDelimiter($source, $openingParen, '(', ')');
        if ($closingParen === null) {
            return [];
        }

        $arguments = $this->topLevelRanges($source, $openingParen + 1, $closingParen - 1);
        if ($arguments === []) {
            return [];
        }

        $isWith = strtolower($method) === 'with';
        $limit = $isWith ? count($arguments) : 1;
        /** @var list<array{path:string,offset:int}> $paths */
        $paths = [];

        for ($index = 0; $index < $limit; $index++) {
            [$start, $end] = $arguments[$index];
            $first = $this->nextNonWhitespaceOffset($source, $start, $end);
            if ($first === null) {
                continue;
            }

            if (($source[$first] ?? '') === '[') {
                foreach ($this->literalArrayEntries($source, $first, $end) as $entry) {
                    $paths[] = $entry;
                }
                continue;
            }

            // A primeira string simples ja foi retornada pela passagem regex inicial.
            if ($index === 0) {
                continue;
            }
            $literal = $this->literalRange($source, $start, $end);
            if ($literal !== null) {
                $paths[] = $literal;
            }
        }

        return $paths;
    }

    /**
     * Extrai strings de array literal usadas como valores ou chaves de relacao.
     *
     * @param string $source Codigo-fonte completo.
     * @param int $openingBracket Offset do colchete inicial.
     * @param int $rangeEnd Limite do argumento.
     * @return list<array{path:string,offset:int}> Entradas literais elegiveis.
     */
    private function literalArrayEntries(string $source, int $openingBracket, int $rangeEnd): array
    {
        $closingBracket = $this->matchingDelimiter($source, $openingBracket, '[', ']');
        if ($closingBracket === null || $closingBracket > $rangeEnd) {
            return [];
        }

        /** @var list<array{path:string,offset:int}> $entries */
        $entries = [];
        foreach ($this->topLevelRanges($source, $openingBracket + 1, $closingBracket - 1) as [$start, $end]) {
            $arrow = $this->topLevelArrowOffset($source, $start, $end);
            $literal = $arrow === null
                ? $this->literalRange($source, $start, $end)
                : $this->literalRange($source, $start, $arrow - 1);
            if ($literal !== null) {
                $entries[] = $literal;
            }
        }

        return $entries;
    }

    /**
     * Divide uma regiao por virgulas no primeiro nivel preservando offsets absolutos.
     *
     * @param string $source Codigo-fonte completo.
     * @param int $start Inicio inclusivo.
     * @param int $end Fim inclusivo.
     * @return list<array{int,int}> Ranges nao vazios.
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
     * Localiza a seta de array no primeiro nivel de uma regiao.
     *
     * @param string $source Codigo-fonte completo.
     * @param int $start Inicio inclusivo.
     * @param int $end Fim inclusivo.
     * @return int|null Offset do sinal de igual da seta.
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
     * Resolve uma string literal isolada dentro de um range.
     *
     * @param string $source Codigo-fonte completo.
     * @param int $start Inicio inclusivo.
     * @param int $end Fim inclusivo.
     * @return array{path:string,offset:int}|null Literal sem interpolacao.
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
        if ($quote === '"' && str_contains($value, '$')) {
            return null;
        }
        if ($quote === "'") {
            $value = str_replace("\\'", "'", $value);
        }

        return ['path' => $value, 'offset' => $first];
    }

    /**
     * Busca fechamento balanceado respeitando strings.
     *
     * @param string $source Codigo-fonte completo.
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
                if (ord($char) === 92) {
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
     * Localiza caractere nao-whitespace no range.
     *
     * @param string $source Codigo-fonte completo.
     * @param int $start Inicio inclusivo.
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
     * Lista PHP apenas nos paths já autorizados pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto que fornece root e paths do consumidor.
     * @return list<string> Arquivos PHP absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos PHP elegíveis à análise de referências. */
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
     * Indexa classes locais, parent, getters e uso de trait que reduz completude.
     *
     * Imports são lidos apenas antes da primeira declaração de classe para não
     * confundir `use SomeTrait;` interno com import de namespace. Getters são guardados
     * mesmo quando o modelo não conseguiu classificá-los como relação: se `getFoo()`
     * existe mas seu retorno é dinâmico, uma query `with('foo')` deve ficar unknown.
     *
     * @param list<string> $files Arquivos PHP candidatos.
     * @return array<string,array{file:string,parent:string|null,trait_use:bool,getters:list<string>}> Metadados por FQCN local.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{file:string,parent:string|null,trait_use:bool,getters:list<string>}> $classes */
        $classes = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $pattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*))?/';
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $short = (string) $match[1][0];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $parentRaw = isset($match[2][0]) ? trim((string) $match[2][0]) : '';
                $parent = $parentRaw === '' ? null : $this->resolveName($parentRaw, $uses, $namespace);
                $offset = (int) $match[0][1];
                $tail = substr($source, $offset);
                $traitUse = preg_match('/\buse\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\s*,\s*[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)*\s*;/', $tail) === 1;
                /** @var list<string> $getters Getters observados a partir da declaração da classe. */
                $getters = [];
                if (preg_match_all('/\bfunction\s+get([A-Z][A-Za-z0-9_]*)\s*\(/', $tail, $getterMatches) > 0) {
                    foreach ($getterMatches[1] as $suffix) {
                        $getters[] = lcfirst((string) $suffix);
                    }
                }
                $getters = array_values(array_unique($getters));

                $classes[$class] = [
                    'file' => $file,
                    'parent' => $parent,
                    'trait_use' => $traitUse,
                    'getters' => $getters,
                ];
            }
        }

        return $classes;
    }

    /**
     * Normaliza o inventário de relações por FQCN e resolve o target literal.
     *
     * @param ProjectContext $context Contexto usado para abrir os arquivos relativos do snapshot.
     * @param Yii2SemanticModel $model Snapshot com relações `hasOne`/`hasMany` observadas.
     * @return array<string,array<string,string|null>> Mapa model => relation => target FQCN/null.
     */
    private function relationMap(ProjectContext $context, Yii2SemanticModel $model): array
    {
        /** @var array<string,array<string,string|null>> $map Relações resolvidas por model. */
        $map = [];
        /** @var array<string,array{namespace:string,uses:array<string,string>}> $fileContext Cache de resolução por arquivo. */
        $fileContext = [];

        foreach ($model->relations() as $relation) {
            $relativeFile = $relation['file'];
            if (!isset($fileContext[$relativeFile])) {
                $source = (string) file_get_contents($context->root() . '/' . $relativeFile);
                $fileContext[$relativeFile] = [
                    'namespace' => $this->namespaceOf($source),
                    'uses' => $this->importsOf($source),
                ];
            }

            $target = $relation['target'];
            $resolvedTarget = $target === null
                ? null
                : $this->resolveName(
                    $target,
                    $fileContext[$relativeFile]['uses'],
                    $fileContext[$relativeFile]['namespace'],
                );
            $map[$relation['model']][$relation['name']] = $resolvedTarget;
        }

        return $map;
    }

    /**
     * Determina se a classe possui inventário suficientemente completo para negar relação.
     *
     * Uma classe é conclusiva apenas quando sua herança termina em um ActiveRecord Yii2
     * conhecido ou em parent local igualmente conclusivo. Traits tornam o resultado
     * incompleto porque podem introduzir getters de relação fora do arquivo da classe.
     *
     * @param string $class FQCN local candidato.
     * @param array<string,array{file:string,parent:string|null,trait_use:bool,getters:list<string>}> $classes Índice local.
     * @param array<string,bool> $visited Proteção contra ciclos de herança inválidos.
     * @return bool True quando ausência no inventário pode ser tratada como evidência.
     */
    private function classInventoryComplete(string $class, array $classes, array $visited): bool
    {
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return false;
        }
        if ($classes[$class]['trait_use']) {
            return false;
        }

        $parent = $classes[$class]['parent'];
        if ($parent === null) {
            return false;
        }
        if (in_array($parent, self::ACTIVE_RECORD_BASES, true)) {
            return true;
        }

        // Parents locais são seguidos recursivamente; parent externo desconhecido impede prova de ausência.
        $visited[$class] = true;
        return $this->classInventoryComplete($parent, $classes, $visited);
    }

    /**
     * Valida cada segmento de uma relation path usando relações próprias e herdadas.
     *
     * Se um getter compatível existe mas não foi classificado como `hasOne/hasMany`, a
     * ausência não é comprovável: o resultado vira unknown. Isso protege relations que
     * são construídas por helper/factory ou cujo target não é literal.
     *
     * @param string $modelClass FQCN do ActiveRecord de origem.
     * @param string $path Relation path literal, possivelmente pontuada/aliased.
     * @param array<string,array<string,string|null>> $relations Relações normalizadas por classe.
     * @param array<string,array{file:string,parent:string|null,trait_use:bool,getters:list<string>}> $classes Índice local.
     * @return array{exists:bool|null,missing_relation:string|null,resolved_prefix:string} Resultado conservador.
     */
    private function resolvePath(string $modelClass, string $path, array $relations, array $classes): array
    {
        $segments = explode('.', $path);
        $lastIndex = count($segments) - 1;
        $current = $modelClass;
        /** @var list<string> $resolved Segmentos comprovadamente encontrados. */
        $resolved = [];

        foreach ($segments as $index => $segment) {
            $trimmed = trim($segment);
            $parts = preg_split('/\s+/', $trimmed, 2) ?: [];
            $relation = $parts[0] ?? '';
            if ($relation === '') {
                return ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
            }

            $available = $this->relationsForClass($current, $relations, $classes, []);
            if (!array_key_exists($relation, $available)) {
                if ($this->getterExists($current, $relation, $classes, [])) {
                    return ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
                }
                return [
                    'exists' => false,
                    'missing_relation' => $relation,
                    'resolved_prefix' => implode('.', $resolved),
                ];
            }

            $resolved[] = $relation;
            $target = $available[$relation];
            if ($target === null) {
                return ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
            }
            $current = $target;

            // Segmentos seguintes só podem ser negados quando o target local também tem inventário completo.
            if ($index < $lastIndex && !$this->classInventoryComplete($current, $classes, [])) {
                return ['exists' => null, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
            }
        }

        return ['exists' => true, 'missing_relation' => null, 'resolved_prefix' => implode('.', $resolved)];
    }

    /**
     * Verifica getter próprio/herdado para não confundir relação dinâmica com ausência.
     *
     * @param string $class FQCN local a consultar.
     * @param string $relation Nome de relation path convertido diretamente para getter Yii2.
     * @param array<string,array{file:string,parent:string|null,trait_use:bool,getters:list<string>}> $classes Índice local.
     * @param array<string,bool> $visited Proteção contra ciclos de herança inválidos.
     * @return bool True quando `get<Relation>()` foi observado na classe ou parent local.
     */
    private function getterExists(string $class, string $relation, array $classes, array $visited): bool
    {
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return false;
        }
        if (in_array($relation, $classes[$class]['getters'], true)) {
            return true;
        }

        $parent = $classes[$class]['parent'];
        if ($parent === null || !isset($classes[$parent])) {
            return false;
        }

        $visited[$class] = true;
        return $this->getterExists($parent, $relation, $classes, $visited);
    }

    /**
     * Combina relações herdadas de parents locais com as relações declaradas na classe.
     *
     * Relações da classe filha prevalecem sobre nomes herdados, espelhando override de
     * getter. Bases framework conhecidas encerram a recursão sem adicionar relações.
     *
     * @param string $class FQCN cuja visão efetiva será montada.
     * @param array<string,array<string,string|null>> $relations Mapa de relações próprias.
     * @param array<string,array{file:string,parent:string|null,trait_use:bool,getters:list<string>}> $classes Índice local.
     * @param array<string,bool> $visited Proteção contra ciclos.
     * @return array<string,string|null> Relações efetivas por nome.
     */
    private function relationsForClass(string $class, array $relations, array $classes, array $visited): array
    {
        if (isset($visited[$class])) {
            return [];
        }
        $visited[$class] = true;

        /** @var array<string,string|null> $effective Relações herdadas e próprias. */
        $effective = [];
        $parent = $classes[$class]['parent'] ?? null;
        if ($parent !== null && isset($classes[$parent])) {
            $effective = $this->relationsForClass($parent, $relations, $classes, $visited);
        }
        foreach ($relations[$class] ?? [] as $name => $target) {
            $effective[$name] = $target;
        }

        return $effective;
    }

    /**
     * Extrai namespace declarado no arquivo.
     *
     * @param string $source Código-fonte PHP completo.
     * @return string Namespace sem barra inicial ou string vazia.
     */
    private function namespaceOf(string $source): string
    {
        return preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $match) === 1
            ? trim($match[1])
            : '';
    }

    /**
     * Extrai imports de classe simples declarados antes da primeira classe.
     *
     * Group use, `use function` e `use const` permanecem fora desta tranche. O alias
     * explícito ou o nome curto final é usado como chave do mapa de resolução.
     *
     * @param string $source Código-fonte PHP completo.
     * @return array<string,string> Mapa alias/nome curto => FQCN sem barra inicial.
     */
    private function importsOf(string $source): array
    {
        $classPosition = preg_match('/\bclass\s+[A-Za-z_]/', $source, $classMatch, PREG_OFFSET_CAPTURE) === 1
            ? (int) $classMatch[0][1]
            : strlen($source);
        $prefix = substr($source, 0, $classPosition);
        /** @var array<string,string> $uses Imports simples por alias. */
        $uses = [];
        $pattern = '/^\s*use\s+(?!function\b|const\b)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/mi';
        if (preg_match_all($pattern, $prefix, $matches, PREG_SET_ORDER) === 0) {
            return $uses;
        }

        foreach ($matches as $match) {
            $fqcn = ltrim((string) $match[1], '\\');
            $segments = explode('\\', $fqcn);
            $alias = isset($match[2]) && $match[2] !== '' ? (string) $match[2] : (string) end($segments);
            $uses[$alias] = $fqcn;
        }

        return $uses;
    }

    /**
     * Resolve nome curto/qualificado conforme imports e namespace do arquivo.
     *
     * @param string $name Nome textual encontrado no source.
     * @param array<string,string> $uses Imports simples por alias.
     * @param string $namespace Namespace corrente.
     * @return string FQCN normalizado sem barra inicial.
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
     * Relativiza arquivo físico para evidência estável do Finding.
     *
     * @param string $root Raiz física do consumidor.
     * @param string $file Arquivo absoluto observado.
     * @return string Path relativo quando o arquivo pertence ao projeto.
     */
    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalized = str_replace('\\', '/', $file);
        return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
    }
}
