<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-controller-access-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-controller-access-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/frontend/controllers', 0775, true);
    mkdir($root . '/common', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/frontend/controllers/BaseController.php', <<<'PHP'
<?php
namespace app\frontend\controllers;
abstract class BaseController extends \yii\web\Controller
{
}
PHP);

    file_put_contents($root . '/frontend/controllers/SiteController.php', <<<'PHP'
<?php
namespace app\frontend\controllers;
final class SiteController extends BaseController
{
    public function actionIndex(): void
    {
        $ip = \Yii::$app->request->getUserIP();
        Yii::$app->response->format = 'json';
        $request = $this->request;
    }
}
PHP);

    file_put_contents($root . '/common/Helper.php', <<<'PHP'
<?php
namespace app\common;
final class Helper
{
    public function request(): mixed
    {
        return \Yii::$app->request;
    }
}
PHP);

    file_put_contents($root . '/frontend/controllers/RuntimeController.php', <<<'PHP'
<?php
namespace app\frontend\controllers;
final class RuntimeController extends ExternalController
{
    public function actionIndex(): mixed
    {
        return Yii::$app->request;
    }
}
PHP);

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2ControllerAccessAnalyzer())->references($context);
    assert(count($references) === 2);
    assert(array_column($references, 'property') === ['request', 'response']);
    assert(array_column($references, 'replacement') === ['$this->request', '$this->response']);
    foreach ($references as $reference) {
        assert($reference['file'] === 'frontend/controllers/SiteController.php');
        assert($reference['controller'] === 'app\\frontend\\controllers\\SiteController');
        assert($reference['line'] > 0);
    }

    $model = Yii2SemanticModel::fromContext($context);
    $findings = (new Yii2RuleEngine())->analyse($model, $context);
    $architecture = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::CONTROLLER_GLOBAL_REQUEST_RESPONSE,
    ));
    assert(count($architecture) === 2);
    foreach ($architecture as $finding) {
        assert($finding->severity === 'warning');
        assert($finding->confidence === 'high');
        assert(($finding->metadata['category'] ?? null) === 'architecture');
        assert(($finding->metadata['remediation_risk'] ?? null) === 'review');
        assert(($finding->metadata['autofix'] ?? null) === false);
    }
} finally {
    putenv('NINFA_WORKSPACE_ROOT');
    $remove = static function (string $path) use (&$remove): void {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
            rmdir($path);
            return;
        }
        unlink($path);
    };
    $remove($root);
    $remove($workspaceRoot);
}
