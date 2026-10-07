<?php

declare(strict_types=1);

/**
 * Guards Ninfa's internal documentation requirements for production code.
 *
 * There is no permanent documentation-debt allowlist for `src/`: every class
 * and every named method/function requires narrative PHPDoc; array contracts
 * preserve generics/shapes; empty array accumulators require a nearby `@var`.
 * Files with relevant control flow also require local comments that explain
 * intent or invariants in addition to symbol-level PHPDoc.
 *
 * Shell has its own guard in `tests/shell-docs.php`. Future first-party
 * JavaScript modules automatically enter the minimum JSDoc requirement.
 */

/** @var string $root Physical root of the Ninfa repository. */
$root = dirname(__DIR__);
/** @var list<string> $errors Documentation violations found by this guard. */
$errors = [];

/**
 * Converts an absolute path to a repository-relative path for diagnostics.
 *
 * @param string $root Physical repository root.
 * @param string $file Absolute or already-relative path.
 * @return string Relative path when the repository prefix is present.
 */
function docsRelativePath(string $root, string $file): string
{
    $prefix = rtrim($root, '/') . '/';
    return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
}

/**
 * Checks whether PHPDoc contains narrative text beyond type tags.
 *
 * @param string $docBlock Raw T_DOC_COMMENT token.
 * @return bool True when at least one meaningful descriptive line exists.
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
 * Finds the PHPDoc immediately associated with a tokenized PHP declaration.
 *
 * Visibility/static/final/readonly modifiers may appear between the docblock
 * and declaration. Any other meaningful token breaks the association so a
 * comment from the previous symbol cannot be reused accidentally.
 *
 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens returned by `token_get_all()`.
 * @param int $index Declaration token index.
 * @return string|null Associated PHPDoc or null.
 */
function docsPreviousPhpDoc(array $tokens, int $index): ?string
{
    /** @var list<int> $skippable Tokens allowed between PHPDoc and declaration. */
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
 * Extracts the textual name and signature of a method/function; closures return null.
 *
 * @param array<int,array{0:int,1:string,2:int}|string> $tokens PHP tokens.
 * @param int $functionIndex Index of the T_FUNCTION token.
 * @return array{name:string,signature:string}|null Named-symbol metadata.
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
 * Requires narrative PHPDoc on every class declared by the file.
 *
 * @param string $root Root used to produce relative diagnostics.
 * @param string $file Production PHP file.
 * @param list<string> $errors Mutable violation accumulator.
 */
function assertDocumentedClasses(string $root, string $file, array &$errors): void
{
    $tokens = token_get_all((string) file_get_contents($file));

    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_CLASS) {
            continue;
        }

        $docBlock = docsPreviousPhpDoc($tokens, $index);
        $label = docsRelativePath($root, $file) . ': class at line ' . $token[2];
        if ($docBlock === null) {
            $errors[] = $label . ' has no associated PHPDoc.';
            continue;
        }
        if (!docsHasNarrative($docBlock)) {
            $errors[] = $label . ' has PHPDoc without a factual description.';
        }
    }
}

/**
 * Requires descriptive PHPDoc on all named production methods/functions.
 *
 * When a signature uses `array`, the docblock must preserve generic/shape
 * information with `@param` and/or `@return`; repeating `array` alone does
 * not satisfy the contract.
 *
 * @param string $root Root used to produce relative diagnostics.
 * @param string $file PHP file subject to the strict documentation standard.
 * @param list<string> $errors Mutable violation accumulator.
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

        $label = docsRelativePath($root, $file) . '::' . $metadata['name'] . '() line ' . $token[2];
        $docBlock = docsPreviousPhpDoc($tokens, $index);
        if ($docBlock === null) {
            $errors[] = $label . ': named function/method has no associated PHPDoc.';
            continue;
        }
        if (!docsHasNarrative($docBlock)) {
            $errors[] = $label . ': PHPDoc must explain responsibility/contract, not only type tags.';
        }

        $signature = $metadata['signature'];
        $hasArrayParameter = preg_match('/(?:\?|\b)array\s+\$[A-Za-z_][A-Za-z0-9_]*/', $signature) === 1;
        $hasArrayReturn = preg_match('/:\s*\??array\b/', $signature) === 1;

        if ($hasArrayParameter && preg_match('/@param\s+(?:array|list)(?:<|\{)/', $docBlock) !== 1) {
            $errors[] = $label . ': array parameter must preserve a generic/shape type in @param.';
        }
        if ($hasArrayReturn && preg_match('/@return\s+(?:array|list)(?:<|\{)/', $docBlock) !== 1) {
            $errors[] = $label . ': array return must preserve a generic/shape type in @return.';
        }
    }
}

/**
 * Requires a nearby `@var` for local accumulators initialized as empty arrays.
 *
 * The rule covers the common `$name = [];` pattern used by accumulating maps
 * and lists. Large shapes may use a multiline docblock immediately above.
 *
 * @param string $root Root used to produce relative diagnostics.
 * @param string $file PHP file subject to the strict documentation standard.
 * @param list<string> $errors Mutable violation accumulator.
 */
function assertTypedEmptyArrayAccumulators(string $root, string $file, array &$errors): void
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $errors[] = docsRelativePath($root, $file) . ': unable to read file.';
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

        $errors[] = docsRelativePath($root, $file) . ': accumulator $' . $variable
            . ' at line ' . ($index + 1) . ' has no nearby @var type/shape.';
    }
}

/**
 * Requires evidence of local documentation when a file contains complex control flow.
 *
 * This check does not attempt to prove semantic comment quality. It prevents files
 * with several `if/foreach/try/match` constructs from containing only symbol-level
 * PHPDoc and no local explanation of decisions, precedence or invariants.
 *
 * @param string $root Root used to produce relative diagnostics.
 * @param string $file Production PHP file.
 * @param list<string> $errors Mutable violation accumulator.
 */
function assertSemanticBlockComments(string $root, string $file, array &$errors): void
{
    $tokens = token_get_all((string) file_get_contents($file));
    $controlCount = 0;
    $localNarrativeComments = 0;
    /** @var list<int> $controlTokens Tokens that represent relevant decisions/iterations. */
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
            . ': contains relevant control flow without a local decision/invariant comment.';
    }
}

/**
 * Requires a file-level docblock near the top of executable/configuration PHP files.
 *
 * @param string $root Root used to produce relative diagnostics.
 * @param string $file Executable/configuration PHP file.
 * @param list<string> $errors Mutable violation accumulator.
 */
function assertPhpHeader(string $root, string $file, array &$errors): void
{
    $prefix = substr((string) file_get_contents($file), 0, 4096);
    if (!str_contains($prefix, '/**')) {
        $errors[] = docsRelativePath($root, $file) . ': file-level PHPDoc header is missing.';
    }
}

/**
 * Lists files recursively for the requested extensions.
 *
 * @param string $directory Base directory.
 * @param list<string> $extensions Lowercase extensions without a leading dot.
 * @return list<string> Sorted absolute paths.
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
 * Checks minimum JSDoc requirements for first-party JavaScript modules when present.
 *
 * Every module must start with a file-level JSDoc block. Functions declared with
 * `function` require nearby JSDoc. Arrow functions remain subject to human review
 * until the repository has a real first-party JS module and a dedicated parser.
 *
 * @param string $root Root used to produce relative diagnostics.
 * @param string $file First-party JS/MJS/CJS module.
 * @param list<string> $errors Mutable violation accumulator.
 */
function assertJavaScriptDocs(string $root, string $file, array &$errors): void
{
    $source = (string) file_get_contents($file);
    if (!str_starts_with(ltrim($source), '/**')) {
        $errors[] = docsRelativePath($root, $file) . ': JavaScript module must start with JSDoc.';
    }

    $lines = preg_split('/\R/', $source) ?: [];
    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(?:export\s+)?(?:async\s+)?function\s+[A-Za-z_$][A-Za-z0-9_$]*\s*\(/', $line) !== 1) {
            continue;
        }

        $prefix = implode("\n", array_slice($lines, max(0, $index - 12), min(12, $index)));
        if (!str_contains($prefix, '/**') || !str_contains($prefix, '*/')) {
            $errors[] = docsRelativePath($root, $file) . ': JS function at line ' . ($index + 1) . ' has no nearby JSDoc.';
        }
    }
}

/** @var list<string> $srcFiles All production PHP files subject to the strict standard. */
$srcFiles = glob($root . '/src/*.php') ?: [];
sort($srcFiles);
foreach ($srcFiles as $file) {
    assertDocumentedClasses($root, $file, $errors);
    assertStrictNamedCallables($root, $file, $errors);
    assertTypedEmptyArrayAccumulators($root, $file, $errors);
    assertSemanticBlockComments($root, $file, $errors);
}

// Entrypoints/configuration files explain their global purpose in addition to internal symbols.
foreach ([
    $root . '/bin/ninfa',
    $root . '/scripts/ninfa-configure.php',
    $root . '/ecs.php',
    $root . '/rector.php',
] as $file) {
    assertPhpHeader($root, $file, $errors);
}

// The configurator contains named production functions and follows the same strict src/ standard.
assertStrictNamedCallables($root, $root . '/scripts/ninfa-configure.php', $errors);
assertTypedEmptyArrayAccumulators($root, $root . '/scripts/ninfa-configure.php', $errors);
assertSemanticBlockComments($root, $root . '/scripts/ninfa-configure.php', $errors);

// Future first-party JavaScript automatically enters the documentation contract.
foreach ([$root . '/src', $root . '/scripts', $root . '/bin'] as $directory) {
    foreach (docsFilesByExtension($directory, ['js', 'mjs', 'cjs']) as $file) {
        assertJavaScriptDocs($root, $file, $errors);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "[ERROR] Internal documentation requirements were not met:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo '[OK] Strict documentation requirements apply to every src/ file; documentation allowlist: 0.' . PHP_EOL;
