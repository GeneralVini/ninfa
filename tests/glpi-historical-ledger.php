<?php

declare(strict_types=1);

/**
 * Protege a proveniência histórica e as filas ainda não reconciliadas do SIGAPS.
 *
 * A validação é documental: aprovar este teste não significa que os chats ou
 * plugins reais foram analisados. O objetivo é impedir remoção silenciosa dos
 * alertas GLPI P1 recuperados do PR #1 e status "resolvido" sem evidência.
 */
$file = dirname(__DIR__) . '/docs/GLPI-PLUGIN-HISTORICAL-LEDGER.json';
$ledger = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
assert($ledger['schema_version'] === 1);
assert($ledger['reconciliation_state'] === 'PARTIAL_SOURCE_RECOVERY');

/** @var array<string,true> $ids IDs de ocorrências históricas, sem duplicidade. */
$ids = [];
foreach ($ledger['cases'] as $case) {
    assert(!isset($ids[$case['case_id']]));
    $ids[$case['case_id']] = true;
    assert($case['source_kind'] === 'ninfa_pr_review');
    assert(str_starts_with($case['source_ref'], 'https://github.com/GeneralVini/ninfa/pull/1#discussion_r'));
    assert($case['origin_priority'] === 'P1');
    assert($case['not_consumer_issue'] === true);
    // A remediação ainda precisa de evidência de aceitação, não bastam testes sintéticos.
    if ($case['current_status'] === 'VERIFIED_FIXED') {
        assert(is_string($case['closure_evidence']) && $case['closure_evidence'] !== '');
    }
}
assert(array_keys($ids) === [
    'GLPI-PR1-REVIEW-01',
    'GLPI-PR1-REVIEW-02',
    'GLPI-PR1-REVIEW-03',
]);

/** @var list<string> $queues Filas de fontes pendentes, não representadas como zero. */
$queues = array_column($ledger['queues'], 'id');
assert(in_array('SIGAPS-LEGACY-CHAT', $queues, true));
assert(in_array('SIGAPS-LEGACY-REPORTS', $queues, true));
foreach ($ledger['queues'] as $queue) {
    assert($queue['count'] === null);
}
assert($ledger['historical_events'][0]['current_consumer_verified'] === false);

echo "[OK] Ledger GLPI: três P1 originais preservados e corpus SIGAPS/EGIDE pendente.\n";
