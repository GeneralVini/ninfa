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

use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\filters\auth\HttpBearerAuth;

final class SiteController extends \yii\web\Controller
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

    public function behaviors(): array
    {
        $dynamicAction = 'runtime-only';
        $behaviorClass = AccessControl::class;

        return [
            'access' => [
                'class' => AccessControl::class,
                'only' => ['index', 'missing-only', 'admin-*', $dynamicAction],
                'except' => ['user-profile', 'missing-except'],
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['external-action', 'missing-rule'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'index' => ['GET'],
                    'missing-verb' => ['POST'],
                    '*' => ['GET'],
                ],
            ],
            'auth' => [
                'class' => HttpBearerAuth::class,
                'optional' => ['index', 'missing-optional', 'api-*'],
            ],
            'dynamic' => [
                'class' => $behaviorClass,
                'only' => ['missing-dynamic-class'],
            ],
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

final class Order extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return ['id', 'customer_id'];
    }

    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }

    public function getItems(): mixed
    {
        return $this->hasMany(OrderItem::class, ['order_id' => 'id']);
    }

    public function getBrokenCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['missing_related' => 'missing_current']);
    }

    public function getViaCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['missing_related' => 'missing_current'])
            ->viaTable('order_customer', ['order_id' => 'id']);
    }

    public function getDynamicCustomer(): mixed
    {
        $link = ['id' => 'customer_id'];
        return $this->hasOne(Customer::class, $link);
    }

    public function getRuntimeRelation(): mixed
    {
        return $this->buildRelationAtRuntime();
    }

    public function relationQueryExamples(): void
    {
        Order::find()->with('customer');
        Order::find()->with('missing-relation');
        Order::find()->joinWith('items item');
        Order::find()->innerJoinWith('customer.address');
        Order::find()->innerJoinWith('customer.missing-nested');
        Order::find()->with('runtimeRelation');
        $dynamicRelation = 'runtime-only';
        Order::find()->with($dynamicRelation);
        Order::find()->with(['missing-array']);
    }
}
PHP,
    );

    file_put_contents(
        $root . '/common/models/Customer.php',
        <<<'PHP'
<?php

namespace app\common\models;

final class Customer extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return ['id', 'address_id'];
    }

    public function getAddress(): mixed
    {
        return $this->hasOne(Address::class, ['id' => 'address_id']);
    }
}
PHP,
    );

    file_put_contents(
        $root . '/common/models/Address.php',
        <<<'PHP'
<?php

namespace app\common\models;

final class Address extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return ['id'];
    }
}
PHP,
    );

    file_put_contents(
        $root . '/common/models/OrderItem.php',
        <<<'PHP'
<?php

namespace app\common\models;

final class OrderItem extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return ['id', 'order_id'];
    }
}
PHP,
    );

    file_put_contents(
        $root . '/common/models/UnknownSchemaOrder.php',
        <<<'PHP'
<?php

namespace app\common\models;

final class UnknownSchemaOrder extends \yii\db\ActiveRecord
{
    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'missing_runtime_column']);
    }
}
PHP,
    );

    file_put_contents(
        $root . '/common/models/DynamicAttributesOrder.php',
        <<<'PHP'
<?php

namespace app\common\models;

final class DynamicAttributesOrder extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return parent::attributes();
    }

    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'missing_dynamic_column']);
    }
}
PHP,
    );

    file_put_contents(
        $root . '/common/models/LegacyOrder.php',
        <<<'PHP'
<?php

namespace app\common\models;

final class LegacyOrder extends RuntimeBaseRecord
{
    public function unresolvedRelationQuery(): void
    {
        LegacyOrder::find()->with('missing-on-unknown-base');
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
    assert(count($relations) === 8);
    assert($relations[0]['model'] === 'app\\common\\models\\Customer');
    assert($relations[0]['name'] === 'address');
    assert($relations[0]['kind'] === 'hasOne');
    assert($relations[0]['target'] === 'Address');
    assert(in_array('brokenCustomer', array_column($relations, 'name'), true));
    assert(in_array('dynamicCustomer', array_column($relations, 'name'), true));
    assert(in_array('viaCustomer', array_column($relations, 'name'), true));

    $serialized = $model->jsonSerialize();
    assert($serialized['capabilities']['redis'] === true);
    assert(count($serialized['controllers']) === 1);

    $behaviorReferences = (new Yii2BehaviorActionAnalyzer())->references($context, $model);
    assert(count($behaviorReferences) === 10);
    assert(count(array_filter($behaviorReferences, static fn (array $reference): bool => $reference['exists'] === false)) === 5);
    assert(!in_array('admin-*', array_column($behaviorReferences, 'action'), true));
    assert(!in_array('api-*', array_column($behaviorReferences, 'action'), true));
    assert(!in_array('missing-dynamic-class', array_column($behaviorReferences, 'action'), true));
    assert(!in_array('runtime-only', array_column($behaviorReferences, 'action'), true));

    $relationReferences = (new Yii2RelationReferenceAnalyzer())->references($context, $model);
    assert(count($relationReferences) === 7);
    assert(count(array_filter($relationReferences, static fn (array $reference): bool => $reference['exists'] === true)) === 3);
    assert(count(array_filter($relationReferences, static fn (array $reference): bool => $reference['exists'] === false)) === 2);
    assert(count(array_filter($relationReferences, static fn (array $reference): bool => $reference['exists'] === null)) === 2);
    assert(array_column(array_values(array_filter(
        $relationReferences,
        static fn (array $reference): bool => $reference['exists'] === false,
    )), 'missing_relation') === ['missing-relation', 'missing-nested']);
    assert(!in_array('runtime-only', array_column($relationReferences, 'relation_path'), true));
    assert(!in_array('missing-array', array_column($relationReferences, 'relation_path'), true));
    $runtimeGetterReference = array_values(array_filter(
        $relationReferences,
        static fn (array $reference): bool => $reference['relation_path'] === 'runtimeRelation',
    ));
    assert(count($runtimeGetterReference) === 1);
    assert($runtimeGetterReference[0]['exists'] === null);

    $linkReferences = (new Yii2RelationLinkAnalyzer())->references($context, $model);
    assert(count($linkReferences) === 6);
    $brokenLink = array_values(array_filter(
        $linkReferences,
        static fn (array $reference): bool => $reference['relation'] === 'brokenCustomer',
    ));
    assert(count($brokenLink) === 1);
    assert($brokenLink[0]['related_exists'] === false);
    assert($brokenLink[0]['current_exists'] === false);
    assert($brokenLink[0]['related_inventory_complete'] === true);
    assert($brokenLink[0]['current_inventory_complete'] === true);
    assert(!in_array('viaCustomer', array_column($linkReferences, 'relation'), true));
    assert(!in_array('dynamicCustomer', array_column($linkReferences, 'relation'), true));

    $unknownSchemaLink = array_values(array_filter(
        $linkReferences,
        static fn (array $reference): bool => $reference['current_model'] === 'app\\common\\models\\UnknownSchemaOrder',
    ));
    assert(count($unknownSchemaLink) === 1);
    assert($unknownSchemaLink[0]['related_exists'] === true);
    assert($unknownSchemaLink[0]['current_exists'] === null);
    assert($unknownSchemaLink[0]['current_inventory_complete'] === false);

    $dynamicAttributesLink = array_values(array_filter(
        $linkReferences,
        static fn (array $reference): bool => $reference['current_model'] === 'app\\common\\models\\DynamicAttributesOrder',
    ));
    assert(count($dynamicAttributesLink) === 1);
    assert($dynamicAttributesLink[0]['related_exists'] === true);
    assert($dynamicAttributesLink[0]['current_exists'] === null);
    assert($dynamicAttributesLink[0]['current_inventory_complete'] === false);

    $yii2Findings = (new Yii2RuleEngine())->analyse($model, $context);
    assert(count($yii2Findings) === 10);
    assert($yii2Findings[0]->tool === 'ninfa-yii2');
    assert($yii2Findings[0]->rule === Yii2RuleEngine::VIEW_NOT_FOUND);
    assert($yii2Findings[0]->file === 'frontend/controllers/SiteController.php');
    assert($yii2Findings[0]->severity === 'error');
    assert($yii2Findings[0]->confidence === 'high');
    assert(($yii2Findings[0]->metadata['category'] ?? null) === 'correctness');
    assert(($yii2Findings[0]->metadata['expected_path'] ?? null) === 'frontend/views/site/missing.php');

    $actionFindings = array_values(array_filter(
        $yii2Findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::BEHAVIOR_ACTION_NOT_FOUND,
    ));
    assert(count($actionFindings) === 5);
    assert(array_column(array_map(static fn (Finding $finding): array => $finding->metadata, $actionFindings), 'action') === [
        'missing-only',
        'missing-except',
        'missing-rule',
        'missing-verb',
        'missing-optional',
    ]);
    foreach ($actionFindings as $finding) {
        assert($finding->file === 'frontend/controllers/SiteController.php');
        assert($finding->line > 0);
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['action_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    $relationFindings = array_values(array_filter(
        $yii2Findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::QUERY_RELATION_NOT_FOUND,
    ));
    assert(count($relationFindings) === 2);
    assert(array_column(array_map(static fn (Finding $finding): array => $finding->metadata, $relationFindings), 'missing_relation') === [
        'missing-relation',
        'missing-nested',
    ]);
    foreach ($relationFindings as $finding) {
        assert($finding->file === 'common/models/Order.php');
        assert($finding->line > 0);
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['relation_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    $linkFindings = array_values(array_filter(
        $yii2Findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::RELATION_LINK_ATTRIBUTE_NOT_FOUND,
    ));
    assert(count($linkFindings) === 2);
    assert(array_column(array_map(static fn (Finding $finding): array => $finding->metadata, $linkFindings), 'side') === [
        'related',
        'current',
    ]);
    assert(array_column(array_map(static fn (Finding $finding): array => $finding->metadata, $linkFindings), 'attribute') === [
        'missing_related',
        'missing_current',
    ]);
    foreach ($linkFindings as $finding) {
        assert($finding->file === 'common/models/Order.php');
        assert($finding->line > 0);
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['relation'] ?? null) === 'brokenCustomer');
        assert(($finding->metadata['attribute_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

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

    echo "[OK] Yii2 cobre views, behavior actions, ActiveQuery relations, links ActiveRecord, findings nativos e índice externo.\n";
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
