<?php

declare(strict_types=1);

/**
 * Regression guard for GLPI PR #1 reviews 3998260114/0120/0123.
 *
 * Exercises real Ninfa configurator against synthetic plugin/hosts. Confirms
 * extension-dependent parameters, regeneration when GLPI host changes, and
 * zero writes to pre-existing consumer configurations even with --force.
 * No real SIGAPS/EGIDE tree is accessed.
 */
require_once dirname(__DIR__) . '/src/ProcessRunner.php';

$fixture = sys_get_temp_dir() . '/ninfa-glpi-pr1-regression-' . bin2hex(random_bytes(8));
$plugin = $fixture . '/consumer/example';
$hostA = $fixture . '/host-a';
$hostB = $fixture . '/host-b';
$workspaceRoot = $fixture . '/workspace';
mkdir($plugin . '/src', 0775, true);
foreach ([$hostA, $hostB] as $host) {
    mkdir($host . '/src/autoload', 0775, true);
    file_put_contents(
        $host . '/src/autoload/constants.php',
        "<?php define('GLPI_VERSION', '" . ($host === $hostA ? '11.0.8' : '11.0.11') . "');\n",
    );
}
file_put_contents($plugin . '/composer.json', '{"name":"synthetic/example","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/src/Example.php', "<?php class PluginExampleItem {}\n");
file_put_contents($plugin . '/setup.php', "<?php function plugin_init_example(): void {}\nfunction plugin_version_example(): array { return ['version'=>'1.0']; }\n");
file_put_contents($plugin . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n");

// Deliberately preserve checked-in consumer configurations; Ninfa may not replace them.
foreach ([
    'phpstan.neon.dist' => "parameters:\n  level: 6\n",
    'psalm.xml' => '<psalm errorLevel="4"/>',
    'ecs.php' => "<?php return 'consumer-ecs';\n",
    'rector.php' => "<?php return 'consumer-rector';\n",
    'phpunit.xml.dist' => '<phpunit bootstrap="tests/bootstrap.php"/>',
] as $name => $contents) {
    file_put_contents($plugin . '/' . $name, $contents);
}

/**
 * Inventories all regular fixture files using relative names and SHA-256.
 *
 * @param string $root Fixture project root.
 * @return array<string,string> Stable content fingerprint of the consumer.
 */
function glpiPr1Snapshot(string $root): array
{
    /** @var array<string,string> $files Relative file/hash mapping. */
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $files[substr($entry->getPathname(), strlen($root) + 1)] = hash_file('sha256', $entry->getPathname());
        }
    }
    ksort($files);
    return $files;
}

$before = glpiPr1Snapshot($plugin);
$runner = new ProcessRunner();
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    // Review 3998260114: no extension -> no extension-only parameter block.
    putenv('NINFA_GLPI_ROOT=' . $hostA);
    $without = $runner->runCaptured([
        PHP_BINARY, dirname(__DIR__) . '/scripts/ninfa-configure.php', $plugin, '--force',
    ], $plugin);
    assert($without->exitCode === 0, $without->stderr);
    assert(str_contains($without->stdout, '[NINFA] Profile: glpi-plugin'));
    assert(str_contains($without->stdout, '--force mantido apenas por compatibilidade'));
    $workspaces = glob($workspaceRoot . '/*', GLOB_ONLYDIR) ?: [];
    assert(count($workspaces) === 1);
    $workingDir = $workspaces[0];
    $stanA = (string) file_get_contents($workingDir . '/phpstan.neon');
    $psalmA = (string) file_get_contents($workingDir . '/psalm.xml');
    assert(str_contains($stanA, $hostA . '/src'));
    assert(!str_contains($stanA, "  glpi:\n"));
    assert(!str_contains($stanA, "includes:\n"));
    assert(str_contains($psalmA, $hostA . '/inc/includes.php'));
    assert(glpiPr1Snapshot($plugin) === $before);

    // Installing the real extension file in the synthetic host enables its own section.
    $extension = $hostA . '/vendor/glpi-project/phpstan-glpi/extension.neon';
    mkdir(dirname($extension), 0775, true);
    file_put_contents($extension, "services: []\n");
    $with = $runner->runCaptured([
        PHP_BINARY, dirname(__DIR__) . '/scripts/ninfa-configure.php', $plugin,
    ], $plugin);
    assert($with->exitCode === 0, $with->stderr);
    $stanWithExtension = (string) file_get_contents($workingDir . '/phpstan.neon');
    assert(str_contains($stanWithExtension, $extension));
    assert(str_contains($stanWithExtension, "  glpi:\n"));
    assert(str_contains($stanWithExtension, "glpiVersion: '11.0.8'"));
    assert(glpiPr1Snapshot($plugin) === $before);

    // Review 3998260120: moving to host B must remove every stale host A path
    // and remove extension configuration when B does not provide that extension.
    putenv('NINFA_GLPI_ROOT=' . $hostB);
    $moved = $runner->runCaptured([
        PHP_BINARY, dirname(__DIR__) . '/scripts/ninfa-configure.php', $plugin, '--force',
    ], $plugin);
    assert($moved->exitCode === 0, $moved->stderr);
    foreach (['phpstan.neon', 'psalm.xml', 'glpi-bootstrap.php'] as $artifact) {
        $generated = (string) file_get_contents($workingDir . '/' . $artifact);
        assert(str_contains($generated, $hostB), $artifact . ' missing current host');
        assert(!str_contains($generated, $hostA), $artifact . ' retains stale host');
    }
    $stanB = (string) file_get_contents($workingDir . '/phpstan.neon');
    assert(!str_contains($stanB, "  glpi:\n"));
    assert(!str_contains($stanB, "includes:\n"));
    assert(!str_contains($stanB, $extension));
    assert(str_contains((string) file_get_contents($workingDir . '/psalm.xml'), $hostB . '/inc/includes.php'));

    // Review 3998260123: --force must never rewrite or create config in the
    // consumer. Snapshot includes all checked-in configs, not just known names.
    assert(glpiPr1Snapshot($plugin) === $before);
    foreach (['phpstan.neon', 'glpi-bootstrap.php', 'semantic-index.json', 'lefthook.yml'] as $artifact) {
        assert(!is_file($plugin . '/' . $artifact));
    }

    echo "[OK] GLPI PR #1 P1 regressions: conditional extension, host relocation, consumer config preservation.\n";
} finally {
    putenv('NINFA_WORKSPACE_ROOT');
    putenv('NINFA_GLPI_ROOT');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
