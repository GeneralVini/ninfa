<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-query-relations-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-query-relations-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/common/models', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/common/models/Models.php', <<<'PHP'
<?php

namespace app\common\models;

final class Order extends \yii\db\ActiveRecord
{
    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }

    public function getItems(): mixed
    {
        return $this->hasMany(Item::class, ['order_id' => 'id']);
    }

    public function examples(string $dynamic): void
    {
        Order::find()->with('customer', 'items', 'missing-variadic');
        Order::find()->with(['customer', 'missing-array']);
        Order::find()->with([
            'items' => static function ($query): void {},
            'missing-key' => static function ($query): void {},
        ]);
        Order::find()->joinWith(['items i', 'missing-join m']);
        Order::find()->innerJoinWith([
            'customer',
            'missing-inner' => static function ($query): void {},
        ]);
        Order::find()->with($dynamic, 'missing-after-dynamic');
    }
}

final class Customer extends \yii\db\ActiveRecord
{
}

final class Item extends \yii\db\ActiveRecord
{
}
PHP);

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');
    $model = Yii2SemanticModel::fromContext($context);

    $references = (new Yii2RelationReferenceAnalyzer())->references($context, $model);

    $missing = array_values(array_filter(
        $references,
        static fn (array $reference): bool => $reference['exists'] === false,
    ));
    $names = array_column($missing, 'missing_relation');
    sort($names);

    assert($names === [
        'missing-array',
        'missing-inner',
        'missing-join',
        'missing-key',
        'missing-variadic',
        'missing-after-dynamic',
    ]);

    assert(in_array('missing-after-dynamic', array_column($references, 'relation_path'), true));
    assert(in_array('items', array_column($references, 'relation_path'), true));
    assert(in_array('items i', array_column($references, 'relation_path'), true));

    $findings = array_values(array_filter(
        (new Yii2RuleEngine())->analyse($model, $context),
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::QUERY_RELATION_NOT_FOUND,
    ));
    assert(count($findings) === 6);
    foreach ($findings as $finding) {
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-correctness');
        assert(($finding->metadata['relation_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    echo "[OK] Yii2 COR-003 cobre arrays e argumentos variádicos literais de ActiveQuery relations.\n";
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
