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

$setupContents = is_file($projectRoot . '/setup.php') ? (string) file_get_contents($projectRoot . '/setup.php') : '';
$hookContents = is_file($projectRoot . '/hook.php') ? (string) file_get_contents($projectRoot . '/hook.php') : '';

$glpiPluginScore = 0;
$glpiPluginScore += is_file($projectRoot . '/setup.php') ? 5 : 0;
$glpiPluginScore += is_file($projectRoot . '/hook.php') ? 4 : 0;
$glpiPluginScore += preg_match('/function\s+plugin_init_[a-z0-9]+\s*\(/i', $setupContents) === 1 ? 4 : 0;
$glpiPluginScore += preg_match('/function\s+plugin_[a-z0-9]+_install\s*\(/i', $hookContents) === 1 ? 3 : 0;

$glpiNamespaceDetected = false;
foreach (glob($projectRoot . '/src/*.php') ?: [] as $sourceFile) {
    if (preg_match('/namespace\s+GlpiPlugin\\\\[A-Za-z0-9_\\\\]+\s*;/', (string) file_get_contents($sourceFile)) === 1) {
        $glpiNamespaceDetected = true;
        break;
    }
}
$glpiPluginScore += $glpiNamespaceDetected ? 4 : 0;
$glpiPluginScore += is_dir($projectRoot . '/src') || is_dir($projectRoot . '/inc') ? 1 : 0;
$glpiPluginScore += 1;
$isGlpiPlugin = $glpiPluginScore >= 8;

$framework = 'PHP genérico';
if ($isGlpiPlugin) {
    $framework = 'GLPI Plugin 11';
} elseif (in_array('laravel/framework', $packages, true)) {
    $framework = 'Laravel';
} elseif (in_array('yiisoft/yii2', $packages, true)) {
    $framework = 'Yii 2';
} elseif (array_filter($packages, static fn (string $package): bool => str_starts_with($package, 'yiisoft/'))) {
    $framework = 'Yii 3';
} elseif (in_array('symfony/framework-bundle', $packages, true)) {
    $framework = 'Symfony';
}

$candidates = $isGlpiPlugin
    ? ['src', 'inc', 'front', 'ajax', 'tests']
    : ['src', 'app', 'config', 'modules', 'console', 'commands', 'public', 'web', 'tests'];
$paths = [];
foreach ($candidates as $candidate) {
    if (is_dir($projectRoot . '/' . $candidate)) {
        $paths[] = $candidate;
    }
}

if ($paths === []) {
    echo "[AVISO] Nenhum caminho convencional encontrado. Configurações automáticas não serão geradas.\n";
}

$glpiRoot = null;
$glpiVersion = null;
$glpiHostDetected = false;
if ($isGlpiPlugin) {
    $configuredGlpiRoot = getenv('NINFA_GLPI_ROOT');
    if (is_string($configuredGlpiRoot) && $configuredGlpiRoot !== '') {
        $candidateRoot = realpath($configuredGlpiRoot) ?: $configuredGlpiRoot;
        if (is_dir($candidateRoot . '/src') && is_file($candidateRoot . '/src/autoload/constants.php')) {
            $glpiRoot = $candidateRoot;
        } else {
            echo "[AVISO] NINFA_GLPI_ROOT não aponta para uma instalação GLPI reconhecível.\n";
        }
    }

    if ($glpiRoot === null && basename(dirname($projectRoot)) === 'plugins') {
        $candidateRoot = dirname(dirname($projectRoot));
        if (is_dir($candidateRoot . '/src') && is_file($candidateRoot . '/src/autoload/constants.php')) {
            $glpiRoot = realpath($candidateRoot) ?: $candidateRoot;
        }
    }

    if ($glpiRoot !== null) {
        $constants = (string) file_get_contents($glpiRoot . '/src/autoload/constants.php');
        if (preg_match("/define\\('GLPI_VERSION',\\s*'([^']+)'\\);/", $constants, $matches) === 1) {
            $glpiVersion = $matches[1];
        }
        $glpiHostDetected = true;

        if ($glpiVersion !== null && !str_starts_with($glpiVersion, '11.')) {
            fwrite(STDERR, "[ERRO] O profile glpi-plugin do Ninfa suporta somente GLPI 11. Detectado: {$glpiVersion}.\n");
            exit(1);
        }
    } else {
        echo "[AVISO] Plugin GLPI detectado, mas o host GLPI 11 não foi localizado. Defina NINFA_GLPI_ROOT para habilitar a análise estática integrada ao core.\n";
    }
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
    'glpi' => 'GLPI Plugin 11',
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
    echo "[AVISO] README/docs mencionam framework diferente do contexto técnico detectado. A evidência técnica terá precedência.\n";
}

$contextDir = $projectRoot . '/.ninfa';
if (!is_dir($contextDir)) {
    mkdir($contextDir, 0775, true);
}

$context = [
    'framework' => $framework,
    'profile' => $isGlpiPlugin ? 'glpi-plugin' : null,
    'paths' => $paths,
    'documentation' => $documentation,
    'documented_frameworks' => array_keys($documentedFrameworks),
];
if ($isGlpiPlugin) {
    $context['glpi'] = [
        'supported_major' => 11,
        'detection_score' => $glpiPluginScore,
        'host_detected' => $glpiHostDetected,
        'root' => $glpiRoot,
        'version' => $glpiVersion,
        'phpstan_extension_declared' => in_array('glpi-project/phpstan-glpi', $packages, true),
    ];
}

file_put_contents(
    $contextDir . '/context.json',
    json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
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

if ($isGlpiPlugin && $glpiRoot !== null) {
    $bootstrapRoot = var_export($glpiRoot, true);
    $writeConfig(
        $contextDir . '/phpstan-glpi-bootstrap.php',
        "<?php\n\ndeclare(strict_types=1);\n\n\$glpiRoot = {$bootstrapRoot};\n\n\$vendorAutoload = \$glpiRoot . '/vendor/autoload.php';\nif (is_file(\$vendorAutoload)) {\n    require_once \$vendorAutoload;\n}\n\nspl_autoload_register(static function (string \$class) use (\$glpiRoot): void {\n    if (str_starts_with(\$class, 'Glpi\\\\')) {\n        \$relative = substr(\$class, strlen('Glpi\\\\'));\n        \$file = \$glpiRoot . '/src/Glpi/' . str_replace('\\\\', '/', \$relative) . '.php';\n    } elseif (!str_contains(\$class, '\\\\')) {\n        \$file = \$glpiRoot . '/src/' . \$class . '.php';\n    } else {\n        return;\n    }\n\n    if (is_file(\$file)) {\n        require_once \$file;\n    }\n});\n",
    );
}

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

    $phpStanHeader = '';
    $phpStanExtra = '';
    if ($isGlpiPlugin && $glpiRoot !== null) {
        $glpiRootForNeon = str_replace('\\', '/', $glpiRoot);
        $pluginExtension = $projectRoot . '/vendor/glpi-project/phpstan-glpi/extension.neon';
        $hostExtension = $glpiRoot . '/vendor/glpi-project/phpstan-glpi/extension.neon';
        $extension = is_file($pluginExtension) ? $pluginExtension : (is_file($hostExtension) ? $hostExtension : null);

        if ($extension !== null) {
            $phpStanHeader = "includes:\n  - " . str_replace('\\', '/', $extension) . "\n\n";
        } else {
            echo "[AVISO] glpi-project/phpstan-glpi não está instalado. Para tipar globais do GLPI (ex.: \$DB), execute: composer require --dev glpi-project/phpstan-glpi:^1.3\n";
        }

        $scanFiles = [];
        foreach ([
            $glpiRoot . '/src/autoload/constants.php',
            $glpiRoot . '/src/autoload/dbutils-aliases.php',
            $glpiRoot . '/src/autoload/i18n.php',
            $glpiRoot . '/src/autoload/misc-functions.php',
        ] as $scanFile) {
            if (is_file($scanFile)) {
                $scanFiles[] = '    - ' . str_replace('\\', '/', $scanFile);
            }
        }

        $bootstrapFiles = [];
        $generatedBootstrap = $contextDir . '/phpstan-glpi-bootstrap.php';
        if (is_file($generatedBootstrap)) {
            $bootstrapFiles[] = '    - ' . str_replace('\\', '/', $generatedBootstrap);
        }
        foreach ([
            $glpiRoot . '/stubs/db_config_classes.php',
            $glpiRoot . '/stubs/glpi_constants.php',
            $glpiRoot . '/stubs/plugins_migrations_classes.php',
        ] as $bootstrapFile) {
            if (is_file($bootstrapFile)) {
                $bootstrapFiles[] = '    - ' . str_replace('\\', '/', $bootstrapFile);
            }
        }

        $phpStanExtra .= "  scanDirectories:\n    - {$glpiRootForNeon}/src\n";
        if ($scanFiles !== []) {
            $phpStanExtra .= "  scanFiles:\n" . implode("\n", $scanFiles) . "\n";
        }
        if ($bootstrapFiles !== []) {
            $phpStanExtra .= "  bootstrapFiles:\n" . implode("\n", $bootstrapFiles) . "\n";
        }
        $phpStanExtra .= "  glpi:\n    glpiPath: {$glpiRootForNeon}\n";
        if ($glpiVersion !== null) {
            $phpStanExtra .= "    glpiVersion: '{$glpiVersion}'\n";
        }
    }

    $writeConfig(
        $projectRoot . '/phpstan.neon.dist',
        $phpStanHeader . "parameters:\n  level: max\n  paths:\n{$yamlPathLines}\n{$phpStanExtra}  tmpDir: runtime/phpstan\n",
    );

    $writeConfig(
        $projectRoot . '/psalm.xml',
        "<?xml version=\"1.0\"?>\n<psalm\n    errorLevel=\"1\"\n    resolveFromConfigFile=\"true\"\n    xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n    xmlns=\"https://getpsalm.org/schema/config\"\n    xsi:schemaLocation=\"https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd\"\n>\n    <projectFiles>\n{$xmlPathLines}\n        <ignoreFiles>\n            <directory name=\"vendor\" />\n            <directory name=\".tools\" />\n            <directory name=\"runtime\" />\n        </ignoreFiles>\n    </projectFiles>\n</psalm>\n",
    );

    $testsDirectory = in_array('tests', $paths, true) ? "            <directory>tests</directory>\n" : '';
    $writeConfig(
        $projectRoot . '/phpunit.xml.dist',
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phpunit xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n         xsi:noNamespaceSchemaLocation=\"https://schema.phpunit.de/11.5/phpunit.xsd\"\n         colors=\"true\"\n         cacheDirectory=\"runtime/phpunit\">\n    <testsuites>\n        <testsuite name=\"Project\">\n{$testsDirectory}        </testsuite>\n    </testsuites>\n</phpunit>\n",
    );
}

echo "[NINFA] Framework: {$framework}\n";
if ($isGlpiPlugin) {
    echo '[NINFA] Profile: glpi-plugin (GLPI 11)' . PHP_EOL;
    echo '[NINFA] GLPI host: ' . ($glpiRoot ?? 'não detectado') . PHP_EOL;
    if ($glpiVersion !== null) {
        echo '[NINFA] GLPI versão: ' . $glpiVersion . PHP_EOL;
    }
}
echo '[NINFA] Caminhos detectados: ' . ($paths === [] ? 'nenhum' : implode(', ', $paths)) . PHP_EOL;
echo '[NINFA] Documentação consultada: ' . ($documentation === [] ? 'nenhuma' : implode(', ', $documentation)) . PHP_EOL;
