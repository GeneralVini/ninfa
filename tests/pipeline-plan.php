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

    file_put_contents($projectRoot . '/package.json', '{"private":true}');
    $frontendContext = ProjectContext::fromRoot($projectRoot);
    assert($frontendContext->hasJavaScript() === true);

    $frontendCheck = $plan->check($frontendContext);
    assert(array_column($frontendCheck, 'id') === ['ecs', 'rector', 'phpstan', 'psalm', 'eslint', 'prettier', 'test']);

    $frontendFix = $plan->fix($frontendContext);
    assert(array_column($frontendFix, 'id') === ['ecs', 'rector', 'eslint', 'prettier']);
    assert($plan->lefthookFixHooks($frontendContext) === ['ecs', 'rector', 'eslint', 'prettier']);

    $security = $plan->security($context);
    assert(array_column($security, 'id') === ['composer-audit', 'psalm-taint', 'semgrep', 'dast']);
    assert($security[3]['optional'] === true);

    echo "[OK] Pipelines check, fix, frontend, lefthook e security definidos.\n";
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
