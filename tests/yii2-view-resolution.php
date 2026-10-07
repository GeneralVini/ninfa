<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-view-resolution-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-view-resolution-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/frontend/controllers', 0775, true);
    mkdir($root . '/frontend/views/site', 0775, true);
    mkdir($root . '/frontend/views', 0775, true);
    mkdir($root . '/shared', 0775, true);
    mkdir($root . '/src', 0775, true);
    mkdir($root . '/resources/custom/views/admin', 0775, true);

    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/frontend/views/site/index.php', <<<'PHP'
<?php

$this->render('_row');
$this->render('nested-missing');
$this->render('/shared');
$this->render('@shared/nested-missing');
$this->render('//global');
$this->render('relative-with-context', [], $context);
$dynamic = 'runtime';
$this->render($dynamic);

// $this->render('comment-only');
$text = '$this->render("string-only")';
PHP);
    file_put_contents($root . '/frontend/views/site/_row.php', "<?php echo 'row';\n");
    file_put_contents($root . '/frontend/views/shared.php', "<?php echo 'shared';\n");
    file_put_contents($root . '/frontend/views/global.php', "<?php echo 'global';\n");
    file_put_contents($root . '/shared/card.php', "<?php echo 'card';\n");

    file_put_contents($root . '/frontend/controllers/SiteController.php', <<<'PHP'
<?php

namespace app\frontend\controllers;

final class SiteController extends \yii\web\Controller
{
    public function actionIndex(): void
    {
        $this->render('index');
        $this->render('missing');
        $this->render('/shared');
        $this->render('//global');
        $this->render('@shared/card');
        $this->render('@shared/controller-missing');
        $name = 'runtime';
        $this->render($name);
        $this->renderFile('@shared/controller-missing');
    }
}
PHP);

    file_put_contents($root . '/src/AdminController.php', <<<'PHP'
<?php

namespace app\custom\controllers;

final class AdminController extends \yii\web\Controller
{
    public function actionIndex(): void
    {
        $this->render('dashboard');
        $this->render('missing');
    }
}
PHP);

    file_put_contents($root . '/resources/custom/views/admin/dashboard.twig', "dashboard\n");

    file_put_contents($root . '/src/Report.php', <<<'PHP'
<?php

namespace app\service;

final class Report
{
    public function render(): void
    {
        \Yii::$app->view->render('@shared/service-missing');
        \Yii::$app->view->render('relative-unknown');
        \Yii::$app->getView()->render('//missing-global');
    }
}
PHP);

    putenv('NINFA_YII2_VIEW_ALIASES_JSON=' . json_encode([
        '@app' => $root . '/frontend',
        '@shared' => $root . '/shared',
    ], JSON_THROW_ON_ERROR));
    putenv('NINFA_YII2_VIEW_PATHS_JSON=' . json_encode([
        'app\\custom\\controllers' => $root . '/resources/custom/views',
    ], JSON_THROW_ON_ERROR));
    putenv('NINFA_YII2_VIEW_EXTENSIONS=php,twig');

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');
    $model = Yii2SemanticModel::fromContext($context);

    $references = (new Yii2ViewReferenceAnalyzer())->references($context, $model);
    $missing = array_values(array_filter(
        $references,
        static fn (array $reference): bool => $reference['exists'] === false,
    ));

    /** @var list<string> $missingKeys Chaves estáveis para conferir contexto + nome ausente. */
    $missingKeys = array_map(
        static fn (array $reference): string => $reference['context'] . ':' . $reference['view'],
        $missing,
    );
    sort($missingKeys);

    assert($missingKeys === [
        'controller:@shared/controller-missing',
        'controller:missing',
        'controller:missing',
        'nested-view:@shared/nested-missing',
        'nested-view:nested-missing',
        'view-service://missing-global',
        'view-service:@shared/service-missing',
    ]);

    $customDashboard = array_values(array_filter(
        $references,
        static fn (array $reference): bool => $reference['file'] === 'src/AdminController.php'
            && $reference['view'] === 'dashboard',
    ));
    assert(count($customDashboard) === 1);
    assert($customDashboard[0]['exists'] === true);
    assert($customDashboard[0]['resolved_path'] === 'resources/custom/views/admin/dashboard.twig');
    assert($customDashboard[0]['source'] === 'configured-view-path');

    assert(!in_array('relative-with-context', array_column($references, 'view'), true));
    assert(!in_array('relative-unknown', array_column($references, 'view'), true));
    assert(!in_array('runtime', array_column($references, 'view'), true));
    assert(!in_array('comment-only', array_column($references, 'view'), true));
    assert(!in_array('string-only', array_column($references, 'view'), true));

    $findings = array_values(array_filter(
        (new Yii2RuleEngine())->analyse($model, $context),
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::VIEW_NOT_FOUND,
    ));
    assert(count($findings) === 7);
    foreach ($findings as $finding) {
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-correctness');
        assert($finding->provenance === ['ninfa:yii2-view-reference-analyzer']);
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['autofix'] ?? null) === false);
        assert(is_string($finding->metadata['expected_path'] ?? null));
    }

    echo "[OK] Yii2 COR-001 cobre controller, nested view, aliases, view paths e View::render comprovado.\n";
} finally {
    putenv('NINFA_WORKSPACE_ROOT');
    putenv('NINFA_YII2_VIEW_ALIASES_JSON');
    putenv('NINFA_YII2_VIEW_PATHS_JSON');
    putenv('NINFA_YII2_VIEW_EXTENSIONS');

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
