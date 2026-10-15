<?php

declare(strict_types=1);

/**
 * Preserva as três regressões P1 históricas identificadas na review do PR #1.
 *
 * Só utiliza hosts e plugin sintéticos, não executa scanners externos e não
 * modifica configurações versionadas no consumidor. A troca de host acontece
 * sobre a MESMA raiz de plugin para reproduzir a portabilidade entre máquinas.
 *
 * Proveniência:
 * - PR1 review #3998260114: parâmetros de extensão PHPStan ausente.
 * - PR1 review #3998260120: paths GLPI absolutos obsoletos entre hosts.
 * - PR1 review #3998260123: --force não pode sobrescrever configs do plugin.
 */
require_once dirname(__DIR__) . '/src/ExternalConfigGenerator.php';

$fixture = sys_get_temp_dir() . '/ninfa-glpi-pr1-' . bin2hex(random_bytes(8));
$plugin = $fixture . '/consumer/example';
$firstHost = $fixture . '/host-one';
$secondHost = $fixture . '/host-two';
$workspaceRoot = $fixture . '/workspace';
mkdir($plugin . '/src', 0775, true);

foreach ([$firstHost => '11.0.8', $secondHost => '11.0.9'] as $host => $version) {
    mkdir($host . '/src/autoload', 0775, true);
    mkdir($host . '/src/Glpi/Plugin', 0775, true);
    mkdir($host . '/inc', 0775, true);
    file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '{$version}');\n");
    file_put_contents($host . '/src/Glpi/Plugin/Hooks.php', "<?php namespace Glpi\\Plugin; class Hooks { public const POST_INIT = 'post_init'; }\n");
    file_put_contents($host . '/inc/includes.php', "<?php\n");
}
file_put_contents($plugin . '/composer.json', '{"name":"synthetic/pr1","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/setup.php', "<?php function plugin_init_example(): void {} function plugin_version_example(): array { return []; }\n");
file_put_contents($plugin . '/hook.php', "<?php function plugin_example_install(): bool { return true; } function plugin_example_uninstall(): bool { return true; }\n");
file_put_contents($plugin . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; class Example {}\n");

// Configs históricas do consumidor precisam permanecer intactas, inclusive com --force.
/** @var array<string,string> $consumerConfigs Conteúdo intencionalmente distinto da saída NINFA. */
$consumerConfigs = [
    'phpstan.neon.dist' => "parameters:\n  level: 3\n  # custom-plugin-baseline\n",
    'psalm.xml' => '<?xml version="1.0"?><psalm errorLevel="2" />',
    'ecs.php' => "<?php // plugin-owned-ecs\n",
    'rector.php' => "<?php // plugin-owned-rector\n",
    'phpunit.xml.dist' => '<?xml version="1.0"?><phpunit />',
];
foreach ($consumerConfigs as $file => $content) {
    file_put_contents($plugin . '/' . $file, $content);
}

/** @var array<string,string> $beforeHash Hashes de arquivos versionáveis da fixture. */
$beforeHash = [];
foreach (['composer.json', 'setup.php', 'hook.php', 'src/Example.php', ...array_keys($consumerConfigs)] as $file) {
    $beforeHash[$file] = hash_file('sha256', $plugin . '/' . $file);
}
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    // P1-01: sem extension.neon instalada, não é permitido gerar parameters.glpi.
    putenv('NINFA_GLPI_ROOT=' . $firstHost);
    $firstContext = ProjectContext::fromRoot($plugin);
    assert($firstContext->profile() === 'glpi-plugin');
    $first = (new ExternalConfigGenerator())->generate($firstContext);
    $firstPhpstan = (string) file_get_contents($first['phpstan']);
    assert(str_contains($firstPhpstan, $firstHost . '/src'));
    assert(!str_contains($firstPhpstan, '  glpi:'));
    assert(!str_contains($firstPhpstan, '/glpi-project/phpstan-glpi/extension.neon'));

    // P1-02: mudar NINFA_GLPI_ROOT sobre a mesma raiz exige regeneração sem paths antigos.
    putenv('NINFA_GLPI_ROOT=' . $secondHost);
    $secondContext = ProjectContext::fromRoot($plugin);
    $second = (new ExternalConfigGenerator())->generate($secondContext);
    assert($second['phpstan'] === $first['phpstan']);
    $secondPhpstan = (string) file_get_contents($second['phpstan']);
    $secondPsalm = (string) file_get_contents($second['psalm']);
    $secondBootstrap = (string) file_get_contents($second['glpi-bootstrap']);
    assert(str_contains($secondPhpstan, $secondHost . '/src'));
    assert(str_contains($secondPsalm, $secondHost . '/inc/includes.php'));
    assert(str_contains($secondBootstrap, $secondHost));
    assert(!str_contains($secondPhpstan, $firstHost));
    assert(!str_contains($secondPsalm, $firstHost));
    assert(!str_contains($secondBootstrap, $firstHost));

    // P1-01 complementar: com extension.neon no host, glpi: volta a ser válido.
    mkdir($secondHost . '/vendor/glpi-project/phpstan-glpi', 0775, true);
    file_put_contents(
        $secondHost . '/vendor/glpi-project/phpstan-glpi/extension.neon',
        "parameters:\n  # synthetic extension\n",
    );
    $withExtension = (new ExternalConfigGenerator())->generate($secondContext);
    $phpstanWithExtension = (string) file_get_contents($withExtension['phpstan']);
    assert(str_contains($phpstanWithExtension, '  glpi:'));
    assert(str_contains($phpstanWithExtension, $secondHost . '/vendor/glpi-project/phpstan-glpi/extension.neon'));
    assert(!str_contains($phpstanWithExtension, $firstHost));

    // P1-03: até a flag legada --force precisa gerar somente no workspace externo.
    $output = [];
    $status = 0;
    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(dirname(__DIR__) . '/scripts/ninfa-configure.php') . ' '
        . escapeshellarg($plugin) . ' --force';
    exec('NINFA_GLPI_ROOT=' . escapeshellarg($secondHost)
        . ' NINFA_WORKSPACE_ROOT=' . escapeshellarg($workspaceRoot)
        . ' ' . $command . ' 2>&1', $output, $status);
    assert($status === 0, implode("\n", $output));
    assert(str_contains(implode("\n", $output), 'Profile: glpi-plugin'));
    foreach ($beforeHash as $file => $hash) {
        assert(hash_file('sha256', $plugin . '/' . $file) === $hash, $file);
    }
    assert(is_file($secondContext->workspace()->file('phpstan.neon')));
    assert(!is_file($plugin . '/.ninfa/phpstan.neon'));

    echo "[OK] PR #1 GLPI P1: conditional extension, host portability and protected configs.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
    putenv('NINFA_WORKSPACE_ROOT');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
