<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/GlpiPluginRuleEngine.php';

$fixture = sys_get_temp_dir() . '/ninfa-glpi-hooks-' . bin2hex(random_bytes(7));
$host = $fixture . '/glpi';
$plugin = $host . '/plugins/example';
$workspace = $fixture . '/workspace';
mkdir($host . '/src/autoload', 0775, true);
mkdir($plugin . '/src', 0775, true);
file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
file_put_contents($plugin . '/composer.json', '{"name":"example/plugin","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; final class Example {}\n");
file_put_contents($plugin . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n");
putenv('NINFA_GLPI_ROOT=' . $host);
putenv('NINFA_WORKSPACE_ROOT=' . $workspace);

try {
    $setup = <<<'PHP'
<?php
function plugin_init_example(): void
{
    global $PLUGIN_HOOKS;
    // $PLUGIN_HOOKS['change_profile'] = 'fake';
    $fake = '$PLUGIN_HOOKS["csrf_compliant"] = true;';
    $PLUGIN_HOOKS['csrf_compliant']['example'] = true;
    $PLUGIN_HOOKS["config_page"]['example'] = 'front/config.php';
    $PLUGIN_HOOKS['use_massive_action']['example'] = 1;
    $PLUGIN_HOOKS['change_profile'] = 'plugin_example_change_profile';
    $PLUGIN_HOOKS['csrf_compliant'] = true;
    $PLUGIN_HOOKS['config_page'] = ['example' => 'front/config.php'];
    $PLUGIN_HOOKS['csrf_compliant'] = true . $foo;
    $PLUGIN_HOOKS['csrf_compliant'] = $dynamic;
    $PLUGIN_HOOKS[$dynamic] = true;
    $PLUGIN_HOOKS['csrf_compliant'][$dynamic] = true;
    $PLUGIN_HOOKS['csrf_compliant']['example'][] = $dynamic;
    $PLUGIN_HOOKS['change_entity'] += 1;
    $PLUGIN_HOOKS[Hooks::POST_INIT]['example'] = 'plugin_example_postinit';
    $PLUGIN_HOOKS['custom_unknown_hook'] = 'custom';
}
function plugin_version_example(): array
{
    return [];
}
PHP;
    file_put_contents($plugin . '/setup.php', $setup);

    $context = ProjectContext::fromRoot($plugin);
    assert($context->profile() === 'glpi-plugin');
    $model = GlpiPluginSemanticModel::fromContext($context);
    $registrations = $model->hookRegistrations();
    assert(count($registrations) === 9);
    assert($registrations[0]['hook'] === 'csrf_compliant');
    assert($registrations[0]['plugin'] === 'example');
    assert($registrations[0]['scope'] === 'plugin');
    assert($registrations[3]['hook'] === 'change_profile');
    assert($registrations[3]['scope'] === 'hook');
    assert($registrations[3]['scalar_value'] === true);
    assert($registrations[4]['hook'] === 'csrf_compliant');
    assert($registrations[4]['scalar_value'] === true);
    assert($registrations[5]['scalar_value'] === false); // array válido
    assert($registrations[6]['scalar_value'] === false); // expressão composta
    assert($registrations[7]['scalar_value'] === false); // variável
    assert($registrations[8]['hook'] === 'custom_unknown_hook');

    // COR-003 só acusa atribuições planas de escalar em hooks conhecidos.
    $findings = (new GlpiPluginRuleEngine())->analyse($model);
    assert(count($findings) === 2);
    foreach ($findings as $finding) {
        assert($finding->rule === GlpiPluginRuleEngine::FLAT_SCALAR_HOOK);
        assert($finding->severity === 'warning');
        assert($finding->confidence === 'high');
        assert($finding->metadata['category'] === 'correctness');
        assert($finding->metadata['remediation_risk'] === 'review');
        assert($finding->metadata['autofix'] === false);
        assert($finding->file === 'setup.php');
        assert($finding->line > 0);
    }
    assert(array_column(array_map(static fn (Finding $f): array => $f->metadata, $findings), 'hook') === ['change_profile', 'csrf_compliant']);
    assert(count(json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)['hook_registrations']) === 9);

    // Código sem registro literal conclusivo é unknown, não Finding.
    file_put_contents($plugin . '/setup.php', <<<'PHP'
<?php
function plugin_init_example(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS[$hook] = true;
    $PLUGIN_HOOKS['csrf_compliant'][$plugin] = true;
    $PLUGIN_HOOKS[Hooks::POST_INIT]['example'] = 'plugin_example_postinit';
    $PLUGIN_HOOKS['change_profile'] = (string) $dynamic;
}
function plugin_version_example(): array { return []; }
PHP);
    $model = GlpiPluginSemanticModel::fromContext($context);
    assert(count((new GlpiPluginRuleEngine())->analyse($model)) === 0);

    // Tokenização, inventário e rule engine preservam o consumidor original.
    $before = hash_file('sha256', $plugin . '/setup.php');
    GlpiPluginSemanticModel::fromContext($context);
    assert(hash_file('sha256', $plugin . '/setup.php') === $before);
    assert(!is_file($plugin . '/glpi-semantic.json'));

    echo "[OK] GLPI hooks: registros literais, atribuição plana, dynamic unknown e read-only.\n";
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
