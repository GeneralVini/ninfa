<?php

declare(strict_types=1);

require_once __DIR__ . '/RunResult.php';
require_once __DIR__ . '/SecurityInventory.php';
require_once __DIR__ . '/ScaFindingDeduplicator.php';

final class SecurityReport implements JsonSerializable
{
    public function __construct(
        private readonly SecurityInventory $inventory,
        private readonly RunResult $runResult,
    ) {
        if ($this->runResult->operation !== 'security') {
            throw new InvalidArgumentException('SecurityReport exige RunResult da operação security.');
        }
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        $scaToolIds = ['composer-audit', 'osv'];
        $sourceResults = [];
        $scaFindings = [];
        $policies = [];

        foreach ($this->runResult->toolResults as $result) {
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
