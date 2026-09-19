<?php

declare(strict_types=1);

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

try {
    mkdir($projectRoot . '/src', 0775, true);
    mkdir($projectRoot . '/vendor/bin', 0775, true);
    file_put_contents($projectRoot . '/src/Example.php', "<?php final class Example {}\n");
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2'],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($projectRoot . '/composer.lock', '{}');

    createFakeTool($projectRoot . '/vendor/bin/composer', $log, ['composer-audit'], 3);
    createFakeTool($projectRoot . '/vendor/bin/psalm', $log, ['psalm-taint'], 0);
    createFakeTool($projectRoot . '/vendor/bin/semgrep', $log, ['semgrep'], 4);

    $environment = [
        'NINFA_WORKSPACE_ROOT' => $workspaceRoot,
        'NO_COLOR' => '1',
        'NINFA_DAST' => '0',
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
    assert(str_contains($result->stdout, 'psalm-taint: ok (codigo 0)'));
    assert(str_contains($result->stdout, 'semgrep: failed (codigo 4)'));
    assert(str_contains($result->stdout, 'dast: skipped (opcional desabilitada)'));

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
    assert(str_contains($partial->stdout, 'composer-audit: skipped (nao aplicavel)'));
    assert(str_contains($partial->stdout, 'psalm-taint: error (codigo 1)'));
    assert(str_contains($partial->stdout, 'semgrep: failed (codigo 4)'));
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

    echo "[OK] Runner consolida etapas, respeita composer test e bloqueia suite vazia.\n";
} finally {
    putenv('NINFA_WORKSPACE_ROOT');
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
