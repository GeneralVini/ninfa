<?php

declare(strict_types=1);

/**
 * Exercita registros de classes por aliases importados e expressões ::class.
 *
 * O cenário é sintético e cobre a convenção PSR-4 comum em plugins GLPI 11,
 * sem importar ou executar código dos consumidores privados usados no corpus.
 */
require_once dirname(__DIR__) . '/src/GlpiPluginRuleEngine.php';

$fixture = sys_get_temp_dir() . '/ninfa-glpi-class-ref-' . bin2hex(random_bytes(8));
$host = $fixture . '/glpi';
$plugin = $host . '/plugins/example';
mkdir($host . '/src/autoload', 0775, true);
mkdir($plugin . '/src/Audit', 0775, true);
file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
file_put_contents($plugin . '/composer.json', '{"name":"synthetic/example","type":"glpi-plugin","autoload":{"psr-4":{"GlpiPlugin\\\\Example\\\\":"src/"}}}');
file_put_contents($plugin . '/hook.php', "<?php\nfunction plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n");
file_put_contents($plugin . '/src/Profile.php', <<<'PHP'
<?php
namespace GlpiPlugin\Example;
class Profile
{
    public static function getTabNameForItem(): string { return 'Example'; }
}
PHP);
file_put_contents($plugin . '/src/Audit/Worker.php', <<<'PHP'
<?php
namespace GlpiPlugin\Example\Audit;
final class Worker
{
    public function run(): void {}
}
PHP);
putenv('NINFA_GLPI_ROOT=' . $host);
putenv('NINFA_WORKSPACE_ROOT=' . $fixture . '/workspace');

try {
    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
use GlpiPlugin\Example\Profile as PluginProfile;
use GlpiPlugin\Example\Audit\Worker as AuditWorker;

function plugin_init_example(): void
{
    Plugin::registerClass(PluginProfile::class, ['addtabon' => ['Profile']]);
    Plugin::registerClass(AuditWorker::class);
    \Plugin::registerClass(\GlpiPlugin\Example\Profile::class);
    Plugin::registerClass(GlpiPlugin\Example\Audit\Worker::class);
    Plugin::registerClass(UnknownPluginType::class);
    Plugin::registerClass(PluginProfile::class . $suffix);
    Plugin::registerClass($dynamic);
    // Plugin::registerClass(Phantom::class);
}
function plugin_version_example(): array { return []; }
PHP);
    $context = ProjectContext::fromRoot($plugin);
    assert($context->profile() === 'glpi-plugin');
    $model = GlpiPluginSemanticModel::fromContext($context);
    $registrations = $model->classRegistrations();
    assert(count($registrations) === 5);
    assert(array_column($registrations, 'class') === [
        'GlpiPlugin\\Example\\Profile',
        'GlpiPlugin\\Example\\Audit\\Worker',
        'GlpiPlugin\\Example\\Profile',
        'GlpiPlugin\\Example\\Audit\\Worker',
        'UnknownPluginType',
    ]);
    assert(array_column($registrations, 'declared') === [true, true, true, true, null]);
    assert($registrations[0]['methods'] === ['gettabnameforitem']);
    assert((new GlpiPluginRuleEngine())->analyse($model) === []);

    // Uma classe Plugin importada encobre o símbolo global e só permite \Plugin.
    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
use Vendor\Plugin;
use GlpiPlugin\Example\Profile as PluginProfile;
function plugin_init_example(): void
{
    Plugin::registerClass(PluginProfile::class);
    \Plugin::registerClass(PluginProfile::class);
}
function plugin_version_example(): array { return []; }
PHP);
    $model = GlpiPluginSemanticModel::fromContext($context);
    assert(count($model->classRegistrations()) === 1);
    assert($model->classRegistrations()[0]['declared'] === true);

    // Namespace explícito altera resolução relativa; \Plugin ainda é global.
    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
namespace Synthetic;
use GlpiPlugin\Example\Profile as PluginProfile;
function plugin_init_example(): void
{
    Plugin::registerClass(PluginProfile::class);
    \Plugin::registerClass(PluginProfile::class);
}
function plugin_version_example(): array { return []; }
PHP);
    $model = GlpiPluginSemanticModel::fromContext($context);
    assert(count($model->classRegistrations()) === 1);
    assert($model->classRegistrations()[0]['declared'] === true);

    // Imports agrupados ou aliases duplicados não autorizam escolha de classe.
    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
use GlpiPlugin\Example\{Profile as PluginProfile, Audit\Worker};
function plugin_init_example(): void
{
    \Plugin::registerClass(PluginProfile::class);
}
function plugin_version_example(): array { return []; }
PHP);
    assert(GlpiPluginSemanticModel::fromContext($context)->classRegistrations() === []);

    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
use GlpiPlugin\Example\Profile as TypeAlias;
use Vendor\Other\Profile as TypeAlias;
function plugin_init_example(): void
{
    \Plugin::registerClass(TypeAlias::class);
}
function plugin_version_example(): array { return []; }
PHP);
    assert(GlpiPluginSemanticModel::fromContext($context)->classRegistrations() === []);

    $before = hash_file('sha256', $plugin . '/setup.php');
    GlpiPluginSemanticModel::fromContext($context);
    assert(hash_file('sha256', $plugin . '/setup.php') === $before);
    assert(!is_file($plugin . '/glpi-semantic.json'));
    echo "[OK] GLPI: registerClass suporta aliases ::class e unknown sem executar o plugin.\n";
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
