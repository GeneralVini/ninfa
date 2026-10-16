<?php

declare(strict_types=1);

/**
 * Reproduz o P1-03 do PR #1 pelos comandos públicos check e assist.
 *
 * O NINFA não pode afirmar que usou regras customizadas do consumidor:
 * configurações externas são aplicadas e as locais apenas detectadas.
 * Fixtures e executáveis substitutos permanecem integralmente em /tmp,
 * sem acesso a SIGAPS/ÉGIDE reais ou execução do projeto consumidor.
 */
require_once dirname(__DIR__) . '/src/ProcessRunner.php';
require_once dirname(__DIR__) . '/src/ProjectContext.php';

$fixture = sys_get_temp_dir() . '/ninfa-glpi-config-coverage-' . bin2hex(random_bytes(7));
$host = $fixture . '/glpi';
$plugin = $fixture . '/consumer/example';
$bin = $plugin . '/vendor/bin';
$workspaceRoot = $fixture . '/workspace';
mkdir($host . '/src/autoload', 0775, true);
mkdir($host . '/inc', 0775, true);
mkdir($plugin . '/src', 0775, true);
mkdir($bin, 0775, true);
file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
file_put_contents($host . '/inc/includes.php', "<?php\n");
file_put_contents($plugin . '/composer.json', '{"name":"synthetic/example","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/setup.php', "<?php function plugin_init_example(): void {}\nfunction plugin_version_example(): array { return []; }\n");
file_put_contents($plugin . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n");
file_put_contents($plugin . '/src/Example.php', "<?php final class Example {}\n");

/** @var array<string,string> $customConfigFiles Sentinelas mantidas somente na fixture sintética. */
$customConfigFiles = [
    'phpstan.neon.dist' => "parameters:\n  level: max\n  ignoreErrors:\n    - 'SYNTHETIC-CONSUMER-RULE'\n",
    'psalm.xml' => '<?xml version="1.0"?><psalm errorLevel="1" />',
    'ecs.php' => "<?php return ['SYNTHETIC-CONSUMER-ECS'];\n",
    'rector.php' => "<?php return ['SYNTHETIC-CONSUMER-RECTOR'];\n",
];
/** @var array<string,string> $hashes Hashes do consumidor sintético anteriores à análise. */
$hashes = [];
foreach ($customConfigFiles as $name => $content) {
    file_put_contents($plugin . '/' . $name, $content);
    $hashes[$name] = hash_file('sha256', $plugin . '/' . $name);
}

// Scanners fictícios somente imprimem JSON válido/exit 0; nunca carregam PHP do consumidor.
foreach (['ecs' => '', 'rector' => '', 'phpstan' => '{"files":[]}', 'psalm' => '[]'] as $name => $output) {
    $path = $bin . '/' . $name;
    file_put_contents($path, "#!/usr/bin/env php\n<?php echo " . var_export($output, true) . ";\n");
    chmod($path, 0755);
}
putenv('NINFA_GLPI_ROOT=' . $host);
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);
putenv('NO_COLOR=1');

try {
    $context = ProjectContext::fromRoot($plugin);
    assert($context->profile() === 'glpi-plugin');

    $runner = new ProcessRunner();
    $check = $runner->runCaptured([PHP_BINARY, dirname(__DIR__) . '/bin/ninfa', 'check', $plugin], $plugin);
    assert($check->exitCode === 0);
    assert(str_contains($check->stdout, 'configs personalizadas do consumidor detectadas e não mescladas'));

    $report = $context->workspace()->file('consumer-config-advisory.json');
    assert(is_file($report));
    $manifest = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
    assert($manifest['profile'] === 'glpi-plugin');
    assert($manifest['consumer_config_policy'] === 'not_merged_with_generated_configs');
    assert($manifest['review_required'] === true);
    assert($manifest['detected'] === [
        'phpstan' => ['phpstan.neon.dist'],
        'psalm' => ['psalm.xml'],
        'ecs' => ['ecs.php'],
        'rector' => ['rector.php'],
    ]);

    // As configs efetivamente entregues aos analyzers são externas, não as do plugin.
    $phpstan = (string) file_get_contents($context->workspace()->file('phpstan.neon'));
    $psalm = (string) file_get_contents($context->workspace()->file('psalm.xml'));
    $ecs = (string) file_get_contents($context->workspace()->file('ecs.php'));
    $rector = (string) file_get_contents($context->workspace()->file('rector.php'));
    assert(!str_contains($phpstan, 'SYNTHETIC-CONSUMER-RULE'));
    assert(!str_contains($ecs, 'SYNTHETIC-CONSUMER-ECS'));
    assert(!str_contains($rector, 'SYNTHETIC-CONSUMER-RECTOR'));
    assert(str_contains($psalm, 'errorLevel="8"'));
    assert(!str_contains($psalm, 'errorLevel="1"'));

    // Assist também deve reportar a limitação operacional, mesmo com scanners limpos.
    $assist = $runner->runCaptured([
        PHP_BINARY, dirname(__DIR__) . '/scripts/ninfa-configure.php', $plugin, '--assist',
    ], $plugin);
    assert($assist->exitCode === 0);
    assert(str_contains($assist->stderr, 'configs personalizadas do consumidor não mescladas'));
    assert(is_file($context->workspace()->file('assist/glpi-semantic.json')));

    foreach ($hashes as $name => $sha256) {
        assert(hash_file('sha256', $plugin . '/' . $name) === $sha256);
    }
    assert(!is_file($plugin . '/consumer-config-advisory.json'));
    assert(!is_dir($plugin . '/.ninfa'));

    echo "[OK] GLPI P1-03: check/assist avisam sobre configs ignoradas; consumidor intocado.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
    putenv('NINFA_WORKSPACE_ROOT');
    putenv('NO_COLOR');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
