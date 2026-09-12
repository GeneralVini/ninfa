<?php

declare(strict_types=1);

$args = $argv;
array_shift($args);
$force = in_array('--force', $args, true);
$args = array_values(array_filter($args, static fn (string $arg): bool => $arg !== '--force'));

$projectRoot = $args[0] ?? getcwd();
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
    echo "[AVISO] Nenhum caminho convencional encontrado. Configurações automáticas não serão geradas.\n";
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
file_put_contents($contextDir . '/paths.txt', implode(PHP_EOL, $paths) . ($paths === [] ? '' : PHP_EOL));

$writeConfig = static function (string $path, string $content) use ($force): void {
    $existed = is_file($path);
    if ($existed && !$force) {
        echo '[MANTIDO] ' . basename($path) . " já existe.\n";
        return;
    }

    file_put_contents($path, $content);
    echo ($existed ? '[SOBRESCRITO] ' : '[GERADO] ') . basename($path) . "\n";
};

if ($paths !== []) {
    $phpPathLines = implode(",\n", array_map(
        static fn (string $path): string => "        __DIR__ . '/{$path}'",
        $paths,
    ));

    $yamlPathLines = implode("\n", array_map(
        static fn (string $path): string => "    - {$path}",
        $paths,
    ));

    $xmlPathLines = implode("\n", array_map(
        static fn (string $path): string => "        <directory name=\"{$path}\" />",
        $paths,
    ));

    $writeConfig(
        $projectRoot . '/ecs.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuse Symplify\\EasyCodingStandard\\Config\\ECSConfig;\n\nreturn ECSConfig::configure()\n    ->withPaths([\n{$phpPathLines},\n    ])\n    ->withRootFiles()\n    ->withPreparedSets(psr12: true);\n",
    );

    $writeConfig(
        $projectRoot . '/rector.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure()\n    ->withPaths([\n{$phpPathLines},\n    ])\n    ->withPreparedSets(\n        deadCode: true,\n        codeQuality: true,\n        typeDeclarations: true,\n    );\n",
    );

    $writeConfig(
        $projectRoot . '/phpstan.neon.dist',
        "parameters:\n  level: max\n  paths:\n{$yamlPathLines}\n  tmpDir: runtime/phpstan\n",
    );

    $writeConfig(
        $projectRoot . '/psalm.xml',
        "<?xml version=\"1.0\"?>\n<psalm\n    errorLevel=\"1\"\n    resolveFromConfigFile=\"true\"\n    xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n    xmlns=\"https://getpsalm.org/schema/config\"\n    xsi:schemaLocation=\"https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd\"\n>\n    <projectFiles>\n{$xmlPathLines}\n        <ignoreFiles>\n            <directory name=\"vendor\" />\n            <directory name=\".tools\" />\n            <directory name=\"runtime\" />\n        </ignoreFiles>\n    </projectFiles>\n</psalm>\n",
    );

    $testsDirectory = in_array('tests', $paths, true)
        ? "            <directory>tests</directory>\n"
        : '';

    $writeConfig(
        $projectRoot . '/phpunit.xml.dist',
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phpunit xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n         xsi:noNamespaceSchemaLocation=\"https://schema.phpunit.de/11.5/phpunit.xsd\"\n         colors=\"true\"\n         cacheDirectory=\"runtime/phpunit\">\n    <testsuites>\n        <testsuite name=\"Project\">\n{$testsDirectory}        </testsuite>\n    </testsuites>\n</phpunit>\n",
    );
}

echo "[NINFA] Framework: {$framework}\n";
echo '[NINFA] Caminhos detectados: ' . ($paths === [] ? 'nenhum' : implode(', ', $paths)) . PHP_EOL;
echo '[NINFA] Documentação consultada: ' . ($documentation === [] ? 'nenhuma' : implode(', ', $documentation)) . PHP_EOL;
