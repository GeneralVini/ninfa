<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SecurityExecutionLedger.php';
require_once __DIR__ . '/../src/SecurityCoordinationReport.php';
require_once __DIR__ . '/../src/ZapParser.php';
require_once __DIR__ . '/../src/NucleiParser.php';

$root = dirname(__DIR__);
$catalog = CapabilityCatalog::load($root);
$caps = array_column($catalog->jsonSerialize()['capabilities'], null, 'id');
$proposal = static function (string $id, string $project, string $profile = 'php-generic') use ($catalog, $caps): array {
    $hash = static fn (string $value): string => hash('sha256', $value);
    $owner = $caps[$id]['owner'];
    $web = in_array($owner, ['zap', 'nuclei'], true);
    $versions = ['psalm' => '6.20.1', 'zap' => '2.17.0', 'nuclei' => '3.11.1'];
    return ['capability' => $id, 'applicability_verified' => true, 'selection_control' => 'exact', 'limits_verified' => true,
        'input' => ['run_id' => str_repeat('a', 32),
            'context' => ['project_digest' => $hash($project), 'scope_digest' => $hash('scope'),
                'authorization_digest' => $hash('authorization'), 'code_digest' => $hash('code'),
                'deployment_digest' => $web ? $hash('deploy') : null, 'deployment_link' => $web ? 'verified' : 'not-applicable',
                'host_digest' => $profile === 'glpi-plugin' ? $hash('glpi11-host-mount-routes') : null,
                'profile' => $profile, 'role_digest' => $web ? $hash('public') : null,
                'session_context_digest' => $web ? $hash('stateless') : null],
            'asset' => ['kind' => !$web ? 'codebase' : ($owner === 'zap' ? 'http-response' : 'http-target'), 'digest' => $hash('asset')],
            'request' => ['method' => $web ? 'GET' : null, 'parameter_location' => null, 'parameter_digest' => null],
            'bindings' => ['engine_version' => $versions[$owner], 'engine_digest' => $hash($owner),
                'runtime_digest' => $hash('runtime'), 'configuration_digest' => $hash('config'),
                'rules_digest' => SecurityTestIdentity::rulesDigest($catalog, $id), 'data_digest' => null,
                'conditions_digest' => $hash('conditions'), 'payload_family_digest' => null]]];
};

// SEC-01: plugin é conclusão de código; DAST exige web-full explícito e contexto do host.
$plugin = SecurityScanPlan::build($catalog, 'plugin', [$proposal('psalm.TaintedHtml', 'plugin', 'glpi-plugin'),
    $proposal('zap.passive.csp', 'plugin', 'glpi-plugin')])->jsonSerialize();
assert($plugin['scope'] === 'plugin' && count($plugin['required_sources']) === 4);
assert(count($plugin['executions']) === 1 && $plugin['executions'][0]['owner'] === 'psalm');
assert($plugin['excluded'][0]['reason'] === 'outside-plugin-code-scope');
try {
    SecurityScanPlan::build($catalog, 'plugin', [$proposal('psalm.TaintedHtml', 'not-plugin')]);
    throw new LogicException('Profile incompatível com plugin foi aceito.');
} catch (InvalidArgumentException) {
}

$temporary = sys_get_temp_dir() . '/ninfa-stage-refs-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$store = new EvidenceStore($temporary . '/evidence');
$now = time();
$ledger = new SecurityExecutionLedger($temporary . '/ledger', $temporary . '/evidence', 3600,
    static function () use (&$now): int { return $now; });
$complete = static function (array $group) use ($store, &$now): array {
    $units = [];
    foreach ($group['units'] as $unit) {
        $units[$unit['identity']['key']] = ['coverage' => 'complete', 'session_valid' => true,
            'valid_until' => $now + 600, 'evidence' => $store->put(['coverage' => 'synthetic-complete'])];
    }
    return ['status' => 'completed', 'units' => $units];
};
try {
    $plan = SecurityScanPlan::build($catalog, 'code', [$proposal('psalm.TaintedHtml', 'clock')]);
    $id = $plan->jsonSerialize()['executions'][0]['execution_id'];
    assert($ledger->run($plan, $id, $complete, true)['decision'] === 'completed');
    assert($ledger->run($plan, $id, $complete, true)['decision'] === 'reused');
    $good = $ledger->inspect();
    $now -= 1;
    assert($ledger->run($plan, $id, $complete, true)['decision'] === 'retry-required');
    $now += 1;

    // Relatório não deve transformar snapshots inconsistentes em prova histórica de reuso.
    $key = $plan->jsonSerialize()['executions'][0]['units'][0]['identity']['key'];
    foreach (['failed-operation', 'future-created', 'expired-at-decision', 'wrong-group'] as $scenario) {
        $bad = $good;
        foreach ($bad['decisions'] as &$decision) {
            if ($decision['result']['decision'] !== 'reused') {
                continue;
            }
            $snapshot =& $decision['result']['coverage_snapshot'][$key];
            if ($scenario === 'failed-operation') {
                $snapshot['operation'] = 'failed';
            } elseif ($scenario === 'future-created') {
                $snapshot['created_at'] = $decision['at'] + 1;
            } elseif ($scenario === 'expired-at-decision') {
                $snapshot['valid_until'] = $decision['at'];
            } else {
                $decision['execution_id'] = 'sec-execution-v1:' . str_repeat('0', 64);
            }
            unset($snapshot);
        }
        unset($decision);
        try {
            SecurityCoordinationReport::fromPlan($plan, $bad);
            throw new LogicException('Proveniência inválida foi aceita: ' . $scenario);
        } catch (UnexpectedValueException) {
        }
    }

    // SEC-03: capturas positivas e saídas vazias continuam unknown, inclusive após persistência SEC-04.
    $captures = [
        ['zap.passive.csp', ZapParser::parse((string) file_get_contents($root . '/tests/fixtures/security-outputs/zap.json'))],
        ['zap.passive.csp', ZapParser::parse('{"site":[]}')],
        ['nuclei.security-headers-bundle', NucleiParser::parse((string) file_get_contents($root . '/tests/fixtures/security-outputs/nuclei.jsonl'))],
        ['nuclei.security-headers-bundle', NucleiParser::parse('')],
    ];
    assert(count($captures[0][1]['findings']) > 0 && count($captures[2][1]['findings']) > 0);
    foreach ($captures as $index => [$capability, $parsed]) {
        assert($parsed['coverage']['status'] === 'unknown');
        $case = SecurityScanPlan::build($catalog, 'web-full', [$proposal($capability, 'native-output-' . $index)]);
        $groupId = $case->jsonSerialize()['executions'][0]['execution_id'];
        $adapter = static function (array $group) use ($store, $parsed, &$now): array {
            $units = [];
            foreach ($group['units'] as $unit) {
                $units[$unit['identity']['key']] = ['coverage' => $parsed['coverage']['status'], 'session_valid' => true,
                    'valid_until' => $now + 600, 'evidence' => $store->put(['coverage' => $parsed['coverage'], 'findings_count' => count($parsed['findings'])])];
            }
            return ['status' => 'completed', 'units' => $units];
        };
        assert($ledger->run($case, $groupId, $adapter, true)['decision'] === 'completed');
        assert($ledger->run($case, $groupId, $adapter, true)['decision'] === 'retry-required');
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
echo "[OK] Revisão SEC-04 x SEC-01/03: plugin, recuo do relógio, proveniência e cobertura nativa unknown preservada.\n";
