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
$documentationText = '';
if (is_file($projectRoot . '/README.md')) {
    $documentation[] = 'README.md';
    $documentationText .= "\n" . strtolower((string) file_get_contents($projectRoot . '/README.md'));
}
foreach (glob($projectRoot . '/docs/*.md') ?: [] as $file) {
    $relative = 'docs/' . basename($file);
    $documentation[] = $relative;
    $documentationText .= "\n" . strtolower((string) file_get_contents($file));
}

$documentedFrameworks = [];
$signals = [
    'yii 3' => 'Yii 3',
    'yii3' => 'Yii 3',
    'yii 2' => 'Yii 2',
    'yii2' => 'Yii 2',
    'laravel' => 'Laravel',
    'symfony' => 'Symfony',
];
foreach ($signals as $needle => $name) {
    if (str_contains($documentationText, $needle)) {
        $documentedFrameworks[$name] = true;
    }
}

if ($framework !== 'PHP genérico' && $documentedFrameworks !== [] && !isset($documentedFrameworks[$framework])) {
    echo "[AVISO] README/docs mencionam framework diferente do composer.json. O Composer terá precedência técnica.\n";
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
        'documented_frameworks' => array_keys($documentedFrameworks),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

file_put_contents($contextDir . '/paths.txt', implode(PHP_EOL, $paths) . PHP_EOL);

echo "[NINFA] Framework: {$framework}\n";
echo '[NINFA] Caminhos detectados: ' . implode(', ', $paths) . PHP_EOL;
echo '[NINFA] Documentação consultada: ' . ($documentation === [] ? 'nenhuma' : implode(', ', $documentation)) . PHP_EOL;
