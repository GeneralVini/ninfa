<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/Yii2SemanticModel.php';
require_once __DIR__ . '/Yii2ViewReferenceAnalyzer.php';
require_once __DIR__ . '/Yii2BehaviorActionAnalyzer.php';
require_once __DIR__ . '/Yii2RelationReferenceAnalyzer.php';
require_once __DIR__ . '/Yii2RelationLinkAnalyzer.php';
require_once __DIR__ . '/Yii2QueryConditionAnalyzer.php';
require_once __DIR__ . '/Yii2ModelRulesAnalyzer.php';
require_once __DIR__ . '/Yii2ModelMetadataAnalyzer.php';
require_once __DIR__ . '/Yii2WhereEqualityAnalyzer.php';
require_once __DIR__ . '/Yii2QueryExistenceAnalyzer.php';
require_once __DIR__ . '/Yii2FindShortcutAnalyzer.php';
require_once __DIR__ . '/Yii2DeprecationAnalyzer.php';
require_once __DIR__ . '/Yii2CachingDeprecationAnalyzer.php';
require_once __DIR__ . '/Yii2ClassNameDeprecationAnalyzer.php';
require_once __DIR__ . '/Yii2MagicPropertyAnalyzer.php';
require_once __DIR__ . '/Yii2ControllerAccessAnalyzer.php';

/**
 * Converte fatos conclusivos das camadas semânticas Yii2 em findings próprios do Ninfa.
 *
 * A engine é deliberadamente menor que os analisadores semânticos: somente regras cuja
 * evidência e política já foram definidas entram aqui. Fatos desconhecidos ou heurísticos
 * permanecem fora do resultado para limitar falso positivo.
 */
final class Yii2RuleEngine
{
    /** Identificador estável da regra de view literal inexistente. */
    public const VIEW_NOT_FOUND = 'NINFA-YII2-COR-001';

    /** Identificador estável da regra de action inexistente referenciada por behavior. */
    public const BEHAVIOR_ACTION_NOT_FOUND = 'NINFA-YII2-COR-002';

    /** Identificador estável da regra de relação inexistente em ActiveQuery literal. */
    public const QUERY_RELATION_NOT_FOUND = 'NINFA-YII2-COR-003';

    /** Identificador estável da regra de atributo inexistente em link hasOne/hasMany. */
    public const RELATION_LINK_ATTRIBUTE_NOT_FOUND = 'NINFA-YII2-COR-004';

    /** Identificador estável de aridade inválida em condition array de Query Yii2. */
    public const QUERY_CONDITION_INVALID = 'NINFA-YII2-COR-005';

    /** Identificador estável de atributo/cenário inválido em Model::scenarios(). */
    public const MODEL_SCENARIO_INVALID = 'NINFA-YII2-COR-007';

    /** Identificador estável de atributo inválido em Model::attributeLabels(). */
    public const MODEL_ATTRIBUTE_LABEL_INVALID = 'NINFA-YII2-COR-008';

    /** Identificador estável de atributo inválido em Model::attributeHints(). */
    public const MODEL_ATTRIBUTE_HINT_INVALID = 'NINFA-YII2-COR-009';

    /** Identificador estável de atributo inexistente referenciado por Model::rules(). */
    public const MODEL_RULE_ATTRIBUTE_NOT_FOUND = 'NINFA-YII2-COR-006';

    /** Identificador estável de igualdade SQL dinâmica simples em condition Yii2. */
    public const DYNAMIC_WHERE_EQUALITY = 'NINFA-YII2-SEC-001';

    /** Identificador estável de verificação redundante de existência em query. */
    public const REDUNDANT_EXISTENCE_CHECK = 'NINFA-YII2-PERF-001';

    /** Identificador estável de shortcut findOne/findAll seguro. */
    public const FIND_SHORTCUT = 'NINFA-YII2-MOD-001';

    /** Identificador estável da substituição deprecated `Yii::trace()` -> `Yii::debug()`. */
    public const TRACE_DEPRECATED = 'NINFA-YII2-DEP-001';

    /** Identificador estável de constantes antigas do console controller. */
    public const EXIT_CONSTANT_DEPRECATED = 'NINFA-YII2-DEP-002';

    /** Identificador estável de magic numbers retornados por console actions. */
    public const ACTION_EXIT_LITERAL = 'NINFA-YII2-DEP-003';

    /** Identificador estável de aliases deprecated de métodos de Cache. */
    public const CACHE_METHOD_DEPRECATED = 'NINFA-YII2-DEP-004';

    /** Identificador estável de Dependency::getHasChanged(). */
    public const DEPENDENCY_METHOD_DEPRECATED = 'NINFA-YII2-DEP-005';

    /** Identificador estável de BaseObject::className(). */
    public const CLASS_NAME_DEPRECATED = 'NINFA-YII2-DEP-006';

    /** Identificador estável de relação sem tag de propriedade mágica em PHPDoc já mantido. */
    public const MAGIC_PROPERTY_MISSING = 'NINFA-YII2-TYPE-001';

    /** Identificador advisory para acesso global a request/response dentro de controller. */
    public const CONTROLLER_GLOBAL_REQUEST_RESPONSE = 'NINFA-YII2-ARCH-001';

    /**
     * Avalia o snapshot Yii2 e produz somente findings suportados pelo catálogo atual.
     *
     * As famílias correctness, security-smell, performance, modernization, deprecation,
     * static-analysis e architecture preservam políticas distintas. Apenas evidência conclusiva
     * vira finding; advisory arquitetural não é promovido a vulnerabilidade e só transformações
     * marcadas como SAFE podem participar de `ninfa fix`.
     *
     * @param Yii2SemanticModel $model Snapshot semântico previamente construído.
     * @param ProjectContext|null $context Contexto necessário às regras que leem o source original.
     * @return list<Finding> Findings Yii2 em ordem estável de regra/controller/ocorrência.
     */
    public function analyse(Yii2SemanticModel $model, ?ProjectContext $context = null): array
    {
        /** @var list<Finding> $findings Findings conclusivos produzidos pelas regras habilitadas. */
        $findings = [];

        // Sem ProjectContext preserva compatibilidade com o snapshot; com contexto usa o resolver ampliado e configurável.
        if ($context === null) {
            foreach ($model->controllers() as $controller) {
                foreach ($controller['views'] as $view) {
                    if ($view['exists'] !== false || $view['resolved_path'] === null) {
                        continue;
                    }

                    $findings[] = new Finding(
                        tool: 'ninfa-yii2',
                        file: $controller['file'],
                        line: $view['line'],
                        rule: self::VIEW_NOT_FOUND,
                        problem: 'View Yii2 literal não encontrada: ' . $view['name'],
                        correction: 'Crie a view esperada ou corrija a referência para um arquivo existente.',
                        severity: 'error',
                        confidence: 'high',
                        evidenceType: 'framework-correctness',
                        provenance: ['ninfa:yii2-semantic-model'],
                        metadata: [
                            'framework' => 'yii2',
                            'category' => 'correctness',
                            'controller' => $controller['class'],
                            'controller_id' => $controller['id'],
                            'view' => $view['name'],
                            'expected_path' => $view['resolved_path'],
                            'autofix' => false,
                        ],
                    );
                }
            }

            return $findings;
        }

        // COR-001 ampliado resolve controller, nested view e receivers Yii::$app->view comprovados.
        foreach ((new Yii2ViewReferenceAnalyzer())->references($context, $model) as $reference) {
            if ($reference['exists']) {
                continue;
            }

            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::VIEW_NOT_FOUND,
                problem: 'View Yii2 literal não encontrada: ' . $reference['view'],
                correction: 'Crie a view esperada ou corrija a referência/configuração para um arquivo existente.',
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:yii2-view-reference-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'correctness',
                    'view' => $reference['view'],
                    'method' => $reference['method'],
                    'view_context' => $reference['context'],
                    'resolution_source' => $reference['source'],
                    'expected_path' => $reference['resolved_path'],
                    'autofix' => false,
                ],
            );
        }

        // COR-002 só acusa ausência quando o analisador provou que o inventário de actions está completo.
        foreach ((new Yii2BehaviorActionAnalyzer())->references($context, $model) as $reference) {
            if ($reference['exists'] !== false) {
                continue;
            }
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::BEHAVIOR_ACTION_NOT_FOUND,
                problem: 'Action Yii2 referenciada em behavior não encontrada: ' . $reference['action'],
                correction: 'Corrija a referência do behavior ou declare a action correspondente no controller.',
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:yii2-behavior-action-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'correctness',
                    'controller' => $reference['controller'],
                    'action' => $reference['action'],
                    'reference' => $reference['reference'],
                    'behavior_class' => $reference['behavior_class'],
                    'action_inventory_complete' => true,
                    'autofix' => false,
                ],
            );
        }

        // COR-003 exige ActiveRecord local com herança conclusiva; targets/traits incertos permanecem unknown.
        foreach ((new Yii2RelationReferenceAnalyzer())->references($context, $model) as $reference) {
            if ($reference['exists'] !== false || $reference['missing_relation'] === null) {
                continue;
            }
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::QUERY_RELATION_NOT_FOUND,
                problem: 'Relação Yii2 referenciada em ActiveQuery não encontrada: ' . $reference['missing_relation'],
                correction: 'Corrija a relation path ou declare o getter hasOne()/hasMany() correspondente no ActiveRecord.',
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:yii2-relation-reference-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'correctness',
                    'model' => $reference['model'],
                    'method' => $reference['method'],
                    'relation_path' => $reference['relation_path'],
                    'missing_relation' => $reference['missing_relation'],
                    'resolved_prefix' => $reference['resolved_prefix'],
                    'relation_inventory_complete' => true,
                    'autofix' => false,
                ],
            );
        }

        // COR-004 trata os dois lados do link separadamente e nunca infere schema implícito de banco.
        foreach ((new Yii2RelationLinkAnalyzer())->references($context, $model) as $reference) {
            if ($reference['related_exists'] === false) {
                $findings[] = $this->relationLinkFinding($reference, 'related');
            }
            if ($reference['current_exists'] === false) {
                $findings[] = $this->relationLinkFinding($reference, 'current');
            }
        }

        // COR-005 só processa condition arrays literais em ActiveRecord::find() comprovado.
        foreach ((new Yii2QueryConditionAnalyzer())->references($context) as $reference) {
            $quantifier = $reference['expectation'] === 'exact' ? 'exatamente' : 'ao menos';
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::QUERY_CONDITION_INVALID,
                problem: 'Operator Yii2 `' . $reference['operator'] . '` em ' . $reference['method'] . '() recebeu '
                    . $reference['actual_operands'] . ' operandos.',
                correction: 'Use ' . $quantifier . ' ' . $reference['expected_operands'] . ' operandos para esse operator.',
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:yii2-query-condition-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'correctness',
                    'model' => $reference['model'],
                    'method' => $reference['method'],
                    'operator' => $reference['operator'],
                    'actual_operands' => $reference['actual_operands'],
                    'expectation' => $reference['expectation'],
                    'expected_operands' => $reference['expected_operands'],
                    'autofix' => false,
                ],
            );
        }

        // COR-006 só nega atributo quando o analyzer provou um inventário completo do Model.
        foreach ((new Yii2ModelRulesAnalyzer())->references($context) as $reference) {
            $validator = $reference['validator'] === null ? '' : ' na regra `' . $reference['validator'] . '`';
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::MODEL_RULE_ATTRIBUTE_NOT_FOUND,
                problem: 'Atributo Yii2 `' . $reference['attribute'] . '` referenciado em Model::rules() não existe' . $validator . '.',
                correction: 'Corrija o nome do atributo ou declare-o no contrato estático de attributes()/propriedades públicas do Model.',
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:yii2-model-rules-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'correctness',
                    'model' => $reference['model'],
                    'attribute' => $reference['attribute'],
                    'validator' => $reference['validator'],
                    'attribute_inventory_complete' => true,
                    'autofix' => false,
                ],
            );
        }

        // COR-007..009 reutilizam o mesmo inventário conclusivo de COR-006 para metadata literal de Model.
        foreach ((new Yii2ModelMetadataAnalyzer())->references($context) as $reference) {
            $rule = match ($reference['kind']) {
                'scenario-name', 'scenario-attribute' => self::MODEL_SCENARIO_INVALID,
                'attribute-label' => self::MODEL_ATTRIBUTE_LABEL_INVALID,
                'attribute-hint' => self::MODEL_ATTRIBUTE_HINT_INVALID,
                default => throw new LogicException('Evidência Yii2 Model metadata desconhecida: ' . $reference['kind']),
            };

            if ($reference['kind'] === 'scenario-name') {
                $problem = 'Model::scenarios() declara nome de cenário vazio.';
                $correction = 'Defina um nome de cenário literal não vazio.';
            } elseif ($reference['kind'] === 'scenario-attribute') {
                $scenario = $reference['scenario'] ?? '';
                $problem = $reference['reason'] === 'empty'
                    ? 'Model::scenarios() referencia atributo vazio no cenário ' . $scenario . '.'
                    : 'Atributo Yii2 ' . $reference['name'] . ' referenciado em Model::scenarios() não existe no cenário ' . $scenario . '.';
                $correction = 'Corrija o atributo do cenário ou declare-o no contrato estático do Model.';
            } elseif ($reference['kind'] === 'attribute-label') {
                $problem = $reference['reason'] === 'empty'
                    ? 'Model::attributeLabels() contém nome de atributo vazio.'
                    : 'Atributo Yii2 ' . $reference['name'] . ' referenciado em Model::attributeLabels() não existe.';
                $correction = 'Corrija a chave do label ou declare o atributo no contrato estático do Model.';
            } else {
                $problem = $reference['reason'] === 'empty'
                    ? 'Model::attributeHints() contém nome de atributo vazio.'
                    : 'Atributo Yii2 ' . $reference['name'] . ' referenciado em Model::attributeHints() não existe.';
                $correction = 'Corrija a chave do hint ou declare o atributo no contrato estático do Model.';
            }

            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: $rule,
                problem: $problem,
                correction: $correction,
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:yii2-model-metadata-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'correctness',
                    'model' => $reference['model'],
                    'method' => $reference['method'],
                    'kind' => $reference['kind'],
                    'attribute' => $reference['name'],
                    'raw_name' => $reference['raw_name'],
                    'scenario' => $reference['scenario'],
                    'reason' => $reference['reason'],
                    'attribute_inventory_complete' => true,
                    'autofix' => false,
                ],
            );
        }

        // SEC-001 descreve um security smell; sem taint proof ele não é promovido a vulnerabilidade nem autofix.
        foreach ((new Yii2WhereEqualityAnalyzer())->references($context) as $reference) {
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::DYNAMIC_WHERE_EQUALITY,
                problem: 'Condition Yii2 monta igualdade SQL por ' . ($reference['style'] === 'concat' ? 'concatenação' : 'interpolação')
                    . ' para a coluna `' . $reference['column'] . '`.',
                correction: 'Revise a origem do valor e prefira `' . $reference['replacement'] . '` para usar binding/quoting do Query Builder.',
                severity: 'warning',
                confidence: 'high',
                evidenceType: 'framework-security-smell',
                provenance: ['ninfa:yii2-where-equality-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'security',
                    'finding_kind' => 'security-smell',
                    'model' => $reference['model'],
                    'method' => $reference['method'],
                    'column' => $reference['column'],
                    'value_expression' => $reference['value_expression'],
                    'style' => $reference['style'],
                    'replacement' => $reference['replacement'],
                    'taint_proven' => false,
                    'remediation_risk' => 'review',
                    'autofix' => false,
                ],
            );
        }

        // PERF-001 é SAFE somente no subconjunto cuja comparação inteira foi provada e tem patch exato.
        foreach ((new Yii2QueryExistenceAnalyzer())->references($context) as $reference) {
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::REDUNDANT_EXISTENCE_CHECK,
                problem: 'Query Yii2 usa ' . $reference['source_method'] . '() apenas para verificar existência.',
                correction: 'Use `' . $reference['replacement_code'] . '` para evitar carregar/contar dados desnecessários.',
                severity: 'warning',
                confidence: 'high',
                evidenceType: 'framework-performance',
                provenance: ['ninfa:yii2-query-existence-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'performance',
                    'model' => $reference['model'],
                    'source_method' => $reference['source_method'],
                    'operator' => $reference['operator'],
                    'operand' => $reference['operand'],
                    'query_on_left' => $reference['query_on_left'],
                    'negated' => $reference['negated'],
                    'replacement' => $reference['replacement'],
                    'replacement_code' => $reference['replacement_code'],
                    'remediation_risk' => 'safe',
                    'autofix' => true,
                ],
            );
        }

        // MOD-001 é SAFE somente para hash literal de string keys; outras shapes permanecem intactas.
        foreach ((new Yii2FindShortcutAnalyzer())->references($context) as $reference) {
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::FIND_SHORTCUT,
                problem: 'ActiveRecord Yii2 usa find()->where(hash)->' . $reference['terminal'] . '() onde existe shortcut equivalente.',
                correction: 'Use `' . $reference['replacement'] . '`.',
                severity: 'warning',
                confidence: 'high',
                evidenceType: 'framework-modernization',
                provenance: ['ninfa:yii2-find-shortcut-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'modernization',
                    'model' => $reference['model'],
                    'terminal' => $reference['terminal'],
                    'replacement_method' => $reference['replacement_method'],
                    'replacement' => $reference['replacement'],
                    'remediation_risk' => 'safe',
                    'autofix' => true,
                ],
            );
        }

        // Todas as deprecations SAFE reutilizam a evidência dos analyzers; a engine só normaliza para Finding.
        foreach ((new Yii2DeprecationAnalyzer())->references($context) as $reference) {
            $findings[] = $this->deprecationFinding($reference, 'ninfa:yii2-deprecation-analyzer');
        }
        foreach ((new Yii2CachingDeprecationAnalyzer())->references($context) as $reference) {
            $findings[] = $this->deprecationFinding($reference, 'ninfa:yii2-caching-deprecation-analyzer');
        }
        foreach ((new Yii2ClassNameDeprecationAnalyzer())->references($context) as $reference) {
            $findings[] = $this->deprecationFinding($reference, 'ninfa:yii2-classname-deprecation-analyzer');
        }

        // TYPE-001 só exige tag quando a classe já mantém um contrato de propriedades mágicas.
        foreach ((new Yii2MagicPropertyAnalyzer())->references($context, $model) as $reference) {
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::MAGIC_PROPERTY_MISSING,
                problem: 'PHPDoc Yii2 não documenta a propriedade mágica da relação: $' . $reference['relation'],
                correction: 'Revise o contrato da classe e considere `' . $reference['expected_tag'] . '`.',
                severity: 'warning',
                confidence: 'high',
                evidenceType: 'framework-static-analysis',
                provenance: ['ninfa:yii2-magic-property-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'static-analysis',
                    'model' => $reference['model'],
                    'relation' => $reference['relation'],
                    'relation_kind' => $reference['kind'],
                    'target' => $reference['target'],
                    'expected_type' => $reference['expected_type'],
                    'expected_tag' => $reference['expected_tag'],
                    'remediation_risk' => 'semantic',
                    'autofix' => false,
                ],
            );
        }

        // ARCH-001 é opinião arquitetural útil, não correctness/security; permanece advisory sem autofix.
        foreach ((new Yii2ControllerAccessAnalyzer())->references($context) as $reference) {
            $findings[] = new Finding(
                tool: 'ninfa-yii2',
                file: $reference['file'],
                line: $reference['line'],
                rule: self::CONTROLLER_GLOBAL_REQUEST_RESPONSE,
                problem: 'Controller Yii2 acessa Yii::$app->' . $reference['property'] . ' apesar de expor a mesma dependência localmente.',
                correction: 'Considere `' . $reference['replacement'] . '` para reduzir acoplamento global.',
                severity: 'warning',
                confidence: 'high',
                evidenceType: 'framework-architecture',
                provenance: ['ninfa:yii2-controller-access-analyzer'],
                metadata: [
                    'framework' => 'yii2',
                    'category' => 'architecture',
                    'controller' => $reference['controller'],
                    'property' => $reference['property'],
                    'replacement' => $reference['replacement'],
                    'remediation_risk' => 'review',
                    'autofix' => false,
                ],
            );
        }

        return $findings;
    }

    /**
     * Normaliza uma deprecation SAFE já comprovada sem repetir a lógica de detecção.
     *
     * O analyzer continua sendo a única fonte de verdade para regra, replacement e offsets;
     * esta camada apenas converte a evidência em `Finding` para `assist` e auditoria JSON.
     *
     * @param array{file:string,line:int,rule:string,kind:string,problem:string,replacement:string,offset:int,length:int} $reference Evidência SAFE do analyzer.
     * @param string $provenance Identificador da camada que produziu a evidência.
     * @return Finding Finding de deprecation com autofix explicitamente autorizado.
     */
    private function deprecationFinding(array $reference, string $provenance): Finding
    {
        return new Finding(
            tool: 'ninfa-yii2',
            file: $reference['file'],
            line: $reference['line'],
            rule: $reference['rule'],
            problem: $reference['problem'],
            correction: 'Substitua por `' . $reference['replacement'] . '`.',
            severity: 'warning',
            confidence: 'high',
            evidenceType: 'framework-deprecation',
            provenance: [$provenance],
            metadata: [
                'framework' => 'yii2',
                'category' => 'deprecation',
                'kind' => $reference['kind'],
                'replacement' => $reference['replacement'],
                'remediation_risk' => 'safe',
                'autofix' => true,
            ],
        );
    }

    /**
     * Constrói finding de link ActiveRecord preservando qual lado possui atributo inválido.
     *
     * @param array{
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
     * } $reference Evidência normalizada do par de link.
     * @param 'related'|'current' $side Lado cujo inventário provou ausência.
     * @return Finding Finding de correctness sem autofix.
     */
    private function relationLinkFinding(array $reference, string $side): Finding
    {
        $attribute = $side === 'related' ? $reference['related_attribute'] : $reference['current_attribute'];
        $model = $side === 'related' ? $reference['related_model'] : $reference['current_model'];
        $label = $side === 'related' ? 'relacionado' : 'atual';

        return new Finding(
            tool: 'ninfa-yii2',
            file: $reference['file'],
            line: $reference['line'],
            rule: self::RELATION_LINK_ATTRIBUTE_NOT_FOUND,
            problem: 'Atributo Yii2 do ActiveRecord ' . $label . ' não encontrado no link ' . $reference['kind'] . '(): ' . $attribute,
            correction: 'Corrija o array de link da relação ou o contrato explícito de attributes() do ActiveRecord correspondente.',
            severity: 'error',
            confidence: 'high',
            evidenceType: 'framework-correctness',
            provenance: ['ninfa:yii2-relation-link-analyzer'],
            metadata: [
                'framework' => 'yii2',
                'category' => 'correctness',
                'relation' => $reference['relation'],
                'relation_kind' => $reference['kind'],
                'side' => $side,
                'model' => $model,
                'attribute' => $attribute,
                'related_model' => $reference['related_model'],
                'current_model' => $reference['current_model'],
                'related_attribute' => $reference['related_attribute'],
                'current_attribute' => $reference['current_attribute'],
                'attribute_inventory_complete' => true,
                'autofix' => false,
            ],
        );
    }
}
