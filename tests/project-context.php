<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ProjectContext.php';

$root = sys_get_temp_dir() . '/ninfa-project-context-' . bin2hex(random_bytes(4));
$glpiRoot = $root . '/glpi';
$pluginRoot = $glpiRoot . '/plugins/example';

try {
    mkdir($pluginRoot . '/src', 0775, true);
    mkdir($glpiRoot . '/src/autoload', 0775, true);

    file_put_contents($glpiRoot . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
    file_put_contents($pluginRoot . '/setup.php', "<?php function plugin_init_example(): void {}\n");
    file_put_contents($pluginRoot . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\n");
    file_put_contents($pluginRoot . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; final class Example {}\n");
    file_put_contents($pluginRoot . '/composer.json', json_encode([
        'name' => 'example/plugin',
        'require' => ['php' => '>=8.2'],
    ], JSON_THROW_ON_ERROR));

    $context = ProjectContext::fromRoot($pluginRoot);
    assert($context->profile() === 'glpi-plugin');
    assert($context->phpStanLevel() === 8);
    assert($context->psalmLevel() === 8);
    assert($context->glpiVersion() === '11.0.8');
    assert(!str_starts_with($context->workspace()->path(), $pluginRoot));

    $yii2Root = $root . '/yii2';
    mkdir($yii2Root . '/src', 0775, true);
    file_put_contents($yii2Root . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2', 'yiisoft/yii2' => '^2.0'],
    ], JSON_THROW_ON_ERROR));
    assert(ProjectContext::fromRoot($yii2Root)->profile() === 'yii2');

    $yii3Root = $root . '/yii3';
    mkdir($yii3Root . '/src', 0775, true);
    file_put_contents($yii3Root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii-http' => '^1.0',
            'yiisoft/di' => '^1.0',
        ],
    ], JSON_THROW_ON_ERROR));
    assert(ProjectContext::fromRoot($yii3Root)->profile() === 'yii3');

    $genericComposerRoot = $root . '/generic-composer';
    mkdir($genericComposerRoot . '/src', 0775, true);
    file_put_contents($genericComposerRoot . '/src/Example.php', "<?php final class GenericExample {}\n");
    file_put_contents($genericComposerRoot . '/composer.json', json_encode([
        'name' => 'example/generic',
        'require' => ['php' => '>=8.2'],
    ], JSON_THROW_ON_ERROR));
    $genericComposer = ProjectContext::fromRoot($genericComposerRoot);
    assert($genericComposer->profile() === 'php-generic');
    assert($genericComposer->paths() === ['src']);

    $genericPlainRoot = $root . '/generic-plain';
    mkdir($genericPlainRoot . '/public', 0775, true);
    file_put_contents($genericPlainRoot . '/public/index.php', "<?php echo 'ok';\n");
    $genericPlain = ProjectContext::fromRoot($genericPlainRoot);
    assert($genericPlain->profile() === 'php-generic');
    assert($genericPlain->paths() === ['public']);

    $libraryRoot = $root . '/yiisoft-library';
    mkdir($libraryRoot . '/src', 0775, true);
    file_put_contents($libraryRoot . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2', 'yiisoft/di' => '^1.0'],
    ], JSON_THROW_ON_ERROR));
    try {
        ProjectContext::fromRoot($libraryRoot);
        assert(false, 'Diretório sem código PHP não deve ser classificado como Yii3 nem PHP genérico.');
    } catch (RuntimeException $error) {
        assert(str_contains($error->getMessage(), 'Profile não reconhecido'));
    }

    $emptyRoot = $root . '/empty';
    mkdir($emptyRoot, 0775, true);
    try {
        ProjectContext::fromRoot($emptyRoot);
        assert(false, 'Diretório vazio não deve ser reconhecido como projeto PHP.');
    } catch (RuntimeException $error) {
        assert(str_contains($error->getMessage(), 'Profile não reconhecido'));
    }

    echo "[OK] ProjectContext cobre glpi-plugin, yii2, yii3, php-generic e rejeita projetos sem evidência PHP.\n";
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
