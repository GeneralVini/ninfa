<?php

declare(strict_types=1);

/**
 * Impede regressão básica da documentação interna do código do Ninfa.
 *
 * O teste não avalia qualidade editorial. Ele garante que classes de produção
 * tenham PHPDoc imediatamente associado, que entrypoints/configurações PHP
 * possuam cabeçalho documental, que scripts shell expliquem seu propósito antes
 * da execução e que futuros módulos JavaScript próprios nasçam documentados.
 */

$root = dirname(__DIR__);
$errors = [];

/**
 * Verifica se cada declaração de classe possui T_DOC_COMMENT antes de
 * `class`, admitindo apenas modificadores e whitespace entre ambos.
 */
function assertDocumentedClasses(string $file, array &$errors): void
{
    $source = (string) file_get_contents($file);
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_CLASS) {
            continue;
        }

        $documented = false;
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $previous = $tokens[$cursor];
            if (is_string($previous)) {
                if (trim($previous) === '') {
                    continue;
                }
                break;
            }

            if (in_array($previous[0], [T_WHITESPACE, T_FINAL, T_ABSTRACT], true)) {
                continue;
            }
            if (defined('T_READONLY') && $previous[0] === T_READONLY) {
                continue;
            }
            if ($previous[0] === T_DOC_COMMENT) {
                $documented = true;
            }
            break;
        }

        if (!$documented) {
            $errors[] = str_replace($GLOBALS['root'] . '/', '', $file)
                . ': classe na linha ' . $token[2] . ' sem PHPDoc associado.';
        }
    }
}

/** Exige um docblock de arquivo nas primeiras linhas de um entrypoint PHP. */
function assertPhpHeader(string $file, array &$errors): void
{
    $source = (string) file_get_contents($file);
    $prefix = substr($source, 0, 4096);
    if (!str_contains($prefix, '/**')) {
        $errors[] = str_replace($GLOBALS['root'] . '/', '', $file) . ': cabeçalho PHPDoc ausente.';
    }
}

/**
 * Exige comentário descritivo após o shebang e antes do primeiro comando shell.
 */
function assertShellHeader(string $file, array &$errors): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || !str_starts_with($lines[0] ?? '', '#!')) {
        $errors[] = str_replace($GLOBALS['root'] . '/', '', $file) . ': shebang ausente.';
        return;
    }

    $descriptiveLines = 0;
    for ($index = 1; $index < min(count($lines), 30); $index++) {
        $line = trim($lines[$index]);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#')) {
            if (strlen(ltrim($line, '# ')) >= 12) {
                $descriptiveLines++;
            }
            continue;
        }
        break;
    }

    if ($descriptiveLines < 2) {
        $errors[] = str_replace($GLOBALS['root'] . '/', '', $file)
            . ': comentário inicial deve explicar propósito/contrato antes da execução.';
    }
}

/** @return list<string> */
function filesByExtension(string $directory, array $extensions): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if (in_array(strtolower($item->getExtension()), $extensions, true)) {
            $files[] = $item->getPathname();
        }
    }
    sort($files);
    return $files;
}

foreach (glob($root . '/src/*.php') ?: [] as $file) {
    assertDocumentedClasses($file, $errors);
}

foreach ([
    $root . '/bin/ninfa',
    $root . '/scripts/ninfa-configure.php',
    $root . '/ecs.php',
    $root . '/rector.php',
    $root . '/tests/osv.php',
    $root . '/tests/pipeline-runner.php',
    $root . '/tests/security-inventory.php',
] as $file) {
    assertPhpHeader($file, $errors);
}

foreach ([
    ...(glob($root . '/scripts/*.sh') ?: []),
    ...(glob($root . '/tests/*.sh') ?: []),
] as $file) {
    assertShellHeader($file, $errors);
}

foreach ([$root . '/src', $root . '/scripts', $root . '/bin'] as $directory) {
    foreach (filesByExtension($directory, ['js', 'mjs', 'cjs']) as $file) {
        $prefix = ltrim(substr((string) file_get_contents($file), 0, 4096));
        if (!str_starts_with($prefix, '/**') && !str_starts_with($prefix, '//')) {
            $errors[] = str_replace($root . '/', '', $file) . ': módulo JavaScript sem documentação inicial.';
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "[ERRO] Documentação interna mínima não atendida:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "[OK] Classes, entrypoints e scripts de produção mantêm documentação interna mínima.\n";
