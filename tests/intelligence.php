<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/VulnerabilityIntelligence.php';

$intelligence = new VulnerabilityIntelligence(
    new EpssClient(static fn (array $cves): array => ['data' => [['cve' => $cves[0], 'epss' => '0.42', 'percentile' => '0.91', 'date' => '2026-09-21']]]),
    new CisaKevClient(static fn (): array => ['vulnerabilities' => [['cveID' => 'CVE-2026-1234', 'dateAdded' => '2026-01-01', 'dueDate' => '2026-01-21', 'requiredAction' => 'Apply update.']]]),
);
$result = $intelligence->enrich([
    ['canonical_id' => 'CVE-2026-1234', 'aliases' => ['GHSA-test'], 'components' => [['name' => 'demo']]],
    ['canonical_id' => 'GHSA-no-cve', 'aliases' => [], 'components' => []],
]);

assert($result['sources'][0]['state'] === 'ok');
assert($result['sources'][1]['state'] === 'ok');
assert($result['vulnerabilities'][0]['epss']['score'] === 0.42);
assert($result['vulnerabilities'][0]['kev']['known_exploited'] === true);
assert($result['vulnerabilities'][1]['epss'] === null);
assert($result['vulnerabilities'][1]['kev']['known_exploited'] === false);

$unavailable = new VulnerabilityIntelligence(
    new EpssClient(static fn (array $cves): array => throw new RuntimeException('offline')),
    new CisaKevClient(static fn (): array => throw new RuntimeException('offline')),
);
$offline = $unavailable->enrich([['canonical_id' => 'CVE-2026-1234', 'aliases' => []]]);
assert($offline['sources'][0]['state'] === 'unavailable');
assert($offline['sources'][1]['state'] === 'unavailable');

echo "[OK] Intelligence EPSS/KEV preserva CVEs, estados e falhas sem bloquear SCA.\n";
