<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ComposerAuditParser.php';

$root = sys_get_temp_dir() . '/ninfa-composer-audit-' . bin2hex(random_bytes(4));

try {
    mkdir($root . '/src', 0775, true);
    file_put_contents($root . '/src/Example.php', "<?php final class Example {}\n");
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'acme/runtime' => '^1.0',
        ],
        'require-dev' => [
            'acme/dev' => '^2.0',
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/composer.lock', json_encode([
        'content-hash' => 'audit-test',
        'packages' => [
            ['name' => 'acme/runtime', 'version' => '1.2.3', 'type' => 'library'],
            ['name' => 'acme/transitive', 'version' => '3.4.5', 'type' => 'library'],
        ],
        'packages-dev' => [
            ['name' => 'acme/dev', 'version' => '2.1.0', 'type' => 'library'],
        ],
    ], JSON_THROW_ON_ERROR));

    $context = ProjectContext::fromRoot($root);
    $inventory = SecurityInventory::fromContext($context);

    $audit = json_encode([
        'advisories' => [
            'acme/runtime' => [[
                'advisoryId' => 'PKSA-test-runtime',
                'packageName' => 'acme/runtime',
                'affectedVersions' => '>=1.0,<1.2.4',
                'title' => 'Runtime advisory',
                'cve' => 'CVE-2026-1000',
                'link' => 'https://example.test/runtime',
                'reportedAt' => '2026-09-01T00:00:00+00:00',
                'sources' => [
                    ['name' => 'GitHub', 'remoteId' => 'GHSA-test-runtime'],
                ],
                'severity' => 'high',
            ]],
            'acme/transitive' => [[
                'advisoryId' => 'PKSA-test-transitive',
                'packageName' => 'acme/transitive',
                'affectedVersions' => '<3.4.6',
                'title' => 'Transitive advisory',
                'sources' => [
                    ['name' => 'FriendsOfPHP', 'remoteId' => 'CVE-2026-2000'],
                ],
                'reportedAt' => '2026-09-02T00:00:00+00:00',
                'severity' => 'medium',
            ]],
        ],
        'abandoned' => [
            'acme/dev' => 'acme/dev-next',
        ],
    ], JSON_THROW_ON_ERROR);

    $findings = ComposerAuditParser::parse($audit, $inventory);
    assert(count($findings) === 3);

    $runtime = $findings[0];
    assert($runtime->tool === 'composer-audit');
    assert($runtime->rule === 'PKSA-test-runtime');
    assert($runtime->severity === 'high');
    assert($runtime->confidence === 'high');
    assert($runtime->evidenceType === 'sca-advisory');
    assert(($runtime->metadata['component']['name'] ?? null) === 'acme/runtime');
    assert(($runtime->metadata['component']['version'] ?? null) === '1.2.3');
    assert(($runtime->metadata['component']['relationship'] ?? null) === 'direct');
    assert(($runtime->metadata['component']['scope'] ?? null) === 'runtime');
    assert(($runtime->metadata['component']['inventory_source'] ?? null) === 'composer.lock');
    assert(($runtime->metadata['advisory']['affected_versions'] ?? null) === '>=1.0,<1.2.4');
    assert(in_array('CVE-2026-1000', $runtime->metadata['advisory']['aliases'] ?? [], true));
    assert(in_array('GHSA-test-runtime', $runtime->metadata['advisory']['aliases'] ?? [], true));

    $transitive = $findings[1];
    assert(($transitive->metadata['component']['relationship'] ?? null) === 'transitive');
    assert(($transitive->metadata['component']['scope'] ?? null) === 'runtime');

    $abandoned = $findings[2];
    assert($abandoned->rule === 'composer.abandoned');
    assert($abandoned->evidenceType === 'dependency-policy');
    assert(($abandoned->metadata['component']['scope'] ?? null) === 'dev');
    assert(($abandoned->metadata['policy']['replacement'] ?? null) === 'acme/dev-next');

    $serialized = json_decode(json_encode($runtime, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    assert(($serialized['metadata']['component']['version'] ?? null) === '1.2.3');

    try {
        ComposerAuditParser::parse('{invalid', $inventory);
        assert(false, 'JSON inválido deve falhar.');
    } catch (JsonException) {
    }

    echo "[OK] Composer Audit normaliza advisories e policy findings com contexto do inventário.\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
