<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ExternalConfigGenerator.php';

$root = sys_get_temp_dir() . '/ninfa-external-config-' . bin2hex(random_bytes(4));
$glpiRoot = $root . '/glpi';
$pluginRoot = $glpiRoot . '/plugins/example';
putenv('NINFA_WORKSPACE_ROOT=' . $root . '/workspace');

try {
    mkdir($pluginRoot . '/src', 0775, true);
    mkdir($glpiRoot . '/src/autoload', 0775, true);
    file_put_contents($glpiRoot . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
    file_put_contents($pluginRoot . '/setup.php', "<?php function plugin_init_example(): void {}\n");
    file_put_contents($pluginRoot . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\n");
    file_put_contents($pluginRoot . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; final class Example {}\n");
    file_put_contents($pluginRoot . '/composer.json', '{"require":{"php":">=8.2"}}');

    $context = ProjectContext::fromRoot($pluginRoot);
    $generator = new ExternalConfigGenerator();
    $files = $generator->generate($context);

    $stan = (string) file_get_contents($files['phpstan']);
    $psalm = (string) file_get_contents($files['psalm']);

    assert(str_contains($stan, 'level: 8'));
    assert(str_contains($stan, str_replace('\\', '/', $glpiRoot . '/src')));
    assert(str_contains($stan, str_replace('\\', '/', $pluginRoot . '/setup.php')));
    assert(str_contains($stan, str_replace('\\', '/', $pluginRoot . '/hook.php')));
    assert(!str_contains($stan, "  glpi:\n"));
    assert(str_contains($psalm, 'errorLevel="8"'));
    assert(str_contains($psalm, '<var name="DB" type="DBmysql" />'));
    assert(str_contains(
        $psalm,
        '<file name="' . str_replace('\\', '/', $pluginRoot . '/setup.php') . '" />',
    ));
    assert(str_contains(
        $psalm,
        '<file name="' . str_replace('\\', '/', $pluginRoot . '/hook.php') . '" />',
    ));
    assert(str_contains(
        $psalm,
        '<directory name="' . str_replace('\\', '/', $pluginRoot . '/src') . '" />',
    ));

    $extension = $glpiRoot . '/vendor/glpi-project/phpstan-glpi/extension.neon';
    mkdir(dirname($extension), 0775, true);
    file_put_contents($extension, "services: []\n");
    $filesWithExtension = $generator->generate(ProjectContext::fromRoot($pluginRoot));
    $stanWithExtension = (string) file_get_contents($filesWithExtension['phpstan']);
    assert(str_contains($stanWithExtension, str_replace('\\', '/', $extension)));
    assert(str_contains($stanWithExtension, "  glpi:\n"));
    assert(str_contains($stanWithExtension, 'glpiVersion: \'11.0.8\''));

    foreach ($filesWithExtension as $file) {
        assert(str_starts_with($file, $root . '/workspace/'));
        assert(!str_starts_with($file, $pluginRoot . '/'));
    }

    $genericRoot = $root . '/generic';
    mkdir($genericRoot . '/src', 0775, true);
    file_put_contents($genericRoot . '/src/Example.php', "<?php final class GenericExample {}\n");
    $genericFiles = $generator->generate(ProjectContext::fromRoot($genericRoot));
    $genericStan = (string) file_get_contents($genericFiles['phpstan']);
    assert(str_contains($genericStan, 'level: max'));
    assert(str_contains($genericStan, str_replace('\\', '/', $genericRoot . '/src')));

    echo "[OK] Configurações externas cobrem GLPI condicional e PHP genérico.\n";
} finally {
    putenv('NINFA_WORKSPACE_ROOT');
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
