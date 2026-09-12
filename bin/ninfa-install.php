<?php

declare(strict_types=1);

$args = $argv;
array_shift($args);
$force = in_array('--force', $args, true);
$args = array_values(array_filter($args, static fn (string $arg): bool => $arg !== '--force'));

$ninfaRoot = dirname(__DIR__);
$projectRoot = $args[0] ?? getcwd();
$projectRoot = realpath($projectRoot) ?: $projectRoot;

if (!is_dir($projectRoot) || !is_file($projectRoot . '/composer.json')) {
    fwrite(STDERR, "[ERRO] Informe a raiz de um projeto PHP com composer.json.\n");
    exit(1);
}

$files = [
    'scripts/bootstrap.sh' => 'scripts/bootstrap.sh',
    'scripts/install-security-tools.sh' => 'scripts/install-security-tools.sh',
    'scripts/ninfa-configure.php' => 'scripts/ninfa-configure.php',
    'scripts/semgrep-scan.sh' => 'scripts/semgrep-scan.sh',
    'scripts/zap-scan.sh' => 'scripts/zap-scan.sh',
    'scripts/merge-composer.php' => 'scripts/merge-composer.php',
    'security/semgrep.yml' => 'security/semgrep.yml',
    'lefthook.yml' => 'lefthook.yml',
    'Makefile' => 'Makefile',
    'composer.ninfa.example.json' => 'composer.ninfa.example.json',
    'templates/github-actions/qa-security.yml' => '.github/workflows/ninfa.yml',
    'templates/docs/NINFA.md' => 'docs/NINFA.md',
    'templates/README-NINFA.md' => 'docs/README-NINFA.md',
];

$conflicts = [];
foreach ($files as $sourceRelative => $targetRelative) {
    $source = $ninfaRoot . '/' . $sourceRelative;
    $target = $projectRoot . '/' . $targetRelative;

    if (is_file($target)) {
        if (hash_file('sha256', $source) === hash_file('sha256', $target)) {
            echo "[OK] {$targetRelative}\n";
            continue;
        }

        if (!$force) {
            echo "[MANTIDO] {$targetRelative} já existe.\n";
            $conflicts[] = $targetRelative;
            continue;
        }
    }

    $directory = dirname($target);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $existed = is_file($target);
    copy($source, $target);
    echo ($existed ? '[SOBRESCRITO] ' : '[CRIADO] ') . $targetRelative . PHP_EOL;
}

$gitignorePath = $projectRoot . '/.gitignore';
$gitignore = is_file($gitignorePath) ? (string) file_get_contents($gitignorePath) : '';
foreach (['/.tools/', '/.ninfa/'] as $entry) {
    if (!preg_match('/^' . preg_quote($entry, '/') . '$/m', $gitignore)) {
        $gitignore = rtrim($gitignore) . ($gitignore === '' ? '' : PHP_EOL) . $entry . PHP_EOL;
        echo "[GITIGNORE] {$entry}\n";
    }
}
file_put_contents($gitignorePath, $gitignore);

$configureCommand = 'php ' . escapeshellarg($projectRoot . '/scripts/ninfa-configure.php') . ' ' . escapeshellarg($projectRoot);
if ($force) {
    $configureCommand .= ' --force';
}
passthru($configureCommand, $status);
if ($status !== 0) {
    exit($status);
}

echo "\n[NINFA] Estrutura copiada e configurações geradas conforme o projeto.\n";
if ($force) {
    echo "[NINFA] --force ativo: arquivos gerenciados pelo Ninfa foram sobrescritos.\n";
} elseif ($conflicts !== []) {
    echo "[NINFA] Revise apenas os arquivos marcados como MANTIDO ou divergências reportadas.\n";
}

echo "\nPróximos passos:\n";
echo "  1. Instalar as dependências PHP indicadas em docs/INTEGRACAO.md\n";
echo "  2. Mesclar os scripts de composer.ninfa.example.json no composer.json\n";
echo "  3. Executar make install\n";
echo "  4. Executar make setup\n";
echo "  5. Executar composer check\n";
