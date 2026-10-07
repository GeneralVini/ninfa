<?php

declare(strict_types=1);

/**
 * Enforces the English engineering-language policy on newly added repository text.
 *
 * The repository still contains legacy Portuguese engineering prose, so this guard is
 * intentionally incremental. Canonical engineering Markdown is checked line-by-line;
 * source files are checked only when an added line is a documentation/comment line.
 */

/**
 * Executes a process without invoking a shell.
 *
 * @param list<string> $command Executable followed by its arguments.
 * @return array{status:int,stdout:string,stderr:string} Captured process result.
 */
function languageRun(array $command): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start process: ' . implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'status' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/**
 * Determines whether a Git commit-like reference is available locally.
 */
function languageCommitExists(string $reference): bool
{
    if ($reference === '' || preg_match('/^0+$/', $reference) === 1) {
        return false;
    }

    $result = languageRun(['git', 'rev-parse', '--verify', $reference . '^{commit}']);
    return $result['status'] === 0;
}

/**
 * Resolves the diff that should be checked.
 *
 * CI supplies NINFA_LANGUAGE_BASE. Local runs inspect all working-tree changes
 * relative to HEAD and fall back to the latest commit when the tree is clean.
 */
function languageDiff(?string $base): string
{
    if ($base !== null && languageCommitExists($base)) {
        $result = languageRun(['git', 'diff', '--unified=0', '--no-ext-diff', $base, 'HEAD']);
        if ($result['status'] !== 0) {
            throw new RuntimeException('Unable to read Git diff: ' . trim($result['stderr']));
        }
        return $result['stdout'];
    }

    $workingTree = languageRun(['git', 'diff', 'HEAD', '--quiet']);
    if ($workingTree['status'] === 1) {
        $result = languageRun(['git', 'diff', 'HEAD', '--unified=0', '--no-ext-diff']);
        if ($result['status'] !== 0) {
            throw new RuntimeException('Unable to read working-tree Git diff: ' . trim($result['stderr']));
        }
        return $result['stdout'];
    }

    if (languageCommitExists('HEAD^')) {
        $result = languageRun(['git', 'diff', '--unified=0', '--no-ext-diff', 'HEAD^', 'HEAD']);
        if ($result['status'] !== 0) {
            throw new RuntimeException('Unable to read latest-commit diff: ' . trim($result['stderr']));
        }
        return $result['stdout'];
    }

    $result = languageRun(['git', 'show', '--format=', '--unified=0', '--no-ext-diff', 'HEAD']);
    if ($result['status'] !== 0) {
        throw new RuntimeException('Unable to inspect initial commit: ' . trim($result['stderr']));
    }
    return $result['stdout'];
}

/**
 * Returns true for Markdown whose canonical engineering language is English.
 */
function languageIsCanonicalEngineeringDoc(string $path): bool
{
    return preg_match(
        '#^(?:docs/(?:development|adr)/|CONTRIBUTING\.md$|\.github/pull_request_template\.md$)#',
        $path,
    ) === 1;
}

/**
 * Returns true for source/configuration formats where comments carry engineering prose.
 */
function languageIsSourceFile(string $path): bool
{
    if ($path === 'bin/ninfa') {
        return true;
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array(
        $extension,
        ['php', 'go', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'sh', 'bash', 'yml', 'yaml', 'neon'],
        true,
    );
}

/**
 * Detects documentation/comment lines without attempting to parse every source grammar.
 */
function languageIsCommentLine(string $line): bool
{
    $trimmed = ltrim($line);
    return preg_match('#^(?://|\#|/\*|\*|\*/)#', $trimmed) === 1;
}

/**
 * Extracts newly added lines and their target line numbers from a zero-context Git diff.
 *
 * @return list<array{path:string,line:int,text:string}> Added lines.
 */
function languageAddedLines(string $diff): array
{
    $path = null;
    $lineNumber = 0;
    $added = [];

    foreach (preg_split('/\R/', $diff) ?: [] as $line) {
        if (str_starts_with($line, '+++ b/')) {
            $path = substr($line, 6);
            continue;
        }

        if (str_starts_with($line, '@@')) {
            if (preg_match('/\+(\d+)(?:,\d+)?/', $line, $matches) === 1) {
                $lineNumber = ((int) $matches[1]) - 1;
            }
            continue;
        }

        if ($path === null || str_starts_with($line, '---') || str_starts_with($line, 'diff --git')) {
            continue;
        }

        if (str_starts_with($line, '-')) {
            continue;
        }

        if (str_starts_with($line, '+')) {
            $lineNumber++;
            $added[] = [
                'path' => $path,
                'line' => $lineNumber,
                'text' => substr($line, 1),
            ];
            continue;
        }

        if (str_starts_with($line, ' ')) {
            $lineNumber++;
        }
    }

    return $added;
}

$root = dirname(__DIR__);
if (!chdir($root)) {
    fwrite(STDERR, "[ERROR] Unable to enter repository root.\n");
    exit(2);
}

$base = getenv('NINFA_LANGUAGE_BASE');
$base = is_string($base) && $base !== '' ? $base : null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--base=')) {
        $base = substr($argument, strlen('--base='));
    }
}

$portugueseVocabulary = '/\b(?:'
    . 'arquivo|arquivos|usu[aá]rio|usu[aá]rios|verifica|verificar|retorna|retorno|regra|regras|'
    . 'classe|m[eé]todo|m[eé]todos|fun[cç][aã]o|fun[cç][oõ]es|par[aâ]metro|par[aâ]metros|'
    . 'linha|linhas|caminho|caminhos|diret[oó]rio|diret[oó]rios|somente|apenas|quando|'
    . 'permite|impede|executa|execu[cç][aã]o|falha|falhas|erro|erros|seguran[cç]a|'
    . 'coment[aá]rio|coment[aá]rios|documenta[cç][aã]o|descri[cç][aã]o|raiz|sa[ií]da|entrada|'
    . 'acumulador|resultado|resultados|achado|achados|evid[eê]ncia|corre[cç][aã]o|projeto|'
    . 'ferramenta|ferramentas|inv[aá]lido|inv[aá]lida|ausente|aus[eê]ncia|devolve|gera|gerado|'
    . 'gerada|carrega|carregado|carregada|n[aã]o'
    . ')\b/iu';

try {
    $addedLines = languageAddedLines(languageDiff($base));
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] Engineering-language check failed: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

$violations = [];
foreach ($addedLines as $addedLine) {
    $path = $addedLine['path'];
    $text = $addedLine['text'];

    $shouldCheck = languageIsCanonicalEngineeringDoc($path)
        || (languageIsSourceFile($path) && languageIsCommentLine($text));

    if (!$shouldCheck || trim($text) === '') {
        continue;
    }

    if (preg_match($portugueseVocabulary, $text) === 1) {
        $violations[] = $path . ':' . $addedLine['line'] . ': ' . trim($text);
    }
}

if ($violations !== []) {
    fwrite(
        STDERR,
        "[ERROR] New engineering documentation must be written in English:\n- "
        . implode("\n- ", $violations)
        . "\n",
    );
    exit(1);
}

echo '[OK] Newly added engineering documentation follows the English language policy.' . PHP_EOL;
