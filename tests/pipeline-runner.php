<?php

declare(strict_types=1);

/**
 * Exercita o fluxo integrado do CLI com ferramentas falsas e workspace isolado.
 *
 * O cenário protege comportamentos que não são evidentes por testes unitários:
 * execução security não fail-fast, preservação do primeiro exit code, Composer
 * Audit estruturado + OSV + security-report, aviso de DAST sem execução, etapas
 * não aplicáveis/indisponíveis, gate Semgrep por severidade, preferência por
 * `composer test` e falha quando PHPUnit termina sem executar testes.
 */

require_once dirname(__DIR__) . '/src/ProjectContext.php';
require_once dirname(__DIR__) . '/src/ProcessRunner.php';

$root = sys_get_temp_dir() . '/ninfa-runner-' . bin2hex(random_bytes(4));
$projectRoot = $root . '/project';
$workspaceRoot = $root . '/workspace';
$log = $root . '/steps.log';

/** @param list<string> $lines */
function createFakeTool(string $path, string $log, array $lines, int $exitCode, string $stdout = ''): void
{
    $script = "#!/usr/bin/env php\n<?php\n"
        . 'file_put_contents(' . var_export($log, true) . ', ' . var_export(implode("\n", $lines) . "\n", true)
        . ", FILE_APPEND);\n"
        . 'fwrite(STDOUT, ' . var_export($stdout, true) . ");\nexit({$exitCode});\n";
    file_put_contents($path, $script);
    chmod($path, 0755);
}

function createFakeComposerAudit(string $path, string $log, string $stdout, int $exitCode): void
{
    $required = ['--no-plugins', '--no-scripts', '--no-interaction', 'audit', '--locked', '--format=json'];
    $script = "#!/usr/bin/env php\n<?php\n"
        . '$required = ' . var_export($required, true) . ";\n"
        . 'foreach ($required as $argument) { if (!in_array($argument, array_slice($argv, 1), true)) { fwrite(STDERR, "missing:" . $argument); exit(9); } }' . "\n"
        . 'file_put_contents(' . var_export($log, true) . ', "composer-audit\n", FILE_APPEND);' . "\n"
        . 'fwrite(STDOUT, ' . var_export($stdout, true) . ');' . "\n"
        . 'exit(' . $exitCode . ');' . "\n";
    file_put_contents($path, $script);
    chmod($path, 0755);
}

try {
    mkdir($projectRoot . '/src', 0775, true);
    mkdir($projectRoot . '/vendor/bin', 0775, true);
    file_put_contents($projectRoot . '/src/Example.php', "<?php final class Example {}\n");
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'example/dependency' => '^1.0',
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($projectRoot . '/composer.lock', json_encode([
        'content-hash' => 'runner-test',
        'packages' => [
            ['name' => 'example/dependency', 'version' => '1.2.3', 'type' => 'library'],
            ['name' => 'example/legacy', 'version' => '1.0.0', 'type' => 'library'],
        ],
        'packages-dev' => [],
    ], JSON_THROW_ON_ERROR));

    $auditJson = json_encode([
        'advisories' => [
            'example/dependency' => [[
                'advisoryId' => 'PKSA-runner-test',
                'packageName' => 'example/dependency',
                'affectedVersions' => '<1.2.4',
                'title' => 'Runner advisory',
                'cve' => 'CVE-2026-3000',
                'link' => 'https://example.test/runner',
                'reportedAt' => '2026-09-01T00:00:00+00:00',
                'sources' => [
                    ['name' => 'GitHub', 'remoteId' => 'GHSA-runner-test'],
                ],
                'severity' => 'high',
            ]],
        ],
        'abandoned' => [
            'example/legacy' => 'example/replacement',
        ],
    ], JSON_THROW_ON_ERROR);

    createFakeComposerAudit($projectRoot . '/vendor/bin/composer', $log, $auditJson, 3);
    createFakeTool($projectRoot . '/vendor/bin/psalm', $log, ['psalm-taint'], 0, '[]');
    $semgrepJson = json_encode([
        'results' => [[
            'check_id' => 'ninfa.php.unsafe-deserialization.unserialize',
            'path' => $projectRoot . '/src/Example.php',
            'start' => ['line' => 1],
            'end' => ['line' => 1],
            'extra' => ['message' => 'Unsafe unserialize', 'severity' => 'ERROR', 'metadata' => []],
        ]],
        'errors' => [],
        'paths' => ['scanned' => [$projectRoot . '/src/Example.php'], 'skipped' => []],
    ], JSON_THROW_ON_ERROR);
    createFakeTool($projectRoot . '/vendor/bin/semgrep', $log, ['semgrep'], 0, $semgrepJson);

    $osvFixture = $root . '/osv.json';
    file_put_contents($osvFixture, json_encode([
        'querybatch' => [
            'results' => [
                ['vulns' => []],
                ['vulns' => []],
            ],
        ],
        'vulns' => [],
    ], JSON_THROW_ON_ERROR));

    $environment = [
        'NINFA_WORKSPACE_ROOT' => $workspaceRoot,
        'NINFA_OSV_FIXTURE' => $osvFixture,
        'NO_COLOR' => '1',
        'NINFA_DAST' => '1',
    ];
    foreach ($environment as $name => $value) {
        putenv($name . '=' . $value);
    }

    $result = (new ProcessRunner())->runCaptured([
        PHP_BINARY,
        dirname(__DIR__) . '/bin/ninfa',
        'security',
        $projectRoot,
    ], $projectRoot);

    assert($result->exitCode === 3);
    assert((string) file_get_contents($log) === "composer-audit\npsalm-taint\nsemgrep\n");
    assert(substr_count($result->stdout, '[NINFA] Resumo (security)') === 1);
    assert(str_contains($result->stdout, 'composer-audit: failed (codigo 3)'));
    assert(str_contains($result->stdout, 'osv: ok (codigo 0)'));
    assert(str_contains($result->stdout, 'PKSA-runner-test'));
    assert(str_contains($result->stdout, 'example/dependency 1.2.3 (runtime, direct)'));
    assert(str_contains($result->stdout, 'psalm-taint: ok (codigo 0)'));
    assert(str_contains($result->stdout, 'semgrep: failed (codigo 1)'));
    assert(str_contains($result->stdout, 'Semgrep — arquivos analisados: 1'));
    assert(!str_contains($result->stdout, 'dast:'));
    assert(str_contains($result->stderr, 'DAST está desabilitado no Ninfa'));

    $reportFile = ProjectContext::fromRoot($projectRoot)->workspace()->file('security-report.json');
    assert(is_file($reportFile));
    $report = json_decode((string) file_get_contents($reportFile), true, 512, JSON_THROW_ON_ERROR);
    assert(count($report['sca']['vulnerabilities'] ?? []) === 1);
    assert(($report['sca']['vulnerabilities'][0]['canonical_id'] ?? null) === 'CVE-2026-3000');
    assert(count($report['sca']['policies'] ?? []) === 1);
    assert(($report['schema_version'] ?? null) === 3);
    assert(count($report['sast']['findings'] ?? []) === 1);
    assert(($report['sast']['sources'][1]['coverage']['scanned_files'] ?? null) === 1);

    unlink($projectRoot . '/composer.lock');
    unlink($projectRoot . '/vendor/bin/psalm');
    file_put_contents($log, '');

    $partial = (new ProcessRunner())->runCaptured([
        PHP_BINARY,
        dirname(__DIR__) . '/bin/ninfa',
        'security',
        $projectRoot,
    ], $projectRoot);

    assert($partial->exitCode === 1);
    assert((string) file_get_contents($log) === "semgrep\n");
    assert(str_contains($partial->stdout, 'composer-audit: not_applicable (nao aplicavel)'));
    assert(str_contains($partial->stdout, 'osv: not_applicable (sem packages Composer resolvidos)'));
    assert(str_contains($partial->stdout, 'psalm-taint: unavailable'));
    assert(str_contains($partial->stdout, 'semgrep: failed (codigo 1)'));
    assert(!str_contains($partial->stdout, 'dast:'));
    assert(str_contains($partial->stderr, 'Ferramenta "psalm" não encontrada'));

    file_put_contents($projectRoot . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2'],
        'scripts' => ['test' => 'php tests/custom.php'],
    ], JSON_THROW_ON_ERROR));
    createFakeTool($projectRoot . '/vendor/bin/composer', $log, ['composer-test'], 0, "Custom tests passed.\n");
    createFakeTool($projectRoot . '/vendor/bin/ecs', $log, ['ecs'], 0);
    createFakeTool($projectRoot . '/vendor/bin/rector', $log, ['rector'], 0);
    createFakeTool($projectRoot . '/vendor/bin/phpstan', $log, ['phpstan'], 0, '{"files":[]}');
    createFakeTool($projectRoot . '/vendor/bin/psalm', $log, ['psalm'], 0, '[]');
    file_put_contents($log, '');

    $customTests = (new ProcessRunner())->runCaptured([
        PHP_BINARY,
        dirname(__DIR__) . '/bin/ninfa',
        'check',
        $projectRoot,
    ], $projectRoot);

    assert($customTests->exitCode === 0);
    assert(str_contains((string) file_get_contents($log), "composer-test\n"));
    assert(str_contains($customTests->stdout, "Custom tests passed.\n"));
    assert(str_contains($customTests->stdout, 'test: ok (codigo 0)'));

    file_put_contents($projectRoot . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2'],
    ], JSON_THROW_ON_ERROR));
    createFakeTool(
        $projectRoot . '/vendor/bin/phpunit',
        $log,
        ['phpunit-empty'],
        0,
        "No tests executed!\n",
    );
    file_put_contents($log, '');

    $emptyTests = (new ProcessRunner())->runCaptured([
        PHP_BINARY,
        dirname(__DIR__) . '/bin/ninfa',
        'check',
        $projectRoot,
    ], $projectRoot);

    assert($emptyTests->exitCode === 1);
    assert(str_contains((string) file_get_contents($log), "phpunit-empty\n"));
    assert(str_contains($emptyTests->stderr, 'nenhuma verificacao foi executada'));
    assert(str_contains($emptyTests->stdout, 'test: failed (codigo 1)'));

    echo "[OK] Runner consolida SCA/SAST, aplica gate Semgrep por severidade, desabilita DAST e preserva check/testes.\n";
} finally {
    putenv('NINFA_WORKSPACE_ROOT');
    putenv('NINFA_OSV_FIXTURE');
    putenv('NO_COLOR');
    putenv('NINFA_DAST');
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
