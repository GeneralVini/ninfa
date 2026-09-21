<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/PipelinePlan.php';

$root = sys_get_temp_dir() . '/ninfa-pipeline-' . bin2hex(random_bytes(4));
$glpiRoot = $root . '/glpi';
$projectRoot = $glpiRoot . '/plugins/example';

try {
    mkdir($projectRoot . '/src', 0775, true);
    mkdir($glpiRoot . '/src/autoload', 0775, true);

    file_put_contents($glpiRoot . '/src/autoload/constants.php', "<?php define('GLPI_VERSION', '11.0.8');\n");
    file_put_contents($projectRoot . '/setup.php', "<?php function plugin_init_example(): void {}\n");
    file_put_contents($projectRoot . '/hook.php', "<?php function plugin_example_install(): bool { return true; }\n");
    file_put_contents($projectRoot . '/src/Example.php', "<?php namespace GlpiPlugin\\Example; final class Example {}\n");
    file_put_contents($projectRoot . '/composer.json', json_encode([
        'require' => ['php' => '>=8.2'],
    ], JSON_THROW_ON_ERROR));

    putenv('NINFA_GLPI_ROOT=' . $glpiRoot);
    $context = ProjectContext::fromRoot($projectRoot);
    $plan = new PipelinePlan();

    $check = $plan->check($context);
    assert(array_column($check, 'id') === ['ecs', 'rector', 'phpstan', 'psalm', 'test']);
    assert($check[1]['mode'] === 'dry-run');

    $fix = $plan->fix($context);
    assert(array_column($fix, 'id') === ['ecs', 'rector']);
    assert(array_unique(array_column($fix, 'mode')) === ['fix']);
    assert($plan->lefthookFixHooks($context) === ['ecs', 'rector']);

    mkdir($projectRoot . '/assets', 0775, true);
    file_put_contents($projectRoot . '/assets/logo.svg', '<svg></svg>');
    file_put_contents($projectRoot . '/package.json', '{"private":true}');
    $noFrontendCheck = $plan->check(ProjectContext::fromRoot($projectRoot));
    assert(!in_array('eslint', array_column($noFrontendCheck, 'id'), true));
    assert(!in_array('prettier', array_column($noFrontendCheck, 'id'), true));

    file_put_contents($projectRoot . '/package.json', json_encode([
        'private' => true,
        'devDependencies' => ['eslint' => '^9.0', 'prettier' => '^3.0'],
    ], JSON_THROW_ON_ERROR));
    $frontendContext = ProjectContext::fromRoot($projectRoot);

    $frontendCheck = $plan->check($frontendContext);
    assert(array_column($frontendCheck, 'id') === ['ecs', 'rector', 'phpstan', 'psalm', 'eslint', 'prettier', 'test']);

    $frontendFix = $plan->fix($frontendContext);
    assert(array_column($frontendFix, 'id') === ['ecs', 'rector', 'eslint', 'prettier']);
    assert($plan->lefthookFixHooks($frontendContext) === ['ecs', 'rector', 'eslint', 'prettier']);

    $security = $plan->security($context);
    assert(array_column($security, 'id') === ['composer-audit', 'osv', 'psalm-taint', 'semgrep']);
    assert(!in_array('dast', array_column($security, 'id'), true));

    $genericRoot = $root . '/generic';
    mkdir($genericRoot . '/src', 0775, true);
    file_put_contents($genericRoot . '/src/Example.php', "<?php final class GenericExample {}\n");
    $genericContext = ProjectContext::fromRoot($genericRoot);
    assert($genericContext->profile() === 'php-generic');
    assert(array_column($plan->check($genericContext), 'id') === ['ecs', 'rector', 'phpstan', 'psalm', 'test']);
    assert(array_column($plan->fix($genericContext), 'id') === ['ecs', 'rector']);
    assert(array_column($plan->security($genericContext), 'id') === ['composer-audit', 'osv', 'psalm-taint', 'semgrep']);

    $recheckingSource = (string) file_get_contents(dirname(__DIR__) . '/src/RecheckingPipelineRunner.php');
    assert(substr_count($recheckingSource, "run('check'") === 1);
    assert(!is_file(dirname(__DIR__) . '/src/FrontendAwarePipelineRunner.php'));

    $runnerSource = (string) file_get_contents(dirname(__DIR__) . '/src/PipelineRunner.php');
    assert(str_contains($runnerSource, "'eslint' =>"));
    assert(str_contains($runnerSource, "'prettier' =>"));
    assert(str_contains($runnerSource, "composer.lock"));
    assert(str_contains($runnerSource, 'runOsv'));
    assert(str_contains($runnerSource, 'security-report.json'));
    assert(!str_contains($runnerSource, "'dast' =>"));

    echo "[OK] Pipelines GLPI e PHP genérico, frontend unificado, recheck único e security SCA/SAST definidos.\n";
} finally {
    putenv('NINFA_GLPI_ROOT');
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
