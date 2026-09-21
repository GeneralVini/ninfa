<?php

declare(strict_types=1);

/**
 * Protege a premissa de documentação interna do código do Ninfa.
 *
 * Arquivos legados podem permanecer temporariamente em uma allowlist de dívida.
 * Arquivos novos e arquivos removidos dessa lista entram no padrão estrito:
 * classes, métodos/funções nomeadas, arrays compostos e acumuladores locais
 * precisam carregar documentação/tipos úteis; scripts shell precisam comentar
 * os blocos semânticos, não apenas o cabeçalho.
 */

$root = dirname(__DIR__);
$errors = [];

/**
 * Dívida documental preexistente à adoção do padrão estrito.
 *
 * Esta lista deve apenas diminuir. `src/OsvClient.php` já foi migrado e não
 * pode voltar para a allowlist.
 *
 * @var list<string> $legacyPhpDocumentationDebt
 */
$legacyPhpDocumentationDebt = [
    'src/CliStyle.php',
    'src/ComposerAuditParser.php',
    'src/ExternalConfigGenerator.php',
    'src/Finding.php',
    'src/FrontendDetector.php',
    'src/LefthookConfigGenerator.php',
    'src/PipelinePlan.php',
    'src/PipelineRunner.php',
    'src/ProcessRunner.php',
    'src/ProfileDetector.php',
    'src/ProjectContext.php',
    'src/RecheckingPipelineRunner.php',
    'src/RunResult.php',
    'src/ScaFindingDeduplicator.php',
    'src/SecurityInventory.php',
    'src/SecurityReport.php',
    'src/SemanticHints.php',
    'src/ToolResolver.php',
    'src/ToolResult.php',
    'src/Workspace.php',
];

/**
 * Converte um path absoluto em path relativo ao repositório para diagnóstico.
 *
 * @param string $file Path absoluto ou relativo.
 * @return string Path relativo quando o prefixo do repositório estiver presente.
 */
function docsRelativePath(string $file): string
{
    $prefix = $GLOBALS['root'] . '/';
    return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
}

/**
 * Verifica se o PHPDoc possui texto narrativo além de tags.
 *
 * @param string $docBlock Token T_DOC_COMMENT bruto.
 * @return bool True quando há ao menos uma linha descritiva significativa.
 */
function docsHasNarrative(string $docBlock): bool
{
    foreach (preg_split('/\R/', $docBlock) ?: [] as $line) {
        $line = trim($line);
        $line = preg_replace('/^\/\*\*?\s?/', '', $line) ?? $line;
        $line = preg_replace('/^\*\s?/', '', $line) ?? $line;
        $line = preg_replace('/\*\/$/', '', $line) ?? $line;
        $line = trim($line);

        if ($line !== '' && !str_starts_with($line, '@') && strlen($line) >= 12) {
            return true;
        }
    }

    return false;
}

/**
 * Localiza o PHPDoc associado imediatamente a uma classe/método/função.
 *
 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
 * @param int $index Índice do token da declaração.
 * @return string|null Conteúdo do PHPDoc ou null quando não associado.
 */
function docsPreviousPhpDoc(array $tokens, int $index): ?string
{
    $skippable = [T_WHITESPACE, T_FINAL, T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC];
    if (defined('T_READONLY')) {
        $skippable[] = T_READONLY;
    }

    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $previous = $tokens[$cursor];
        if (is_string($previous)) {
            if (trim($previous) === '') {
                continue;
            }
            return null;
        }

        if (in_array($previous[0], $skippable, true)) {
            continue;
        }

        return $previous[0] === T_DOC_COMMENT ? $previous[1] : null;
    }

    return null;
}

/**
 * Mantém o baseline mínimo de PHPDoc nas classes, inclusive arquivos em dívida.
 *
 * @param string $file Arquivo PHP de produção.
 * @param list<string> $errors Acumulador de violações.
 * @return void
 */
function assertDocumentedClasses(string $file, array &$errors): void
{
    $tokens = token_get_all((string) file_get_contents($file));

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_CLASS) {
            continue;
        }

        $docBlock = docsPreviousPhpDoc($tokens, $index);
        if ($docBlock === null) {
            $errors[] = docsRelativePath($file) . ': classe na linha ' . $token[2] . ' sem PHPDoc associado.';
            continue;
        }
        if (!docsHasNarrative($docBlock)) {
            $errors[] = docsRelativePath($file) . ': classe na linha ' . $token[2] . ' sem descrição factual.';
        }
    }
}

/**
 * Extrai nome e assinatura de método/função; closures retornam null.
 *
 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
 * @param int $functionIndex Índice de T_FUNCTION.
 * @return array{name:string,signature:string}|null Metadados do símbolo nomeado.
 */
function docsNamedCallable(array $tokens, int $functionIndex): ?array
{
    $name = null;
    $signature = '';

    for ($cursor = $functionIndex; $cursor < count($tokens); $cursor++) {
        $current = $tokens[$cursor];
        $text = is_array($current) ? $current[1] : $current;
        $signature .= $text;

        if ($cursor > $functionIndex && $name === null && is_array($current) && $current[0] === T_STRING) {
            $name = $current[1];
        }
        if ($name === null && $text === '(') {
            return null;
        }
        if ($text === '{' || $text === ';') {
            break;
        }
    }

    return $name === null ? null : ['name' => $name, 'signature' => $signature];
}

/**
 * Exige PHPDoc descritivo e tipos genéricos/shapes em callables do padrão estrito.
 *
 * @param string $file Arquivo PHP já migrado ou novo.
 * @param list<string> $errors Acumulador de violações.
 * @return void
 */
function assertStrictNamedCallables(string $file, array &$errors): void
{
    $tokens = token_get_all((string) file_get_contents($file));

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $metadata = docsNamedCallable($tokens, $index);
        if ($metadata === null) {
            continue;
        }

        $label = docsRelativePath($file) . '::' . $metadata['name'] . '() linha ' . $token[2];
        $docBlock = docsPreviousPhpDoc($tokens, $index);
        if ($docBlock === null) {
            $errors[] = $label . ': função/método sem PHPDoc associado.';
            continue;
        }
        if (!docsHasNarrative($docBlock)) {
            $errors[] = $label . ': PHPDoc deve explicar responsabilidade/contrato, não apenas tags.';
        }

        $signature = $metadata['signature'];
        $hasArrayParameter = preg_match('/(?:\?|\b)array\s+\$[A-Za-z_][A-Za-z0-9_]*/', $signature) === 1;
        $hasArrayReturn = preg_match('/:\s*\??array\b/', $signature) === 1;

        if ($hasArrayParameter && preg_match('/@param\s+(?:array|list)(?:<|\{)/', $docBlock) !== 1) {
            $errors[] = $label . ': parâmetro array deve preservar tipo genérico/shape em @param.';
        }
        if ($hasArrayReturn && preg_match('/@return\s+(?:array|list)(?:<|\{)/', $docBlock) !== 1) {
            $errors[] = $label . ': retorno array deve preservar tipo genérico/shape em @return.';
        }
    }
}

/**
 * Exige `@var` próximo para acumuladores locais iniciados como array vazio.
 *
 * A busca aceita docblocks multilinha para que shapes extensos continuem legíveis.
 *
 * @param string $file Arquivo PHP sujeito ao padrão estrito.
 * @param list<string> $errors Acumulador de violações.
 * @return void
 */
function assertTypedEmptyArrayAccumulators(string $file, array &$errors): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $errors[] = docsRelativePath($file) . ': não foi possível ler o arquivo.';
        return;
    }

    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\[\];/', $line, $matches) !== 1) {
            continue;
        }

        $variable = $matches[1];
        $start = max(0, $index - 18);
        $prefix = implode("\n", array_slice($lines, $start, $index - $start));
        if (preg_match('/@var[\s\S]{0,900}\$' . preg_quote($variable, '/') . '\b/', $prefix) === 1) {
            continue;
        }

        $errors[] = docsRelativePath($file) . ': acumulador $' . $variable
            . ' na linha ' . ($index + 1) . ' sem @var de tipo/shape próximo.';
    }
}

/**
 * Exige docblock nas primeiras linhas de entrypoint/configuração PHP.
 *
 * @param string $file Arquivo PHP executável/configuração.
 * @param list<string> $errors Acumulador de violações.
 * @return void
 */
function assertPhpHeader(string $file, array &$errors): void
{
    $prefix = substr((string) file_get_contents($file), 0, 4096);
    if (!str_contains($prefix, '/**')) {
        $errors[] = docsRelativePath($file) . ': cabeçalho PHPDoc ausente.';
    }
}

/**
 * Exige comentário descritivo entre shebang e primeiro comando shell.
 *
 * @param string $file Script shell.
 * @param list<string> $errors Acumulador de violações.
 * @return void
 */
function assertShellHeader(string $file, array &$errors): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || !str_starts_with($lines[0] ?? '', '#!')) {
        $errors[] = docsRelativePath($file) . ': shebang ausente.';
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
        $errors[] = docsRelativePath($file) . ': cabeçalho shell deve explicar propósito/contrato.';
    }
}

/**
 * Exige comentário local antes de blocos semânticos em shell de produção.
 *
 * @param string $file Script shell de produção.
 * @param list<string> $errors Acumulador de violações.
 * @return void
 */
function assertShellSemanticBlocks(string $file, array &$errors): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $errors[] = docsRelativePath($file) . ': não foi possível ler o script shell.';
        return;
    }

    foreach ($lines as $index => $line) {
        $trimmed = ltrim($line);
        $isBlock = preg_match('/^(if\b|case\b|for\b|while\b|until\b)/', $trimmed) === 1;
        $isFunction = preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*\(\)\s*\{/', $trimmed) === 1;
        if (!$isBlock && !$isFunction) {
            continue;
        }

        $cursor = $index - 1;
        while ($cursor >= 0 && trim($lines[$cursor]) === '') {
            $cursor--;
        }

        if ($cursor < 0 || !str_starts_with(ltrim($lines[$cursor]), '#')) {
            $errors[] = docsRelativePath($file) . ': bloco shell na linha '
                . ($index + 1) . ' sem comentário local de intenção/invariante.';
        }
    }
}

/**
 * Lista arquivos recursivamente pelas extensões informadas.
 *
 * @param string $directory Diretório base.
 * @param list<string> $extensions Extensões sem ponto.
 * @return list<string> Paths absolutos ordenados.
 */
function filesByExtension(string $directory, array $extensions): array
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
        if ($item->isFile() && in_array(strtolower($item->getExtension()), $extensions, true)) {
            $files[] = $item->getPathname();
        }
    }
    sort($files);
    return $files;
}

// Baseline para todo src; padrão estrito para qualquer arquivo fora da dívida.
foreach (glob($root . '/src/*.php') ?: [] as $file) {
    assertDocumentedClasses($file, $errors);
    if (!in_array(docsRelativePath($file), $legacyPhpDocumentationDebt, true)) {
        assertStrictNamedCallables($file, $errors);
        assertTypedEmptyArrayAccumulators($file, $errors);
    }
}

// A allowlist não pode carregar path removido/renomeado silenciosamente.
foreach ($legacyPhpDocumentationDebt as $relative) {
    if (!is_file($root . '/' . $relative)) {
        $errors[] = $relative . ': entrada obsoleta na allowlist de dívida documental.';
    }
}

// Entry points/configurações exigem contexto de arquivo.
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

// O configurador já está no padrão estrito.
assertStrictNamedCallables($root . '/scripts/ninfa-configure.php', $errors);
assertTypedEmptyArrayAccumulators($root . '/scripts/ninfa-configure.php', $errors);

// Shell de produção exige documentação de arquivo e de blocos internos.
foreach (glob($root . '/scripts/*.sh') ?: [] as $file) {
    assertShellHeader($file, $errors);
    assertShellSemanticBlocks($file, $errors);
}

// Fixtures shell permanecem no baseline de cabeçalho.
foreach (glob($root . '/tests/*.sh') ?: [] as $file) {
    assertShellHeader($file, $errors);
}

// Não há JS próprio hoje; arquivo futuro precisa ao menos nascer documentado.
foreach ([$root . '/src', $root . '/scripts', $root . '/bin'] as $directory) {
    foreach (filesByExtension($directory, ['js', 'mjs', 'cjs']) as $file) {
        $prefix = ltrim(substr((string) file_get_contents($file), 0, 4096));
        if (!str_starts_with($prefix, '/**') && !str_starts_with($prefix, '//')) {
            $errors[] = docsRelativePath($file) . ': módulo JavaScript sem documentação inicial.';
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "[ERRO] Premissa de documentação interna não atendida:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo '[OK] Documentação estrita protegida; dívida legada explícita: '
    . count($legacyPhpDocumentationDebt) . " arquivo(s).\n";
