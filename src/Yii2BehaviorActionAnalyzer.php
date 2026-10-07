<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2SemanticModel.php';

/**
 * Extrai referências literais a actions declaradas em `Controller::behaviors()`.
 *
 * O analisador cobre configurações estáticas de ActionFilter, filtros de autenticação,
 * AccessControl e VerbFilter sem executar código do consumidor. Cada referência recebe
 * estado tri-state de existência: true/false quando o inventário de actions é conclusivo
 * e null quando herança ou composição dinâmica impede prova segura.
 *
 * Esta classe não produz Finding nem define severidade. Ela preserva a separação entre
 * coleta semântica e política aplicada por Yii2RuleEngine.
 */
final class Yii2BehaviorActionAnalyzer
{
    /**
     * Localiza referências de behavior e determina se cada action existe no controller.
     *
     * Listas `only`/`except` aceitam wildcards no Yii2 e, por isso, padrões contendo
     * `*`, `?` ou `[` são ignorados. `optional` é tratado apenas para filtros de auth.
     * Em AccessControl, `rules[].actions` é lista literal de IDs; em VerbFilter, os IDs
     * são as chaves de `actions` e `*` representa todas as actions.
     *
     * @param ProjectContext $context Contexto Yii2 que fornece a raiz física do consumidor.
     * @param Yii2SemanticModel $model Inventário semântico de controllers/actions já coletado.
     * @return list<array{
     *   controller:string,
     *   file:string,
     *   action:string,
     *   line:int,
     *   reference:string,
     *   behavior_class:string,
     *   exists:bool|null
     * }> Referências estáticas encontradas em ordem de controller/declaração.
     */
    public function references(ProjectContext $context, Yii2SemanticModel $model): array
    {
        /** @var list<array{controller:string,file:string,action:string,line:int,reference:string,behavior_class:string,exists:bool|null}> $references */
        $references = [];
        /** @var array<string,array{actions:list<string>,file:string}> $controllersByClass Inventário indexado para herança local. */
        $controllersByClass = [];
        /** @var array<string,string> $classParents Herança local comprovada para behaviors/rules customizados. */
        $classParents = $this->localClassParents($context);

        foreach ($model->controllers() as $controller) {
            $controllersByClass[$controller['class']] = [
                'actions' => $controller['actions'],
                'file' => $controller['file'],
            ];
        }

        foreach ($model->controllers() as $controller) {
            $absolute = $context->root() . '/' . $controller['file'];
            if (!is_file($absolute)) {
                continue;
            }

            $source = (string) file_get_contents($absolute);
            $method = $this->methodBodyMetadata($source, 'behaviors');
            if ($method === null) {
                continue;
            }

            $array = $this->parseReturnedArray($method['body'], $method['opening_line']);
            if ($array === null) {
                continue;
            }

            $uses = $this->useMap($source);
            $namespace = $this->namespaceName($source);
            [$knownActions, $inventoryComplete] = $this->availableActions(
                $controller['class'],
                $context,
                $controllersByClass,
                [],
            );

            // A coleta de referências independe da completude; apenas o campo `exists`
            // vira null quando não há prova suficiente de ausência da action.
            foreach ($this->behaviorReferences($array, $uses, $namespace, $classParents) as $reference) {
                $exists = $inventoryComplete ? in_array($reference['action'], $knownActions, true) : null;
                $references[] = [
                    'controller' => $controller['class'],
                    'file' => $controller['file'],
                    'action' => $reference['action'],
                    'line' => $reference['line'],
                    'reference' => $reference['reference'],
                    'behavior_class' => $reference['behavior_class'],
                    'exists' => $exists,
                ];
            }
        }

        return $references;
    }

    /**
     * Calcula actions conhecidas incluindo herança entre controllers do próprio projeto.
     *
     * Quando a classe pai não está no inventário local e não é um controller-base do
     * Yii2, o resultado continua útil para presença positiva, mas `complete=false`
     * impede que ausência seja promovida a finding. Ciclos também degradam para unknown.
     *
     * @param string $controllerClass Classe totalmente qualificada do controller atual.
     * @param ProjectContext $context Contexto que resolve os arquivos físicos.
     * @param array<string,array{actions:list<string>,file:string}> $controllersByClass Índice local por FQCN.
     * @param list<string> $visited Classes já percorridas na cadeia de herança.
     * @return array{0:list<string>,1:bool} União de actions e completude do inventário.
     */
    private function availableActions(
        string $controllerClass,
        ProjectContext $context,
        array $controllersByClass,
        array $visited,
    ): array {
        if (in_array($controllerClass, $visited, true)) {
            return [[], false];
        }
        $visited[] = $controllerClass;

        $current = $controllersByClass[$controllerClass] ?? null;
        if ($current === null) {
            return [[], false];
        }

        /** @var list<string> $actions Actions próprias e herdadas conhecidas. */
        $actions = $current['actions'];
        $absolute = $context->root() . '/' . $current['file'];
        if (!is_file($absolute)) {
            return [$actions, false];
        }

        $source = (string) file_get_contents($absolute);
        $complete = $this->actionDeclarationIsStatic($source);
        $parent = $this->parentControllerClass($source);
        if ($parent === null) {
            sort($actions);
            return [array_values(array_unique($actions)), $complete];
        }

        $uses = $this->useMap($source);
        $namespace = $this->namespaceName($source);
        $resolvedParent = $this->resolveName($parent, $uses, $namespace);

        // Os controllers-base do framework não adicionam actions de aplicação por padrão;
        // pais externos/customizados permanecem unknown porque podem fornecer actions.
        if (in_array(strtolower($resolvedParent), ['yii\\base\\controller', 'yii\\web\\controller', 'yii\\console\\controller'], true)) {
            sort($actions);
            return [array_values(array_unique($actions)), $complete];
        }
        if (!isset($controllersByClass[$resolvedParent])) {
            sort($actions);
            return [array_values(array_unique($actions)), false];
        }

        [$parentActions, $parentComplete] = $this->availableActions(
            $resolvedParent,
            $context,
            $controllersByClass,
            $visited,
        );
        $actions = array_values(array_unique([...$actions, ...$parentActions]));
        sort($actions);

        return [$actions, $complete && $parentComplete];
    }

    /**
     * Verifica se `actions()` usa uma forma estática que o modelo consegue inventariar.
     *
     * Ausência do método é conclusiva. Composição via `parent::actions()`, spread,
     * `array_merge()` ou retorno indireto é marcada como incompleta para evitar falso
     * positivo quando uma action referenciada nasce dinamicamente.
     *
     * @param string $source Código-fonte completo do controller.
     * @return bool True quando a declaração de external actions é estaticamente enumerável.
     */
    private function actionDeclarationIsStatic(string $source): bool
    {
        $method = $this->methodBodyMetadata($source, 'actions');
        if ($method === null) {
            return true;
        }

        $body = $method['body'];
        if (preg_match('/\bparent\s*::\s*actions\s*\(/i', $body) === 1) {
            return false;
        }
        if (preg_match('/\barray_merge\s*\(/i', $body) === 1 || str_contains($body, '...')) {
            return false;
        }

        return preg_match('/\breturn\s*(?:\[|array\s*\()/i', $body) === 1;
    }

    /**
     * Extrai a classe pai declarada no header do controller, quando existir.
     *
     * @param string $source Código-fonte completo do controller.
     * @return string|null Nome textual após `extends` ou null quando não há herança explícita.
     */
    private function parentControllerClass(string $source): ?string
    {
        if (preg_match('/\bclass\s+[A-Za-z_][A-Za-z0-9_]*Controller\s+extends\s+([^\s{]+)/', $source, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }

    /**
     * Converte o array retornado por `behaviors()` em referências de action relevantes.
     *
     * Apenas behaviors com classe literal resolvível e arrays estáticos são analisados.
     * Classes desconhecidas ou configurações dinâmicas são ignoradas, pois inferir que
     * `only`/`except` possuem semântica de ActionFilter sem prova elevaria falso positivo.
     *
     * @param array<string,mixed> $array Nó raiz do array estático retornado.
     * @param array<string,string> $uses Imports simples do arquivo controller.
     * @param string $namespace Namespace declarado pelo controller.
     * @param array<string,string> $classParents Mapa local classe => parent resolvido.
     * @return list<array{action:string,line:int,reference:string,behavior_class:string}> Referências literais reconhecidas.
     */
    private function behaviorReferences(array $array, array $uses, string $namespace, array $classParents): array
    {
        /** @var list<array{action:string,line:int,reference:string,behavior_class:string}> $references */
        $references = [];

        foreach ($array['items'] ?? [] as $behaviorItem) {
            $config = $behaviorItem['value'] ?? null;
            if (!is_array($config) || ($config['kind'] ?? null) !== 'array') {
                continue;
            }

            $fields = $this->itemsByKey($config);
            $classNode = $fields['__class'] ?? $fields['class'] ?? null;
            if (!is_array($classNode)) {
                continue;
            }

            $behaviorClass = $this->classNameFromNode($classNode, $uses, $namespace);
            if ($behaviorClass === null) {
                continue;
            }

            $kind = $this->behaviorKind($behaviorClass, $classParents);
            if ($kind === null) {
                continue;
            }

            if ($kind === 'verb') {
                $actionsNode = $fields['actions'] ?? null;
                if (is_array($actionsNode)) {
                    foreach ($this->arrayStringKeys($actionsNode) as $action) {
                        if ($action['value'] === '*') {
                            continue;
                        }
                        $references[] = [
                            'action' => $action['value'],
                            'line' => $action['line'],
                            'reference' => 'actions de VerbFilter',
                            'behavior_class' => $behaviorClass,
                        ];
                    }
                }
                continue;
            }

            foreach (['only', 'except'] as $option) {
                $optionNode = $fields[$option] ?? null;
                if (!is_array($optionNode)) {
                    continue;
                }
                foreach ($this->arrayStringValues($optionNode, true) as $action) {
                    $references[] = [
                        'action' => $action['value'],
                        'line' => $action['line'],
                        'reference' => $option . ' de ' . $this->shortClassName($behaviorClass),
                        'behavior_class' => $behaviorClass,
                    ];
                }
            }

            if ($kind === 'auth') {
                $optionalNode = $fields['optional'] ?? null;
                if (is_array($optionalNode)) {
                    foreach ($this->arrayStringValues($optionalNode, true) as $action) {
                        $references[] = [
                            'action' => $action['value'],
                            'line' => $action['line'],
                            'reference' => 'optional de ' . $this->shortClassName($behaviorClass),
                            'behavior_class' => $behaviorClass,
                        ];
                    }
                }
            }

            if ($kind === 'access') {
                $rulesNode = $fields['rules'] ?? null;
                if (is_array($rulesNode)) {
                    foreach ($this->accessRuleReferences($rulesNode, $uses, $namespace, $behaviorClass, $classParents) as $reference) {
                        $references[] = $reference;
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Extrai `rules[].actions` de AccessControl quando a regra é AccessRule padrão/literal.
     *
     * Uma regra sem `class` usa AccessRule por padrão e é segura para análise. Quando
     * `class`/`__class` existe, somente uma classe resolvida como AccessRule é aceita.
     * Wildcards não são descartados aqui porque `AccessRule::actions` usa IDs literais.
     *
     * @param array<string,mixed> $rulesNode Nó array de `rules`.
     * @param array<string,string> $uses Imports simples do arquivo.
     * @param string $namespace Namespace do controller.
     * @param string $behaviorClass Classe AccessControl dona das regras.
     * @param array<string,string> $classParents Mapa local usado para reconhecer subclasses de AccessRule.
     * @return list<array{action:string,line:int,reference:string,behavior_class:string}> Referências de regras de acesso.
     */
    private function accessRuleReferences(
        array $rulesNode,
        array $uses,
        string $namespace,
        string $behaviorClass,
        array $classParents,
    ): array {
        /** @var list<array{action:string,line:int,reference:string,behavior_class:string}> $references */
        $references = [];
        if (($rulesNode['kind'] ?? null) !== 'array') {
            return $references;
        }

        foreach ($rulesNode['items'] ?? [] as $ruleItem) {
            $rule = $ruleItem['value'] ?? null;
            if (!is_array($rule) || ($rule['kind'] ?? null) !== 'array') {
                continue;
            }

            $fields = $this->itemsByKey($rule);
            $classNode = $fields['__class'] ?? $fields['class'] ?? null;
            if (is_array($classNode)) {
                $ruleClass = $this->classNameFromNode($classNode, $uses, $namespace);
                if ($ruleClass === null || !$this->isClassOrSubclassOf($ruleClass, 'yii\\filters\\AccessRule', $classParents)) {
                    continue;
                }
            }

            $actionsNode = $fields['actions'] ?? null;
            if (!is_array($actionsNode)) {
                continue;
            }
            foreach ($this->arrayStringValues($actionsNode, false) as $action) {
                $references[] = [
                    'action' => $action['value'],
                    'line' => $action['line'],
                    'reference' => 'rules[].actions de ' . $this->shortClassName($behaviorClass),
                    'behavior_class' => $behaviorClass,
                ];
            }
        }

        return $references;
    }

    /**
     * Classifica classes de behavior conhecidas por contrato do Yii2.
     *
     * Imports são resolvidos antes desta etapa. Classes oficiais sob `yii\\filters\\`
     * são tratadas como ActionFilter, com exceções especializadas para VerbFilter,
     * AccessControl e namespace de autenticação. Alguns nomes curtos oficiais são
     * aceitos para controllers sem `use` explícito.
     *
     * @param string $class Classe resolvida ou nome curto literal.
     * @return 'verb'|'access'|'auth'|'action-filter'|null Família semântica reconhecida.
     */
    private function behaviorKind(string $class, array $classParents): ?string
    {
        if ($this->isClassOrSubclassOf($class, 'yii\\filters\\VerbFilter', $classParents)) {
            return 'verb';
        }
        if ($this->isClassOrSubclassOf($class, 'yii\\filters\\AccessControl', $classParents)) {
            return 'access';
        }
        if ($this->isClassOrSubclassOf($class, 'yii\\filters\\auth\\AuthMethod', $classParents)) {
            return 'auth';
        }
        if ($this->isClassOrSubclassOf($class, 'yii\\base\\ActionFilter', $classParents)) {
            return 'action-filter';
        }

        // Classes oficiais conhecidas continuam reconhecidas mesmo fora do mapa local.
        $normalized = strtolower(ltrim($class, '\\'));
        if (str_starts_with($normalized, 'yii\\filters\\auth\\')) {
            return 'auth';
        }
        if (str_starts_with($normalized, 'yii\\filters\\')) {
            return 'action-filter';
        }

        return null;
    }

    /**
     * Verifica herança local até uma classe-base Yii2 conhecida.
     *
     * O mapa não tenta carregar autoload/vendor: uma classe customizada só é
     * classificada quando toda a cadeia necessária é observável no consumidor.
     * Ciclos e parents externos não conhecidos encerram a prova como false.
     *
     * @param string $class Classe candidata.
     * @param string $expectedBase Classe-base canônica do Yii2.
     * @param array<string,string> $classParents Mapa local classe => parent.
     * @return bool True somente quando igualdade/herança pode ser demonstrada.
     */
    private function isClassOrSubclassOf(string $class, string $expectedBase, array $classParents): bool
    {
        $current = ltrim($class, '\\');
        $expected = strtolower(ltrim($expectedBase, '\\'));
        /** @var array<string,bool> $visited Proteção contra ciclos locais. */
        $visited = [];

        while ($current !== '') {
            $normalized = strtolower($current);
            if ($normalized === $expected) {
                return true;
            }
            if ($expected === 'yii\\filters\\auth\\authmethod'
                && str_starts_with($normalized, 'yii\\filters\\auth\\')) {
                return true;
            }
            if ($expected === 'yii\\base\\actionfilter'
                && str_starts_with($normalized, 'yii\\filters\\')
                && $normalized !== 'yii\\filters\\accessrule') {
                return true;
            }
            if (isset($visited[$normalized])) {
                return false;
            }
            $visited[$normalized] = true;

            $parent = $classParents[$normalized] ?? null;
            if ($parent === null) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Indexa classes locais e seus parents sem executar autoload do consumidor.
     *
     * Arquivos com múltiplos namespaces são ignorados porque o resolver textual
     * simples não conseguiria associar imports com segurança a cada declaração.
     *
     * @param ProjectContext $context Contexto que limita a árvore analisável.
     * @return array<string,string> Mapa lowercase FQCN => parent FQCN resolvido.
     */
    private function localClassParents(ProjectContext $context): array
    {
        /** @var array<string,string> $parents */
        $parents = [];

        foreach ($this->phpFiles($context) as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all('/\\bnamespace\\s+([^;{]+)[;{]/', $source, $namespaceMatches) > 1) {
                continue;
            }

            $namespace = $this->namespaceName($source);
            $uses = $this->useMap($source);
            if (preg_match_all(
                '/\\b(?:abstract\\s+|final\\s+)?class\\s+([A-Za-z_][A-Za-z0-9_]*)\\s+extends\\s+([^\\s{]+)/',
                $source,
                $matches,
                PREG_SET_ORDER,
            ) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $class = $namespace === '' ? $match[1] : $namespace . '\\' . $match[1];
                $parent = $this->resolveName($match[2], $uses, $namespace);
                $parents[strtolower(ltrim($class, '\\'))] = ltrim($parent, '\\');
            }
        }

        return $parents;
    }

    /**
     * Lista arquivos PHP somente dentro dos paths permitidos pelo profile.
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
     * Indexa os itens de um nó array pelas chaves string estáticas.
     *
     * @param array<string,mixed> $arrayNode Nó produzido pelo parser estático.
     * @return array<string,array<string,mixed>> Mapa chave => nó de valor.
     */
    private function itemsByKey(array $arrayNode): array
    {
        /** @var array<string,array<string,mixed>> $fields */
        $fields = [];
        foreach ($arrayNode['items'] ?? [] as $item) {
            $key = $item['key'] ?? null;
            $value = $item['value'] ?? null;
            if (is_string($key) && is_array($value)) {
                $fields[$key] = $value;
            }
        }
        return $fields;
    }

    /**
     * Retorna valores string não nomeados de um array literal de IDs de action.
     *
     * @param array<string,mixed> $arrayNode Nó array estático.
     * @param bool $ignoreWildcards Se true, padrões de ActionFilter são ignorados.
     * @return list<array{value:string,line:int}> Strings literais elegíveis.
     */
    private function arrayStringValues(array $arrayNode, bool $ignoreWildcards): array
    {
        /** @var list<array{value:string,line:int}> $values */
        $values = [];
        if (($arrayNode['kind'] ?? null) !== 'array') {
            return $values;
        }

        foreach ($arrayNode['items'] ?? [] as $item) {
            if (($item['key'] ?? null) !== null) {
                continue;
            }
            $node = $item['value'] ?? null;
            if (!is_array($node) || ($node['kind'] ?? null) !== 'string') {
                continue;
            }
            $value = (string) ($node['value'] ?? '');
            if ($value === '' || ($ignoreWildcards && strpbrk($value, '*?[') !== false)) {
                continue;
            }
            $values[] = ['value' => $value, 'line' => (int) ($node['line'] ?? 0)];
        }

        return $values;
    }

    /**
     * Retorna chaves string de primeiro nível usadas por VerbFilter::actions.
     *
     * @param array<string,mixed> $arrayNode Nó array estático.
     * @return list<array{value:string,line:int}> Chaves literais e linhas de declaração.
     */
    private function arrayStringKeys(array $arrayNode): array
    {
        /** @var list<array{value:string,line:int}> $keys */
        $keys = [];
        if (($arrayNode['kind'] ?? null) !== 'array') {
            return $keys;
        }

        foreach ($arrayNode['items'] ?? [] as $item) {
            $key = $item['key'] ?? null;
            if (!is_string($key) || $key === '') {
                continue;
            }
            $keys[] = ['value' => $key, 'line' => (int) ($item['key_line'] ?? 0)];
        }
        return $keys;
    }

    /**
     * Resolve uma expressão de classe literal (`Foo::class` ou string) para nome canônico.
     *
     * @param array<string,mixed> $node Nó de valor da propriedade `class`/`__class`.
     * @param array<string,string> $uses Imports simples do arquivo.
     * @param string $namespace Namespace corrente.
     * @return string|null Classe resolvida ou null para expressão dinâmica.
     */
    private function classNameFromNode(array $node, array $uses, string $namespace): ?string
    {
        $kind = $node['kind'] ?? null;
        $value = trim((string) ($node['value'] ?? ''));
        if ($kind === 'string' && $value !== '') {
            return $this->resolveName($value, $uses, $namespace);
        }
        if ($kind !== 'expr' || preg_match('/^(.+?)\s*::\s*class$/i', $value, $match) !== 1) {
            return null;
        }

        return $this->resolveName(trim($match[1]), $uses, $namespace);
    }

    /**
     * Resolve nome curto/qualificado usando imports simples e namespace do arquivo.
     *
     * @param string $name Nome textual de classe sem `::class`.
     * @param array<string,string> $uses Mapa alias => FQCN.
     * @param string $namespace Namespace corrente.
     * @return string Nome normalizado sem barra inicial.
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

        return $namespace === '' ? $name : ($uses[$name] ?? $namespace . '\\' . $name);
    }

    /**
     * Coleta imports `use Foo\\Bar [as Alias];` simples do arquivo controller.
     *
     * Group use e imports de função/constante são ignorados deliberadamente porque
     * não são necessários para resolver as configurações de behavior suportadas.
     *
     * @param string $source Código-fonte completo.
     * @return array<string,string> Mapa alias/nome curto => FQCN sem barra inicial.
     */
    private function useMap(string $source): array
    {
        /** @var array<string,string> $uses */
        $uses = [];
        if (preg_match_all('/^\s*use\s+(?!function\b|const\b)([^;{}]+);/mi', $source, $matches) === 0) {
            return $uses;
        }

        foreach ($matches[1] as $declaration) {
            $declaration = trim((string) $declaration);
            if ($declaration === '' || str_contains($declaration, '{') || str_contains($declaration, ',')) {
                continue;
            }
            if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $declaration, $aliasMatch) === 1) {
                $fqcn = ltrim(trim($aliasMatch[1]), '\\');
                $uses[$aliasMatch[2]] = $fqcn;
                continue;
            }

            $fqcn = ltrim($declaration, '\\');
            $uses[$this->shortClassName($fqcn)] = $fqcn;
        }

        return $uses;
    }

    /**
     * Retorna o namespace declarado no arquivo, ou string vazia no namespace global.
     *
     * @param string $source Código-fonte completo.
     * @return string Namespace sem barra inicial.
     */
    private function namespaceName(string $source): string
    {
        return preg_match('/\bnamespace\s+([^;{]+)[;{]/', $source, $match) === 1
            ? trim($match[1])
            : '';
    }

    /**
     * Retorna o nome curto de uma classe qualificada.
     *
     * @param string $class Nome de classe com ou sem namespace.
     * @return string Segmento após a última barra invertida.
     */
    private function shortClassName(string $class): string
    {
        $parts = explode('\\', trim($class, '\\'));
        return (string) end($parts);
    }

    /**
     * Extrai corpo e linha da chave de abertura de um método nomeado.
     *
     * O balanceamento textual é suficiente para a camada conservadora atual; quando
     * não há declaração/corpo inequívoco, retorna null e nenhuma regra é promovida.
     *
     * @param string $source Código-fonte PHP completo.
     * @param string $method Nome exato do método sem parênteses.
     * @return array{body:string,opening_line:int}|null Corpo e linha da chave inicial.
     */
    private function methodBodyMetadata(string $source, string $method): ?array
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
            } elseif ($source[$cursor] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [
                        'body' => substr($source, $opening + 1, $cursor - $opening - 1),
                        'opening_line' => substr_count(substr($source, 0, $opening), "\n") + 1,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Parseia somente o array literal retornado pelo corpo de um método.
     *
     * O parser não avalia PHP. Ele reconhece `return [...]`/`return array(...)`,
     * strings, expressões opacas e arrays aninhados, preservando linhas dos literais.
     * Qualquer retorno que não comece por array literal é tratado como desconhecido.
     *
     * @param string $body Corpo textual do método sem chaves externas.
     * @param int $openingLine Linha 1-based da chave de abertura do método no arquivo.
     * @return array<string,mixed>|null Nó array raiz ou null para retorno dinâmico.
     */
    private function parseReturnedArray(string $body, int $openingLine): ?array
    {
        $tokens = $this->significantTokens("<?php\n" . $body, $openingLine);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (($tokens[$index]['id'] ?? null) !== T_RETURN) {
                continue;
            }

            $cursor = $index + 1;
            if (($tokens[$cursor]['id'] ?? null) === T_ARRAY) {
                $cursor++;
            }
            if (!isset($tokens[$cursor]) || !in_array($tokens[$cursor]['text'], ['[', '('], true)) {
                return null;
            }

            return $this->parseArrayNode($tokens, $cursor);
        }

        return null;
    }

    /**
     * Converte tokens PHP em um nó array recursivo sem executar expressões.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens significativos.
     * @param int $cursor Índice mutável posicionado em `[` ou `(`.
     * @return array<string,mixed> Nó `kind=array` com itens chave/valor.
     */
    private function parseArrayNode(array $tokens, int &$cursor): array
    {
        $opening = $tokens[$cursor]['text'];
        $closing = $opening === '[' ? ']' : ')';
        $line = $tokens[$cursor]['line'];
        $cursor++;
        /** @var list<array{key:string|null,key_line:int,value:array<string,mixed>}> $items */
        $items = [];

        while (isset($tokens[$cursor]) && $tokens[$cursor]['text'] !== $closing) {
            if ($tokens[$cursor]['text'] === ',') {
                $cursor++;
                continue;
            }

            $first = $this->parseValueNode($tokens, $cursor, [$closing, ',', '=>']);
            $key = null;
            $keyLine = (int) ($first['line'] ?? 0);
            $value = $first;

            if (isset($tokens[$cursor]) && $tokens[$cursor]['text'] === '=>') {
                if (($first['kind'] ?? null) === 'string') {
                    $key = (string) ($first['value'] ?? '');
                }
                $cursor++;
                $value = $this->parseValueNode($tokens, $cursor, [$closing, ',']);
            }

            $items[] = [
                'key' => $key,
                'key_line' => $keyLine,
                'value' => $value,
            ];

            if (isset($tokens[$cursor]) && $tokens[$cursor]['text'] === ',') {
                $cursor++;
            }
        }

        if (isset($tokens[$cursor]) && $tokens[$cursor]['text'] === $closing) {
            $cursor++;
        }

        return ['kind' => 'array', 'value' => null, 'line' => $line, 'items' => $items];
    }

    /**
     * Parseia um valor estático simples ou preserva expressão dinâmica como texto opaco.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens significativos.
     * @param int $cursor Índice mutável do primeiro token do valor.
     * @param list<string> $stops Delimitadores de nível atual que encerram expressão.
     * @return array<string,mixed> Nó com kind string/array/expr/unknown.
     */
    private function parseValueNode(array $tokens, int &$cursor, array $stops): array
    {
        if (!isset($tokens[$cursor])) {
            return ['kind' => 'unknown', 'value' => null, 'line' => 0, 'items' => []];
        }

        $token = $tokens[$cursor];
        if ($token['text'] === '[') {
            return $this->parseArrayNode($tokens, $cursor);
        }
        if (($token['id'] ?? null) === T_ARRAY && isset($tokens[$cursor + 1]) && $tokens[$cursor + 1]['text'] === '(') {
            $cursor++;
            return $this->parseArrayNode($tokens, $cursor);
        }
        if (($token['id'] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
            $cursor++;
            return [
                'kind' => 'string',
                'value' => $this->decodeStringLiteral($token['text']),
                'line' => $token['line'],
                'items' => [],
            ];
        }

        $line = $token['line'];
        $depth = 0;
        $expression = '';
        while (isset($tokens[$cursor])) {
            $text = $tokens[$cursor]['text'];
            if ($depth === 0 && in_array($text, $stops, true)) {
                break;
            }
            if (in_array($text, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [']', ')', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            $expression .= $text;
            $cursor++;
        }

        $expression = trim($expression);
        return [
            'kind' => $expression === '' ? 'unknown' : 'expr',
            'value' => $expression === '' ? null : $expression,
            'line' => $line,
            'items' => [],
        ];
    }

    /**
     * Remove aspas de um literal tokenizado sem avaliar interpolação ou código PHP.
     *
     * @param string $literal Token T_CONSTANT_ENCAPSED_STRING completo.
     * @return string Conteúdo textual decodificado de escapes simples.
     */
    private function decodeStringLiteral(string $literal): string
    {
        if (strlen($literal) < 2) {
            return $literal;
        }
        $quote = $literal[0];
        $value = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }

        return stripcslashes($value);
    }

    /**
     * Remove whitespace/comentários dos tokens mantendo texto, tipo e linha de origem.
     *
     * A linha é convertida do corpo isolado para a linha real do arquivo usando a
     * linha da chave de abertura. Tokens de pontuação herdam a linha corrente.
     *
     * @param string $php Fragmento iniciado por `<?php` para token_get_all().
     * @param int $openingLine Linha real da chave de abertura do método.
     * @return list<array{id:int|null,text:string,line:int}> Stream compacto para o parser.
     */
    private function significantTokens(string $php, int $openingLine): array
    {
        /** @var list<array{id:int|null,text:string,line:int}> $result */
        $result = [];
        $raw = token_get_all($php);
        $currentLine = 1;

        foreach ($raw as $token) {
            if (is_array($token)) {
                $id = $token[0];
                $text = $token[1];
                $line = $token[2];
                $currentLine = $line + substr_count($text, "\n");
                if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $result[] = [
                    'id' => $id,
                    'text' => $text,
                    'line' => $openingLine + $line - 2,
                ];
                continue;
            }

            $line = $currentLine;
            $currentLine += substr_count($token, "\n");
            if (trim($token) === '') {
                continue;
            }
            $result[] = [
                'id' => null,
                'text' => $token,
                'line' => $openingLine + $line - 2,
            ];
        }

        return $result;
    }
}
