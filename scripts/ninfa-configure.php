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
} elseif (array_filter($packages, static fn (string $package): bool => str_starts_with($package, 'yiisoft/'))) {
    $framework = 'Yii 3';
} elseif (in_array('symfony/framework-bundle', $packages, true)) {
    $framework = 'Symfony';
}

$candidates = ['src', 'app', 'config', 'modules', 'console', 'commands', 'public', 'web', 'tests'];
$paths = [];
foreach ($candidates as $candidate) {
    if (is_dir($projectRoot . '/' . $candidate)) {
        $paths[] = $candidate;
    }
}

if ($paths === []) {
    $paths = ['src'];
    echo "[AVISO] Estrutura não convencional; usando src como baseline.\n";
}

$documentation = [];
if (is_file($projectRoot . '/README.md')) {
    $documentation[] = 'README.md';
}
foreach (glob($projectRoot . '/docs/*.md') ?: [] as $file) {
    $documentation[] = 'docs/' . basename($file);
}

$contextDir = $projectRoot . '/.ninfa';
if (!is_dir($contextDir)) {
    mkdir($contextDir, 0775, true);
}

file_put_contents(
    $contextDir . '/context.json',
    json_encode([
        'framework' => $framework,
        'paths' => $paths,
        'documentation' => $documentation,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

file_put_contents($contextDir . '/paths.txt', implode(PHP_EOL, $paths) . PHP_EOL);

echo "[NINFA] Framework: {$framework}\n";
echo '[NINFA] Caminhos detectados: ' . implode(', ', $paths) . PHP_EOL;
echo '[NINFA] Documentação consultada: ' . ($documentation === [] ? 'nenhuma' : implode(', ', $documentation)) . PHP_EOL;
