<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ToolResolver.php';
require_once dirname(__DIR__) . '/src/ProjectContext.php';
require_once dirname(__DIR__) . '/src/LefthookConfigGenerator.php';

$root = sys_get_temp_dir() . '/ninfa-tooling-' . bin2hex(random_bytes(4));

try {
    mkdir($root . '/src', 0775, true);
    mkdir($root . '/vendor/bin', 0775, true);
    mkdir($root . '/node_modules/.bin', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2', 'yiisoft/yii2' => '^2.0'],
    ], JSON_THROW_ON_ERROR));

    $phpstan = $root . '/vendor/bin/phpstan';
    file_put_contents($phpstan, 'fixture');
    $eslint = $root . '/node_modules/.bin/eslint';
    file_put_contents($eslint, 'fixture');

    $resolver = new ToolResolver();
    assert($resolver->resolve('phpstan', $root) === $phpstan);
    assert($resolver->resolve('eslint', $root) === $eslint);
    assert($resolver->resolve('tool-that-does-not-exist', $root) === 'tool-that-does-not-exist');

    $context = ProjectContext::fromRoot($root);
    $lefthook = (new LefthookConfigGenerator())->generate($context);
    assert(is_file($lefthook));
    assert(!str_starts_with($lefthook, $root));
    assert(!is_file($root . '/lefthook.yml'));

    $contents = (string) file_get_contents($lefthook);
    assert(str_contains($contents, 'ninfa-fix'));
    assert(str_contains($contents, 'ninfa-check'));
    assert(str_contains($contents, 'stage_fixed: true'));

    echo "[OK] ToolResolver e Lefthook externo integrados.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}
