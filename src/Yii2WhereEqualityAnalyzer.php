<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Detecta igualdade SQL dinâmica em conditions Yii2 que pode ser expressa como hash condition.
 *
 * A primeira tranche reconhece apenas receivers iniciados por `ActiveRecord::find()` de classes
 * locais com herança Yii2 comprovada e dois formatos sintáticos inequívocos: concatenação de
 * prefixo literal (`'column = ' . $value`) e interpolação simples (`"column = $value"`). Casos
 * mais complexos permanecem fora do finding para não transformar heurística textual em prova.
 */
final class Yii2WhereEqualityAnalyzer
{
    /** @var list<string> Bases ActiveRecord aceitas no profile Yii2. */
    private const ACTIVE_RECORD_BASES = [
        'yii\\db\\ActiveRecord',
        'yii\\db\\BaseActiveRecord',
        'yii\\redis\\ActiveRecord',
        'yii\\mongodb\\ActiveRecord',
    ];

    /** @var list<string> Métodos de Query que aceitam condition Yii2. */
    private const WHERE_METHODS = ['where', 'andWhere', 'orWhere'];

    /**
     * Localiza igualdade dinâmica simples que pode migrar para array condition com binding.
     *
     * O resultado informa a sugestão de replacement, mas a política atual é REVIEW: detectar
     * a forma insegura não prova que o valor seja controlado externamente nem que o código
     * legado não dependa de quoting manual. O `fix` portanto não consome estes offsets.
     *
     * @param ProjectContext $context Contexto do consumidor usado para paths e prova de profile.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   model:string,
     *   method:'where'|'andWhere'|'orWhere',
     *   column:string,
     *   value_expression:string,
     *   style:'concat'|'interpolated',
     *   replacement:string,
     *   offset:int,
     *   length:int
     * }> Conditions simples elegíveis a revisão para hash condition.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var array<string,bool> $activeCache Cache de prova de herança ActiveRecord. */
        $activeCache = [];
        /** @var list<array{file:string,line:int,model:string,method:'where'|'andWhere'|'orWhere',column:string,value_expression:string,style:'concat'|'interpolated',replacement:string,offset:int,length:int}> $references */
        $references = [];

        // Tokenização mantém strings-alvo disponíveis, mas impede que exemplos em comentários sejam tratados como chamadas.
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $tokens = $this->tokensWithOffsets($source);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);

            foreach ($tokens as $index => $token) {
                $method = $token['text'];
                if ($token['id'] !== T_STRING || !in_array($method, self::WHERE_METHODS, true)) {
                    continue;
                }

                $operatorIndex = $this->previousSignificantIndex($tokens, $index - 1);
                $openIndex = $this->nextSignificantIndex($tokens, $index + 1);
                if ($operatorIndex === null || $openIndex === null
                    || $tokens[$operatorIndex]['text'] !== '->' || $tokens[$openIndex]['text'] !== '(') {
                    continue;
                }

                $model = $this->receiverModel($tokens, $operatorIndex, $uses, $namespace);
                if ($model === null || !$this->isActiveRecord($model, $classes, $activeCache, [])) {
                    continue;
                }

                $closeIndex = $this->matchingParenIndex($tokens, $openIndex);
                if ($closeIndex === null) {
                    continue;
                }

                $condition = $this->simpleEqualityCondition($tokens, $openIndex + 1, $closeIndex - 1);
                if ($condition === null) {
                    continue;
                }

                $references[] = [
                    'file' => $this->relativePath($context->root(), $file),
                    'line' => substr_count(substr($source, 0, $condition['offset']), "\n") + 1,
                    'model' => $model,
                    'method' => $method,
                    'column' => $condition['column'],
                    'value_expression' => $condition['value_expression'],
                    'style' => $condition['style'],
                    'replacement' => sprintf("['%s' => %s]", $condition['column'], $condition['value_expression']),
                    'offset' => $condition['offset'],
                    'length' => $condition['length'],
                ];
            }
        }

        return $references;
    }

    /**
     * Converte `token_get_all()` em tokens com offsets absolutos preservados.
     *
     * @param string $source Código-fonte completo do arquivo PHP.
     * @return list<array{id:int|null,text:string,offset:int}> Tokens em ordem lexical.
     */
    private function tokensWithOffsets(string $source): array
    {
        /** @var list<array{id:int|null,text:string,offset:int}> $result Tokens normalizados com posição. */
        $result = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $result[] = [
                'id' => is_array($token) ? $token[0] : null,
                'text' => $text,
                'offset' => $offset,
            ];
            $offset += strlen($text);
        }

        return $result;
    }

    /**
     * Busca token significativo anterior ignorando apenas whitespace e comentários.
     *
     * @param list<array{id:int|null,text:string,offset:int}> $tokens Stream tokenizado.
     * @param int $index Posição inicial da busca regressiva.
     * @return int|null Índice do token significativo ou null.
     */
    private function previousSignificantIndex(array $tokens, int $index): ?int
    {
        for ($cursor = $index; $cursor >= 0; $cursor--) {
            $id = $tokens[$cursor]['id'];
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            return $cursor;
        }

        return null;
    }

    /**
     * Busca token significativo seguinte ignorando apenas whitespace e comentários.
     *
     * @param list<array{id:int|null,text:string,offset:int}> $tokens Stream tokenizado.
     * @param int $index Posição inicial da busca progressiva.
     * @return int|null Índice do token significativo ou null.
     */
    private function nextSignificantIndex(array $tokens, int $index): ?int
    {
        for ($cursor = $index, $count = count($tokens); $cursor < $count; $cursor++) {
            $id = $tokens[$cursor]['id'];
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            return $cursor;
        }

        return null;
    }

    /**
     * Encontra o parêntese de fechamento respeitando nesting interno.
     *
     * @param list<array{id:int|null,text:string,offset:int}> $tokens Stream tokenizado.
     * @param int $openIndex Índice do `(` inicial.
     * @return int|null Índice do `)` correspondente ou null para source incompleto.
     */
    private function matchingParenIndex(array $tokens, int $openIndex): ?int
    {
        $depth = 0;
        for ($cursor = $openIndex, $count = count($tokens); $cursor < $count; $cursor++) {
            $text = $tokens[$cursor]['text'];
            if ($text === '(') {
                $depth++;
                continue;
            }
            if ($text !== ')') {
                continue;
            }

            $depth--;
            if ($depth === 0) {
                return $cursor;
            }
        }

        return null;
    }

    /**
     * Resolve a classe que iniciou a chain imediatamente antes de `->where*()`.
     *
     * A prova é intencionalmente restrita a uma expressão contendo `Class::find()` no mesmo
     * statement. Receivers armazenados em variável exigiriam análise de fluxo e ficam fora.
     *
     * @param list<array{id:int|null,text:string,offset:int}> $tokens Stream tokenizado.
     * @param int $operatorIndex Índice do `->` que antecede o método where.
     * @param array<string,string> $uses Imports de classe por alias.
     * @param string $namespace Namespace do arquivo.
     * @return string|null FQCN do modelo quando a origem `find()` é demonstrável.
     */
    private function receiverModel(array $tokens, int $operatorIndex, array $uses, string $namespace): ?string
    {
        $start = $operatorIndex;
        for ($cursor = $operatorIndex - 1; $cursor >= 0; $cursor--) {
            $text = $tokens[$cursor]['text'];
            if (in_array($text, [';', '{', '}', '='], true)) {
                $start = $cursor + 1;
                break;
            }
            $start = $cursor;
        }

        $prefix = '';
        for ($cursor = $start; $cursor <= $operatorIndex; $cursor++) {
            $prefix .= $tokens[$cursor]['text'];
        }

        if (preg_match('/(?P<class>\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::find\s*\(\s*\)/', $prefix, $match) !== 1) {
            return null;
        }

        $rawClass = (string) $match['class'];
        if (in_array(strtolower($rawClass), ['self', 'static', 'parent'], true)) {
            return null;
        }

        return $this->resolveName($rawClass, $uses, $namespace);
    }

    /**
     * Reconhece apenas os dois formatos de igualdade dinâmica suportados nesta tranche.
     *
     * Concatenação aceita somente variável simples à direita. Interpolação aceita exatamente
     * um trecho literal `column = ` seguido de uma variável simples. Commas, chamadas, casts,
     * arithmetic, quoting adicional e qualquer shape ambígua retornam null.
     *
     * @param list<array{id:int|null,text:string,offset:int}> $tokens Stream tokenizado.
     * @param int $start Índice inicial do argumento.
     * @param int $end Índice final do argumento.
     * @return array{column:string,value_expression:string,style:'concat'|'interpolated',offset:int,length:int}|null Condition normalizada.
     */
    private function simpleEqualityCondition(array $tokens, int $start, int $end): ?array
    {
        while ($start <= $end && $tokens[$start]['id'] === T_WHITESPACE) {
            $start++;
        }
        while ($end >= $start && $tokens[$end]['id'] === T_WHITESPACE) {
            $end--;
        }
        if ($start > $end) {
            return null;
        }

        /** @var list<int> $significant Índices dos tokens relevantes do argumento. */
        $significant = [];
        for ($cursor = $start; $cursor <= $end; $cursor++) {
            $id = $tokens[$cursor]['id'];
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            $significant[] = $cursor;
        }

        // Forma 1: `'column = ' . $value`.
        if (count($significant) === 3) {
            [$literalIndex, $dotIndex, $valueIndex] = $significant;
            if ($tokens[$literalIndex]['id'] === T_CONSTANT_ENCAPSED_STRING
                && $tokens[$dotIndex]['text'] === '.'
                && $tokens[$valueIndex]['id'] === T_VARIABLE) {
                $column = $this->columnFromLiteral($tokens[$literalIndex]['text']);
                if ($column !== null) {
                    return $this->conditionResult(
                        $tokens,
                        $start,
                        $end,
                        $column,
                        $tokens[$valueIndex]['text'],
                        'concat',
                    );
                }
            }
        }

        // Forma 2: `"column = $value"` tokenizado como aspas + texto + variável + aspas.
        if (count($significant) === 4) {
            [$quoteStart, $textIndex, $valueIndex, $quoteEnd] = $significant;
            if ($tokens[$quoteStart]['text'] === '"'
                && $tokens[$textIndex]['id'] === T_ENCAPSED_AND_WHITESPACE
                && $tokens[$valueIndex]['id'] === T_VARIABLE
                && $tokens[$quoteEnd]['text'] === '"') {
                $column = $this->columnFromPrefix($tokens[$textIndex]['text']);
                if ($column !== null) {
                    return $this->conditionResult(
                        $tokens,
                        $start,
                        $end,
                        $column,
                        $tokens[$valueIndex]['text'],
                        'interpolated',
                    );
                }
            }
        }

        return null;
    }

    /**
     * Extrai nome de coluna de string PHP literal simples terminada em `=`.
     *
     * @param string $literal Token T_CONSTANT_ENCAPSED_STRING incluindo aspas.
     * @return string|null Coluna segura para hash condition ou null.
     */
    private function columnFromLiteral(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            return null;
        }

        $quote = $literal[0];
        if (($quote !== "'" && $quote !== '"') || $literal[strlen($literal) - 1] !== $quote) {
            return null;
        }

        $value = substr($literal, 1, -1);
        return $this->columnFromPrefix($value);
    }

    /**
     * Valida prefixo literal `column = ` sem permitir SQL adicional.
     *
     * @param string $prefix Conteúdo literal antes da variável dinâmica.
     * @return string|null Coluna composta por identificadores/dots ou null.
     */
    private function columnFromPrefix(string $prefix): ?string
    {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*$/', $prefix, $match) !== 1) {
            return null;
        }

        return (string) $match[1];
    }

    /**
     * Monta metadata de offset/length para a condition reconhecida.
     *
     * @param list<array{id:int|null,text:string,offset:int}> $tokens Stream tokenizado.
     * @param int $start Primeiro token da condition incluindo whitespace lateral já delimitado.
     * @param int $end Último token da condition.
     * @param string $column Coluna validada.
     * @param string $valueExpression Variável simples observada.
     * @param 'concat'|'interpolated' $style Forma sintática original.
     * @return array{column:string,value_expression:string,style:'concat'|'interpolated',offset:int,length:int} Metadata normalizada.
     */
    private function conditionResult(
        array $tokens,
        int $start,
        int $end,
        string $column,
        string $valueExpression,
        string $style,
    ): array {
        $offset = $tokens[$start]['offset'];
        $last = $tokens[$end];
        $length = ($last['offset'] + strlen($last['text'])) - $offset;

        return [
            'column' => $column,
            'value_expression' => $valueExpression,
            'style' => $style,
            'offset' => $offset,
            'length' => $length,
        ];
    }

    /**
     * Lista arquivos PHP somente nos paths autorizados pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto que fornece root e paths elegíveis.
     * @return list<string> Arquivos absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos PHP candidatos. */
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
     * Indexa classes locais e seus parents para prova de ActiveRecord sem executar o consumidor.
     *
     * @param list<string> $files Arquivos PHP candidatos.
     * @return array<string,array{parent:string|null}> Metadados mínimos por FQCN local.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null}> $classes Índice local de herança. */
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
     * Prova se uma classe local termina em uma base ActiveRecord Yii2 conhecida.
     *
     * @param string $class FQCN candidato.
     * @param array<string,array{parent:string|null}> $classes Índice local de herança.
     * @param array<string,bool> $cache Cache mutável de classificações.
     * @param array<string,bool> $visited Proteção contra ciclos inválidos.
     * @return bool True apenas para cadeia de herança comprovada.
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

        // Parent externo desconhecido encerra a prova sem finding em vez de assumir convenção.
        $visited[$class] = true;
        $cache[$class] = $this->isActiveRecord($parent, $classes, $cache, $visited);
        return $cache[$class];
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
            ? trim((string) $match[1], " \t\n\r\0\x0B\\")
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
            $fqcn = trim((string) ($parts[0] ?? ''), " \t\n\r\0\x0B\\");
            if ($fqcn === '') {
                continue;
            }
            $alias = isset($parts[1]) ? trim((string) $parts[1]) : basename(str_replace('\\', '/', $fqcn));
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
     * Converte path absoluto em path relativo à raiz do consumidor.
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
