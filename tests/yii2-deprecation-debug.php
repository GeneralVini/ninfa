<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2DeprecationAnalyzer.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-deprecation-debug-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-deprecation-debug-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);
mkdir($root . '/console/controllers', 0775, true);
file_put_contents($root . '/composer.json', '{"require":{"yiisoft/yii2":"^2.0.53"}}');
file_put_contents($root . '/console/controllers/HealthController.php', <<<'PHP'
<?php
namespace app\console\controllers;
use yii\console\Controller;
final class HealthController extends Controller
{
    public function actionOk(): int { \Yii::trace('health'); return 0; }
    public function actionFail(): int { return 1; }
    public function legacyConstant(): int { return Controller::EXIT_CODE_ERROR; }
}
PHP);
file_put_contents($root . '/console/controllers/OtherController.php', <<<'PHP'
<?php
namespace app\console\controllers;
final class OtherController extends RuntimeController
{
    public const EXIT_CODE_ERROR = 99;
    public function actionNoProof(): int { return 1; }
    public function legacyConstant(): int { return self::EXIT_CODE_ERROR; }
}
PHP);

$references = (new Yii2DeprecationAnalyzer())->references(ProjectContext::fromRoot($root));
fwrite(STDERR, '[DEBUG Yii2 deprecations] ' . json_encode($references, JSON_UNESCAPED_SLASHES) . PHP_EOL);

$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
        return;
    }
    unlink($path);
};
$remove($root);
$remove($workspaceRoot);
putenv('NINFA_WORKSPACE_ROOT');
