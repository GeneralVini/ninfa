<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/Yii2SemanticModel.php';

/**
 * Converte fatos conclusivos do Yii2SemanticModel em findings próprios do Ninfa.
 *
 * A engine é deliberadamente menor que o modelo semântico: somente regras cuja
 * evidência e política já foram definidas entram aqui. Fatos desconhecidos ou
 * heurísticos permanecem fora do resultado para limitar falso positivo.
 */
final class Yii2RuleEngine
{
    /** Identificador estável da regra de view literal inexistente. */
    public const VIEW_NOT_FOUND = 'NINFA-YII2-COR-001';

    /**
     * Avalia o snapshot Yii2 e produz somente findings suportados pelo catálogo atual.
     *
     * A primeira regra promove para correctness bloqueante apenas referências
     * literais cuja resolução convencional produziu path físico e confirmou que
     * o arquivo não existe. Views dinâmicas/desconhecidas são ignoradas.
     *
     * @param Yii2SemanticModel $model Snapshot semântico previamente construído.
     * @return list<Finding> Findings Yii2 em ordem de controller/ocorrência.
     */
    public function analyse(Yii2SemanticModel $model): array
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

        return $findings;
    }
}
