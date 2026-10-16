<?php

declare(strict_types=1);

/**
 * Regressões dos apontamentos P1 do review do PR #1 do NINFA.
 *
 * Reproduz exclusivamente os contratos da infraestrutura de configuração
 * externa em hosts/plugins GLPI sintéticos. Não executa analisadores e não
 * acessa os consumidores SIGAPS ou ÉGIDE reais.
 */
require_once dirname(__DIR__) . '/src/ExternalConfigGenerator.php';

$root = sys_get_temp_dir() . '/ninfa-glpi-pr1-review-' . bin2hex(random_bytes(7));
$plugin = $root . '/consumer/plugins/example';
$hostA = $root . '/glpi-a';
$hostB = $root . '/glpi-b';
$workspaceRoot = $root . '/workspace';

mkdir($plugin . '/src', 0775, true);
foreach ([$hostA, $hostB] as $host) {
    mkdir($host . '/src/autoload', 0775, true);
    mkdir($host . '/inc', 0775, true);
    file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
    file_put_contents($host . '/inc/includes.php', "<?php\n");
}

file_put_contents($plugin . '/composer.json', '{"name":"synthetic/example","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/setup.php', "<?php function plugin_init_example(): void {}\nfunction plugin_version_example(): array { return []; }\n");
file_put_contents($plugin . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n");
file_put_contents($plugin . '/src/Example.php', "<?php final class Example {}\n");

// O review P1-03 alertou sobre arquivos de configuração versionados. Estes
// sentinels são do consumidor: a geração NINFA jamais pode sobrescrevê-los.
/** @var array<string,string> $preserved Sentinels de configuração existentes no consumidor. */
$preserved = [
    'phpstan.neon.dist' => "parameters:\n  level: max\n  ignoreErrors:\n    - 'custom-consumer-pattern'\n",
    'psalm.xml' => '<?xml version="1.0"?><psalm errorLevel="1" />',
    'ecs.php' => "<?php return ['consumer-owned-ecs'];\n",
    'rector.php' => "<?php return ['consumer-owned-rector'];\n",
    'phpunit.xml.dist' => '<phpunit cacheDirectory="consumer-owned" />',
];
foreach ($preserved as $file => $body) {
    file_put_contents($plugin . '/' . $file, $body);
}

/** @var array<string,string> $originalHashes Hashes do consumidor após materialização dos sentinels. */
$originalHashes = [];
foreach (['composer.json', 'setup.php', 'hook.php', 'src/Example.php', ...array_keys($preserved)] as $file) {
    $originalHashes[$file] = hash_file('sha256', $plugin . '/' . $file);
}

putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);
try {
    // P1-01: nenhuma extensão instalada => nunca emitir parâmetros da extensão.
    putenv('NINFA_GLPI_ROOT=' . $hostA);
    $contextA = ProjectContext::fromRoot($plugin);
    assert($contextA->profile() === 'glpi-plugin');
    $configsA = (new ExternalConfigGenerator())->generate($contextA);
    $stanA = (string) file_get_contents($configsA['phpstan']);
    assert(!str_contains($stanA, '  glpi:'));
    assert(!str_contains($stanA, 'includes:'));
    assert(str_contains($stanA, $hostA . '/src'));
    assert(!str_contains($stanA, $hostB . '/src'));
    // O PR1 P1-03 não está fechado: preservar a configuração não é usá-la.
    $coverageFile = $contextA->workspace()->file('consumer-config-advisory.json');
    $coverage = json_decode((string) file_get_contents($coverageFile), true, 512, JSON_THROW_ON_ERROR);
    assert($coverage['review_required'] === true);
    assert($coverage['consumer_config_policy'] === 'not_merged_with_generated_configs');
    assert($coverage['detected']['phpstan'] === ['phpstan.neon.dist']);
    assert($coverage['detected']['psalm'] === ['psalm.xml']);
    assert($coverage['detected']['ecs'] === ['ecs.php']);
    assert($coverage['detected']['rector'] === ['rector.php']);
    assert(!str_contains($stanA, 'custom-consumer-pattern'));

    // P1-01: quando instalada no host, a extensão deve ser incluída e
    // só então é permitido produzir parâmetros específicos phpstan-glpi.
    mkdir($hostA . '/vendor/glpi-project/phpstan-glpi', 0775, true);
    file_put_contents($hostA . '/vendor/glpi-project/phpstan-glpi/extension.neon', "parameters:\n");
    $configsWithExtension = (new ExternalConfigGenerator())->generate(ProjectContext::fromRoot($plugin));
    $stanWithExtension = (string) file_get_contents($configsWithExtension['phpstan']);
    assert(str_contains($stanWithExtension, 'includes:'));
    assert(str_contains($stanWithExtension, $hostA . '/vendor/glpi-project/phpstan-glpi/extension.neon'));
    assert(str_contains($stanWithExtension, '  glpi:'));
    assert(str_contains($stanWithExtension, '    glpiPath: ' . $hostA));

    // P1-02: no mesmo plugin/workspace, trocar NINFA_GLPI_ROOT precisa
    // regenerar todos os paths, sem carregar referências ao host anterior.
    putenv('NINFA_GLPI_ROOT=' . $hostB);
    $contextB = ProjectContext::fromRoot($plugin);
    $configsB = (new ExternalConfigGenerator())->generate($contextB);
    $stanB = (string) file_get_contents($configsB['phpstan']);
    $psalmB = (string) file_get_contents($configsB['psalm']);
    $bootstrapB = (string) file_get_contents($configsB['glpi-bootstrap']);
    assert($contextB->workspace()->path() === $contextA->workspace()->path());
    assert(str_contains($stanB, $hostB . '/src'));
    assert(!str_contains($stanB, $hostA));
    assert(!str_contains($stanB, '  glpi:'));
    assert(!str_contains($stanB, 'includes:'));
    assert(str_contains($psalmB, $hostB . '/inc/includes.php'));
    assert(!str_contains($psalmB, $hostA));
    assert(str_contains($bootstrapB, $hostB));
    assert(!str_contains($bootstrapB, $hostA));

    // P1-03: também exercita a interface legada --force, origem da revisão.
    // A CLI não pode reescrever as configurações customizadas do consumidor.
    require_once dirname(__DIR__) . '/src/ProcessRunner.php';
    $forceRun = (new ProcessRunner())->runCaptured([
        PHP_BINARY, dirname(__DIR__) . '/scripts/ninfa-configure.php', $plugin, '--force',
    ], $plugin);
    assert($forceRun->exitCode === 0);
    assert(str_contains($forceRun->stdout, '--force mantido apenas por compatibilidade'));
    assert(str_contains($forceRun->stdout, '[NINFA] Profile: glpi-plugin'));
    assert(str_contains($forceRun->stderr, 'configs personalizadas do consumidor não mescladas'));

    // P1-03: a geração só pode alterar artefatos externos. A preservação
    // não implica que todas as customizações do consumidor sejam aplicadas
    // pelos scanners — essa é uma questão separada, ainda sob triagem.
    foreach ($originalHashes as $file => $expected) {
        assert(hash_file('sha256', $plugin . '/' . $file) === $expected);
    }
    foreach (['phpstan', 'psalm', 'ecs', 'rector'] as $tool) {
        assert(str_starts_with($configsB[$tool], $contextB->workspace()->path() . '/'));
        assert(!str_starts_with($configsB[$tool], $plugin . '/'));
    }

    echo "[OK] GLPI PR #1 P1: extensao condicional, host atualizado, consumidor imutavel.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
    putenv('NINFA_WORKSPACE_ROOT');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}
