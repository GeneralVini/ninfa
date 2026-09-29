<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2SemanticModel.php';

/**
 * Valida atributos literais usados no array de link de relações ActiveRecord Yii2.
 *
 * O analisador só considera um inventário de atributos conclusivo quando a classe
 * declara `attributes()` como retorno literal de strings ou herda esse contrato de
 * uma classe local igualmente conclusiva. O schema implícito do banco, PHPDoc,
 * migrations e reflection incompleta não são usados como prova de ausência.
 * Relações encadeadas por `via()`/`viaTable()` são ignoradas nesta tranche porque a
 * semântica do link muda e exige conhecimento adicional do modelo intermediário.
 */
final class Yii2RelationLinkAnalyzer
{
    /**
     * Analisa pares `related => current` de `hasOne()`/`hasMany()` com evidência estática.
     *
     * Cada lado do par possui estado tri-state independente. Assim, um atributo do
     * modelo atual pode ser provado inválido mesmo quando o target relacionado é
     * externo/desconhecido, e vice-versa. Somente `false` deve virar finding; `null`
     * significa que o Ninfa não possui inventário forte o suficiente para negar.
     *
     * @param ProjectContext $context Contexto Yii2 que delimita root e paths analisáveis.
     * @param Yii2SemanticModel $model Snapshot com relações `hasOne()`/`hasMany()` observadas.
     * @return list<array{
     *   file:string,
     *   line:int,
     *   relation:string,
     *   kind:'hasOne'|'hasMany',
     *   current_model:string,
     *   related_model:string,
     *   related_attribute:string,
     *   current_attribute:string,
     *   related_exists:bool|null,
     *   current_exists:bool|null,
     *   related_inventory_complete:bool,
     *   current_inventory_complete:bool
     * }> Pares literais com estado de existência por lado.
     */
    public function references(ProjectContext $context, Yii2SemanticModel $model): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $files = $this->phpFiles($context);
        $classes = $this->classMetadata($files);
        /** @var array<string,array{attributes:list<string>,complete:bool,source:string}> $inventoryCache Inventários resolvidos por classe. */
        $inventoryCache = [];
        /** @var list<array{file:string,line:int,relation:string,kind:'hasOne'|'hasMany',current_model:string,related_model:string,related_attribute:string,current_attribute:string,related_exists:bool|null,current_exists:bool|null,related_inventory_complete:bool,current_inventory_complete:bool}> $references */
        $references = [];

        // A relação já foi reconhecida pelo modelo; esta camada só aprofunda o link quando o source permanece literal.
        foreach ($model->relations() as $relation) {
            if ($relation['target'] === null) {
                continue;
            }

            $absoluteFile = $context->root() . '/' . $relation['file'];
            if (!is_file($absoluteFile)) {
                continue;
            }

            $source = (string) file_get_contents($absoluteFile);
            $method = 'get' . ucfirst($relation['name']);
            $methodBody = $this->methodBody($source, $method);
            if ($methodBody === null) {
                continue;
            }

            $calls = $this->relationCalls($methodBody['body'], $relation['kind']);
            if (count($calls) !== 1 || $calls[0]['via']) {
                continue;
            }

            $pairs = $this->literalStringMap($calls[0]['args'][1] ?? '');
            if ($pairs === null || $pairs === []) {
                continue;
            }

            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $relatedModel = $this->resolveName($relation['target'], $uses, $namespace);
            $currentInventory = $this->attributeInventory($relation['model'], $classes, $inventoryCache, []);
            $relatedInventory = $this->attributeInventory($relatedModel, $classes, $inventoryCache, []);
            $line = $methodBody['start_line'] + $calls[0]['line'] - 1;

            foreach ($pairs as $pair) {
                $references[] = [
                    'file' => $relation['file'],
                    'line' => $line,
                    'relation' => $relation['name'],
                    'kind' => $relation['kind'],
                    'current_model' => $relation['model'],
                    'related_model' => $relatedModel,
                    'related_attribute' => $pair['key'],
                    'current_attribute' => $pair['value'],
                    'related_exists' => $relatedInventory['complete']
                        ? in_array($pair['key'], $relatedInventory['attributes'], true)
                        : null,
                    'current_exists' => $currentInventory['complete']
                        ? in_array($pair['value'], $currentInventory['attributes'], true)
                        : null,
                    'related_inventory_complete' => $relatedInventory['complete'],
                    'current_inventory_complete' => $currentInventory['complete'],
                ];
            }
        }

        return $references;
    }

    /**
     * Lista arquivos PHP apenas nos paths já autorizados pelo ProjectContext.
     *
     * @param ProjectContext $context Contexto que fornece root e paths do consumidor.
     * @return list<string> Arquivos PHP absolutos, únicos e ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files Arquivos elegíveis para inventário local de classes. */
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
     * Indexa classes locais e a fonte explícita de atributos quando demonstrável.
     *
     * Arquivos com múltiplas classes continuam indexados para resolução de nome, mas
     * não recebem inventário explícito: associar um único `attributes()` textual à
     * classe correta exigiria AST por classe. Esse caso degrada deliberadamente para
     * unknown. Traits também invalidam herança implícita de atributos quando a classe
     * não declara seu próprio método literal.
     *
     * @param list<string> $files Arquivos PHP candidatos do consumidor.
     * @return array<string,array{
     *   parent:string|null,
     *   trait_use:bool,
     *   attributes_declared:bool,
     *   attributes_complete:bool,
     *   attributes:list<string>
     * }> Metadados necessários ao inventário de atributos.
     */
    private function classMetadata(array $files): array
    {
        /** @var array<string,array{parent:string|null,trait_use:bool,attributes_declared:bool,attributes_complete:bool,attributes:list<string>}> $classes */
        $classes = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $namespace = $this->namespaceOf($source);
            $uses = $this->importsOf($source);
            $pattern = '/\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s+extends\s+(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*))?/';
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            $singleClass = count($matches) === 1;
            $explicitAttributes = $singleClass
                ? $this->explicitAttributes($source)
                : ['declared' => false, 'complete' => false, 'attributes' => []];

            foreach ($matches as $match) {
                $short = (string) $match[1][0];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $parentRaw = isset($match[2][0]) ? trim((string) $match[2][0]) : '';
                $parent = $parentRaw === '' ? null : $this->resolveName($parentRaw, $uses, $namespace);
                $tail = substr($source, (int) $match[0][1]);
                $traitUse = preg_match('/\buse\s+[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\s*,\s*[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)*\s*;/', $tail) === 1;

                $classes[$class] = [
                    'parent' => $parent,
                    'trait_use' => $traitUse,
                    'attributes_declared' => $explicitAttributes['declared'],
                    'attributes_complete' => $explicitAttributes['complete'],
                    'attributes' => $explicitAttributes['attributes'],
                ];
            }
        }

        return $classes;
    }

    /**
     * Extrai `attributes()` somente quando o retorno é uma lista literal completa.
     *
     * `return parent::attributes()`, `array_merge`, condicionais, variáveis e demais
     * expressões marcam o método como declarado porém não conclusivo. Isso impede que
     * ausência no source seja confundida com ausência de coluna do schema em runtime.
     *
     * @param string $source Código-fonte completo de um arquivo com uma única classe.
     * @return array{declared:bool,complete:bool,attributes:list<string>} Resultado explícito.
     */
    private function explicitAttributes(string $source): array
    {
        $body = $this->methodBody($source, 'attributes');
        if ($body === null) {
            return ['declared' => false, 'complete' => false, 'attributes' => []];
        }

        $tokens = token_get_all("<?php\n" . $body['body']);
        $compact = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $compact .= is_array($token) ? $token[1] : $token;
        }

        if (preg_match('/^return(.+);$/s', $compact, $match) !== 1) {
            return ['declared' => true, 'complete' => false, 'attributes' => []];
        }

        $attributes = $this->literalStringList($match[1]);
        return $attributes === null
            ? ['declared' => true, 'complete' => false, 'attributes' => []]
            : ['declared' => true, 'complete' => true, 'attributes' => $attributes];
    }

    /**
     * Resolve o inventário efetivo de atributos, incluindo herança local segura.
     *
     * Método `attributes()` literal declarado na própria classe é autoritativo. Se o
     * método existe mas é dinâmico, a resolução para nessa classe. Na ausência do
     * método, parent local pode fornecer o inventário; trait sem override explícito
     * mantém o resultado desconhecido porque pode introduzir `attributes()`.
     *
     * @param string $class FQCN cujo inventário será consultado.
     * @param array<string,array{parent:string|null,trait_use:bool,attributes_declared:bool,attributes_complete:bool,attributes:list<string>}> $classes Índice local.
     * @param array<string,array{attributes:list<string>,complete:bool,source:string}> $cache Cache mutável por FQCN.
     * @param array<string,bool> $visited Proteção contra ciclos de herança inválidos.
     * @return array{attributes:list<string>,complete:bool,source:string} Inventário efetivo.
     */
    private function attributeInventory(string $class, array $classes, array &$cache, array $visited): array
    {
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        if (isset($visited[$class]) || !isset($classes[$class])) {
            return ['attributes' => [], 'complete' => false, 'source' => 'unknown'];
        }

        $metadata = $classes[$class];
        if ($metadata['attributes_declared']) {
            $cache[$class] = [
                'attributes' => $metadata['attributes'],
                'complete' => $metadata['attributes_complete'],
                'source' => $metadata['attributes_complete'] ? 'attributes-method' : 'attributes-dynamic',
            ];
            return $cache[$class];
        }

        if ($metadata['trait_use'] || $metadata['parent'] === null) {
            $cache[$class] = ['attributes' => [], 'complete' => false, 'source' => 'unknown'];
            return $cache[$class];
        }

        // Sem override local, apenas parent também local pode transportar um contrato estático completo.
        $visited[$class] = true;
        $parentInventory = $this->attributeInventory($metadata['parent'], $classes, $cache, $visited);
        $cache[$class] = $parentInventory['complete']
            ? ['attributes' => $parentInventory['attributes'], 'complete' => true, 'source' => 'local-parent']
            : ['attributes' => [], 'complete' => false, 'source' => 'unknown'];

        return $cache[$class];
    }

    /**
     * Localiza o corpo de um método e a linha inicial de seu conteúdo.
     *
     * O balanceamento de chaves segue o mesmo escopo conservador já usado pelo modelo
     * semântico. Quando o bloco não pode ser delimitado de forma inequívoca, retorna
     * null e nenhuma regra de ausência é produzida.
     *
     * @param string $source Código-fonte PHP completo.
     * @param string $method Nome exato do método sem parênteses.
     * @return array{body:string,start_line:int}|null Corpo e linha 1-based do primeiro conteúdo.
     */
    private function methodBody(string $source, string $method): ?array
    {
        if (preg_match('/\bfunction\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{;]*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $opening = strpos($source, '{', (int) $match[0][1]);
        if ($opening === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);
        for ($cursor = $opening; $cursor < $length; $cursor++) {
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
                    'body' => substr($source, $opening + 1, $cursor - $opening - 1),
                    'start_line' => substr_count(substr($source, 0, $opening + 1), "\n") + 1,
                ];
            }
        }

        return null;
    }

    /**
     * Extrai chamadas da família de relação e seus argumentos sem executar PHP.
     *
     * O parser tokenizado respeita delimitadores aninhados ao separar argumentos e
     * registra se a chamada é seguida imediatamente por `via()`/`viaTable()`. A regra
     * exige exatamente uma chamada do tipo esperado no getter para evitar escolher
     * arbitrariamente entre branches de controle diferentes.
     *
     * @param string $body Corpo textual do getter de relação.
     * @param 'hasOne'|'hasMany' $kind Método de relação esperado pelo snapshot.
     * @return list<array{args:list<string>,line:int,via:bool}> Chamadas encontradas.
     */
    private function relationCalls(string $body, string $kind): array
    {
        $tokens = token_get_all("<?php\n" . $body);
        /** @var list<array{args:list<string>,line:int,via:bool}> $calls */
        $calls = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || strcasecmp($token[1], $kind) !== 0) {
                continue;
            }

            $previous = $this->previousSignificantIndex($tokens, $index - 1);
            $opening = $this->nextSignificantIndex($tokens, $index + 1);
            if ($previous === null || $opening === null || !$this->isObjectOperator($tokens[$previous]) || $tokens[$opening] !== '(') {
                continue;
            }

            $parsed = $this->callArguments($tokens, $opening);
            if ($parsed === null || count($parsed['args']) < 2) {
                continue;
            }

            $next = $this->nextSignificantIndex($tokens, $parsed['closing'] + 1);
            $via = false;
            if ($next !== null && $this->isObjectOperator($tokens[$next])) {
                $methodIndex = $this->nextSignificantIndex($tokens, $next + 1);
                if ($methodIndex !== null && is_array($tokens[$methodIndex]) && $tokens[$methodIndex][0] === T_STRING) {
                    $via = in_array(strtolower($tokens[$methodIndex][1]), ['via', 'viatable'], true);
                }
            }

            $calls[] = [
                'args' => $parsed['args'],
                'line' => max(1, $token[2] - 1),
                'via' => $via,
            ];
        }

        return $calls;
    }

    /**
     * Separa os argumentos de uma chamada a partir do parêntese de abertura.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens do corpo isolado.
     * @param int $opening Índice do `(` que inicia a chamada.
     * @return array{args:list<string>,closing:int}|null Argumentos textuais e fechamento.
     */
    private function callArguments(array $tokens, int $opening): ?array
    {
        $depth = 0;
        $current = '';
        /** @var list<string> $args Argumentos reconstruídos em nível raiz da chamada. */
        $args = [];

        for ($cursor = $opening; $cursor < count($tokens); $cursor++) {
            $token = $tokens[$cursor];
            $text = is_array($token) ? $token[1] : $token;

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
                if ($depth > 1) {
                    $current .= $text;
                }
                continue;
            }
            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    $args[] = trim($current);
                    return ['args' => $args, 'closing' => $cursor];
                }
                $current .= $text;
                continue;
            }
            if ($text === ',' && $depth === 1) {
                $args[] = trim($current);
                $current = '';
                continue;
            }
            if ($depth >= 1) {
                $current .= $text;
            }
        }

        return null;
    }

    /**
     * Converte um array literal simples em pares string => string.
     *
     * Qualquer item dinâmico, unpack, chave numérica, expressão ou nesting torna o
     * array não conclusivo. A regra prefere perder cobertura nesta tranche a tratar
     * uma representação parcial como se fosse o link completo do ActiveRecord.
     *
     * @param string $expression Expressão textual do segundo argumento da relação.
     * @return list<array{key:string,value:string}>|null Mapa literal ou null se dinâmico.
     */
    private function literalStringMap(string $expression): ?array
    {
        $tokens = $this->significantTokens($expression);
        if ($tokens === []) {
            return null;
        }

        $cursor = 0;
        if ($tokens[$cursor] === '[') {
            $closing = ']';
            $cursor++;
        } elseif (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_ARRAY && ($tokens[$cursor + 1] ?? null) === '(') {
            $closing = ')';
            $cursor += 2;
        } else {
            return null;
        }

        /** @var list<array{key:string,value:string}> $pairs Pares literais na ordem declarada. */
        $pairs = [];
        while (isset($tokens[$cursor]) && $tokens[$cursor] !== $closing) {
            $key = $this->simpleStringToken($tokens[$cursor] ?? null);
            $arrow = $tokens[$cursor + 1] ?? null;
            $value = $this->simpleStringToken($tokens[$cursor + 2] ?? null);
            if ($key === null || !is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW || $value === null) {
                return null;
            }

            $pairs[] = ['key' => $key, 'value' => $value];
            $cursor += 3;
            if (($tokens[$cursor] ?? null) === ',') {
                $cursor++;
                continue;
            }
            if (($tokens[$cursor] ?? null) !== $closing) {
                return null;
            }
        }

        if (($tokens[$cursor] ?? null) !== $closing || isset($tokens[$cursor + 1])) {
            return null;
        }

        return $pairs;
    }

    /**
     * Converte uma lista literal simples de strings em nomes de atributos.
     *
     * @param string $expression Expressão retornada por `attributes()`.
     * @return list<string>|null Lista completa ou null para qualquer item dinâmico.
     */
    private function literalStringList(string $expression): ?array
    {
        $tokens = $this->significantTokens($expression);
        if ($tokens === []) {
            return null;
        }

        $cursor = 0;
        if ($tokens[$cursor] === '[') {
            $closing = ']';
            $cursor++;
        } elseif (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_ARRAY && ($tokens[$cursor + 1] ?? null) === '(') {
            $closing = ')';
            $cursor += 2;
        } else {
            return null;
        }

        /** @var list<string> $values Atributos literais na ordem retornada. */
        $values = [];
        while (isset($tokens[$cursor]) && $tokens[$cursor] !== $closing) {
            $value = $this->simpleStringToken($tokens[$cursor]);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
            $cursor++;

            if (($tokens[$cursor] ?? null) === ',') {
                $cursor++;
                continue;
            }
            if (($tokens[$cursor] ?? null) !== $closing) {
                return null;
            }
        }

        if (($tokens[$cursor] ?? null) !== $closing || isset($tokens[$cursor + 1])) {
            return null;
        }

        return array_values(array_unique($values));
    }

    /**
     * Tokeniza uma expressão removendo somente espaços/comentários e a tag sintética.
     *
     * @param string $expression Expressão PHP isolada que não será executada.
     * @return list<array{0:int,1:string,2:int}|string> Tokens significativos preservados.
     */
    private function significantTokens(string $expression): array
    {
        /** @var list<array{0:int,1:string,2:int}|string> $significant */
        $significant = [];
        foreach (token_get_all("<?php\n" . $expression) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }
        return $significant;
    }

    /**
     * Decodifica somente string PHP simples adequada a nome de coluna/atributo.
     *
     * Escapes, interpolação e nomes fora do conjunto conservador permanecem unknown;
     * o analisador não usa `eval()` para interpretar código do consumidor.
     *
     * @param array{0:int,1:string,2:int}|string|null $token Token candidato.
     * @return string|null Conteúdo literal simples ou null.
     */
    private function simpleStringToken(array|string|null $token): ?string
    {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        if (preg_match('/^([\'\"])([A-Za-z_][A-Za-z0-9_]*|[A-Za-z0-9_]+)\1$/', $token[1], $match) !== 1) {
            return null;
        }
        return $match[2];
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
     * Resolve nome curto/qualificado usando imports simples e namespace corrente.
     *
     * @param string $name Nome textual observado no source.
     * @param array<string,string> $uses Imports simples por alias.
     * @param string $namespace Namespace do arquivo.
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
     * Localiza o token significativo anterior a partir de um índice.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens Stream tokenizado.
     * @param int $start Índice inicial inclusivo.
     * @return int|null Índice encontrado ou null.
     */
    private function previousSignificantIndex(array $tokens, int $start): ?int
    {
        for ($cursor = $start; $cursor >= 0; $cursor--) {
            if (!$this->isIgnorableToken($tokens[$cursor])) {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * Localiza o próximo token significativo a partir de um índice.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens Stream tokenizado.
     * @param int $start Índice inicial inclusivo.
     * @return int|null Índice encontrado ou null.
     */
    private function nextSignificantIndex(array $tokens, int $start): ?int
    {
        for ($cursor = $start; $cursor < count($tokens); $cursor++) {
            if (!$this->isIgnorableToken($tokens[$cursor])) {
                return $cursor;
            }
        }
        return null;
    }

    /**
     * Identifica espaços/comentários que não alteram a estrutura da expressão.
     *
     * @param array{0:int,1:string,2:int}|string $token Token PHP.
     * @return bool True quando pode ser ignorado durante navegação estrutural.
     */
    private function isIgnorableToken(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true);
    }

    /**
     * Reconhece o operador `->` nas representações possíveis de token_get_all().
     *
     * @param array{0:int,1:string,2:int}|string $token Token candidato.
     * @return bool True quando representa operador de objeto.
     */
    private function isObjectOperator(array|string $token): bool
    {
        return $token === '->' || (is_array($token) && $token[0] === T_OBJECT_OPERATOR);
    }
}
