<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2ModelRulesAnalyzer.php';

/**
 * Valida metadados literais de Model que referenciam atributos conhecidos.
 *
 * O analyzer reutiliza exclusivamente inventários conclusivos produzidos por
 * Yii2ModelRulesAnalyzer. scenarios(), attributeLabels() e attributeHints()
 * só geram finding quando a chave/atributo é literal e a ausência pode ser
 * provada estaticamente. Shapes dinâmicas permanecem desconhecidas.
 */
final class Yii2ModelMetadataAnalyzer
{
    /**
     * Recebe o provedor de inventários para compartilhar a prova usada por rules().
     *
     * @param Yii2ModelRulesAnalyzer $inventoryAnalyzer Analyzer que resolve atributos conclusivos.
     */
    public function __construct(
        private readonly Yii2ModelRulesAnalyzer $inventoryAnalyzer = new Yii2ModelRulesAnalyzer(),
    ) {
    }

    /**
     * Retorna referências inválidas em scenarios(), attributeLabels() e attributeHints().
     *
     * scenarios() aceita o prefixo ! usado pelo Yii2 para marcar atributo
     * unsafe; a validação remove apenas esse prefixo para consultar o inventário.
     * Arrays/valores dinâmicos são ignorados em vez de tratados como inválidos.
     *
     * @param ProjectContext $context Contexto Yii2 do consumidor.
     * @return list<array{file:string,line:int,model:string,method:string,kind:string,name:string,raw_name:string,scenario:string|null,reason:string,attribute_inventory_complete:true}> Referências conclusivas.
     */
    public function references(ProjectContext $context): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $inventories = $this->inventoryAnalyzer->inventories($context);
        /** @var array<string,array<string,array{attributes:list<string>}>> $targetsByFile Models conclusivos agrupados por arquivo. */
        $targetsByFile = [];
        foreach ($inventories as $class => $inventory) {
            if (!$inventory['complete']) {
                continue;
            }
            $targetsByFile[$inventory['file']][$class] = [
                'attributes' => $inventory['attributes'],
            ];
        }

        /** @var list<array{file:string,line:int,model:string,method:string,kind:string,name:string,raw_name:string,scenario:string|null,reason:string,attribute_inventory_complete:true}> $references */
        $references = [];
        foreach ($targetsByFile as $relativeFile => $targets) {
            $absolute = $context->root() . '/' . $relativeFile;
            if (!is_file($absolute)) {
                continue;
            }

            foreach ($this->metadataMethods((string) file_get_contents($absolute)) as $class => $methods) {
                if (!isset($targets[$class])) {
                    continue;
                }
                $attributes = $targets[$class]['attributes'];

                foreach ($methods['scenarios'] ?? [] as $entry) {
                    $scenario = $entry['key'];
                    if ($scenario === '') {
                        $references[] = $this->reference(
                            $relativeFile,
                            $entry['line'],
                            $class,
                            'scenarios',
                            'scenario-name',
                            '',
                            '',
                            null,
                            'empty',
                        );
                        continue;
                    }

                    foreach ($this->scenarioAttributes($entry['tokens'], $entry['value_start'], $entry['value_end']) as $attribute) {
                        $rawName = $attribute['name'];
                        $name = str_starts_with($rawName, '!') ? substr($rawName, 1) : $rawName;
                        if ($name === '') {
                            $references[] = $this->reference(
                                $relativeFile,
                                $attribute['line'],
                                $class,
                                'scenarios',
                                'scenario-attribute',
                                '',
                                $rawName,
                                $scenario,
                                'empty',
                            );
                            continue;
                        }
                        if (!in_array($name, $attributes, true)) {
                            $references[] = $this->reference(
                                $relativeFile,
                                $attribute['line'],
                                $class,
                                'scenarios',
                                'scenario-attribute',
                                $name,
                                $rawName,
                                $scenario,
                                'not-found',
                            );
                        }
                    }
                }

                foreach (['attributeLabels' => 'attribute-label', 'attributeHints' => 'attribute-hint'] as $method => $kind) {
                    foreach ($methods[$method] ?? [] as $entry) {
                        $name = $entry['key'];
                        if ($name !== '' && in_array($name, $attributes, true)) {
                            continue;
                        }
                        $references[] = $this->reference(
                            $relativeFile,
                            $entry['line'],
                            $class,
                            $method,
                            $kind,
                            $name,
                            $name,
                            null,
                            $name === '' ? 'empty' : 'not-found',
                        );
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
                $left['name'],
            ] <=> [
                $right['file'],
                $right['line'],
                $right['method'],
                $right['name'],
            ],
        );

        return $references;
    }

    /**
     * Materializa o shape comum das evidências retornadas pelo analyzer.
     *
     * @param string $file Path relativo do arquivo.
     * @param int $line Linha da evidência literal.
     * @param string $model FQCN do Model.
     * @param string $method Método de metadata analisado.
     * @param string $kind Família semântica da evidência.
     * @param string $name Nome normalizado para lookup.
     * @param string $rawName Nome literal observado no source.
     * @param string|null $scenario Cenário proprietário do atributo, quando aplicável.
     * @param string $reason Motivo canônico: empty ou not-found.
     * @return array{file:string,line:int,model:string,method:string,kind:string,name:string,raw_name:string,scenario:string|null,reason:string,attribute_inventory_complete:true} Evidência normalizada.
     */
    private function reference(
        string $file,
        int $line,
        string $model,
        string $method,
        string $kind,
        string $name,
        string $rawName,
        ?string $scenario,
        string $reason,
    ): array {
        return [
            'file' => $file,
            'line' => $line,
            'model' => $model,
            'method' => $method,
            'kind' => $kind,
            'name' => $name,
            'raw_name' => $rawName,
            'scenario' => $scenario,
            'reason' => $reason,
            'attribute_inventory_complete' => true,
        ];
    }

    /**
     * Indexa os três métodos suportados por classe e preserva tokens/ranges dos valores.
     *
     * O parser aceita somente return array literal e não executa PHP do consumidor.
     * Classes anônimas e métodos com retorno dinâmico são ignorados.
     *
     * @param string $source Source PHP completo.
     * @return array<string,array<string,list<array{key:string,line:int,tokens:list<array{id:int|null,text:string,line:int}>,value_start:int,value_end:int}>>> Métodos por FQCN.
     */
    private function metadataMethods(string $source): array
    {
        $namespace = preg_match('/\bnamespace\s+([^;{]+)\s*[;{]/', $source, $match) === 1
            ? trim((string) $match[1], " \t\n\r\0\x0B\\")
            : '';

        /** @var list<array{id:int|null,text:string,line:int}> $tokens Stream simplificado com linha preservada. */
        $tokens = [];
        foreach (token_get_all($source) as $rawToken) {
            $tokens[] = [
                'id' => is_array($rawToken) ? $rawToken[0] : null,
                'text' => is_array($rawToken) ? $rawToken[1] : $rawToken,
                'line' => is_array($rawToken) ? $rawToken[2] : ($tokens === [] ? 1 : $tokens[array_key_last($tokens)]['line']),
            ];
        }

        /** @var array<string,array<string,list<array{key:string,line:int,tokens:list<array{id:int|null,text:string,line:int}>,value_start:int,value_end:int}>>> $classes */
        $classes = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_CLASS) {
                continue;
            }
            $nameIndex = $this->next($tokens, $i + 1);
            if ($nameIndex === null || $tokens[$nameIndex]['id'] !== T_STRING) {
                continue;
            }
            $classOpen = $this->seekText($tokens, $nameIndex + 1, '{');
            if ($classOpen === null) {
                continue;
            }
            $classClose = $this->matching($tokens, $classOpen, '{', '}');
            if ($classClose === null) {
                continue;
            }

            $short = $tokens[$nameIndex]['text'];
            $class = $namespace === '' ? $short : $namespace . '\\' . $short;
            $depth = 1;
            for ($cursor = $classOpen + 1; $cursor < $classClose; $cursor++) {
                $text = $tokens[$cursor]['text'];
                if ($text === '{') {
                    $depth++;
                    continue;
                }
                if ($text === '}') {
                    $depth--;
                    continue;
                }
                if ($depth !== 1 || $tokens[$cursor]['id'] !== T_FUNCTION) {
                    continue;
                }

                $methodNameIndex = $this->next($tokens, $cursor + 1);
                if ($methodNameIndex === null || $tokens[$methodNameIndex]['id'] !== T_STRING) {
                    continue;
                }
                $method = $tokens[$methodNameIndex]['text'];
                if (!in_array($method, ['scenarios', 'attributeLabels', 'attributeHints'], true)) {
                    continue;
                }
                $methodOpen = $this->seekText($tokens, $methodNameIndex + 1, '{', $classClose);
                if ($methodOpen === null) {
                    continue;
                }
                $methodClose = $this->matching($tokens, $methodOpen, '{', '}');
                if ($methodClose === null) {
                    continue;
                }

                $entries = $this->literalKeyedArrayReturn($tokens, $methodOpen, $methodClose);
                if ($entries !== null) {
                    $classes[$class][$method] = $entries;
                }
            }
            $i = $classClose;
        }

        return $classes;
    }

    /**
     * Extrai itens string literal => expressão de um único return array literal.
     *
     * Itens com chave dinâmica, spread ou sem seta ficam desconhecidos. O valor
     * não é interpretado nesta etapa; seu range é preservado para scenarios().
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $open Chave inicial do método.
     * @param int $close Chave final do método.
     * @return list<array{key:string,line:int,tokens:list<array{id:int|null,text:string,line:int}>,value_start:int,value_end:int}>|null Itens literais ou null para retorno dinâmico.
     */
    private function literalKeyedArrayReturn(array $tokens, int $open, int $close): ?array
    {
        $return = $this->soleReturn($tokens, $open, $close);
        if ($return === null) {
            return null;
        }
        $arrayOpen = $this->next($tokens, $return + 1, $close);
        if ($arrayOpen === null || $tokens[$arrayOpen]['text'] !== '[') {
            return null;
        }
        $arrayClose = $this->matching($tokens, $arrayOpen, '[', ']');
        if ($arrayClose === null || $arrayClose > $close) {
            return null;
        }

        /** @var list<array{key:string,line:int,tokens:list<array{id:int|null,text:string,line:int}>,value_start:int,value_end:int}> $entries */
        $entries = [];
        foreach ($this->segments($tokens, $arrayOpen + 1, $arrayClose - 1) as [$start, $end]) {
            $arrow = $this->topLevelArrow($tokens, $start, $end);
            if ($arrow === null) {
                continue;
            }
            $key = $this->literal($tokens, $start, $arrow - 1);
            $valueStart = $this->next($tokens, $arrow + 1, $end);
            if ($key === null || $valueStart === null) {
                continue;
            }
            $entries[] = [
                'key' => $key['value'],
                'line' => $key['line'],
                'tokens' => $tokens,
                'value_start' => $valueStart,
                'value_end' => $end,
            ];
        }
        return $entries;
    }

    /**
     * Extrai atributos string de um valor literal de scenario.
     *
     * Um scenario cujo valor não é array literal é tratado como desconhecido.
     * Itens não literais dentro do array também são ignorados individualmente.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Início do valor.
     * @param int $end Fim do valor.
     * @return list<array{name:string,line:int}> Atributos literais observados.
     */
    private function scenarioAttributes(array $tokens, int $start, int $end): array
    {
        $arrayOpen = $this->next($tokens, $start, $end);
        if ($arrayOpen === null || $tokens[$arrayOpen]['text'] !== '[') {
            return [];
        }
        $arrayClose = $this->matching($tokens, $arrayOpen, '[', ']');
        if ($arrayClose === null || $arrayClose > $end) {
            return [];
        }

        /** @var list<array{name:string,line:int}> $attributes */
        $attributes = [];
        foreach ($this->segments($tokens, $arrayOpen + 1, $arrayClose - 1) as [$itemStart, $itemEnd]) {
            $literal = $this->literal($tokens, $itemStart, $itemEnd);
            if ($literal !== null) {
                $attributes[] = ['name' => $literal['value'], 'line' => $literal['line']];
            }
        }
        return $attributes;
    }

    /**
     * Localiza a seta de array no primeiro nível de um item.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return int|null Índice do token de seta.
     */
    private function topLevelArrow(array $tokens, int $start, int $end): ?int
    {
        $round = $square = $curly = 0;
        for ($i = $start; $i <= $end; $i++) {
            $text = $tokens[$i]['text'];
            $round += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            $square += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
            $curly += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
            if ($tokens[$i]['id'] === T_DOUBLE_ARROW && $round === 0 && $square === 0 && $curly === 0) {
                return $i;
            }
        }
        return null;
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
     * Resolve string literal isolada e preserva sua linha.
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
        if ($this->next($tokens, $first + 1, $end) !== null) {
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
     * @param int $openIndex Índice da abertura.
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
     * Busca próximo token significativo dentro do range informado.
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
}
