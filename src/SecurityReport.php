<?php

declare(strict_types=1);

require_once __DIR__ . '/RunResult.php';
require_once __DIR__ . '/SecurityInventory.php';
require_once __DIR__ . '/ScaFindingDeduplicator.php';

/**
 * Monta o relatório canônico atual da operação `security`.
 *
 * O schema 1 incorpora o SecurityInventory, registra o estado das fontes SCA,
 * separa findings de advisory e de policy e deduplica vulnerabilidades SCA por
 * meio de ScaFindingDeduplicator. Nesta versão somente `composer-audit` e
 * `osv` são tratados como fontes SCA do relatório.
 *
 * A classe rejeita RunResult de outras operações e não executa scanners nem
 * calcula exposure, exploitability ou prioridade operacional.
 */
final class SecurityReport implements JsonSerializable
{
    /**
     * Associa inventário e resultado de uma execução `security` já finalizada.
     *
     * @param SecurityInventory $inventory Inventário usado pelas fontes SCA dessa execução.
     * @param RunResult $runResult Resultado consolidado que deve pertencer à operação security.
     * @throws InvalidArgumentException Quando o RunResult pertence a outra operação.
     */
    public function __construct(
        private readonly SecurityInventory $inventory,
        private readonly RunResult $runResult,
    ) {
        if ($this->runResult->operation !== 'security') {
            throw new InvalidArgumentException('SecurityReport exige RunResult da operação security.');
        }
    }

    /**
     * Materializa o schema 1 do relatório sem executar novas consultas/scanners.
     *
     * Apenas resultados de `composer-audit` e `osv` alimentam a seção SCA nesta
     * versão. Advisories são separados de policy findings e deduplicados depois
     * de toda a coleta para preservar source findings auditáveis.
     *
     * @return array<string,mixed> Relatório canônico serializável da execução security.
     */
    public function jsonSerialize(): array
    {
        /** @var list<string> $scaToolIds Fontes SCA reconhecidas pelo schema atual. */
        $scaToolIds = ['composer-audit', 'osv'];
        /** @var list<array{id:string,state:string,exit_code:?int,detail:?string,duration_ms:?int}> $sourceResults */
        $sourceResults = [];
        /** @var list<Finding> $scaFindings Advisories usados na deduplicação canônica. */
        $scaFindings = [];
        /** @var list<Finding> $policies Findings de política separados de vulnerabilidades. */
        $policies = [];

        foreach ($this->runResult->toolResults as $result) {
            // Ferramentas SAST/auxiliares continuam no RunResult, mas não pertencem à seção SCA schema 1.
            if (!in_array($result->id, $scaToolIds, true)) {
                continue;
            }

            $sourceResults[] = [
                'id' => $result->id,
                'state' => $result->state,
                'exit_code' => $result->exitCode,
                'detail' => $result->detail,
                'duration_ms' => $result->durationMs,
            ];

            // Evidence type define a seção; outros tipos futuros não são reclassificados implicitamente.
            foreach ($result->findings as $finding) {
                if ($finding->evidenceType === 'sca-advisory') {
                    $scaFindings[] = $finding;
                } elseif ($finding->evidenceType === 'dependency-policy') {
                    $policies[] = $finding;
                }
            }
        }

        return [
            'schema_version' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'operation' => 'security',
            'profile' => $this->inventory->toArray()['profile'] ?? null,
            'inventory' => $this->inventory,
            'sca' => [
                'sources' => $sourceResults,
                'vulnerabilities' => ScaFindingDeduplicator::canonicalize($scaFindings),
                'policies' => $policies,
                'source_findings' => $scaFindings,
            ],
            'run' => [
                'final_exit_code' => $this->runResult->finalExitCode,
            ],
        ];
    }
}
