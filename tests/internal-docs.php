<?php

declare(strict_types=1);

/**
 * Protege a premissa de documentação interna do código de produção do Ninfa.
 *
 * Não existe mais allowlist de dívida documental para `src/`: toda classe e
 * todo método/função nomeada precisam de PHPDoc narrativo; arrays em contratos
 * precisam preservar genéricos/shapes; acumuladores vazios precisam de `@var`.
 * Arquivos com fluxo de controle relevante também precisam conter comentários
 * de intenção/invariante além dos PHPDocs de símbolo.
 *
 * Shell possui guard específico em `tests/shell-docs.php`. JavaScript próprio,
 * quando surgir, entra automaticamente no requisito mínimo de JSDoc.
 */

/** @var string $root Raiz física do repositório Ninfa. */
$root = dirname(__DIR__);
/** @var list<string> $errors Violações documentais encontradas durante o guard. */
$errors = [];

/**
 * Converte um path absoluto em path relativo ao repositório para diagnóstico.
 *
 * @param string $root Raiz física do repositório.
 * @param string $file Path absoluto ou já relativo.
 * @return string Path relativo quando o prefixo do repositório estiver presente.
 */
function docsRelativePath(string $root, string $file): string
{
    $prefix = rtrim($root, '/') . '/';
    return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
}

/**
 * Verifica se o PHPDoc possui explicação narrativa além de tags de tipo.
 *
 * @param string $docBlock Token T_DOC_COMMENT bruto.
 * @return bool True quando existe ao menos uma linha descritiva significativa.
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
 * Localiza o PHPDoc imediatamente associado a uma declaração PHP tokenizada.
 *
 * Modificadores de visibilidade/static/final/readonly podem existir entre o
 * docblock e a declaração; qualquer outro token significativo interrompe a
 * associação para não reutilizar comentário pertencente ao símbolo anterior.
 *
 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens de `token_get_all()`.
 * @param int $index Índice do token da declaração.
 * @return string|null PHPDoc associado ou null.
 */
function docsPreviousPhpDoc(array $tokens, int $index): ?string
{
    /** @var list<int> $skippable Tokens permitidos entre PHPDoc e declaração. */
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
 * Extrai nome e assinatura textual de método/função; closures retornam null.
 *
 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens PHP.
 * @param int $functionIndex Índice do token T_FUNCTION.
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
 * Exige PHPDoc narrativo em todas as classes declaradas pelo arquivo.
 *
 * @param string $root Raiz usada para mensagens relativas.
 * @param string $file Arquivo PHP de produção.
 * @param list<string> $errors Acumulador mutável de violações.
 */
function assertDocumentedClasses(string $root, string $file, array &$errors): void
{
    $tokens = token_get_all((string) file_get_contents($file));

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_CLASS) {
            continue;
        }

        $docBlock = docsPreviousPhpDoc($tokens, $index);
        $label = docsRelativePath($root, $file) . ': classe na linha ' . $token[2];
        if ($docBlock === null) {
            $errors[] = $label . ' sem PHPDoc associado.';
            continue;
        }
        if (!docsHasNarrative($docBlock)) {
            $errors[] = $label . ' possui PHPDoc sem descrição factual.';
        }
    }
}

/**
 * Exige PHPDoc descritivo em todos os métodos/funções nomeados de produção.
 *
 * Quando a assinatura usa `array`, o docblock precisa preservar genérico/shape
 * por `@param` e/ou `@return`; apenas repetir `array` não atende ao contrato.
 *
 * @param string $root Raiz usada para mensagens relativas.
 * @param string $file Arquivo PHP submetido ao padrão estrito.
 * @param list<string> $errors Acumulador mutável de violações.
 */
function assertStrictNamedCallables(string $root, string $file, array &$errors): void
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

        $label = docsRelativePath($root, $file) . '::' . $metadata['name'] . '() linha ' . $token[2];
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
 * Exige `@var` próximo de acumuladores locais iniciados como array vazio.
 *
 * A regra cobre o padrão `$nome = [];`, comum em mapas/listas acumuladas. Shapes
 * extensos podem usar docblock multilinha nas linhas imediatamente anteriores.
 *
 * @param string $root Raiz usada para mensagens relativas.
 * @param string $file Arquivo PHP submetido ao padrão estrito.
 * @param list<string> $errors Acumulador mutável de violações.
 */
function assertTypedEmptyArrayAccumulators(string $root, string $file, array &$errors): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $errors[] = docsRelativePath($root, $file) . ': não foi possível ler o arquivo.';
        return;
    }

    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\[\];/', $line, $matches) !== 1) {
            continue;
        }

        $variable = $matches[1];
        $start = max(0, $index - 20);
        $prefix = implode("\n", array_slice($lines, $start, $index - $start));
        if (preg_match('/@var[\s\S]{0,1200}\$' . preg_quote($variable, '/') . '\b/', $prefix) === 1) {
            continue;
        }

        $errors[] = docsRelativePath($root, $file) . ': acumulador $' . $variable
            . ' na linha ' . ($index + 1) . ' sem @var de tipo/shape próximo.';
    }
}

/**
 * Exige evidência de documentação interna quando o arquivo contém fluxo complexo.
 *
 * Não tenta provar qualidade semântica do comentário. O objetivo é impedir que
 * arquivos com vários `if/foreach/try/match` tenham apenas PHPDoc de cabeçalho e
 * nenhum comentário local sobre decisões, precedências ou invariantes.
 *
 * @param string $root Raiz usada para mensagens relativas.
 * @param string $file Arquivo PHP de produção.
 * @param list<string> $errors Acumulador mutável de violações.
 */
function assertSemanticBlockComments(string $root, string $file, array &$errors): void
{
    $tokens = token_get_all((string) file_get_contents($file));
    $controlCount = 0;
    $localNarrativeComments = 0;
    /** @var list<int> $controlTokens Tokens que indicam decisões/iterações relevantes. */
    $controlTokens = [T_IF, T_FOREACH, T_FOR, T_WHILE, T_TRY, T_SWITCH];
    if (defined('T_MATCH')) {
        $controlTokens[] = T_MATCH;
    }

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        if (in_array($token[0], $controlTokens, true)) {
            $controlCount++;
        }
        if ($token[0] === T_COMMENT) {
            $text = trim(preg_replace('/^\/\/|^#/', '', trim($token[1])) ?? '');
            if (strlen($text) >= 20) {
                $localNarrativeComments++;
            }
        }
    }

    if ($controlCount >= 3 && $localNarrativeComments === 0) {
        $errors[] = docsRelativePath($root, $file)
            . ': possui fluxo de controle relevante sem comentário local de decisão/invariante.';
    }
}

/**
 * Exige um docblock de arquivo nas primeiras linhas de entrypoint/config PHP.
 *
 * @param string $root Raiz usada para mensagens relativas.
 * @param string $file Arquivo PHP executável/configuração.
 * @param list<string> $errors Acumulador mutável de violações.
 */
function assertPhpHeader(string $root, string $file, array &$errors): void
{
    $prefix = substr((string) file_get_contents($file), 0, 4096);
    if (!str_contains($prefix, '/**')) {
        $errors[] = docsRelativePath($root, $file) . ': cabeçalho PHPDoc ausente.';
    }
}

/**
 * Lista arquivos recursivamente pelas extensões informadas.
 *
 * @param string $directory Diretório base.
 * @param list<string> $extensions Extensões sem ponto, em lowercase.
 * @return list<string> Paths absolutos ordenados.
 */
function docsFilesByExtension(string $directory, array $extensions): array
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

/**
 * Verifica JSDoc mínimo em módulos JavaScript próprios quando eles existirem.
 *
 * Todo módulo precisa iniciar com um bloco JSDoc de arquivo. Funções declaradas
 * por `function` precisam de JSDoc imediatamente anterior; arrow functions
 * continuam sob revisão humana até existir primeiro módulo real e um parser JS
 * dedicado no projeto.
 *
 * @param string $root Raiz usada para mensagens relativas.
 * @param string $file Módulo JS/MJS/CJS próprio do Ninfa.
 * @param list<string> $errors Acumulador mutável de violações.
 */
function assertJavaScriptDocs(string $root, string $file, array &$errors): void
{
    $source = (string) file_get_contents($file);
    if (!str_starts_with(ltrim($source), '/**')) {
        $errors[] = docsRelativePath($root, $file) . ': módulo JavaScript deve iniciar com JSDoc.';
    }

    $lines = preg_split('/\R/', $source) ?: [];
    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(?:export\s+)?(?:async\s+)?function\s+[A-Za-z_$][A-Za-z0-9_$]*\s*\(/', $line) !== 1) {
            continue;
        }

        $prefix = implode("\n", array_slice($lines, max(0, $index - 12), min(12, $index)));
        if (!str_contains($prefix, '/**') || !str_contains($prefix, '*/')) {
            $errors[] = docsRelativePath($root, $file) . ': função JS na linha ' . ($index + 1) . ' sem JSDoc próximo.';
        }
    }
}

/** @var list<string> $srcFiles Todos os arquivos PHP de produção sujeitos ao padrão estrito. */
$srcFiles = glob($root . '/src/*.php') ?: [];
sort($srcFiles);
foreach ($srcFiles as $file) {
    assertDocumentedClasses($root, $file, $errors);
    assertStrictNamedCallables($root, $file, $errors);
    assertTypedEmptyArrayAccumulators($root, $file, $errors);
    assertSemanticBlockComments($root, $file, $errors);
}

// Entrypoints/configurações precisam explicar finalidade global além dos símbolos internos.
foreach ([
    $root . '/bin/ninfa',
    $root . '/scripts/ninfa-configure.php',
    $root . '/ecs.php',
    $root . '/rector.php',
] as $file) {
    assertPhpHeader($root, $file, $errors);
}

// O configurador possui função de produção nomeada e segue o mesmo padrão estrito de src/.
assertStrictNamedCallables($root, $root . '/scripts/ninfa-configure.php', $errors);
assertTypedEmptyArrayAccumulators($root, $root . '/scripts/ninfa-configure.php', $errors);
assertSemanticBlockComments($root, $root . '/scripts/ninfa-configure.php', $errors);

// JavaScript próprio futuro entra automaticamente na premissa, sem depender de checklist manual.
foreach ([$root . '/src', $root . '/scripts', $root . '/bin'] as $directory) {
    foreach (docsFilesByExtension($directory, ['js', 'mjs', 'cjs']) as $file) {
        assertJavaScriptDocs($root, $file, $errors);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "[ERRO] Premissa de documentação interna não atendida:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo '[OK] Documentação estrita aplicada a todos os arquivos de src/; allowlist documental: 0.' . PHP_EOL;
