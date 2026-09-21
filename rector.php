<?php

declare(strict_types=1);

/**
 * Configuração do Rector usada para evoluir o próprio código do Ninfa.
 *
 * Aplica os conjuntos de dead code, code quality e type declarations sobre
 * `src/` e `tests/`. Não é a configuração entregue ao projeto consumidor; o
 * Ninfa gera um `rector.php` específico no workspace externo durante a execução.
 */

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
