<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ToolResolver.php';
require_once dirname(__DIR__) . '/src/ProcessRunner.php';
require_once dirname(__DIR__) . '/src/ProjectContext.php';
require_once dirname(__DIR__) . '/src/LefthookConfigGenerator.php';

$root = sys_get_temp_dir() . '/ninfa-tooling-' . bin2hex(random_bytes(4));
$ninfaRoot = $root . '/ninfa';
$projectRoot = $root . '/project';

try {
    mkdir($projectRoot . '/src', 0775, true);
    mkdir($projectRoot . '/vendor/bin', 0775, true);
    mkdir($projectRoot . '/node_modules/.bin', 0775, true);
    mkdir($ninfaRoot . '/.tools/semgrep/bin', 0775, true);

    file_put_contents($projectRoot . '/src/Example.php', "<?php final class Example {}\n");
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2', 'yiisoft/yii2' => '^2.0'],
    ], JSON_THROW_ON_ERROR));

    $phpstan = $projectRoot . '/vendor/bin/phpstan';
    file_put_contents($phpstan, "#!/bin/sh\nexit 0\n");
    chmod($phpstan, 0755);

    $eslint = $projectRoot . '/node_modules/.bin/eslint';
    file_put_contents($eslint, "#!/bin/sh\nexit 0\n");
    chmod($eslint, 0755);

    $semgrep = $ninfaRoot . '/.tools/semgrep/bin/semgrep';
    file_put_contents($semgrep, "#!/bin/sh\nexit 0\n");
    chmod($semgrep, 0755);

    $resolver = new ToolResolver($ninfaRoot);
    assert($resolver->resolve('phpstan', $projectRoot) === $phpstan);
    assert($resolver->resolve('eslint', $projectRoot) === $eslint);
    assert($resolver->resolve('semgrep', $projectRoot) === $semgrep);

    try {
        $resolver->resolve('tool-that-does-not-exist', $projectRoot);
        assert(false, 'Ferramenta ausente deve falhar explicitamente.');
    } catch (RuntimeException $error) {
        assert(str_contains($error->getMessage(), 'não encontrada'));
    }

    try {
        (new ProcessRunner())->run([$root . '/missing-binary'], $projectRoot);
        assert(false, 'Processo inexistente deve falhar sem warning bruto.');
    } catch (RuntimeException $error) {
        assert(str_contains($error->getMessage(), 'Falha ao iniciar a ferramenta'));
    }

    $captured = (new ProcessRunner())->runCaptured([PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);'], $projectRoot);
    assert($captured->exitCode === 3);
    assert($captured->stdout === 'out');
    assert($captured->stderr === 'err');

    $context = ProjectContext::fromRoot($projectRoot);
    $lefthook = (new LefthookConfigGenerator())->generate($context);
    assert(is_file($lefthook));
    assert(!str_starts_with($lefthook, $projectRoot));
    assert(!is_file($projectRoot . '/lefthook.yml'));

    $contents = (string) file_get_contents($lefthook);
    assert(str_contains($contents, 'ninfa-fix'));
    assert(str_contains($contents, 'ninfa-check'));
    assert(str_contains($contents, 'stage_fixed: true'));

    $stanJson = json_encode([
        'totals' => ['errors' => 0, 'file_errors' => 1],
        'files' => [
            $projectRoot . '/src/Example.php' => [
                'errors' => 1,
                'messages' => [[
                    'message' => 'Parameter #1 $string expects string, mixed given.',
                    'line' => 19,
                    'identifier' => 'argument.type',
                ]],
            ],
        ],
        'errors' => [],
    ], JSON_THROW_ON_ERROR);
    file_put_contents($phpstan, "#!/usr/bin/env php\n<?php echo " . var_export($stanJson, true) . "; exit(1);\n");
    chmod($phpstan, 0755);

    $psalm = $projectRoot . '/vendor/bin/psalm';
    file_put_contents($psalm, "#!/usr/bin/env php\n<?php echo '[]'; exit(0);\n");
    chmod($psalm, 0755);

    $assist = (new ProcessRunner())->runCaptured([
        PHP_BINARY,
        dirname(__DIR__) . '/scripts/ninfa-configure.php',
        $projectRoot,
        '--assist',
    ], $projectRoot);
    assert($assist->exitCode === 1);
    assert(str_contains($assist->stdout, 'Regra: argument.type'));
    assert(str_contains($assist->stdout, 'Corrigir:'));
    assert(str_contains($assist->stdout, 'Correção:'));

    $audit = $context->workspace()->file('assist/findings.json');
    assert(is_file($audit));
    $auditData = json_decode((string) file_get_contents($audit), true, 512, JSON_THROW_ON_ERROR);
    assert(($auditData['findings'][0]['rule'] ?? null) === 'argument.type');
    assert(is_file($context->workspace()->file('assist/phpstan.json')));
    assert(is_file($context->workspace()->file('assist/psalm.json')));

    echo "[OK] ToolResolver, captura de processo, assist auditável e Lefthook externo integrados.\n";
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
