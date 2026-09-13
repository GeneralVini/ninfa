<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/ninfa-install-' . bin2hex(random_bytes(4));
$glpiRoot = $root . '/glpi';
$projectRoot = $glpiRoot . '/plugins/example';

try {
    mkdir($projectRoot . '/src', 0775, true);
    mkdir($glpiRoot . '/src/autoload', 0775, true);

    file_put_contents($glpiRoot . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
    file_put_contents($projectRoot . '/setup.php', "<?php function plugin_init_example(): void {}\n");
    file_put_contents($projectRoot . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\n");
    file_put_contents($projectRoot . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; final class Example {}\n");
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'name' => 'example/plugin',
        'require' => ['php' => '>=8.2'],
    ], JSON_THROW_ON_ERROR));

    $before = scandir($projectRoot);
    assert(is_array($before));

    putenv('NINFA_GLPI_ROOT=' . $glpiRoot);
    $installer = dirname(__DIR__) . '/bin/ninfa-install.php';
    passthru('php ' . escapeshellarg($installer) . ' ' . escapeshellarg($projectRoot), $status);
    assert($status === 0);

    $after = scandir($projectRoot);
    assert(is_array($after));
    assert($before === $after);

    foreach (['.ninfa', 'ecs.php', 'rector.php', 'phpstan.neon.dist', 'psalm.xml', 'Makefile', 'lefthook.yml'] as $forbidden) {
        assert(!file_exists($projectRoot . '/' . $forbidden));
    }

    echo "[OK] Instalacao externa nao altera o projeto consumidor.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
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
