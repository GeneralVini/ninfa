<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/CliStyle.php';

putenv('NO_COLOR');
putenv('NINFA_COLOR=never');
assert(CliStyle::success('ok') === 'ok');
assert(!str_contains(CliStyle::legend(), "\033["));

putenv('NINFA_COLOR=always');
assert(str_contains(CliStyle::success('ok'), "\033[32m"));
assert(str_contains(CliStyle::error('erro'), "\033[31m"));
assert(str_contains(CliStyle::warning('atencao'), "\033[33m"));
assert(str_contains(CliStyle::info('info'), "\033[36m"));

putenv('NO_COLOR=1');
assert(CliStyle::success('ok') === 'ok');
assert(!str_contains(CliStyle::legend(), "\033["));

putenv('NO_COLOR');
putenv('NINFA_COLOR');

echo "[OK] Paleta semantica, NINFA_COLOR e NO_COLOR validados.\n";
