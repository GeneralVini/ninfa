<?php

declare(strict_types=1);

$ninfaRoot = dirname(__DIR__);
$projectRoot = $argv[1] ?? getcwd();
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
    'ecs.php' => 'ecs.php',
    'rector.php' => 'rector.php',
    'phpstan.neon.dist' => 'phpstan.neon.dist',
    'psalm.xml' => 'psalm.xml',
    'phpunit.xml.dist' => 'phpunit.xml.dist',
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
        } else {
            echo "[MANTIDO] {$targetRelative} já existe.\n";
            $conflicts[] = $targetRelative;
        }
        continue;
    }

    $directory = dirname($target);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    copy($source, $target);
    echo "[CRIADO] {$targetRelative}\n";
}

passthru('php ' . escapeshellarg($projectRoot . '/scripts/ninfa-configure.php') . ' ' . escapeshellarg($projectRoot), $status);
if ($status !== 0) {
    exit($status);
}

echo "\n[NINFA] Estrutura copiada sem sobrescrever arquivos do projeto.\n";
if ($conflicts !== []) {
    echo "[NINFA] Revise apenas os arquivos marcados como MANTIDO ou divergências reportadas.\n";
}

echo "\nPróximos passos:\n";
echo "  1. Instalar as dependências PHP indicadas em docs/INTEGRACAO.md\n";
echo "  2. Mesclar os scripts de composer.ninfa.example.json no composer.json\n";
echo "  3. Executar make install\n";
echo "  4. Executar make setup\n";
echo "  5. Executar composer check\n";
