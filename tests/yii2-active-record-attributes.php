<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-active-record-attributes-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-active-record-attributes-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/common/models', 0775, true);
    mkdir($root . '/common/services', 0775, true);

    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/common/models/Records.php', <<<'PHP'
<?php

namespace app\common\models;

final class ExplicitRecord extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return ['id', 'status', 'views'];
    }
}

final class RuntimeRecord extends \yii\db\ActiveRecord
{
}
PHP);

    file_put_contents($root . '/common/services/RecordService.php', <<<'PHP'
<?php

namespace app\common\services;

use app\common\models\ExplicitRecord as Record;
use app\common\models\RuntimeRecord;

final class RecordService
{
    public function run(string $dynamicKey): void
    {
        Record::findOne(['id' => 1, 'missingCondition' => 2]);
        Record::findAll(['status' => 'open']);
        Record::deleteAll(['missingDelete' => 1]);

        Record::updateAll(
            ['status' => 'closed', 'missingValue' => 10],
            ['id' => 1, 'missingWhere' => 2],
        );

        Record::updateAllCounters(
            ['views' => 1, 'missingCounter' => 1],
            ['status' => 'open'],
        );

        Record::findOne(['=', 'missingOperatorColumn', 1]);
        Record::findOne([$dynamicKey => 1]);
        RuntimeRecord::findOne(['notProvable' => 1]);
    }
}
PHP);

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2ActiveRecordAttributeAnalyzer())->references($context);
    assert(count($references) === 5);
    assert(array_column($references, 'attribute') === [
        'missingCondition',
        'missingDelete',
        'missingValue',
        'missingWhere',
        'missingCounter',
    ]);
    assert(array_column($references, 'role') === [
        'condition',
        'condition',
        'attributes',
        'condition',
        'counters',
    ]);
    foreach ($references as $reference) {
        assert($reference['model'] === 'app\\common\\models\\ExplicitRecord');
        assert($reference['attribute_inventory_complete'] === true);
        assert($reference['line'] > 0);
    }

    $model = Yii2SemanticModel::fromContext($context);
    $findings = array_values(array_filter(
        (new Yii2RuleEngine())->analyse($model, $context),
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::ACTIVE_RECORD_ATTRIBUTE_NOT_FOUND,
    ));
    assert(count($findings) === 5);
    foreach ($findings as $finding) {
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-correctness');
        assert($finding->provenance === ['ninfa:yii2-active-record-attribute-analyzer']);
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['attribute_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    echo "[OK] Yii2 COR-010 valida hash conditions/updates apenas com inventário ActiveRecord conclusivo.\n";
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
