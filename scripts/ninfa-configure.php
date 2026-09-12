<?php

declare(strict_types=1);

$projectRoot = $argv[1] ?? getcwd();
$projectRoot = realpath($projectRoot) ?: $projectRoot;

if (!is_file($projectRoot . '/composer.json')) {
    fwrite(STDERR, "[ERRO] composer.json não encontrado.\n");
    exit(1);
}

$composer = json_decode((string) file_get_contents($projectRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$packages = array_merge(array_keys($composer['require'] ?? []), array_keys($composer['require-dev'] ?? []));

$framework = 'PHP genérico';
if (in_array('laravel/framework', $packages, true)) {
    $framework = 'Laravel';
} elseif (in_array('yiisoft/yii2', $packages, true)) {
    $framework = 'Yii 2';
} elseif (array_filter($packages, static fn(string $p): bool => str_starts_with($p, 'yiisoft/'))) {
    $framework = 'Yii 3';
} elseif (in_array('symfony/framework-bundle', $packages, true)) {
    $framework = 'Symfony';
}

$candidates = ['src', 'app', 'config', 'modules', 'console', 'commands', 'public', 'web', 'tests'];
$paths = [];
foreach ($candidates as $path) {
    if (is_dir($projectRoot . '/' . $path)) {
        $paths[] = $path;
    }
}
if ($paths === []) {
    $paths = ['src'];
}

$docs = [];
if (is_file($projectRoot . '/README.md')) {
    $docs[] = 'README.md';
}
foreach (glob($projectRoot . '/docs/*.md') ?: [] as $file) {
    $docs[] = 'docs/' . basename($file);
}

$contextDir = $projectRoot . '/.ninfa';
if (!is_dir($contextDir)) {
    mkdir($contextDir, 0775, true);
}
file_put_contents(
    $contextDir . '/context.json',
    json_encode(['framework' => $framework, 'paths' => $paths, 'documentation' => $docs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

echo "[NINFA] Framework: {$framework}\n";
echo '[NINFA] Caminhos detectados: ' . implode(', ', $paths) i . PHP_EOL;
echo '[NINFA] Documentação consultada: ' . ($docs === [] ? 'nenhuma' : implode(', ', $docs)) . HP_EOL;
