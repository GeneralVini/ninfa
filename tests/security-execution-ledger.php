<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SecurityExecutionLedger.php';

function ledgerTestPlan(array $ids = ['psalm.TaintedHtml'], bool $known = true, string $project = 'project', string $run = 'a'): SecurityScanPlan
{
    $catalog = CapabilityCatalog::load(dirname(__DIR__));
    $proposals = [];
    foreach ($ids as $id) {
        $proposals[] = ['capability' => $id, 'applicability_verified' => true, 'selection_control' => 'exact', 'limits_verified' => true,
            'input' => ['run_id' => str_repeat($run, 32),
                'context' => ['project_digest' => hash('sha256', $project), 'scope_digest' => hash('sha256', 'scope'),
                    'authorization_digest' => hash('sha256', 'auth'), 'code_digest' => $known ? hash('sha256', 'source') : null,
                    'deployment_digest' => null, 'deployment_link' => 'not-applicable', 'host_digest' => null,
                    'profile' => 'php-generic', 'role_digest' => null, 'session_context_digest' => null],
                'asset' => ['kind' => 'codebase', 'digest' => hash('sha256', 'asset')],
                'request' => ['method' => null, 'parameter_location' => null, 'parameter_digest' => null],
                'bindings' => ['engine_version' => '6.20.1', 'engine_digest' => hash('sha256', 'psalm'),
                    'runtime_digest' => hash('sha256', 'runtime'), 'configuration_digest' => hash('sha256', 'config'),
                    'rules_digest' => SecurityTestIdentity::rulesDigest($catalog, $id), 'data_digest' => null,
                    'conditions_digest' => hash('sha256', 'conditions'), 'payload_family_digest' => null]]];
    }
    return SecurityScanPlan::build($catalog, 'code', $proposals);
}

if (in_array($argv[1] ?? '', ['--probe', '--crash'], true)) {
    $ledger = new SecurityExecutionLedger($argv[2], $argv[3]);
    if ($argv[1] === '--probe') {
        $plan = ledgerTestPlan();
        echo json_encode($ledger->run($plan, $plan->jsonSerialize()['executions'][0]['execution_id'],
            static function (): array { throw new LogicException('Segundo motor não deveria iniciar.'); }, true), JSON_THROW_ON_ERROR);
        exit(0);
    }
    $plan = ledgerTestPlan();
    $ledger->run($plan, $plan->jsonSerialize()['executions'][0]['execution_id'], static function (): array { exit(17); }, true);
    exit(99);
}

$temporary = sys_get_temp_dir() . '/ninfa-execution-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$now = time();
$clock = static function () use (&$now): int { return $now; };
$store = new EvidenceStore($temporary . '/evidence');
$ledger = new SecurityExecutionLedger($temporary . '/ledger', $temporary . '/evidence', 3600, $clock);
$plan = ledgerTestPlan();
$execution = $plan->jsonSerialize()['executions'][0]['execution_id'];
$key = $plan->jsonSerialize()['executions'][0]['units'][0]['identity']['key'];
$calls = 0;
$lastEvidence = null;
$adapter = static function (array $group) use ($store, &$calls, &$lastEvidence, &$now): array {
    $calls++;
    $lastEvidence = $store->put(['synthetic' => true, 'coverage' => 'complete']);
    $units = [];
    foreach ($group['units'] as $unit) {
        $units[$unit['identity']['key']] = ['coverage' => 'complete', 'session_valid' => true,
            'valid_until' => $now + 600, 'evidence' => $lastEvidence];
    }
    return ['status' => 'completed', 'units' => $units];
};
$spawn = static function (string $mode, string $directory) use ($temporary): array {
    $process = proc_open([PHP_BINARY, __FILE__, $mode, $directory, $temporary . '/evidence'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert(is_resource($process));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};
try {
    assert($ledger->run($plan, $execution, $adapter, false)['decision'] === 'context-invalid');
    assert($calls === 0);
    $concurrent = static function (array $group) use ($temporary, $ledger, $spawn, $adapter, $plan, $execution): array {
        $other = new SecurityExecutionLedger($temporary . '/ledger', $temporary . '/evidence');
        assert($other->inspect() === ['decision' => 'busy']);
        assert($other->run($plan, $execution, $adapter, true) === ['decision' => 'busy']);
        $child = $spawn('--probe', $temporary . '/ledger');
        assert($child['exit'] === 0 && json_decode($child['stdout'], true) === ['decision' => 'busy']);
        $state = json_decode((string) file_get_contents($temporary . '/ledger/ledger.json'), true);
        assert(array_values($state['attempts'])[0][0]['status'] === 'running');
        assert(count(glob($temporary . '/ledger/plans/*.json')) === 1);
        return $adapter($group);
    };
    assert($ledger->run($plan, $execution, $concurrent, true)['decision'] === 'completed');
    assert($calls === 1);
    assert($ledger->run($plan, $execution, $adapter, true)['decision'] === 'reused');
    $newRun = ledgerTestPlan(run: 'b');
    assert($ledger->run($newRun, $execution, $adapter, true)['decision'] === 'reused');
    assert($calls === 1);
    assert((fileperms($temporary . '/ledger/ledger.json') & 0777) === 0600);
    assert((fileperms($temporary . '/ledger') & 0777) === 0700);
    $catalog = CapabilityCatalog::load(dirname(__DIR__));
    assert($ledger->fallbackDecision($catalog, $plan, $key, 'ninfa.php.xss.request-to-output', true)['decision'] === 'fallback-not-needed');

    $now += 601;
    assert($ledger->run($plan, $execution, $adapter, true)['decision'] === 'retry-required');
    assert($ledger->run($plan, $execution, $adapter, true, 'expired-coverage')['decision'] === 'completed');
    assert($calls === 2);
    file_put_contents($temporary . '/evidence/' . $lastEvidence['reference'], 'corrupted-envelope');
    assert($ledger->run($plan, $execution, $adapter, true)['decision'] === 'retry-required');
    assert($ledger->run($plan, $execution, $adapter, true, 'corrupt-evidence')['decision'] === 'completed');

    // Mudar contexto permite novo trabalho; nunca reutiliza o snapshot anterior.
    $changed = ledgerTestPlan(project: 'changed-project');
    assert($ledger->run($changed, $changed->jsonSerialize()['executions'][0]['execution_id'], $adapter, true)['decision'] === 'completed');
    $unknown = ledgerTestPlan(known: false);
    $unknownExecution = $unknown->jsonSerialize()['executions'][0]['execution_id'];
    assert($ledger->run($unknown, $unknownExecution, $adapter, true)['decision'] === 'completed');
    assert($ledger->run($unknown, $unknownExecution, $adapter, true, 'explicit-retry')['decision'] === 'unverifiable-context');

    // Cobertura parcial do grupo exige replanejamento sem repetir a parte concluída.
    $grouped = ledgerTestPlan(['psalm.TaintedHtml', 'psalm.TaintedSql']);
    $groupedId = $grouped->jsonSerialize()['executions'][0]['execution_id'];
    assert($ledger->run($grouped, $groupedId, $adapter, true)['decision'] === 'replan-required');

    $failPlan = ledgerTestPlan(project: 'failed-context');
    $failId = $failPlan->jsonSerialize()['executions'][0]['execution_id'];
    $failed = static function (array $group) use ($adapter): array {
        $result = $adapter($group);
        $result['status'] = 'failed';
        return $result;
    };
    assert($ledger->run($failPlan, $failId, $failed, true)['decision'] === 'failed');
    assert($ledger->run($failPlan, $failId, $adapter, true)['decision'] === 'retry-required');
    $failKey = $failPlan->jsonSerialize()['executions'][0]['units'][0]['identity']['key'];
    assert($ledger->fallbackDecision($catalog, $failPlan, $failKey, 'ninfa.php.xss.request-to-output', true)['decision'] === 'fallback-not-approved');
    $regroup = ledgerTestPlan(['psalm.TaintedHtml', 'psalm.TaintedSql'], project: 'failed-context');
    assert($ledger->run($regroup, $regroup->jsonSerialize()['executions'][0]['execution_id'], $adapter, true)['decision'] === 'retry-required');
    assert($ledger->run($failPlan, $failId, $adapter, true, 'engine-error')['decision'] === 'completed');

    foreach (['partial', 'unknown', 'invalid-session', 'raw-evidence', 'missing-evidence'] as $scenario) {
        $case = ledgerTestPlan(project: $scenario);
        $caseId = $case->jsonSerialize()['executions'][0]['execution_id'];
        $caseKey = $case->jsonSerialize()['executions'][0]['units'][0]['identity']['key'];
        assert($ledger->fallbackDecision($catalog, $case, $caseKey, 'ninfa.php.xss.request-to-output', true)['decision'] === 'primary-not-settled');
        $badCoverage = static function (array $group) use ($adapter, $scenario): array {
            $result = $adapter($group);
            foreach ($result['units'] as &$unit) {
                if (in_array($scenario, ['partial', 'unknown'], true)) {
                    $unit['coverage'] = $scenario;
                } elseif ($scenario === 'invalid-session') {
                    $unit['session_valid'] = false;
                } elseif ($scenario === 'raw-evidence') {
                    $unit['evidence']['raw'] = true;
                } else {
                    $unit['evidence']['reference'] = 'safe-' . str_repeat('0', 24) . '.json';
                }
            }
            unset($unit);
            return $result;
        };
        $outcome = $ledger->run($case, $caseId, $badCoverage, true);
        $decision = $outcome['decision'];
        assert($decision === (in_array($scenario, ['raw-evidence', 'missing-evidence'], true) ? 'interrupted' : 'completed'));
        assert($ledger->run($case, $caseId, $adapter, true)['decision'] !== 'reused');
        if ($decision === 'interrupted') {
            $another = ledgerTestPlan(project: 'another-context-during-interruption');
            assert($ledger->run($another, $another->jsonSerialize()['executions'][0]['execution_id'], $adapter, true)['decision'] === 'recovery-required');
            assert($ledger->confirmStopped($outcome['attempt'], 'adapter-job-stopped')['decision'] === 'stop-confirmed');
        }
    }

    // Exceção não confirma parada; mensagem sensível não vai para o journal.
    $interrupted = ledgerTestPlan(project: 'interrupted-context');
    $interruptedId = $interrupted->jsonSerialize()['executions'][0]['execution_id'];
    assert($ledger->run($interrupted, $interruptedId, static function (): array { throw new RuntimeException('SECRET-TEST'); }, true)['decision'] === 'interrupted');
    assert($ledger->run($interrupted, $interruptedId, $adapter, true, 'retry-interrupted')['decision'] === 'recovery-required');
    assert($ledger->run($interrupted, $interruptedId, $adapter, true, 'retry-interrupted', true)['decision'] === 'completed');
    assert(!str_contains((string) file_get_contents($temporary . '/ledger/ledger.json'), 'SECRET-TEST'));

    // Processo real sai no meio do adapter; outra instância recupera a trava, não a cobertura.
    $child = $spawn('--crash', $temporary . '/crashed-ledger');
    assert($child['exit'] === 17);
    $reopened = new SecurityExecutionLedger($temporary . '/crashed-ledger', $temporary . '/evidence', 3600, $clock);
    assert($reopened->run($plan, $execution, $adapter, true)['decision'] === 'recovery-required');
    assert($reopened->run($plan, $execution, $adapter, true, 'process-stopped')['decision'] === 'recovery-required');
    assert($reopened->run($plan, $execution, $adapter, true, 'process-stopped', true)['decision'] === 'completed');
    assert($reopened->run($plan, $execution, $adapter, true)['decision'] === 'reused');

    $state = $ledger->inspect();
    assert($state['ledger_version'] === 1);
    assert(in_array('reused', array_column(array_column($state['decisions'], 'result'), 'decision'), true));
    assert(in_array('replan-required', array_column(array_column($state['decisions'], 'result'), 'decision'), true));
    file_put_contents($temporary . '/ledger/ledger.json', '{broken');
    try {
        $ledger->run($plan, $execution, $adapter, true);
        throw new LogicException('Journal corrompido foi aceito.');
    } catch (JsonException) {
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}

echo "[OK] SEC-04.04: trava entre processos, journal, reuso, expiração, recuperação, retries e fallback restrito.\n";
