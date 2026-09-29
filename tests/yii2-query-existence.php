<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';
require_once dirname(__DIR__) . '/src/Yii2SafeRemediator.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-query-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-query-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/common/models', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents(
        $root . '/common/models/Order.php',
        <<<'PHP'
<?php

namespace app\common\models;

class Order extends \yii\db\ActiveRecord
{
    public function examples(): void
    {
        $oneExists = Order::find()->where(['status' => 1])->one() !== null;
        $oneMissing = null === Order::find()->where(['status' => 2])->one();
        $countPositive = Order::find()->where(['status' => 3])->count() > 0;
        $countNonZero = 0 !== Order::find()->where(['status' => 4])->count();
        $countEmpty = Order::find()->where(['status' => 5])->count() <= 0;
        $countAtLeastOne = 1 <= Order::find()->where(['status' => 6])->count();

        $total = Order::find()->count();
        $row = Order::find()->one();
        $moreThanOne = Order::find()->count() > 1;
        $utility = Utility::find()->count() > 0;
    }
}

final class ChildOrder extends Order
{
    public function childExample(): void
    {
        $empty = ChildOrder::find()->count() === 0;
    }
}

final class Utility
{
    public static function find(): object
    {
        return new \stdClass();
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2QueryExistenceAnalyzer())->references($context);
    assert(count($references) === 7);
    assert(array_column($references, 'source_method') === ['one', 'one', 'count', 'count', 'count', 'count', 'count']);
    assert(array_column($references, 'replacement') === [
        'exists()',
        '!exists()',
        'exists()',
        'exists()',
        '!exists()',
        'exists()',
        '!exists()',
    ]);
    assert(array_column($references, 'query_on_left') === [true, false, true, false, true, false, true]);
    assert(!in_array('app\\common\\models\\Utility', array_column($references, 'model'), true));
    foreach ($references as $reference) {
        assert($reference['offset'] >= 0);
        assert($reference['length'] > 0);
        assert(str_contains($reference['replacement_code'], '->exists()'));
    }

    $model = Yii2SemanticModel::fromContext($context);
    $findings = (new Yii2RuleEngine())->analyse($model, $context);
    $performanceFindings = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::REDUNDANT_EXISTENCE_CHECK,
    ));

    assert(count($performanceFindings) === 7);
    foreach ($performanceFindings as $finding) {
        assert($finding->file === 'common/models/Order.php');
        assert($finding->line > 0);
        assert($finding->severity === 'warning');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-performance');
        assert(($finding->metadata['category'] ?? null) === 'performance');
        assert(($finding->metadata['autofix'] ?? null) === true);
        assert(($finding->metadata['remediation_risk'] ?? null) === 'safe');
        assert(str_contains((string) ($finding->metadata['replacement_code'] ?? ''), '->exists()'));
    }

    $result = (new Yii2SafeRemediator())->apply($context);
    assert($result['changed_files'] === ['common/models/Order.php']);
    assert($result['changes'] === 7);

    $fixed = (string) file_get_contents($root . '/common/models/Order.php');
    assert(str_contains($fixed, "$oneExists = Order::find()->where(['status' => 1])->exists();"));
    assert(str_contains($fixed, "$oneMissing = !Order::find()->where(['status' => 2])->exists();"));
    assert(str_contains($fixed, "$countPositive = Order::find()->where(['status' => 3])->exists();"));
    assert(str_contains($fixed, "$countNonZero = Order::find()->where(['status' => 4])->exists();"));
    assert(str_contains($fixed, "$countEmpty = !Order::find()->where(['status' => 5])->exists();"));
    assert(str_contains($fixed, "$countAtLeastOne = Order::find()->where(['status' => 6])->exists();"));
    assert(str_contains($fixed, '$empty = !ChildOrder::find()->exists();'));
    assert(str_contains($fixed, '$total = Order::find()->count();'));
    assert(str_contains($fixed, '$row = Order::find()->one();'));
    assert(str_contains($fixed, '$moreThanOne = Order::find()->count() > 1;'));
    assert(str_contains($fixed, '$utility = Utility::find()->count() > 0;'));

    // PERF-001 prevalece sobre MOD-001 quando ambos cobrem a mesma chain e o segundo run precisa ser idempotente.
    $second = (new Yii2SafeRemediator())->apply($context);
    assert($second['changed_files'] === []);
    assert($second['changes'] === 0);

    echo "[OK] Yii2 PERF-001 reconhece e corrige checks de existência com patch SAFE idempotente.\n";
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
