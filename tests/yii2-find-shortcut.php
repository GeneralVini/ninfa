<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2SafeRemediator.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-find-shortcut-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-find-shortcut-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/common/models', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/common/models/Order.php', <<<'PHP'
<?php
namespace app\common\models;
final class Order extends \yii\db\ActiveRecord
{
    public function examples(int $status): void
    {
        $one = Order::find()->where(['status' => 1])->one();
        $all = Order::find()->where([
            'status' => $status,
            'tenant_id' => 7,
        ])->all();

        $list = Order::find()->where([1, 2])->one();
        $operator = Order::find()->where(['=', 'status', 1])->one();
        $dynamic = Order::find()->where($this->runtimeCondition())->all();
        $empty = Order::find()->where([])->one();
    }
}
PHP);

    file_put_contents($root . '/common/models/Other.php', <<<'PHP'
<?php
namespace app\common\models;
final class Other
{
    public static function find(): object
    {
        return new \stdClass();
    }

    public function example(): void
    {
        Other::find()->where(['status' => 1])->one();
    }
}
PHP);

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2FindShortcutAnalyzer())->references($context);
    assert(count($references) === 2);
    assert(array_column($references, 'terminal') === ['one', 'all']);
    assert(array_column($references, 'replacement_method') === ['findOne', 'findAll']);
    assert(str_contains($references[0]['replacement'], "Order::findOne(['status' => 1])"));
    assert(str_contains($references[1]['replacement'], 'Order::findAll(['));
    foreach ($references as $reference) {
        assert($reference['file'] === 'common/models/Order.php');
        assert($reference['model'] === 'app\\common\\models\\Order');
        assert($reference['offset'] >= 0);
        assert($reference['length'] > 0);
    }

    $result = (new Yii2SafeRemediator())->apply($context);
    assert($result['changed_files'] === ['common/models/Order.php']);
    assert($result['changes'] === 2);

    $fixed = (string) file_get_contents($root . '/common/models/Order.php');
    assert(str_contains($fixed, "Order::findOne(['status' => 1])"));
    assert(str_contains($fixed, 'Order::findAll(['));
    assert(str_contains($fixed, "Order::find()->where([1, 2])->one()"));
    assert(str_contains($fixed, "Order::find()->where(['=', 'status', 1])->one()"));
    assert(str_contains($fixed, 'Order::find()->where($this->runtimeCondition())->all()'));
    assert(str_contains($fixed, 'Order::find()->where([])->one()'));

    $second = (new Yii2SafeRemediator())->apply($context);
    assert($second['changed_files'] === []);
    assert($second['changes'] === 0);
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
