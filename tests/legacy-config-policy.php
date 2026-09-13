<?php

declare(strict_types=1);

$configure = (string) file_get_contents(dirname(__DIR__) . '/scripts/ninfa-configure.php');

if (!str_contains($configure, "$phpStanLevel = $isGlpiPlugin ? 8 : 'max';")) {
    fwrite(STDERR, "[ERRO] ninfa-configure.php deve definir PHPStan nivel 8 para glpi-plugin.\n");
    exit(1);
}

if (str_contains($configure, 'parameters:\\n  level: max\\n')) {
    fwrite(STDERR, "[ERRO] nivel max continua hardcoded na configuracao PHPStan legada.\n");
    exit(1);
}

echo "[OK] Politica legada preserva PHPStan nivel 8 para glpi-plugin.\n";
