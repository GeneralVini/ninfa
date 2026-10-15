<?php

declare(strict_types=1);

/**
 * Testa o índice GLPI de classes/métodos e registros literais sem autoload.
 *
 * Usa plugin e host sintéticos e confirma somente fatos positivos e unknown.
 * Não executa código de classes ou writes no consumidor durante a indexação.
 */
require_once dirname(__DIR__) . '/src/GlpiPluginRuleEngine.php';

$fixture = sys_get_temp_dir() . '/ninfa-glpi-class-index-' . bin2hex(random_bytes(7));
$host = $fixture . '/glpi';
$plugin = $host . '/plugins/example';
mkdir($host . '/src/autoload', 0775, true);
mkdir($plugin . '/src/Audit', 0775, true);
file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
file_put_contents($plugin . '/composer.json', '{"name":"synthetic/example","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n");
file_put_contents($plugin . '/src/Profile.php', <<<'PHP'
<?php
// class Phantom {}
class PluginExampleProfile
{
    public static function getTabNameForItem(): array { return []; }
    public function displayTabContentForItem(): void
    {
        $anon = new class {
            public function hiddenMethod(): void {}
        };
    }
}
PHP);
file_put_contents($plugin . '/src/Audit/Worker.php', <<<'PHP'
<?php
namespace GlpiPlugin\Example\Audit;
final class Worker
{
    public function run(): void {}
    private function validate(): bool { return true; }
}
PHP);
putenv('NINFA_GLPI_ROOT=' . $host);
putenv('NINFA_WORKSPACE_ROOT=' . $fixture . '/workspace');

try {
    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
function plugin_init_example(): void
{
    Plugin::registerClass('PluginExampleProfile', ['addtabon' => ['Profile']]);
    \Plugin::registerClass('PluginExampleMissing');
    Plugin::registerClass('PluginExampleGhost' . $suffix);
    Plugin::registerClass($computed);
    // Plugin::registerClass('PluginFakeComment');
    $sample = "Plugin::registerClass('PluginFakeString')";
    AnotherPlugin::registerClass('NotGlobal');
}
function plugin_version_example(): array { return []; }
PHP);

    $ctx = ProjectContext::fromRoot($plugin);
    assert($ctx->profile() === 'glpi-plugin');
    $model = GlpiPluginSemanticModel::fromContext($ctx);
    $classes = $model->classDeclarations();
    assert(isset($classes['pluginexampleprofile']));
    assert($classes['pluginexampleprofile']['file'] === 'src/Profile.php');
    assert($classes['pluginexampleprofile']['methods'] === ['displaytabcontentforitem', 'gettabnameforitem']);
    assert(isset($classes['glpiplugin\\example\\audit\\worker']));
    assert($classes['glpiplugin\\example\\audit\\worker']['methods'] === ['run', 'validate']);
    assert(!isset($classes['phantom']));
    $registrations = $model->classRegistrations();
    assert(count($registrations) === 2);
    assert($registrations[0]['class'] === 'PluginExampleProfile');
    assert($registrations[0]['declared'] === true);
    assert($registrations[0]['methods'] === ['displaytabcontentforitem', 'gettabnameforitem']);
    assert($registrations[1]['class'] === 'PluginExampleMissing');
    assert($registrations[1]['declared'] === null);
    assert($registrations[1]['methods'] === []);
    assert((new GlpiPluginRuleEngine())->analyse($model) === []);
    $json = json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    assert(count($json['class_registrations']) === 2);
    assert(count($json['class_declarations']) === 2);

    // Com um alias que encobre Plugin global, um registro curto não é conclusivo.
    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
use Vendor\Plugin;
function plugin_init_example(): void
{
    Plugin::registerClass('PluginExampleProfile');
    \Plugin::registerClass('PluginExampleProfile');
}
function plugin_version_example(): array { return []; }
PHP);
    $model = GlpiPluginSemanticModel::fromContext($ctx);
    assert(count($model->classRegistrations()) === 1);
    assert($model->classRegistrations()[0]['declared'] === true);

    // Namespace em bloco e declarações condicionais não geram prova positiva.
    file_put_contents($plugin . '/src/Audit/Worker.php', <<<'PHP'
<?php
namespace GlpiPlugin\Example\Audit {
    class Worker {}
}
PHP);
    file_put_contents($plugin . '/src/Conditional.php', <<<'PHP'
<?php
if ($enabled) {
    class PluginExampleRuntimeOnly {}
}
PHP);
    $model = GlpiPluginSemanticModel::fromContext($ctx);
    assert(!isset($model->classDeclarations()['glpiplugin\\example\\audit\\worker']));
    assert(!isset($model->classDeclarations()['pluginexampleruntimeonly']));

    // O indexador somente lê arquivos originais; nenhuma execução de classes.
    $hash = hash_file('sha256', $plugin . '/src/Profile.php');
    GlpiPluginSemanticModel::fromContext($ctx);
    assert(hash_file('sha256', $plugin . '/src/Profile.php') === $hash);
    assert(!is_file($plugin . '/glpi-semantic.json'));
    echo "[OK] GLPI: classes/métodos e registerClass literais como true/unknown, sem execução.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
    putenv('NINFA_WORKSPACE_ROOT');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
