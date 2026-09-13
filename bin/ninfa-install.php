<?php

declare(strict_types=1);

$args = $argv;
array_shift($args);
$force = in_array('--force', $args, true);
$args = array_values(array_filter($args, static fn (string $arg): bool => $arg !== '--force'));

$ninfaRoot = dirname(__DIR__);
$projectRoot = $args[0] ?? getcwd();
$projectRoot = is_string($projectRoot) ? (realpath($projectRoot) ?: $projectRoot) : '';

if ($projectRoot === '' || !is_dir($projectRoot) || !is_file($projectRoot . '/composer.json')) {
    fwrite(STDERR, "[ERRO] Informe a raiz de um projeto PHP com composer.json.\n");
    exit(1);
}

$configure = $ninfaRoot . '/scripts/ninfa-configure.php';
if (!is_file($configure)) {
    fwrite(STDERR, "[ERRO] Configurador do Ninfa nao encontrado.\n");
    exit(1);
}

$command = 'php ' . escapeshellarg($configure) . ' ' . escapeshellarg($projectRoot);
if ($force) {
    $command .= ' --force';
}

passthru($command, $status);
if ($status !== 0) {
    exit($status);
}

echo PHP_EOL;
echo "[NINFA] Projeto configurado externamente. Nenhum arquivo do Ninfa foi copiado para o consumidor.\n";
echo "[NINFA] Use o root do projeto nas operacoes check, fix e security.\n";
