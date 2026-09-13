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

    $context = ProjectContext::fromRoot($projectRoot);
    $lefthook = (new LefthookConfigGenerator())->generate($context);
    assert(is_file($lefthook));
    assert(!str_starts_with($lefthook, $projectRoot));
    assert(!is_file($projectRoot . '/lefthook.yml'));

    $contents = (string) file_get_contents($lefthook);
    assert(str_contains($contents, 'ninfa-fix'));
    assert(str_contains($contents, 'ninfa-check'));
    assert(str_contains($contents, 'stage_fixed: true'));

    echo "[OK] ToolResolver encontra tooling gerenciado, falha de forma explícita e Lefthook permanece externo.\n";
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
