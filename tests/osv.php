<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/OsvClient.php';
require_once dirname(__DIR__) . '/src/ComposerAuditParser.php';
require_once dirname(__DIR__) . '/src/SecurityReport.php';

$root = sys_get_temp_dir() . '/ninfa-osv-' . bin2hex(random_bytes(4));

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
        'content-hash' => 'osv-test',
        'packages' => [
            ['name' => 'acme/runtime', 'version' => '1.2.3', 'type' => 'library'],
        ],
        'packages-dev' => [
            ['name' => 'acme/dev', 'version' => '2.1.0', 'type' => 'library'],
        ],
    ], JSON_THROW_ON_ERROR));

    $context = ProjectContext::fromRoot($root);
    $inventory = SecurityInventory::fromContext($context);
    $batchCalls = 0;
    $getCalls = [];

    $transport = static function (string $method, string $path, ?array $payload) use (&$batchCalls, &$getCalls): array {
        if ($method === 'POST' && $path === '/querybatch') {
            $batchCalls++;
            $queries = $payload['queries'] ?? null;
            assert(is_array($queries));

            if ($batchCalls === 1) {
                assert(count($queries) === 2);
                $names = [];
                $results = [];
                foreach ($queries as $query) {
                    assert(($query['package']['ecosystem'] ?? null) === 'Packagist');
                    $name = $query['package']['name'] ?? null;
                    $version = $query['version'] ?? null;
                    assert(is_string($name));
                    assert(is_string($version));
                    $names[$name] = $version;

                    $results[] = $name === 'acme/runtime'
                        ? [
                            'vulns' => [['id' => 'GHSA-test-runtime', 'modified' => '2026-09-01T00:00:00Z']],
                            'next_page_token' => 'next-runtime',
                        ]
                        : ['vulns' => []];
                }
                assert($names === [
                    'acme/dev' => '2.1.0',
                    'acme/runtime' => '1.2.3',
                ] || $names === [
                    'acme/runtime' => '1.2.3',
                    'acme/dev' => '2.1.0',
                ]);
                return ['results' => $results];
            }

            assert(count($queries) === 1);
            assert(($queries[0]['package']['name'] ?? null) === 'acme/runtime');
            assert(($queries[0]['version'] ?? null) === '1.2.3');
            assert(($queries[0]['page_token'] ?? null) === 'next-runtime');
            return [
                'results' => [[
                    'vulns' => [['id' => 'OSV-TEST-SECOND', 'modified' => '2026-09-02T00:00:00Z']],
                ]],
            ];
        }

        if ($method === 'GET' && str_starts_with($path, '/vulns/')) {
            $id = rawurldecode(substr($path, strlen('/vulns/')));
            $getCalls[] = $id;
            if ($id === 'GHSA-test-runtime') {
                return [
                    'id' => 'GHSA-test-runtime',
                    'aliases' => ['CVE-2026-4000'],
                    'summary' => 'OSV runtime advisory',
                    'published' => '2026-09-01T00:00:00Z',
                    'modified' => '2026-09-02T00:00:00Z',
                    'database_specific' => ['severity' => 'HIGH'],
                    'references' => [['type' => 'ADVISORY', 'url' => 'https://example.test/osv-runtime']],
                    'affected' => [[
                        'package' => ['name' => 'acme/runtime', 'ecosystem' => 'Packagist'],
                    ]],
                ];
            }
            if ($id === 'OSV-TEST-SECOND') {
                return [
                    'id' => 'OSV-TEST-SECOND',
                    'summary' => 'Second OSV advisory',
                    'modified' => '2026-09-02T00:00:00Z',
                    'references' => [],
                    'affected' => [[
                        'package' => ['name' => 'acme/runtime', 'ecosystem' => 'Packagist'],
                        'ecosystem_specific' => ['severity' => 'MODERATE'],
                    ]],
                ];
            }
        }

        throw new RuntimeException('Unexpected OSV request: ' . $method . ' ' . $path);
    };

    $osvFindings = (new OsvClient($transport))->scan($inventory);
    assert($batchCalls === 2);
    sort($getCalls);
    assert($getCalls === ['GHSA-test-runtime', 'OSV-TEST-SECOND']);
    assert(count($osvFindings) === 2);
    assert($osvFindings[0]->tool === 'osv');
    assert(($osvFindings[0]->metadata['component']['name'] ?? null) === 'acme/runtime');
    assert(($osvFindings[0]->metadata['component']['relationship'] ?? null) === 'direct');
    assert(in_array($osvFindings[0]->severity, ['high', 'medium'], true));

    $composerJson = json_encode([
        'advisories' => [
            'acme/runtime' => [[
                'advisoryId' => 'PKSA-osv-dedup',
                'packageName' => 'acme/runtime',
                'affectedVersions' => '<1.2.4',
                'title' => 'Composer view of same advisory',
                'cve' => 'CVE-2026-4000',
                'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-test-runtime']],
                'severity' => 'high',
            ]],
        ],
        'abandoned' => [],
    ], JSON_THROW_ON_ERROR);
    $composerFindings = ComposerAuditParser::parse($composerJson, $inventory);
    assert(count($composerFindings) === 1);

    $runResult = new RunResult('security', [
        ToolResult::completed('composer-audit', 1, $composerFindings, 4),
        ToolResult::completed('osv', 1, $osvFindings, 7),
        ToolResult::completed('psalm-taint', 0, [], 1),
        ToolResult::completed('semgrep', 0, [], 1),
    ], 1);
    $report = json_decode(
        json_encode(new SecurityReport($inventory, $runResult), JSON_THROW_ON_ERROR),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    assert(($report['schema_version'] ?? null) === 1);
    assert(count($report['sca']['sources'] ?? []) === 2);
    assert(count($report['sca']['source_findings'] ?? []) === 3);
    assert(count($report['sca']['vulnerabilities'] ?? []) === 2);

    $vulnerabilities = [];
    foreach ($report['sca']['vulnerabilities'] as $vulnerability) {
        $vulnerabilities[$vulnerability['canonical_id']] = $vulnerability;
    }
    assert(isset($vulnerabilities['CVE-2026-4000']));
    assert(in_array('GHSA-TEST-RUNTIME', $vulnerabilities['CVE-2026-4000']['aliases'] ?? [], true));
    assert(in_array('PKSA-OSV-DEDUP', $vulnerabilities['CVE-2026-4000']['aliases'] ?? [], true));
    assert(($vulnerabilities['CVE-2026-4000']['sources'] ?? []) === ['composer-audit', 'osv']);
    assert(($vulnerabilities['CVE-2026-4000']['severity'] ?? null) === 'high');
    assert(isset($vulnerabilities['OSV-TEST-SECOND']));

    echo "[OK] OSV usa querybatch/paginação, normaliza findings e deduplica aliases no security report.\n";
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
