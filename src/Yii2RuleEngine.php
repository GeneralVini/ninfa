<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/Yii2SemanticModel.php';
require_once __DIR__ . '/Yii2BehaviorActionAnalyzer.php';
require_once __DIR__ . '/Yii2RelationReferenceAnalyzer.php';
require_once __DIR__ . '/Yii2RelationLinkAnalyzer.php';

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

    /**
     * Avalia o snapshot Yii2 e produz somente findings suportados pelo catálogo atual.
     *
     * `COR-001` promove referências literais de view com path resolvido e arquivo ausente.
     * Quando ProjectContext é fornecido, `COR-002` valida actions em behaviors/filters,
     * `COR-003` valida relation paths literais de ActiveQuery e `COR-004` valida cada lado
     * de links literais de `hasOne()`/`hasMany()` somente quando `attributes()` fornece um
     * inventário estático completo. Os analisadores usam `null` quando herança, trait,
     * schema runtime ou expressão dinâmica impede prova segura; a engine ignora esses casos.
     *
     * @param Yii2SemanticModel $model Snapshot semântico previamente construído.
     * @param ProjectContext|null $context Contexto necessário às regras que leem o source original.
     * @return list<Finding> Findings Yii2 em ordem estável de regra/controller/ocorrência.
     */
    public function analyse(Yii2SemanticModel $model, ?ProjectContext $context = null): array
    {
        /** @var list<Finding> $findings Findings conclusivos produzidos pelas regras habilitadas. */
        $findings = [];

        // A engine só promove evidência conclusiva; `exists=null` representa resolução deliberadamente desconhecida.
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

        if ($context === null) {
            return $findings;
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

        return $findings;
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
