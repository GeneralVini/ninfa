<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-model-rules-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-model-rules-workspace-' . $token;
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
        $root . '/common/models/Models.php',
        <<<'PHP'
<?php

namespace app\common\models;

class SignupForm extends \yii\base\Model
{
    public string $name = '';
    public string $email = '';
    public static string $ignoredStatic = '';

    public function rules(): array
    {
        return [
            [['name', 'email'], 'required'],
            ['emial', 'string'],
            [['name', 'missing'], 'safe'],
        ];
    }
}

class ChildForm extends SignupForm
{
    public string $code = '';

    public function rules(): array
    {
        return [
            ['name', 'required'],
            ['code', 'string'],
        ];
    }
}

class ExplicitRecord extends \yii\db\ActiveRecord
{
    public function attributes(): array
    {
        return ['id', 'status'];
    }

    public function rules(): array
    {
        return [
            ['status', 'string'],
            ['statuz', 'string'],
        ];
    }
}

class RuntimeRecord extends \yii\db\ActiveRecord
{
    public function rules(): array
    {
        return [['not_provable', 'string']];
    }
}

trait ExtraAttributes
{
    public string $fromTrait = '';
}

class TraitForm extends \yii\base\Model
{
    use ExtraAttributes;

    public string $name = '';

    public function rules(): array
    {
        return [['not_provable', 'string']];
    }
}

class DynamicAttributesForm extends \yii\base\Model
{
    public string $name = '';

    public function attributes(): array
    {
        return parent::attributes();
    }

    public function rules(): array
    {
        return [['not_provable', 'string']];
    }
}

class DynamicRulesForm extends \yii\base\Model
{
    public string $name = '';

    public function rules(): array
    {
        $rules = [['missing', 'string']];
        return $rules;
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $analyzer = new Yii2ModelRulesAnalyzer();
    $inventories = $analyzer->inventories($context);

    $signup = $inventories['app\\common\\models\\SignupForm'] ?? null;
    assert($signup !== null);
    assert($signup['complete'] === true);
    assert($signup['source'] === 'public-properties');
    assert($signup['attributes'] === ['email', 'name']);

    $child = $inventories['app\\common\\models\\ChildForm'] ?? null;
    assert($child !== null);
    assert($child['complete'] === true);
    assert($child['source'] === 'inherited');
    assert($child['attributes'] === ['code', 'email', 'name']);

    $record = $inventories['app\\common\\models\\ExplicitRecord'] ?? null;
    assert($record !== null);
    assert($record['complete'] === true);
    assert($record['source'] === 'literal-attributes');
    assert($record['active_record'] === true);
    assert($record['attributes'] === ['id', 'status']);

    assert(($inventories['app\\common\\models\\RuntimeRecord']['complete'] ?? true) === false);
    assert(($inventories['app\\common\\models\\TraitForm']['complete'] ?? true) === false);
    assert(($inventories['app\\common\\models\\DynamicAttributesForm']['complete'] ?? true) === false);

    $references = $analyzer->references($context);
    assert(count($references) === 3);
    assert(array_column($references, 'attribute') === ['emial', 'missing', 'statuz']);
    assert(array_column($references, 'validator') === ['string', 'safe', 'string']);
    foreach ($references as $reference) {
        assert($reference['attribute_inventory_complete'] === true);
    }

    $model = Yii2SemanticModel::fromContext($context);
    $findings = array_values(array_filter(
        (new Yii2RuleEngine())->analyse($model, $context),
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::MODEL_RULE_ATTRIBUTE_NOT_FOUND,
    ));
    assert(count($findings) === 3);
    foreach ($findings as $finding) {
        assert($finding->severity === 'error');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-correctness');
        assert($finding->provenance === ['ninfa:yii2-model-rules-analyzer']);
        assert(($finding->metadata['category'] ?? null) === 'correctness');
        assert(($finding->metadata['attribute_inventory_complete'] ?? null) === true);
        assert(($finding->metadata['autofix'] ?? null) === false);
    }

    echo "[OK] Yii2 COR-006 valida atributos literais de rules() somente com inventário conclusivo.\n";
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
