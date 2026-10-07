<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-model-metadata-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-model-metadata-workspace-' . $token;
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
        $root . '/common/models/MetadataModels.php',
        <<<'PHP'
<?php

namespace app\common\models;

class ProfileForm extends \yii\base\Model
{
    public string $name = '';
    public string $email = '';

    public function rules(): array
    {
        return [['name', 'required'], ['email', 'email']];
    }

    public function scenarios(): array
    {
        return [
            'default' => ['name', '!email', 'missingScenario', '!'],
            '' => ['name'],
            'dynamic' => $this->dynamicScenario(),
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Name',
            'missingLabel' => 'Missing',
            '' => 'Empty',
        ];
    }

    public function attributeHints(): array
    {
        return [
            'email' => 'Email',
            'missingHint' => 'Missing',
            '' => 'Empty',
        ];
    }
}

class RuntimeRecord extends \yii\db\ActiveRecord
{
    public function scenarios(): array
    {
        return ['default' => ['notProvable']];
    }

    public function attributeLabels(): array
    {
        return ['notProvable' => 'No proof'];
    }

    public function attributeHints(): array
    {
        return ['notProvable' => 'No proof'];
    }
}

class DynamicMetadataForm extends \yii\base\Model
{
    public string $name = '';

    public function scenarios(): array
    {
        $value = ['name'];
        return ['dynamic' => $value];
    }

    public function attributeLabels(): array
    {
        $labels = ['missing' => 'Missing'];
        return $labels;
    }

    public function attributeHints(): array
    {
        return array_merge([], ['missing' => 'Missing']);
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $analyzer = new Yii2ModelMetadataAnalyzer();
    $references = $analyzer->references($context);
    assert(count($references) === 7);

    $byKind = [];
    foreach ($references as $reference) {
        $byKind[$reference['kind']][] = $reference;
        assert($reference['attribute_inventory_complete'] === true);
        assert($reference['model'] === 'app\\common\\models\\ProfileForm');
    }

    assert(count($byKind['scenario-name'] ?? []) === 1);
    assert(count($byKind['scenario-attribute'] ?? []) === 2);
    assert(count($byKind['attribute-label'] ?? []) === 2);
    assert(count($byKind['attribute-hint'] ?? []) === 2);

    $scenarioNames = array_column($byKind['scenario-attribute'], 'name');
    sort($scenarioNames);
    assert($scenarioNames === ['', 'missingScenario']);

    $labels = array_column($byKind['attribute-label'], 'name');
    sort($labels);
    assert($labels === ['', 'missingLabel']);

    $hints = array_column($byKind['attribute-hint'], 'name');
    sort($hints);
    assert($hints === ['', 'missingHint']);

    $model = Yii2SemanticModel::fromContext($context);
    $findings = (new Yii2RuleEngine())->analyse($model, $context);

    $cor007 = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::MODEL_SCENARIO_INVALID,
    ));
    $cor008 = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::MODEL_ATTRIBUTE_LABEL_INVALID,
    ));
    $cor009 = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::MODEL_ATTRIBUTE_HINT_INVALID,
    ));

    assert(count($cor007) === 3);
    assert(count($cor008) === 2);
    assert(count($cor009) === 2);

    foreach ([...$cor007, ...$cor008, ...$cor009] as $finding) {
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-correctness');
        assert($finding->provenance === ['ninfa:yii2-model-metadata-analyzer']);
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['attribute_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    echo "[OK] Yii2 COR-007..009 validam scenarios/labels/hints somente com inventário conclusivo.\n";
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
