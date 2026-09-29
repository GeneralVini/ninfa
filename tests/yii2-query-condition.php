<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-query-condition-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-query-condition-workspace-' . $token;
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
    public function conditions(mixed $from, mixed $to, array $dynamic): void
    {
        Order::find()->where(['=', 'status', 1]);
        Order::find()->andWhere(['in', 'id', [1, 2]]);
        Order::find()->orWhere(['between', 'created_at', $from, $to]);
        Order::find()->where(['status' => 1]);
        Order::find()->where($dynamic);

        Order::find()->where(['=', 'status']);
        Order::find()->where(['between', 'created_at', $from]);
        Order::find()->andWhere(['not', ['in', 'id']]);
        Order::find()->orWhere(['exists']);

        RuntimeModel::find()->where(['=', 'status']);
    }
}
PHP);

    file_put_contents($root . '/common/models/RuntimeModel.php', <<<'PHP'
<?php

namespace app\common\models;

final class RuntimeModel
{
    public static function find(): object
    {
        return new \stdClass();
    }
}
PHP);

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2QueryConditionAnalyzer())->references($context);
    assert(count($references) === 4);
    assert(array_column($references, 'operator') === ['=', 'BETWEEN', 'IN', 'EXISTS']);
    assert(array_column($references, 'actual_operands') === [1, 2, 1, 0]);
    assert(array_column($references, 'expected_operands') === [2, 3, 2, 1]);
    assert(array_column($references, 'expectation') === ['exact', 'at_least', 'at_least', 'at_least']);
    foreach ($references as $reference) {
        assert($reference['file'] === 'common/models/Order.php');
        assert($reference['model'] === 'app\\common\\models\\Order');
        assert($reference['line'] > 0);
    }

    $model = Yii2SemanticModel::fromContext($context);
    $findings = (new Yii2RuleEngine())->analyse($model, $context);
    $conditionFindings = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::QUERY_CONDITION_INVALID,
    ));
    assert(count($conditionFindings) === 4);
    foreach ($conditionFindings as $finding) {
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert(($finding->metadata['category'] ?? null) === 'correctness');
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
