<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-semantic-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/frontend/controllers', 0775, true);
    mkdir($root . '/frontend/views/site', 0775, true);
    mkdir($root . '/common/models', 0775, true);

    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
            'yiisoft/yii2-redis' => '^2.0',
        ],
        'require-dev' => [
            'yiisoft/yii2-mongodb' => '^3.0',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/frontend/views/site/index.php', "<?php echo 'ok';\n");
    file_put_contents(
        $root . '/frontend/controllers/SiteController.php',
        <<<'PHP'
<?php

namespace app\frontend\controllers;

final class SiteController
{
    public function actionIndex(): void
    {
        $this->render('index');
        $this->render('missing');
        $view = 'dynamic';
        $this->render($view);
    }

    public function actionUserProfile(): void
    {
    }

    public function actions(): array
    {
        return [
            'external-action' => ['class' => ExampleAction::class],
        ];
    }
}
PHP,
    );

    file_put_contents(
        $root . '/common/models/Order.php',
        <<<'PHP'
<?php

namespace app\common\models;

final class Order
{
    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }

    public function getItems(): mixed
    {
        return $this->hasMany(OrderItem::class, ['order_id' => 'id']);
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $model = Yii2SemanticModel::fromContext($context);
    assert($model->capabilities() === ['redis' => true, 'mongodb' => true]);

    $controllers = $model->controllers();
    assert(count($controllers) === 1);
    assert($controllers[0]['class'] === 'app\\frontend\\controllers\\SiteController');
    assert($controllers[0]['id'] === 'site');
    assert($controllers[0]['actions'] === ['external-action', 'index', 'user-profile']);
    assert(count($controllers[0]['views']) === 2);
    assert($controllers[0]['views'][0]['name'] === 'index');
    assert($controllers[0]['views'][0]['resolved_path'] === 'frontend/views/site/index.php');
    assert($controllers[0]['views'][0]['exists'] === true);
    assert($controllers[0]['views'][1]['name'] === 'missing');
    assert($controllers[0]['views'][1]['resolved_path'] === 'frontend/views/site/missing.php');
    assert($controllers[0]['views'][1]['exists'] === false);

    $relations = $model->relations();
    assert(count($relations) === 2);
    assert($relations[0]['model'] === 'app\\common\\models\\Order');
    assert($relations[0]['name'] === 'customer');
    assert($relations[0]['kind'] === 'hasOne');
    assert($relations[0]['target'] === 'Customer');
    assert($relations[1]['name'] === 'items');
    assert($relations[1]['kind'] === 'hasMany');
    assert($relations[1]['target'] === 'OrderItem');

    $serialized = $model->jsonSerialize();
    assert($serialized['capabilities']['redis'] === true);
    assert(count($serialized['controllers']) === 1);

    $yii2Findings = (new Yii2RuleEngine())->analyse($model);
    assert(count($yii2Findings) === 1);
    assert($yii2Findings[0]->tool === 'ninfa-yii2');
    assert($yii2Findings[0]->rule === Yii2RuleEngine::VIEW_NOT_FOUND);
    assert($yii2Findings[0]->file === 'frontend/controllers/SiteController.php');
    assert($yii2Findings[0]->severity === 'error');
    assert($yii2Findings[0]->confidence === 'high');
    assert(($yii2Findings[0]->metadata['category'] ?? null) === 'correctness');
    assert(($yii2Findings[0]->metadata['expected_path'] ?? null) === 'frontend/views/site/missing.php');

    $configureOutput = [];
    $configureCode = 0;
    exec(
        escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/ninfa-configure.php')
        . ' ' . escapeshellarg($root)
        . ' 2>&1',
        $configureOutput,
        $configureCode,
    );
    assert($configureCode === 0, implode("\n", $configureOutput));

    $semanticIndexFile = $context->workspace()->file('semantic-index.json');
    assert(is_file($semanticIndexFile));
    $semanticIndex = json_decode((string) file_get_contents($semanticIndexFile), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($semanticIndex));
    assert(($semanticIndex['profile'] ?? null) === 'yii2');
    assert(($semanticIndex['framework_semantics']['capabilities']['redis'] ?? null) === true);
    assert(($semanticIndex['framework_semantics']['capabilities']['mongodb'] ?? null) === true);
    assert(count($semanticIndex['framework_semantics']['controllers'] ?? []) === 1);

    echo "[OK] Yii2 cobre modelo semântico, finding nativo e índice externo.\n";
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
