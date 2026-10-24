<?php
/** Confronta parsers com bytes sanitizados capturados dos seis motores/serviços reais. */
declare(strict_types=1);
require_once __DIR__ . '/../src/ComposerAuditParser.php';
require_once __DIR__ . '/../src/OsvClient.php';
require_once __DIR__ . '/../src/SemgrepParser.php';
require_once __DIR__ . '/../src/PsalmTaintParser.php';
require_once __DIR__ . '/../src/ZapParser.php';
require_once __DIR__ . '/../src/NucleiParser.php';
$fixtures = __DIR__ . '/fixtures/security-outputs';
foreach (['composer', 'osv', 'semgrep', 'psalm', 'zap', 'nuclei'] as $name) {
    $meta = json_decode(file_get_contents($fixtures . '/' . $name . '.capture.json'), true, flags: JSON_THROW_ON_ERROR);
    assert($meta['origin'] === 'real');
    assert(hash_file('sha256', $fixtures . '/' . $meta['artifact']) === $meta['sha256']);
    assert(strtotime($meta['captured_at']) !== false);
}
assert(count(SemgrepParser::parse(file_get_contents($fixtures . '/semgrep.json'), '/fixture')['findings']) > 0);
assert(count(PsalmTaintParser::parse(file_get_contents($fixtures . '/psalm.json'), '/fixture')) > 0);
$zap = ZapParser::parse(file_get_contents($fixtures . '/zap.json'));
assert(count($zap['findings']) > 0);
assert($zap['coverage']['status'] === 'unknown');
assert(count(NucleiParser::parse(file_get_contents($fixtures . '/nuclei.jsonl'))['findings']) > 0);
$root = sys_get_temp_dir() . '/ninfa-real-output-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    file_put_contents($root . '/Example.php', '<?php echo "fixture";');
    file_put_contents($root . '/composer.json', '{"require":{"ninfa/fixture":"1.0.0","monolog/monolog":"1.10.0"}}');
    file_put_contents($root . '/composer.lock', '{"packages":[{"name":"ninfa/fixture","version":"1.0.0"},{"name":"monolog/monolog","version":"1.10.0"}],"packages-dev":[]}');
    $inventory = SecurityInventory::fromContext(ProjectContext::fromRoot($root));
    $composer = ComposerAuditParser::parse(file_get_contents($fixtures . '/composer.json'), $inventory);
    assert(count($composer) === 2);
    assert($composer[0]->evidenceType === 'sca-advisory');
    assert($composer[1]->evidenceType === 'dependency-policy');
    assert(!isset($composer[1]->metadata['policy']['replacement']));
    $osv = json_decode(file_get_contents($fixtures . '/osv.json'), true, flags: JSON_THROW_ON_ERROR);
    $client = new OsvClient(static function (string $method, string $path, ?array $payload) use ($osv): array {
        if ($method === 'POST') {
            $results = [];
            foreach ($payload['queries'] as $query) {
                $results[] = $query['package']['name'] === 'monolog/monolog' ? $osv['batch']['results'][0] : [];
            }
            return ['results' => $results];
        }
        foreach ($osv['vulnerabilities'] as $record) {
            if (str_ends_with($path, $record['id'])) return $record;
        }
        throw new RuntimeException('Unexpected OSV detail');
    });
    $findings = $client->scan($inventory);
    assert(count($findings) > 0);
    assert(($findings[0]->metadata['advisory']['aliases'] ?? []) === ($osv['vulnerabilities'][0]['aliases'] ?? []));
    assert($findings[0]->metadata['advisory']['affected'] !== []);
} finally {
    unlink($root . '/Example.php'); unlink($root . '/composer.json'); unlink($root . '/composer.lock'); rmdir($root);
}
echo "[OK] Capturas reais das seis fontes: hashes, adaptadores e limites.\n";
