<?php

declare(strict_types=1);

$configure = (string) file_get_contents(dirname(__DIR__) . '/scripts/ninfa-configure.php');

foreach ([
    "ProjectContext::fromRoot",
    "ExternalConfigGenerator",
    "PHPStan level",
] as $required) {
    if (!str_contains($configure, $required)) {
        fwrite(STDERR, "[ERRO] configurador legado nao delega para a arquitetura externa: {$required}.\n");
        exit(1);
    }
}

foreach ([
    "level: max",
    "/.ninfa/",
    "Laravel",
    "Symfony",
    "PHP generico",
] as $forbidden) {
    if (str_contains($configure, $forbidden)) {
        fwrite(STDERR, "[ERRO] configurador legado ainda contem politica antiga: {$forbidden}.\n");
        exit(1);
    }
}

echo "[OK] ninfa-configure.php e fachada da configuracao externa.\n";
