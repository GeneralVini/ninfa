<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SecurityExecutionLedger.php';
require_once __DIR__ . '/../src/SecurityCoordinationReport.php';

$catalog = CapabilityCatalog::load(dirname(__DIR__));
$caps = array_column($catalog->jsonSerialize()['capabilities'], null, 'id');
$hash = static fn (string $value): string => hash('sha256', $value);
$proposal = static function (string $id, string $run = 'a', string $role = 'public', bool $known = true) use ($catalog, $caps, $hash): array {
    $cap = $caps[$id];
    $owner = $cap['owner'];
    $web = in_array($owner, ['zap', 'nuclei'], true);
    $sca = in_array($owner, ['composer-audit', 'osv'], true);
    $active = $cap['technique'] === 'active-injection';
    $versions = ['composer-audit' => '2.9.5', 'osv' => 'v1', 'semgrep' => '1.177.0', 'psalm' => '6.20.1', 'zap' => '2.17.0', 'nuclei' => '3.11.1'];
    return ['capability' => $id, 'applicability_verified' => true, 'selection_control' => 'exact', 'limits_verified' => true,
        'input' => ['run_id' => str_repeat($run, 32),
            'context' => ['project_digest' => $hash('synthetic-project'), 'scope_digest' => $hash('scope'),
                'authorization_digest' => $hash('authorization'), 'code_digest' => $known ? $hash('source') : null,
                'deployment_digest' => $web ? $hash('deploy') : null, 'deployment_link' => $web ? 'verified' : 'not-applicable',
                'host_digest' => null, 'profile' => 'php-generic', 'role_digest' => $web ? $hash($role) : null,
                'session_context_digest' => $web ? $hash('session-context') : null],
            'asset' => ['kind' => $web ? ($cap['technique'] === 'passive-response' ? 'http-response' : 'http-target') : ($sca ? 'component-inventory' : 'codebase'),
                'digest' => $hash($web ? 'web-asset' : 'source-asset')],
            'request' => ['method' => $web ? 'GET' : null, 'parameter_location' => $active ? 'query' : null,
                'parameter_digest' => $active ? $hash('parameter-q') : null],
            'bindings' => ['engine_version' => $versions[$owner], 'engine_digest' => $hash($owner),
                'runtime_digest' => $hash('runtime'), 'configuration_digest' => $hash('config'),
                'rules_digest' => SecurityTestIdentity::rulesDigest($catalog, $id),
                'data_digest' => $sca ? $hash('advisory-snapshot') : null,
                'conditions_digest' => $hash('conditions'),
                'payload_family_digest' => in_array($cap['technique'], ['active-injection', 'http-specific-cve'], true) ? $hash('payload-family') : null]]];
};
$ids = ['composer.advisories', 'osv.packagist', 'ninfa.php.xss.request-to-output',
    'psalm.TaintedHtml', 'psalm.TaintedSql', 'zap.passive.csp', 'zap.active.reflected-xss',
    'nuclei.security-headers-bundle', 'nuclei.CVE-2023-23752', 'ninfa.php.xss.request-to-output'];
$plan = SecurityScanPlan::build($catalog, 'web-full', array_map($proposal, $ids));
$temporary = sys_get_temp_dir() . '/ninfa-proof-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$store = new EvidenceStore($temporary . '/evidence');
$now = time();
$clock = static function () use (&$now): int { return $now; };
$ledger = new SecurityExecutionLedger($temporary . '/ledger', $temporary . '/evidence', 3600, $clock);
$calls = [];
$internalRequests = [];
$adapter = static function (array $group) use ($store, &$calls, &$internalRequests, &$now): array {
    $calls[] = $group['owner'];
    if (in_array($group['owner'], ['zap', 'nuclei'], true)) {
        // Simulação de duas requisições internas iguais: o coordenador não as intercepta.
        $internalRequests[] = ['method' => 'GET', 'path' => '/synthetic'];
        $internalRequests[] = ['method' => 'GET', 'path' => '/synthetic'];
    }
    $evidence = $store->put(['synthetic' => true, 'group' => $group['execution_id']]);
    $units = [];
    foreach ($group['units'] as $unit) {
        $units[$unit['identity']['key']] = ['coverage' => 'complete', 'session_valid' => true,
            'valid_until' => $now + 600, 'evidence' => $evidence];
    }
    return ['status' => 'completed', 'units' => $units];
};
try {
    $notStarted = SecurityCoordinationReport::fromPlan($plan, $ledger->inspect())->jsonSerialize();
    assert($notStarted['attempts_in_this_plan'] === 0);
    assert(count($notStarted['suppression_events']) === 1);
    foreach ($plan->jsonSerialize()['executions'] as $group) {
        assert($ledger->run($plan, $group['execution_id'], $adapter, true)['decision'] === 'completed');
    }
    assert(count($calls) === 8); // Psalm compartilha dois tipos; Nuclei mantém templates distintos.
    assert(array_count_values($calls) === ['composer-audit' => 1, 'osv' => 1, 'semgrep' => 1, 'psalm' => 1, 'zap' => 2, 'nuclei' => 2]);
    assert(count($internalRequests) === 8 && count(array_unique(array_map('serialize', $internalRequests))) === 1);
    $firstReport = SecurityCoordinationReport::fromPlan($plan, $ledger->inspect());
    assert($firstReport->jsonSerialize()['attempts_in_this_plan'] === 8);
    assert(count($firstReport->jsonSerialize()['suppression_events']) === 1);
    assert(in_array('uncontrolled-overlap', array_column($firstReport->jsonSerialize()['warnings'], 'code'), true));
    assert(in_array('complementary-overlap', array_column($firstReport->jsonSerialize()['warnings'], 'code'), true));

    // Outro run comprovado: todos os oito grupos reaproveitam cobertura sem chamar adapters.
    $resumed = SecurityScanPlan::build($catalog, 'web-full', array_map(static fn (string $id): array => $proposal($id, 'b'), $ids));
    foreach ($resumed->jsonSerialize()['executions'] as $group) {
        assert($ledger->run($resumed, $group['execution_id'], $adapter, true)['decision'] === 'reused');
    }
    assert(count($calls) === 8 && count($internalRequests) === 8);
    $resumeReport = SecurityCoordinationReport::fromPlan($resumed, $ledger->inspect());
    $resumeData = $resumeReport->jsonSerialize();
    assert($resumeData['attempts_in_this_plan'] === 0 && $resumeData['reuse_events_in_this_plan'] === 8);
    assert(count($resumeData['suppression_events']) === 10); // Uma proposta duplicada + nove unidades reutilizadas.
    foreach ($resumeData['suppression_events'] as $event) {
        if ($event['kind'] === 'valid-coverage-reused') {
            assert($event['provenance']['status'] === 'captured-at-decision-time');
            assert($event['provenance']['source_plan_id'] === $plan->jsonSerialize()['plan_id']);
        }
    }
    assert(str_contains($resumeReport->markdown(), 'adapter não chamado'));

    $rolePlan = SecurityScanPlan::build($catalog, 'web-full', [$proposal('zap.active.reflected-xss', 'c', 'admin')]);
    $roleGroup = $rolePlan->jsonSerialize()['executions'][0];
    assert($ledger->run($rolePlan, $roleGroup['execution_id'], $adapter, true)['decision'] === 'completed');
    assert(count($calls) === 9);
    $unknownPlan = SecurityScanPlan::build($catalog, 'web-full', [$proposal('zap.active.reflected-xss', 'd', known: false)]);
    $unknownGroup = $unknownPlan->jsonSerialize()['executions'][0];
    assert($ledger->run($unknownPlan, $unknownGroup['execution_id'], $adapter, true)['decision'] === 'completed');
    assert($ledger->run($unknownPlan, $unknownGroup['execution_id'], $adapter, true)['decision'] === 'unverifiable-context');
    assert(count($calls) === 10);

    // Uma requisição interna complementar continua necessária; fallback cruzado não é inventado.
    $primary = $resumed->jsonSerialize()['executions'][2]; // Semgrep: executou e possui cobertura.
    $primaryKey = $primary['units'][0]['identity']['key'];
    assert($ledger->fallbackDecision($catalog, $resumed, $primaryKey, 'psalm.TaintedHtml', true)['decision'] === 'fallback-not-needed');
    $now += 601;
    assert($ledger->fallbackDecision($catalog, $resumed, $primaryKey, 'psalm.TaintedHtml', true)['decision'] === 'fallback-not-approved');
    assert($ledger->run($resumed, $primary['execution_id'], $adapter, true)['decision'] === 'retry-required');
    $beforeRetry = $ledger->inspect();
    assert($ledger->run($resumed, $primary['execution_id'], $adapter, true, 'expired-evidence')['decision'] === 'completed');
    assert(count($calls) === 11);
    // O relatório de reuso continua apontando para a evidência antiga, não para a substituta.
    $afterRetry = SecurityCoordinationReport::fromPlan($resumed, $ledger->inspect())->jsonSerialize();
    $oldEvents = array_values(array_filter($resumeData['suppression_events'], static fn (array $event): bool => $event['kind'] === 'valid-coverage-reused' && $event['unit'] === $primaryKey));
    $newEvents = array_values(array_filter($afterRetry['suppression_events'], static fn (array $event): bool => $event['kind'] === 'valid-coverage-reused' && $event['unit'] === $primaryKey));
    assert($oldEvents === $newEvents);
    assert($newEvents[0]['provenance']['evidence_reference'] !== $ledger->inspect()['coverage'][$primaryKey]['evidence']['reference']);
    assert($newEvents[0]['provenance']['attempt'] !== $ledger->inspect()['coverage'][$primaryKey]['attempt']);

    $legacy = $beforeRetry;
    foreach ($legacy['decisions'] as &$decision) {
        unset($decision['result']['coverage_snapshot']);
    }
    unset($decision);
    $legacyData = SecurityCoordinationReport::fromPlan($resumed, $legacy)->jsonSerialize();
    $legacyReuses = array_values(array_filter($legacyData['suppression_events'], static fn (array $event): bool => $event['kind'] === 'valid-coverage-reused'));
    assert($legacyReuses[0]['provenance']['status'] === 'legacy-decision-without-provenance');
    try {
        SecurityCoordinationReport::fromPlan($plan, ['decision' => 'busy']);
        throw new LogicException('Busy foi tratado como execução vazia.');
    } catch (InvalidArgumentException) {
    }
    if (isset($argv[1]) && $argv[1] === '--export') {
        if (!isset($argv[2]) || !is_dir($argv[2])) {
            throw new InvalidArgumentException('Diretório de exportação deve existir.');
        }
        file_put_contents($argv[2] . '/sec04-05-coordination-example.json', json_encode($resumeReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($argv[2] . '/sec04-05-coordination-example.md', $resumeReport->markdown());
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
echo "[OK] SEC-04.05: oito grupos/seis fontes; duplicação, retomada, contexto, complementaridade, fallback e proveniência histórica.\n";
