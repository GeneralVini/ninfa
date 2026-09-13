<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ProjectContext.php';
require_once dirname(__DIR__) . '/src/ExternalConfigGenerator.php';
require_once dirname(__DIR__) . '/src/SemanticHints.php';

$args = $argv;
array_shift($args);
$force = in_array('--force', $args, true);
$args = array_values(array_filter($args, static fn (string $arg): bool => $arg !== '--force'));

$projectRoot = $args[0] ?? getcwd();
if (!is_string($projectRoot) || $projectRoot === '') {
    fwrite(STDERR, "[ERRO] Informe a raiz do projeto.\n");
    exit(1);
}

try {
    $context = ProjectContext::fromRoot($projectRoot);
    $configs = (new ExternalConfigGenerator())->generate($context);
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
