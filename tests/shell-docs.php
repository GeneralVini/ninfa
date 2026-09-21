<?php

declare(strict_types=1);

/**
 * Valida documentação estruturada em todos os scripts shell versionados.
 *
 * O contrato cobre produção e fixtures: cabeçalho factual, declaração semântica
 * de variáveis globais relevantes (`# @var`), contrato de funções nomeadas
 * (`# @function`) e comentário de intenção antes de blocos de controle.
 *
 * O teste é recursivo em `scripts/` e `tests/`. Portanto qualquer futuro
 * `scripts/bootstrap.sh` ou outro `.sh` entra automaticamente no mesmo padrão.
 */

/** @var string $root Raiz física do repositório Ninfa. */
$root = dirname(__DIR__);
/** @var list<string> $errors Violações documentais encontradas. */
$errors = [];

/**
 * Converte um path absoluto em path relativo à raiz do repositório.
 *
 * @param string $root Raiz física do repositório.
 * @param string $file Arquivo shell absoluto.
 * @return string Path relativo usado nas mensagens de erro.
 */
function shellDocsRelative(string $root, string $file): string
{
    $prefix = rtrim($root, '/') . '/';
    return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
}

/**
 * Lista recursivamente scripts `.sh` existentes no diretório informado.
 *
 * @param string $directory Diretório de busca.
 * @return list<string> Arquivos shell ordenados por path.
 */
function shellDocsFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    /** @var list<string> $files */
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $item) {
        if ($item->isFile() && strtolower($item->getExtension()) === 'sh') {
            $files[] = $item->getPathname();
        }
    }

    sort($files);
    return $files;
}

/**
 * Retorna a linha não vazia imediatamente anterior a um índice.
 *
 * @param list<string> $lines Conteúdo do script sem terminadores de linha.
 * @param int $index Índice zero-based da linha atual.
 * @return string|null Linha anterior ou null quando não existe.
 */
function shellDocsPreviousNonBlank(array $lines, int $index): ?string
{
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        if (trim($lines[$cursor]) !== '') {
            return trim($lines[$cursor]);
        }
    }

    return null;
}

/**
 * Valida um único script shell contra a premissa documental do projeto.
 *
 * Variáveis em uppercase atribuídas no script precisam de declaração `# @var`
 * em algum ponto anterior ou próximo ao bloco de configuração. Funções nomeadas
 * precisam de `# @function nome`. Blocos condicionais/iterativos precisam de um
 * comentário local que explique intenção ou invariante.
 *
 * @param string $root Raiz do repositório para diagnóstico relativo.
 * @param string $file Script shell a validar.
 * @param list<string> $errors Acumulador de violações.
 * @return void
 */
function assertShellDocumentation(string $root, string $file, array &$errors): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $relative = shellDocsRelative($root, $file);

    if (!is_array($lines) || !str_starts_with($lines[0] ?? '', '#!')) {
        $errors[] = $relative . ': shebang ausente.';
        return;
    }

    // O cabeçalho precisa explicar mais do que o nome do script antes da execução.
    $headerDescriptions = 0;
    for ($index = 1; $index < min(count($lines), 40); $index++) {
        $line = trim($lines[$index]);
        if ($line === '') {
            continue;
        }
        if (!str_starts_with($line, '#')) {
            break;
        }
        if (strlen(ltrim($line, '# ')) >= 12) {
            $headerDescriptions++;
        }
    }
    if ($headerDescriptions < 3) {
        $errors[] = $relative . ': cabeçalho deve explicar propósito, entradas/efeitos e falhas relevantes.';
    }

    /** @var array<string,true> $documentedVariables Variáveis declaradas por `# @var`. */
    $documentedVariables = [];
    /** @var array<string,true> $documentedFunctions Funções declaradas por `# @function`. */
    $documentedFunctions = [];

    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*#\s*@var\s+([A-Z][A-Z0-9_]*)\s+([^\s]+)\s+.+$/', $line, $matches) === 1) {
            $documentedVariables[$matches[1]] = true;
        }
        if (preg_match('/^\s*#\s*@function\s+([A-Za-z_][A-Za-z0-9_]*)\s+.+$/', $line, $matches) === 1) {
            $documentedFunctions[$matches[1]] = true;
        }

        // Variável de script em uppercase representa configuração/estado compartilhado;
        // exige tipo semântico e descrição, mesmo quando seu valor é uma string shell.
        if (preg_match('/^\s*([A-Z][A-Z0-9_]*)=/', $line, $matches) === 1) {
            $name = $matches[1];
            if (!isset($documentedVariables[$name])) {
                $errors[] = $relative . ': variável ' . $name . ' na linha ' . ($index + 1)
                    . ' sem `# @var ' . $name . ' tipo — descrição` anterior.';
            }
        }

        if (preg_match('/^\s*(?:function\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*\(\)\s*\{/', $line, $matches) === 1) {
            $name = $matches[1];
            if (!isset($documentedFunctions[$name])) {
                $errors[] = $relative . ': função ' . $name . '() na linha ' . ($index + 1)
                    . ' sem `# @function ' . $name . ' — contrato`.';
            }
            continue;
        }

        // Decisões e loops precisam explicar intenção/invariante local; um @var
        // imediatamente anterior não conta como justificativa do bloco.
        if (preg_match('/^\s*(if\b|case\b|for\b|while\b|until\b)/', $line) === 1) {
            $previous = shellDocsPreviousNonBlank($lines, $index);
            if ($previous === null
                || !str_starts_with($previous, '#')
                || str_starts_with($previous, '# @var')
                || str_starts_with($previous, '# @function')) {
                $errors[] = $relative . ': bloco semântico na linha ' . ($index + 1)
                    . ' sem comentário local de intenção/invariante.';
            }
        }
    }
}

/** @var list<string> $shellFiles Scripts shell cobertos por produção e fixtures. */
$shellFiles = [
    ...shellDocsFiles($root . '/scripts'),
    ...shellDocsFiles($root . '/tests'),
];

foreach ($shellFiles as $file) {
    assertShellDocumentation($root, $file, $errors);
}

if ($errors !== []) {
    fwrite(STDERR, "[ERRO] Documentação estruturada de scripts shell não atendida:\n- "
        . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo '[OK] Todos os scripts shell versionados preservam cabeçalho, @var/@function e documentação de blocos.' . PHP_EOL;
