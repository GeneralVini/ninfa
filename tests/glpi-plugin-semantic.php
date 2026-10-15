<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/GlpiPluginRuleEngine.php';

$fixture = sys_get_temp_dir() . '/ninfa-glpi-semantic-' . bin2hex(random_bytes(7));
$host = $fixture . '/glpi';
$plugin = $host . '/plugins/example';
$workspace = $fixture . '/workspace';
mkdir($host . '/src/autoload', 0775, true);
mkdir($plugin . '/src', 0775, true);
file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
file_put_contents($plugin . '/composer.json', '{"name":"example/plugin","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; final class Example {}\n");

putenv('NINFA_GLPI_ROOT=' . $host);
putenv('NINFA_WORKSPACE_ROOT=' . $workspace);

try {
    $setup = "<?php\nfunction plugin_init_example(): void {}\nfunction plugin_version_example(): array { return []; }\n";
    $hook = "<?php\nfunction plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n";
    file_put_contents($plugin . '/setup.php', $setup);
    file_put_contents($plugin . '/hook.php', $hook);

    $context = ProjectContext::fromRoot($plugin);
    assert($context->profile() === 'glpi-plugin');
    $model = GlpiPluginSemanticModel::fromContext($context);
    assert(count($model->callbacks()) === 4);
    assert(count((new GlpiPluginRuleEngine())->analyse($model)) === 0);
    assert(json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)['host_version'] === '11.0.8');

    // Duas funções realmente ausentes são correctness, sem autofix.
    file_put_contents($plugin . '/setup.php', "<?php function plugin_init_example() {}\n");
    file_put_contents($plugin . '/hook.php', "<?php function plugin_example_install() {}\n");
    $model = GlpiPluginSemanticModel::fromContext($context);
    $findings = (new GlpiPluginRuleEngine())->analyse($model);
    assert(count($findings) === 2);
    $rules = array_map(static fn(Finding $finding): string => $finding->rule, $findings);
    assert($rules === [GlpiPluginRuleEngine::REQUIRED_CALLBACK_MISSING, GlpiPluginRuleEngine::REQUIRED_CALLBACK_MISSING]);
    assert(str_contains(json_encode($findings, JSON_THROW_ON_ERROR), 'plugin_version_example'));
    assert(str_contains(json_encode($findings, JSON_THROW_ON_ERROR), 'plugin_example_uninstall'));

    // Entry point ausente gera um achado, não dois callbacks redundantes.
    unlink($plugin . '/hook.php');
    $findings = (new GlpiPluginRuleEngine())->analyse(GlpiPluginSemanticModel::fromContext($context));
    assert(count($findings) === 2);
    assert($findings[1]->rule === GlpiPluginRuleEngine::ENTRYPOINT_MISSING);

    // Includes podem declarar callbacks externos; ausência permanece unknown.
    file_put_contents($plugin . '/hook.php', "<?php require_once __DIR__ . '/other.php';\n");
    file_put_contents($plugin . '/setup.php', "<?php include __DIR__ . '/config.php';\n");
    $model = GlpiPluginSemanticModel::fromContext($context);
    assert(count((new GlpiPluginRuleEngine())->analyse($model)) === 0);
    assert($model->callbacks()[0]['exists'] === null);

    // Comentários/strings com nomes não se tornam declarações funcionais.
    file_put_contents($plugin . '/setup.php', "<?php\n// function plugin_init_example() {}\n\$text = 'function plugin_version_example() {}';\n");
    file_put_contents($plugin . '/hook.php', "<?php /* function plugin_example_install() {} */\n");
    $findings = (new GlpiPluginRuleEngine())->analyse(GlpiPluginSemanticModel::fromContext($context));
    assert(count($findings) === 4);

    // Definição condicional não prova nem ausência nem disponibilidade em runtime.
    file_put_contents($plugin . '/setup.php', "<?php if (true) { function plugin_init_example() {} }\n");
    file_put_contents($plugin . '/hook.php', "<?php if (true) { function plugin_example_install() {} }\n");
    $model = GlpiPluginSemanticModel::fromContext($context);
    assert($model->callbacks()[0]['exists'] === null);
    assert(count((new GlpiPluginRuleEngine())->analyse($model)) === 0);

    // Um namespace declarado impede pressupor funções globais.
    file_put_contents($plugin . '/setup.php', "<?php namespace Custom; function plugin_init_example() {}\n");
    $model = GlpiPluginSemanticModel::fromContext($context);
    assert($model->callbacks()[0]['exists'] === null);

    // Análise não altera os arquivos reais de entrada ou cria boilerplate.
    $before = hash_file('sha256', $plugin . '/setup.php');
    GlpiPluginSemanticModel::fromContext($context);
    assert(hash_file('sha256', $plugin . '/setup.php') === $before);
    assert(!is_file($plugin . '/glpi-semantic.json'));
    assert(!is_file($plugin . '/glpi-findings.json'));

    echo "[OK] Lifecycle GLPI estático e tri-state, sem alterações no consumidor.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
    putenv('NINFA_WORKSPACE_ROOT');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($fixture);
}
