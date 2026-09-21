<?php

declare(strict_types=1);

/**
 * Configuração do ECS usada para validar o próprio repositório Ninfa.
 *
 * Analisa `src/`, `tests/` e arquivos PHP da raiz usando o conjunto PSR-12.
 * Esta configuração é de desenvolvimento do Ninfa; as configurações aplicadas
 * aos projetos consumidores são geradas separadamente no workspace externo.
 */

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withPreparedSets(psr12: true);
