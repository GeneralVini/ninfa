<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Uso: php merge-composer.php <composer.json> <fragmento.json> [--force]\n");
    exit(2);
}

$composerFile = $argv[1];
$fragmentFile = $argv[2];
$force = in_array('--force', $argv, true);

$decode = static function (string $file): array {
    if (!is_file($file)) {
        throw new RuntimeException("Arquivo não encontrado: {$file}");
    }

    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("JSON inválido: {$file}");
    }

    return $data;
};

try {
    $composer = $decode($composerFile);
    $fragment = $decode($fragmentFile);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . "\n");
    exit(1);
}

$composer['scripts'] ??= [];
$fragment['scripts'] ??= [];

$added = [];
$kept = [];
$replaced = [];

foreach ($fragment['scripts'] as $name => $value) {
    if (!array_key_exists($name, $composer['scripts'])) {
        $composer['scripts'][$name] = $value;
        $added[] = $name;
        continue;
    }

    if ($composer['scripts'][$name] === $value) {
        $kept[] = $name;
        continue;
    }

    if ($force) {
        $composer['scripts'][$name] = $value;
        $replaced[] = $name;
    } else {
        $kept[] = $name;
        fwrite(STDERR, "[AVISO] Script Composer '{$name}' já existe e foi preservado. Revise a integração.\n");
    }
}

$json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
file_put_contents($composerFile, $json);

printf("[NINFA] composer.json atualizado. adicionados=%d preservados=%d substituídos=%d\n", count($added), count($kept), count($replaced));
