<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ProjectContext.php';
require_once dirname(__DIR__) . '/src/ExternalConfigGenerator.php';
require_once dirname(__DIR__) . '/src/SemanticHints.php';
require_once dirname(__DIR__) . '/src/LefthookConfigGenerator.php';
require_once dirname(__DIR__) . '/src/ProcessRunner.php';
require_once dirname(__DIR__) . '/src/ToolResolver.php';

function runAssist(ProjectContext $context, array $configs): int
{
    $dir = $context->workspace()->file('assist');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a auditoria do assist.');
    }

    $resolver = new ToolResolver();
    $runner = new ProcessRunner();
    $phpstan = $runner->runCaptured([
        $resolver->resolve('phpstan', $context->root()),
        'analyse', '--configuration', $configs['phpstan'], '--error-format=json', '--no-progress',
    ], $context->root());
    $psalm = $runner->runCaptured([
        $resolver->resolve('psalm', $context->root()),
        '--config=' . $configs['psalm'], '--output-format=json', '--no-progress',
    ], $context->root());

    file_put_contents($dir . '/phpstan.json', $phpstan->stdout);
    file_put_contents($dir . '/phpstan.stderr.log', $phpstan->stderr);
    file_put_contents($dir . '/psalm.json', $psalm->stdout);
    file_put_contents($dir . '/psalm.stderr.log', $psalm->stderr);

    $findings = [
        ...FindingRenderer::phpStan($phpstan->stdout, $context->root()),
        ...FindingRenderer::psalm($psalm->stdout, $context->root()),
    ];

    $audit = $dir . '/findings.json';
    file_put_contents(
        $audit,
        json_encode([
            'schema' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'project' => $context->root(),
            'profile' => $context->profile(),
            'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
    );

    FindingRenderer::render($findings, true);
    echo '[NINFA][assist] Achados: ' . count($findings) . PHP_EOL;
    echo '[NINFA][assist] Auditoria: ' . $audit . PHP_EOL;

    if ($findings !== []) {
        return 1;
    }

    foreach ([['PHPStan', $phpstan], ['Psalm', $psalm]] as [$name, $result]) {
        if ($result->exitCode === 0) {
            continue;
        }

        fwrite(STDERR, '[ERRO] ' . $name . ' terminou com código ' . $result->exitCode . ' sem finding estruturado.' . PHP_EOL);
        if (trim($result->stderr) !== '') {
            fwrite(STDERR, $result->stderr . PHP_EOL);
        }
        if (trim($result->stdout) !== '') {
            fwrite(STDERR, $result->stdout . PHP_EOL);
        }

        return $result->exitCode;
    }

    return 0;
}

$args = $argv;
array_shift($args);
$force = in_array('--force', $args, true);
$assist = in_array('--assist', $args, true);
$args = array_values(array_filter($args, static fn (string $arg): bool => !in_array($arg, ['--force', '--assist'], true)));

$projectRoot = $args[0] ?? getcwd();
if (!is_string($projectRoot) || $projectRoot === '') {
    fwrite(STDERR, "[ERRO] Informe a raiz do projeto.\n");
    exit(1);
}

try {
    $context = ProjectContext::fromRoot($projectRoot);
    $configs = (new ExternalConfigGenerator())->generate($context);
    if ($assist) {
        exit(runAssist($context, $configs));
    }

    $lefthookConfig = (new LefthookConfigGenerator())->generate($context);
    $semanticHints = SemanticHints::fromProject($context->root());
    $semanticIndex = $context->workspace()->file('semantic-index.json');
    file_put_contents(
        $semanticIndex,
        json_encode([
            'profile' => $context->profile(),
            'files' => $semanticHints->files(),
            'symbols' => $semanticHints->symbols(),
            'profile_signals' => $semanticHints->profileSignals(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    );
} catch (Throwable $error) {
    fwrite(STDERR, '[ERRO] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

if ($force) {
    echo "[NINFA] --force mantido apenas por compatibilidade; o workspace externo e regenerado a cada execucao.\n";
}

echo '[NINFA] Profile: ' . $context->profile() . PHP_EOL;
echo '[NINFA] Projeto: ' . $context->root() . PHP_EOL;
echo '[NINFA] PHP: ' . $context->phpVersion() . PHP_EOL;
echo '[NINFA] Workspace: ' . $context->workspace()->path() . PHP_EOL;
echo '[NINFA] Caminhos: ' . implode(', ', $context->paths()) . PHP_EOL;
echo '[NINFA] Lefthook: ' . $lefthookConfig . PHP_EOL;
echo '[NINFA] Semantica: ' . $semanticIndex . PHP_EOL;
echo '[NINFA] Docs semanticos: ' . ($semanticHints->files() === [] ? 'nenhum' : implode(', ', $semanticHints->files())) . PHP_EOL;
echo '[NINFA] Simbolos documentados: ' . count($semanticHints->symbols()) . PHP_EOL;

if ($context->profile() === 'glpi-plugin') {
    echo '[NINFA] GLPI host: ' . $context->glpiRoot() . PHP_EOL;
    echo '[NINFA] GLPI versao: ' . ($context->glpiVersion() ?? 'desconhecida') . PHP_EOL;
    echo '[NINFA] PHPStan level: ' . $context->phpStanLevel() . PHP_EOL;
    echo '[NINFA] Psalm level: ' . $context->psalmLevel() . PHP_EOL;
}

foreach ($configs as $name => $path) {
    echo '[NINFA] Config ' . $name . ': ' . $path . PHP_EOL;
}
