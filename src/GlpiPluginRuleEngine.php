<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/GlpiPluginSemanticModel.php';

/**
 * Normaliza somente violações conclusivas de lifecycle GLPI 11 em Finding.
 *
 * A engine não executa setup/hook, não altera o plugin, nem classifica
 * ausência de callback como segurança. Entrypoints inexistentes e callbacks
 * estaticamente ausentes são correctness; evidência unknown é ignorada.
 */
final class GlpiPluginRuleEngine
{
    /** Entry point convencional GLPI não encontrado na raiz do plugin. */
    public const ENTRYPOINT_MISSING = 'NINFA-GLPI-COR-001';

    /** Callback de lifecycle obrigatório não declarado estaticamente. */
    public const REQUIRED_CALLBACK_MISSING = 'NINFA-GLPI-COR-002';

    /**
     * Converte inventário GLPI conclusivo em achados não autofixáveis.
     *
     * @param GlpiPluginSemanticModel $model Snapshot de lifecycle.
     * @return list<Finding> Findings estáveis sem evidência especulativa.
     */
    public function analyse(GlpiPluginSemanticModel $model): array
    {
        /** @var list<Finding> $findings */
        $findings = [];
        foreach ($model->entrypoints() as $file => $entry) {
            if ($entry['exists']) {
                continue;
            }
            $findings[] = new Finding(
                tool: 'ninfa-glpi',
                file: $file,
                line: 1,
                rule: self::ENTRYPOINT_MISSING,
                problem: 'Entrypoint obrigatório de plugin GLPI 11 não encontrado: ' . $file,
                correction: 'Crie o entrypoint esperado ou revise a estrutura de distribuição do plugin.',
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:glpi-lifecycle-semantic-model'],
                metadata: [
                    'framework' => 'glpi11',
                    'category' => 'correctness',
                    'plugin' => $model->plugin(),
                    'entrypoint' => $file,
                    'remediation_risk' => 'review',
                    'autofix' => false,
                ],
            );
        }

        // Arquivo ausente recebe um único finding; ausência de callback nele seria redundante.
        foreach ($model->callbacks() as $callback) {
            if ($callback['exists'] !== false) {
                continue;
            }
            $findings[] = new Finding(
                tool: 'ninfa-glpi',
                file: $callback['file'],
                line: 1,
                rule: self::REQUIRED_CALLBACK_MISSING,
                problem: 'Callback de lifecycle GLPI 11 não declarado: ' . $callback['callback'],
                correction: 'Declare o callback global correspondente ao identificador técnico do plugin, ou revise a resolução estática de includes.',
                severity: 'error',
                confidence: 'high',
                evidenceType: 'framework-correctness',
                provenance: ['ninfa:glpi-lifecycle-semantic-model'],
                metadata: [
                    'framework' => 'glpi11',
                    'category' => 'correctness',
                    'plugin' => $model->plugin(),
                    'entrypoint' => $callback['file'],
                    'callback' => $callback['callback'],
                    'callback_exists' => false,
                    'inventory_complete' => true,
                    'remediation_risk' => 'review',
                    'autofix' => false,
                ],
            );
        }

        return $findings;
    }
}
