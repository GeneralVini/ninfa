<?php
/** Segredos em estruturas/texto e comportamento real de armazenamento privado. */
declare(strict_types=1);
require_once __DIR__ . '/../src/EvidenceStore.php';
$root = sys_get_temp_dir() . '/ninfa-evidence-test-' . bin2hex(random_bytes(8));
$store = new EvidenceStore($root);
try {
    EvidenceSanitizer::registerSecrets(['UNLABELED_CANARY']);
    assert(!str_contains(EvidenceSanitizer::text('trace UNLABELED_CANARY'), 'CANARY'));
    assert(!str_contains(EvidenceSanitizer::text('{"password":"JSON_CANARY"}'), 'CANARY'));
    $input = ['headers' => ['Authorization' => 'Bearer HEADER_CANARY'], 'url' => 'https://user:PASS_CANARY@localhost/?q=QUERY_CANARY&token=TOKEN_CANARY', 'message' => "Cookie: sid=COOKIE_CANARY\npassword=PASS_CANARY person@example.org", 'trace' => [['snippet' => 'OPAQUE_CANARY', 'message' => 'Bearer TRACE_CANARY']], 'api_key' => 'KEY_CANARY'];
    $ref = $store->put($input);
    $path = $root . '/' . $ref['reference'];
    $bytes = file_get_contents($path);
    assert(!str_contains($bytes, 'CANARY'));
    assert(!str_contains($bytes, 'person@example.org'));
    $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    assert($ref['sha256'] === hash('sha256', $bytes));
    assert($record['sha256'] === hash('sha256', $record['payload']));
    assert((fileperms($path) & 0777) === 0600);
    assert((fileperms($root) & 0777) === 0700);
    try { $store->put('RAW_CANARY', true); assert(false); } catch (RuntimeException) {}
    $raw = (new EvidenceStore($root, true))->put('RAW_CANARY', true);
    assert($raw['expires_at'] < $ref['expires_at']);
    // Uma abertura/limpeza sem nova captura também aplica expiração.
    assert($store->cleanup(time() + 86401) === 1);
    assert($store->cleanup(time() + 2592001) === 1);
    assert(!is_file($path));
    EvidenceStore::atomicWrite($root . '/security-report.json', '{}');
    touch($root . '/security-report.json', time() - 2592001);
    assert(EvidenceStore::cleanupReports($root) === 1);
    assert(!file_exists($root . '/security-report.json'));
    symlink('/tmp', $root . '/escape');
    try { EvidenceStore::atomicWrite($root . '/escape/dont-write', 'bad'); assert(false); } catch (RuntimeException) {}
    unlink($root . '/escape');
    mkdir($root . '/destination');
    try { EvidenceStore::atomicWrite($root . '/destination', 'bad'); assert(false); } catch (RuntimeException) {}
    assert(glob($root . '/.evidence-*') === []);
    rmdir($root . '/destination');
} finally {
    EvidenceSanitizer::registerSecrets([]);
    foreach (glob($root . '/*') ?: [] as $file) { if (is_file($file)) unlink($file); }
    rmdir($root);
}
echo "[OK] Evidências: segredos, permissões, hashes, raw opt-in, retenção e falha atômica.\n";
