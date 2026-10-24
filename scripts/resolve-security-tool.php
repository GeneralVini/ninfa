<?php
/** Bridge read-only para usar exatamente a resolução do CLI no diagnóstico nativo. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/ToolResolver.php';
try {
    echo (new ToolResolver($argv[3] ?? null))->resolve($argv[1], $argv[2]);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
