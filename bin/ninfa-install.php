<?php

declare(strict_types=1);

$ninfaRoot = dirname(__DIR__);
$projectRoot = $argv[1] ?? getcwd();
$projectRoot = realpath($projectRoot) ?: $projectRoot;

if (!is_file($projectRoot . '/composer.json')) {
    fwrite(STDERR, "[ERRO] composer.json não encontrado em {$projectRoot}\n");
    exit(1);
}

$files = [
    'scripts/bootstrap.sh',
    'scripts/install-security-tools.sh',
    'scripts/semgrep-scan.sh',
    'scripts/zap-scan.sh',
    'security/semgrep.yml',
    'ecs.php',
    'rector.php',
    'phpstan.neon.dist',
    'psalm.xml',
    'phpunit.xml.dist',
    'lefthook.yml',
    'Makefile',
];

foreach ($files as $relative) {
    $source = $ninfaRoot . '/' . $relative;
    $target = $projectRoot . '/' . $relative;

    if (is_file($target)) {
        echo "[MANTIDO] {$relative}\n";
        continue;
    }

    $directory = dirname($target);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    copy($source, $target);
    echo "[CRIADO] {$relative}\n";
}

echo "\n[NINFA] Arquivos básicos integrados sem sobrescrever configurações existentes.\n";
echo "Consulte docs/INTEGRACAO.md para concluir a implantação.\n";
