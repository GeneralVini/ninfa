<?php

declare(strict_types=1);

/**
 * Valida a execução de regras GLPI nativas dentro do pipeline público check.
 *
 * Usa host e plugin sintéticos, binários substitutos que não escrevem no
 * consumidor e workspace externo. Exercita sucesso, warning não bloqueante,
 * erro conclusivo e casos unknown, além de inspecionar o RunResult normalizado.
 */
require_once dirname(__DIR__) . '/src/PipelineRunner.php';

$root = sys_get_temp_dir() . '/ninfa-glpi-check-' . bin2hex(random_bytes(8));
$host = $root . '/glpi';
$plugin = $host . '/plugins/example';
$workspaceRoot = $root . '/workspace';
$bin = $plugin . '/vendor/bin';
mkdir($host . '/src/autoload', 0775, true);
mkdir($host . '/inc', 0775, true);
mkdir($plugin . '/src', 0775, true);
mkdir($bin, 0775, true);
file_put_contents($host . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
file_put_contents($host . '/inc/includes.php', "<?php\n");
file_put_contents($plugin . '/composer.json', '{"name":"test/example","type":"glpi-plugin","require":{"php":">=8.2"}}');
file_put_contents($plugin . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; final class Example {}\n");
file_put_contents($plugin . '/hook.php', "<?php\nfunction plugin_example_install(): bool { return true; }\nfunction plugin_example_uninstall(): bool { return true; }\n");

/**
 * Cria uma ferramenta substituta sem efeitos de escrita sobre o plugin.
 *
 * @param string $bin Diretório de executáveis locais da fixture.
 * @param string $name Nome que o ToolResolver deve encontrar.
 * @param string $stdout Resposta estruturada para analyzers PHPStan/Psalm.
 */
function glpiCheckFakeTool(string $bin, string $name, string $stdout): void
{
    $file = $bin . '/' . $name;
    file_put_contents(
        $file,
        "#!/usr/bin/env php\n<?php\nfwrite(STDOUT, " . var_export($stdout, true) . ");\nexit(0);\n",
    );
    chmod($file, 0755);
}

foreach (['ecs' => '', 'rector' => '', 'phpstan' => '{"files":[]}', 'psalm' => '[]'] as $name => $stdout) {
    glpiCheckFakeTool($bin, $name, $stdout);
}
putenv('NINFA_GLPI_ROOT=' . $host);
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);
putenv('NO_COLOR=1');

try {
    $valid = <<<'PHP'
<?php
function plugin_init_example(): void
{
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['example'] = true;
}
function plugin_version_example(): array { return ['version' => '1.0.0']; }
PHP;
    file_put_contents($plugin . '/setup.php', $valid);
    $context = ProjectContext::fromRoot($plugin);
    assert($context->profile() === 'glpi-plugin');

    // Nenhum finding em lifecycle/hook correto; nenhuma etapa security é agregada.
    $runner = new PipelineRunner();
    $clean = $runner->runResult('check', $context);
    assert($clean->finalExitCode === 0);
    $semantic = array_values(array_filter(
        $clean->toolResults,
        static fn (ToolResult $result): bool => $result->id === 'glpi-semantic',
    ));
    assert(count($semantic) === 1);
    assert($semantic[0]->state === ToolResult::OK);
    assert($semantic[0]->findings === []);
    assert($semantic[0]->coverage['blocking'] === 0);
    assert(count($clean->toolResults) === 6);
    assert(is_file($context->workspace()->file('check/glpi-semantic.json')));
    assert(is_file($context->workspace()->file('check/glpi-findings.json')));

    // Warning de COR-003 é observável, mas não é security nem erro bloqueante.
    $warning = str_replace(
        "\$PLUGIN_HOOKS['csrf_compliant']['example'] = true;",
        "\$PLUGIN_HOOKS['csrf_compliant'] = true;",
        $valid,
    );
    file_put_contents($plugin . '/setup.php', $warning);
    $sourceHash = hash_file('sha256', $plugin . '/setup.php');
    $warnRun = $runner->runResult('check', $context);
    assert($warnRun->finalExitCode === 0);
    $warnings = array_values(array_filter($warnRun->findings(), static fn (Finding $f): bool => $f->rule === GlpiPluginRuleEngine::FLAT_SCALAR_HOOK));
    assert(count($warnings) === 1);
    assert($warnings[0]->severity === 'warning');
    assert($warnings[0]->metadata['autofix'] === false);
    assert(hash_file('sha256', $plugin . '/setup.php') === $sourceHash);
    $audit = json_decode((string) file_get_contents($context->workspace()->file('check/glpi-findings.json')), true, 512, JSON_THROW_ON_ERROR);
    assert(count($audit) === 1);
    assert($audit[0]['rule'] === GlpiPluginRuleEngine::FLAT_SCALAR_HOOK);

    // O CLI público também deve preservar warning não bloqueante.
    $cliWarning = (new ProcessRunner())->runCaptured([
        PHP_BINARY, dirname(__DIR__) . '/bin/ninfa', 'check', $plugin,
    ], $plugin);
    assert($cliWarning->exitCode === 0);
    assert(str_contains($cliWarning->stdout, 'glpi-semantic: ok (codigo 0)'));
    assert(str_contains($cliWarning->stdout, GlpiPluginRuleEngine::FLAT_SCALAR_HOOK));

    // Ausência conclusiva de callback transforma a etapa nativa em bloqueante.
    file_put_contents($plugin . '/setup.php', str_replace(
        "function plugin_version_example(): array { return ['version' => '1.0.0']; }",
        '',
        $valid,
    ));
    $bad = $runner->runResult('check', $context);
    assert($bad->finalExitCode === 1);
    $callbackFindings = array_values(array_filter($bad->findings(), static fn (Finding $f): bool => $f->rule === GlpiPluginRuleEngine::REQUIRED_CALLBACK_MISSING));
    assert(count($callbackFindings) === 1);
    assert($callbackFindings[0]->severity === 'error');
    assert($callbackFindings[0]->metadata['remediation_risk'] === 'review');

    // O CLI público precisa propagar erro nativo mesmo com scanners externos limpos.
    $cliError = (new ProcessRunner())->runCaptured([
        PHP_BINARY, dirname(__DIR__) . '/bin/ninfa', 'check', $plugin,
    ], $plugin);
    assert($cliError->exitCode === 1);
    assert(str_contains($cliError->stdout, 'glpi-semantic: failed (codigo 1)'));
    assert(str_contains($cliError->stdout, GlpiPluginRuleEngine::REQUIRED_CALLBACK_MISSING));

    // Include pode declarar callback externo: a ausência não é demonstrável.
    file_put_contents($plugin . '/setup.php', "<?php\nrequire_once __DIR__ . '/other.php';\nfunction plugin_init_example(): void {}\n");
    $unknown = $runner->runResult('check', $context);
    assert($unknown->finalExitCode === 0);
    assert(array_values(array_filter(
        $unknown->findings(),
        static fn (Finding $f): bool => $f->rule === GlpiPluginRuleEngine::REQUIRED_CALLBACK_MISSING,
    )) === []);

    // Não há vazamento de relatórios para a árvore consumidora.
    assert(!is_file($plugin . '/glpi-findings.json'));
    assert(!is_dir($plugin . '/check'));
    echo "[OK] GLPI check integra semântica, warnings, erros, unknown e auditoria externa.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
    putenv('NINFA_WORKSPACE_ROOT');
    putenv('NO_COLOR');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
