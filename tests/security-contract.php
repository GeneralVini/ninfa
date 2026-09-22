<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SecurityContract.php';

$generic = SecurityContract::forProfile('php-generic');
$yii3 = SecurityContract::forProfile('yii3');
$glpi = SecurityContract::forProfile('glpi-plugin');

assert(count($generic->contracts) === 12);
assert($generic->capabilities === ['filesystem', 'console']);
assert($generic->semgrepConfigs === ['security/semgrep/common.yml']);
assert(in_array('Yiisoft\\Db\\Connection::createCommand', $yii3->contracts['sql-injection']->sinks, true));
assert(in_array('GLPI upload validation', $glpi->contracts['file-access']->sanitizers, true));
assert(in_array('host-api', $glpi->capabilities, true));
assert($yii3->contracts['sql-injection']->provenance === ['ninfa:common', 'ninfa:yii3']);
assert($glpi->semgrepConfigs === ['security/semgrep/common.yml', 'security/semgrep/profiles/glpi-plugin-11.yml']);

echo "[OK] SecurityContract preserva os 12 contratos, baseline e overlays Yii3/GLPI.\n";
