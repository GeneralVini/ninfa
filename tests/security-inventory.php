<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SecurityInventory.php';
require_once dirname(__DIR__) . '/src/ProcessRunner.php';

$root = sys_get_temp_dir() . '/ninfa-security-inventory-' . bin2hex(random_bytes(4));
$projectRoot = $root . '/project';

try {
    mkdir($projectRoot . '/src', 0775, true);
    mkdir($projectRoot . '/vendor/composer', 0775, true);
    mkdir($projectRoot . '/vendor/bin', 0775, true);
    file_put_contents($projectRoot . '/src/Example.php', "<?php final class Example {}\n");
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'name' => 'example/security-inventory',
        'require' => [
            'php' => '>=8.2 <9',
            'ext-json' => '*',
            'vendor/direct' => '^1.0',
        ],
        'require-dev' => [
            'vendor/dev' => '^3.0',
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($projectRoot . '/composer.lock', json_encode([
        'content-hash' => 'abc123',
        'packages' => [
            ['name' => 'vendor/direct', 'version' => '1.2.3', 'type' => 'library'],
            ['name' => 'vendor/transitive', 'version' => '2.4.0', 'type' => 'library'],
        ],
        'packages-dev' => [
            ['name' => 'vendor/dev', 'version' => '3.1.0', 'type' => 'library'],
        ],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($projectRoot . '/vendor/composer/installed.json', json_encode([
        'packages' => [
            ['name' => 'vendor/ignored-fallback', 'version' => '9.9.9'],
        ],
    ], JSON_THROW_ON_ERROR));

    $context = ProjectContext::fromRoot($projectRoot);
    $inventory = SecurityInventory::fromContext($context)->toArray();

    assert(($inventory['profile'] ?? null) === 'php-generic');
    assert(($inventory['php']['constraint'] ?? null) === '>=8.2 <9');
    assert(($inventory['php']['runtime']['version'] ?? null) === PHP_VERSION);
    assert(($inventory['composer']['package_source'] ?? null) === 'composer.lock');
    assert(($inventory['composer']['lock']['content_hash'] ?? null) === 'abc123');
    assert(($inventory['composer']['installed_json']['used_as_fallback'] ?? null) === false);

    $packages = [];
    foreach ($inventory['composer']['packages'] ?? [] as $package) {
        $packages[$package['name']] = $package;
    }
    assert(($packages['vendor/direct']['direct'] ?? null) === true);
    assert(($packages['vendor/direct']['scope'] ?? null) === 'runtime');
    assert(($packages['vendor/transitive']['relationship'] ?? null) === 'transitive');
    assert(($packages['vendor/dev']['direct'] ?? null) === true);
    assert(($packages['vendor/dev']['scope'] ?? null) === 'dev');
    assert(!isset($packages['vendor/ignored-fallback']));

    $requiredExtensions = $inventory['php']['extensions']['required'] ?? [];
    assert(($requiredExtensions[0]['name'] ?? null) === 'ext-json');
    assert(($requiredExtensions[0]['installed'] ?? null) === true);

    foreach (['composer', 'psalm', 'semgrep'] as $tool) {
        $path = $projectRoot . '/vendor/bin/' . $tool;
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0755);
    }

    $workspaceRoot = $root . '/workspace';
    putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);
    putenv('NO_COLOR=1');
    $execution = (new ProcessRunner())->runCaptured([
        PHP_BINARY,
        dirname(__DIR__) . '/bin/ninfa',
        'security',
        $projectRoot,
    ], $projectRoot);
    assert($execution->exitCode === 0);
    $inventoryFile = ProjectContext::fromRoot($projectRoot)->workspace()->file('security-inventory.json');
    assert(is_file($inventoryFile));
    $writtenInventory = json_decode((string) file_get_contents($inventoryFile), true, 512, JSON_THROW_ON_ERROR);
    assert(($writtenInventory['composer']['package_source'] ?? null) === 'composer.lock');
    assert(str_contains($execution->stdout, 'Inventário de segurança:'));

    echo "[OK] SecurityInventory prioriza composer.lock, separa runtime/constraint e integra ninfa security.\n";
} finally {
    putenv('NINFA_WORKSPACE_ROOT');
    putenv('NO_COLOR');
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
