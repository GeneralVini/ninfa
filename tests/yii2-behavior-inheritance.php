<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-behavior-inheritance-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-behavior-inheritance-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/frontend/controllers', 0775, true);
    mkdir($root . '/common/filters', 0775, true);

    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/common/filters/CustomFilters.php', <<<'PHP'
<?php

namespace app\common\filters;

use yii\base\ActionFilter;
use yii\filters\AccessControl;
use yii\filters\AccessRule;
use yii\filters\VerbFilter;
use yii\filters\auth\HttpBearerAuth;

final class CustomVerbFilter extends VerbFilter
{
}

final class CustomAuthFilter extends HttpBearerAuth
{
}

final class CustomAccessControl extends AccessControl
{
}

final class CustomAccessRule extends AccessRule
{
}

final class CustomActionFilter extends ActionFilter
{
}

final class UnknownFilter extends \vendor\package\ExternalFilter
{
}
PHP);

    file_put_contents($root . '/frontend/controllers/SiteController.php', <<<'PHP'
<?php

namespace app\frontend\controllers;

use app\common\filters\CustomAccessControl;
use app\common\filters\CustomAccessRule;
use app\common\filters\CustomActionFilter;
use app\common\filters\CustomAuthFilter;
use app\common\filters\CustomVerbFilter;
use app\common\filters\UnknownFilter;

final class SiteController extends \yii\web\Controller
{
    public function actionIndex(): void
    {
    }

    public function actionExisting(): void
    {
    }

    public function behaviors(): array
    {
        return [
            'verb' => [
                'class' => CustomVerbFilter::class,
                'actions' => [
                    'index' => ['GET'],
                    'missing-verb' => ['POST'],
                    '*' => ['GET'],
                ],
            ],
            'auth' => [
                'class' => CustomAuthFilter::class,
                'only' => ['index', 'missing-auth-only'],
                'optional' => ['existing', 'missing-auth-optional'],
            ],
            'access' => [
                'class' => CustomAccessControl::class,
                'only' => ['missing-access-only'],
                'rules' => [
                    [
                        'class' => CustomAccessRule::class,
                        'allow' => true,
                        'actions' => ['existing', 'missing-access-rule'],
                    ],
                ],
            ],
            'generic' => [
                'class' => CustomActionFilter::class,
                'except' => ['existing', 'missing-generic'],
            ],
            'unknown' => [
                'class' => UnknownFilter::class,
                'only' => ['must-stay-unknown'],
            ],
        ];
    }
}
PHP);

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $model = Yii2SemanticModel::fromContext($context);
    $references = (new Yii2BehaviorActionAnalyzer())->references($context, $model);

    $missing = array_values(array_filter(
        $references,
        static fn (array $reference): bool => $reference['exists'] === false,
    ));
    $missingActions = array_column($missing, 'action');
    sort($missingActions);

    assert($missingActions === [
        'missing-access-only',
        'missing-access-rule',
        'missing-auth-only',
        'missing-auth-optional',
        'missing-generic',
        'missing-verb',
    ]);

    assert(!in_array('must-stay-unknown', array_column($references, 'action'), true));
    assert(!in_array('*', array_column($references, 'action'), true));

    $known = array_values(array_filter(
        $references,
        static fn (array $reference): bool => $reference['exists'] === true,
    ));
    $knownActions = array_column($known, 'action');
    sort($knownActions);
    assert($knownActions === ['existing', 'existing', 'existing', 'index', 'index']);

    $findings = array_values(array_filter(
        (new Yii2RuleEngine())->analyse($model, $context),
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::BEHAVIOR_ACTION_NOT_FOUND,
    ));
    assert(count($findings) === 6);

    foreach ($findings as $finding) {
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-correctness');
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['action_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    echo "[OK] Yii2 COR-002 reconhece subclasses locais de filters e AccessRule sem promover parent externo desconhecido.\n";
} finally {
    putenv('NINFA_WORKSPACE_ROOT');

    foreach ([$root, $workspaceRoot] as $directory) {
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
