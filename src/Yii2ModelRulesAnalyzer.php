<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Modela atributos estáticos de `yii\base\Model` e valida os atributos literais declarados em rules().
 *
 * O analyzer favorece falso negativo a falso positivo: só considera completo um inventário vindo
 * de `attributes()` com lista literal ou de propriedades públicas em cadeia local que termina em
 * `yii\base\Model`. ActiveRecord sem override literal, traits, parent externo e código dinâmico
 * permanecem `unknown`, pois podem depender de schema/runtime.
 */
final class Yii2ModelRulesAnalyzer
{
    /** @var list<string> Bases ActiveRecord cujo inventário padrão depende de schema/runtime. */
    private const ACTIVE_RECORD_BASES = ['yii\\db\\ActiveRecord', 'yii\\db\\BaseActiveRecord', 'yii\\redis\\ActiveRecord', 'yii\\mongodb\\ActiveRecord'];

    /**
     * Expõe inventários reutilizáveis por scenarios(), labels, hints e ActiveForm.
     *
     * @param ProjectContext $context Contexto do consumidor.
     * @return array<string,array{file:string,class:string,parent:string|null,attributes:list<string>,complete:bool,source:string,active_record:bool}> Inventários por FQCN.
     */
    public function inventories(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $classes = $this->classes($context);
        /** @var array<string,array{file:string,class:string,parent:string|null,attributes:list<string>,complete:bool,source:string,active_record:bool}> $resolved */
        $resolved = [];
        foreach (array_keys($classes) as $class) {
            $this->resolveInventory($class, $classes, $resolved, []);
        }
        ksort($resolved);
        return $resolved;
    }

    /**
     * Retorna atributos de rules() comprovadamente ausentes do inventário completo do Model.
     *
     * Apenas `return [...]` literal é interpretado. O índice 0 de cada regra precisa ser string
     * literal ou lista literal de strings; qualquer spread, variável, merge ou expressão dinâmica
     * faz a regra ser ignorada em vez de produzir negação especulativa.
     *
     * @param ProjectContext $context Contexto do consumidor.
     * @return list<array{file:string,line:int,model:string,attribute:string,validator:string|null,attribute_inventory_complete:true}> Referências inválidas.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $classes = $this->classes($context);
        $inventories = $this->inventories($context);
        /** @var list<array{file:string,line:int,model:string,attribute:string,validator:string|null,attribute_inventory_complete:true}> $references */
        $references = [];

        foreach ($classes as $class => $metadata) {
            $inventory = $inventories[$class] ?? null;
            if ($inventory === null || !$inventory['complete'] || $metadata['rules'] === null) {
                continue;
            }
            foreach ($metadata['rules'] as $rule) {
                foreach ($rule['attributes'] as $attribute) {
                    if (in_array($attribute['name'], $inventory['attributes'], true)) {
                        continue;
                    }
                    $references[] = [
                        'file' => $metadata['file'],
                        'line' => $attribute['line'],
                        'model' => $class,
                        'attribute' => $attribute['name'],
                        'validator' => $rule['validator'],
                        'attribute_inventory_complete' => true,
                    ];
                }
            }
        }
        return $references;
    }

    /**
     * Indexa classes locais e extrai somente fatos necessários ao contrato desta tranche.
     *
     * @param ProjectContext $context Contexto que define root e paths autorizados.
     * @return array<string,array{file:string,parent:string|null,public:list<string>,trait:bool,attributes_method:bool,literal_attributes:list<string>|null,rules:list<array{attributes:list<array{name:string,line:int}>,validator:string|null}>|null}> Metadados locais.
     */
    private function classes(ProjectContext $context): array
    {
        /** @var array<string,array{file:string,parent:string|null,public:list<string>,trait:bool,attributes_method:bool,literal_attributes:list<string>|null,rules:list<array{attributes:list<array{name:string,line:int}>,validator:string|null}>|null}> $classes */
        $classes = [];

        foreach ($this->phpFiles($context) as $file) {
            $source = (string) file_get_contents($file);
            $namespace = preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $ns) === 1 ? trim((string) $ns[1], " \t\n\r\0\x0B\\") : '';
            /** @var array<string,string> $uses */
            $uses = [];
            $prefix = preg_split('/\b(?:abstract\s+|final\s+)?class\s+[A-Za-z_]/', $source, 2)[0] ?? $source;
            if (preg_match_all('/\buse\s+([^;]+);/', $prefix, $matches) > 0) {
                foreach ($matches[1] as $raw) {
                    $parts = preg_split('/\s+as\s+/i', trim((string) $raw), 2) ?: [];
                    $fqcn = trim((string) ($parts[0] ?? ''), " \t\n\r\0\x0B\\");
                    if ($fqcn === '' || str_contains($fqcn, '{') || str_starts_with($fqcn, 'function ') || str_starts_with($fqcn, 'const ')) {
                        continue;
                    }
                    $alias = isset($parts[1]) ? trim((string) $parts[1]) : basename(str_replace('\\', '/', $fqcn));
                    $uses[$alias] = $fqcn;
                }
            }

            /** @var list<array{id:int|null,text:string,line:int}> $tokens */
            $tokens = [];
            foreach (token_get_all($source) as $rawToken) {
                $tokens[] = [
                    'id' => is_array($rawToken) ? $rawToken[0] : null,
                    'text' => is_array($rawToken) ? $rawToken[1] : $rawToken,
                    'line' => is_array($rawToken) ? $rawToken[2] : ($tokens === [] ? 1 : $tokens[array_key_last($tokens)]['line']),
                ];
            }

            // Cada classe é tratada isoladamente; classes anônimas são descartadas por não possuírem T_STRING após T_CLASS.
            for ($i = 0, $count = count($tokens); $i < $count; $i++) {
                if ($tokens[$i]['id'] !== T_CLASS) {
                    continue;
                }
                $nameIndex = $this->next($tokens, $i + 1);
                if ($nameIndex === null || $tokens[$nameIndex]['id'] !== T_STRING) {
                    continue;
                }
                $open = $this->seekText($tokens, $nameIndex + 1, '{');
                if ($open === null) {
                    continue;
                }
                $close = $this->matching($tokens, $open, '{', '}');
                if ($close === null) {
                    continue;
                }

                $short = $tokens[$nameIndex]['text'];
                $class = $namespace === '' ? $short : $namespace . '\\' . $short;
                $parent = null;
                for ($cursor = $nameIndex + 1; $cursor < $open; $cursor++) {
                    if ($tokens[$cursor]['id'] !== T_EXTENDS) {
                        continue;
                    }
                    $parentIndex = $this->next($tokens, $cursor + 1);
                    if ($parentIndex !== null) {
                        $rawParent = $tokens[$parentIndex]['text'];
                        $parent = $this->resolveName($rawParent, $uses, $namespace);
                    }
                    break;
                }

                /** @var list<string> $public */
                $public = [];
                $trait = false;
                $attributesMethod = false;
                $literalAttributes = null;
                $rules = null;
                $depth = 1;
                for ($cursor = $open + 1; $cursor < $close; $cursor++) {
                    $text = $tokens[$cursor]['text'];
                    if ($text === '{') {
                        $depth++;
                        continue;
                    }
                    if ($text === '}') {
                        $depth--;
                        continue;
                    }
                    if ($depth !== 1) {
                        continue;
                    }
                    if ($tokens[$cursor]['id'] === T_USE) {
                        $trait = true;
                        continue;
                    }

                    // Propriedades públicas entram no attributes() padrão de yii\base\Model; métodos/estáticos são excluídos.
                    if ($tokens[$cursor]['id'] === T_PUBLIC) {
                        $end = $this->seekText($tokens, $cursor + 1, ';', $close);
                        if ($end !== null) {
                            $static = false;
                            $method = false;
                            for ($p = $cursor; $p <= $end; $p++) {
                                $static = $static || $tokens[$p]['id'] === T_STATIC;
                                $method = $method || $tokens[$p]['id'] === T_FUNCTION;
                            }
                            if (!$static && !$method) {
                                for ($p = $cursor; $p <= $end; $p++) {
                                    if ($tokens[$p]['id'] === T_VARIABLE) {
                                        $public[] = ltrim($tokens[$p]['text'], '$');
                                    }
                                }
                            }
                        }
                    }
                    if ($tokens[$cursor]['id'] !== T_FUNCTION) {
                        continue;
                    }
                    $methodNameIndex = $this->next($tokens, $cursor + 1);
                    if ($methodNameIndex === null || $tokens[$methodNameIndex]['id'] !== T_STRING) {
                        continue;
                    }
                    $methodName = strtolower($tokens[$methodNameIndex]['text']);
                    if (!in_array($methodName, ['attributes', 'rules'], true)) {
                        continue;
                    }
                    $methodOpen = $this->seekText($tokens, $methodNameIndex + 1, '{', $close);
                    if ($methodOpen === null) {
                        continue;
                    }
                    $methodClose = $this->matching($tokens, $methodOpen, '{', '}');
                    if ($methodClose === null) {
                        continue;
                    }
                    if ($methodName === 'attributes') {
                        $attributesMethod = true;
                        $literalAttributes = $this->literalStringListReturn($tokens, $methodOpen, $methodClose);
                    } else {
                        $rules = $this->literalRulesReturn($tokens, $methodOpen, $methodClose);
                    }
                }

                $public = array_values(array_unique($public));
                sort($public);
                $classes[$class] = [
                    'file' => $this->relativePath($context->root(), $file),
                    'parent' => $parent,
                    'public' => $public,
                    'trait' => $trait,
                    'attributes_method' => $attributesMethod,
                    'literal_attributes' => $literalAttributes,
                    'rules' => $rules,
                ];
                $i = $close;
            }
        }
        return $classes;
    }

    /**
     * Resolve inventário completo/incompleto com herança local sem transformar schema implícito em fato.
     *
     * @param string $class Classe local.
     * @param array<string,array{file:string,parent:string|null,public:list<string>,trait:bool,attributes_method:bool,literal_attributes:list<string>|null,rules:list<array{attributes:list<array{name:string,line:int}>,validator:string|null}>|null}> $classes Metadados locais.
     * @param array<string,array{file:string,class:string,parent:string|null,attributes:list<string>,complete:bool,source:string,active_record:bool}> $resolved Cache/resultados.
     * @param array<string,bool> $visited Proteção contra ciclo.
     * @return array{file:string,class:string,parent:string|null,attributes:list<string>,complete:bool,source:string,active_record:bool}|null Inventário ou null para classe não Model.
     */
    private function resolveInventory(string $class, array $classes, array &$resolved, array $visited): ?array
    {
        if (isset($resolved[$class])) {
            return $resolved[$class];
        }
        if (!isset($classes[$class]) || isset($visited[$class])) {
            return null;
        }
        $visited[$class] = true;
        $meta = $classes[$class];
        $parent = $meta['parent'];

        if ($meta['literal_attributes'] !== null) {
            return $resolved[$class] = [
                'file' => $meta['file'], 'class' => $class, 'parent' => $parent,
                'attributes' => $meta['literal_attributes'], 'complete' => true, 'source' => 'literal-attributes',
                'active_record' => $this->isActiveRecord($parent, $classes, []),
            ];
        }
        if ($meta['attributes_method']) {
            return $resolved[$class] = [
                'file' => $meta['file'], 'class' => $class, 'parent' => $parent,
                'attributes' => $meta['public'], 'complete' => false, 'source' => 'unknown',
                'active_record' => $this->isActiveRecord($parent, $classes, []),
            ];
        }
        if ($parent === 'yii\\base\\Model') {
            return $resolved[$class] = [
                'file' => $meta['file'], 'class' => $class, 'parent' => $parent,
                'attributes' => $meta['public'], 'complete' => !$meta['trait'],
                'source' => $meta['trait'] ? 'unknown' : 'public-properties', 'active_record' => false,
            ];
        }
        if ($parent !== null && in_array($parent, self::ACTIVE_RECORD_BASES, true)) {
            return $resolved[$class] = [
                'file' => $meta['file'], 'class' => $class, 'parent' => $parent,
                'attributes' => $meta['public'], 'complete' => false, 'source' => 'unknown', 'active_record' => true,
            ];
        }
        if ($parent === null) {
            return null;
        }

        $parentInventory = $this->resolveInventory($parent, $classes, $resolved, $visited);
        if ($parentInventory === null) {
            return null;
        }
        $attributes = array_values(array_unique(array_merge($parentInventory['attributes'], $meta['public'])));
        sort($attributes);
        $complete = $parentInventory['complete'] && !$meta['trait'];
        return $resolved[$class] = [
            'file' => $meta['file'], 'class' => $class, 'parent' => $parent,
            'attributes' => $attributes, 'complete' => $complete,
            'source' => $complete ? 'inherited' : 'unknown', 'active_record' => $parentInventory['active_record'],
        ];
    }

    /**
     * Testa se uma cadeia local termina em ActiveRecord Yii2 conhecido.
     *
     * @param string|null $parent Parent resolvido.
     * @param array<string,array{parent:string|null}>|array<string,mixed> $classes Índice local.
     * @param array<string,bool> $visited Proteção contra ciclo.
     * @return bool True somente para herança comprovada.
     */
    private function isActiveRecord(?string $parent, array $classes, array $visited): bool
    {
        if ($parent === null) {
            return false;
        }
        if (in_array($parent, self::ACTIVE_RECORD_BASES, true)) {
            return true;
        }
        if (!isset($classes[$parent]) || isset($visited[$parent])) {
            return false;
        }
        $visited[$parent] = true;
        return $this->isActiveRecord($classes[$parent]['parent'] ?? null, $classes, $visited);
    }

    /**
     * Extrai lista literal do único return de attributes().
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $open Chave inicial do método.
     * @param int $close Chave final do método.
     * @return list<string>|null Lista completa ou null.
     */
    private function literalStringListReturn(array $tokens, int $open, int $close): ?array
    {
        $return = $this->soleReturn($tokens, $open, $close);
        if ($return === null) {
            return null;
        }
        $arrayOpen = $this->next($tokens, $return + 1);
        if ($arrayOpen === null || $tokens[$arrayOpen]['text'] !== '[') {
            return null;
        }
        $arrayClose = $this->matching($tokens, $arrayOpen, '[', ']');
        if ($arrayClose === null) {
            return null;
        }
        /** @var list<string> $values */
        $values = [];
        foreach ($this->segments($tokens, $arrayOpen + 1, $arrayClose - 1) as [$start, $end]) {
            $literal = $this->literal($tokens, $start, $end);
            if ($literal === null) {
                return null;
            }
            $values[] = $literal['value'];
        }
        return array_values(array_unique($values));
    }

    /**
     * Extrai as rules arrays literais cujo primeiro item pode ser resolvido estaticamente.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $open Chave inicial do método.
     * @param int $close Chave final do método.
     * @return list<array{attributes:list<array{name:string,line:int}>,validator:string|null}>|null Regras ou null para retorno dinâmico.
     */
    private function literalRulesReturn(array $tokens, int $open, int $close): ?array
    {
        $return = $this->soleReturn($tokens, $open, $close);
        if ($return === null) {
            return null;
        }
        $arrayOpen = $this->next($tokens, $return + 1);
        if ($arrayOpen === null || $tokens[$arrayOpen]['text'] !== '[') {
            return null;
        }
        $arrayClose = $this->matching($tokens, $arrayOpen, '[', ']');
        if ($arrayClose === null) {
            return null;
        }

        /** @var list<array{attributes:list<array{name:string,line:int}>,validator:string|null}> $rules */
        $rules = [];
        foreach ($this->segments($tokens, $arrayOpen + 1, $arrayClose - 1) as [$start, $end]) {
            $first = $this->next($tokens, $start, $end);
            if ($first === null || $tokens[$first]['text'] !== '[') {
                continue;
            }
            $ruleClose = $this->matching($tokens, $first, '[', ']');
            if ($ruleClose === null || $ruleClose > $end) {
                continue;
            }
            $items = $this->segments($tokens, $first + 1, $ruleClose - 1);
            if ($items === []) {
                continue;
            }

            /** @var list<array{name:string,line:int}> $attributes Atributos literais resolvidos para a rule atual. */
            $attributes = [];
            $attributesValid = true;
            $single = $this->literal($tokens, $items[0][0], $items[0][1]);
            if ($single !== null) {
                $attributes[] = ['name' => $single['value'], 'line' => $single['line']];
            } else {
                $attrOpen = $this->next($tokens, $items[0][0], $items[0][1]);
                if ($attrOpen === null || $tokens[$attrOpen]['text'] !== '[') {
                    continue;
                }
                $attrClose = $this->matching($tokens, $attrOpen, '[', ']');
                if ($attrClose === null || $attrClose > $items[0][1]) {
                    continue;
                }
                foreach ($this->segments($tokens, $attrOpen + 1, $attrClose - 1) as [$aStart, $aEnd]) {
                    $literal = $this->literal($tokens, $aStart, $aEnd);
                    if ($literal === null) {
                        $attributesValid = false;
                        break;
                    }
                    $attributes[] = ['name' => $literal['value'], 'line' => $literal['line']];
                }
                if (!$attributesValid || $attributes === []) {
                    continue;
                }
            }

            $validator = isset($items[1]) ? ($this->literal($tokens, $items[1][0], $items[1][1])['value'] ?? null) : null;
            $rules[] = ['attributes' => $attributes, 'validator' => $validator];
        }
        return $rules;
    }

    /**
     * Divide uma lista por vírgulas de primeiro nível respeitando delimitadores aninhados.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return list<array{int,int}> Segmentos não vazios.
     */
    private function segments(array $tokens, int $start, int $end): array
    {
        /** @var list<array{int,int}> $result */
        $result = [];
        $segment = $start;
        $round = $square = $curly = 0;
        for ($i = $start; $i <= $end; $i++) {
            $text = $tokens[$i]['text'];
            $round += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            $square += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
            $curly += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
            if ($text === ',' && $round === 0 && $square === 0 && $curly === 0) {
                if ($this->next($tokens, $segment, $i - 1) !== null) {
                    $result[] = [$segment, $i - 1];
                }
                $segment = $i + 1;
            }
        }
        if ($this->next($tokens, $segment, $end) !== null) {
            $result[] = [$segment, $end];
        }
        return $result;
    }

    /**
     * Resolve string literal isolada, recusando interpolação/escapes ambíguos.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return array{value:string,line:int}|null Literal normalizado.
     */
    private function literal(array $tokens, int $start, int $end): ?array
    {
        $first = $this->next($tokens, $start, $end);
        if ($first === null || $tokens[$first]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $next = $this->next($tokens, $first + 1, $end);
        if ($next !== null) {
            return null;
        }
        $raw = $tokens[$first]['text'];
        if (strlen($raw) < 2 || !in_array($raw[0], ["'", '"'], true) || $raw[strlen($raw) - 1] !== $raw[0]) {
            return null;
        }
        $value = substr($raw, 1, -1);
        if ($raw[0] === '"' && (str_contains($value, '$') || str_contains($value, '\\'))) {
            return null;
        }
        if ($raw[0] === "'") {
            $value = str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }
        return ['value' => $value, 'line' => $tokens[$first]['line']];
    }

    /**
     * Encontra o único return de primeiro nível do método.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $open Chave inicial.
     * @param int $close Chave final.
     * @return int|null Índice do return único.
     */
    private function soleReturn(array $tokens, int $open, int $close): ?int
    {
        $depth = 1;
        $found = null;
        for ($i = $open + 1; $i < $close; $i++) {
            $depth += $tokens[$i]['text'] === '{' ? 1 : ($tokens[$i]['text'] === '}' ? -1 : 0);
            if ($depth === 1 && $tokens[$i]['id'] === T_RETURN) {
                if ($found !== null) {
                    return null;
                }
                $found = $i;
            }
        }
        return $found;
    }

    /**
     * Busca delimitador correspondente em stream tokenizado.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $openIndex Índice de abertura.
     * @param string $open Delimitador inicial.
     * @param string $close Delimitador final.
     * @return int|null Índice de fechamento.
     */
    private function matching(array $tokens, int $openIndex, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
            $depth += $tokens[$i]['text'] === $open ? 1 : ($tokens[$i]['text'] === $close ? -1 : 0);
            if ($depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Busca próximo token significativo, opcionalmente limitado por índice final.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Índice inicial.
     * @param int|null $end Limite inclusivo.
     * @return int|null Índice encontrado.
     */
    private function next(array $tokens, int $start, ?int $end = null): ?int
    {
        $limit = $end ?? count($tokens) - 1;
        for ($i = $start; $i <= $limit; $i++) {
            if (!in_array($tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Busca texto literal de token dentro de um range.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Índice inicial.
     * @param string $text Texto procurado.
     * @param int|null $end Limite exclusivo.
     * @return int|null Índice encontrado.
     */
    private function seekText(array $tokens, int $start, string $text, ?int $end = null): ?int
    {
        $limit = $end ?? count($tokens);
        for ($i = $start; $i < $limit; $i++) {
            if ($tokens[$i]['text'] === $text) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Resolve nome de classe por namespace/imports sem carregar classes do consumidor.
     *
     * @param string $name Nome observado no source.
     * @param array<string,string> $uses Imports alias => FQCN.
     * @param string $namespace Namespace atual.
     * @return string FQCN normalizado.
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $name = trim($name);
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name);
        $head = $parts[0] ?? '';
        if ($head !== '' && isset($uses[$head])) {
            array_shift($parts);
            return $uses[$head] . ($parts === [] ? '' : '\\' . implode('\\', $parts));
        }
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * Lista PHPs dos paths permitidos pelo profile.
     *
     * @param ProjectContext $context Contexto do consumidor.
     * @return list<string> Paths absolutos ordenados.
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
     * Converte path absoluto em path relativo à raiz do consumidor.
     *
     * @param string $root Raiz física.
     * @param string $file Arquivo absoluto.
     * @return string Path relativo quando aplicável.
     */
    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
