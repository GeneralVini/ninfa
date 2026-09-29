<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';
require_once dirname(__DIR__) . '/src/Yii2SafeRemediator.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-where-equality-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-where-equality-workspace-' . $token;
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
    public function examples(string $status, int $tenantId): void
    {
        $concat = Order::find()->where('status = ' . $status)->all();
        $interpolated = Order::find()->andWhere("tenant_id = $tenantId")->one();

        $hash = Order::find()->where(['status' => $status])->all();
        $complex = Order::find()->where('status = ' . trim($status))->all();
        $compound = Order::find()->where('status = ' . $status . ' AND active = 1')->all();
        $literal = Order::find()->where('status = active')->all();
        $stored = Order::find();
        $stored->where('status = ' . $status)->all();

        $example = "Order::find()->where('status = ' . $status)";
        // Order::find()->where('status = ' . $status) é apenas documentação local.
    }
}

final class ChildOrder extends Order
{
    public function inherited(string $status): void
    {
        ChildOrder::find()->orWhere('status = ' . $status)->all();
    }
}

final class Utility
{
    public static function find(): object
    {
        return new \stdClass();
    }

    public function ignored(string $status): void
    {
        Utility::find()->where('status = ' . $status);
    }
}

final class RuntimeOrder extends RuntimeActiveRecord
{
    public function ignored(string $status): void
    {
        RuntimeOrder::find()->where('status = ' . $status);
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2WhereEqualityAnalyzer())->references($context);
    if (count($references) !== 3) {
        throw new RuntimeException('SEC-001 detector diagnostics: ' . json_encode($references, JSON_UNESCAPED_SLASHES));
    }
    assert(array_column($references, 'method') === ['where', 'andWhere', 'orWhere']);
    assert(array_column($references, 'column') === ['status', 'tenant_id', 'status']);
    assert(array_column($references, 'style') === ['concat', 'interpolated', 'concat']);
    assert(array_column($references, 'replacement') === [
        "['status' => \$status]",
        "['tenant_id' => \$tenantId]",
        "['status' => \$status]",
    ]);

    $source = (string) file_get_contents($root . '/common/models/Order.php');
    assert(substr($source, $references[0]['offset'], $references[0]['length']) === "'status = ' . \$status");
    assert(substr($source, $references[1]['offset'], $references[1]['length']) === '"tenant_id = $tenantId"');

    $model = Yii2SemanticModel::fromContext($context);
    $findings = (new Yii2RuleEngine())->analyse($model, $context);
    $securityFindings = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::DYNAMIC_WHERE_EQUALITY,
    ));

    assert(count($securityFindings) === 3);
    foreach ($securityFindings as $finding) {
        assert($finding->severity === 'warning');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-security-smell');
        assert($finding->provenance === ['ninfa:yii2-where-equality-analyzer']);
        assert(($finding->metadata['category'] ?? null) === 'security');
        assert(($finding->metadata['finding_kind'] ?? null) === 'security-smell');
        assert(($finding->metadata['taint_proven'] ?? null) === false);
        assert(($finding->metadata['remediation_risk'] ?? null) === 'review');
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    $before = (string) file_get_contents($root . '/common/models/Order.php');
    (new Yii2SafeRemediator())->apply($context);
    $after = (string) file_get_contents($root . '/common/models/Order.php');
    assert($after === $before);

    echo "[OK] Yii2 SEC-001 detecta igualdade SQL dinâmica simples sem promovê-la a autofix/vulnerabilidade.\n";
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
