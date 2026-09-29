<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2ClassNameDeprecationAnalyzer.php';
require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';
require_once dirname(__DIR__) . '/src/Yii2SafeRemediator.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-classname-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-classname-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/src', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents(
        $root . '/src/ClassNameConsumer.php',
        <<<'PHP'
<?php

namespace app;

use yii\base\BaseObject;

class LocalObject extends BaseObject
{
    public static function examples(): array
    {
        return [
            LocalObject::className(),
            static::className(),
            self::className(),
            parent::className(),
        ];
    }
}

class ChildObject extends LocalObject
{
}

final class ClassNameConsumer
{
    public function run(): array
    {
        $ignoredString = 'LocalObject::className()';
        // LocalObject::className() não pode ser interpretado como código.
        return [
            LocalObject::className(),
            ChildObject::className(),
            BaseObject::className(),
        ];
    }
}

class UnknownObject extends RuntimeBase
{
    public static function unresolved(): string
    {
        return static::className();
    }
}

final class UnknownConsumer
{
    public function run(): string
    {
        return RuntimeBase::className();
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2ClassNameDeprecationAnalyzer())->references($context);
    assert(count($references) === 5);
    foreach ($references as $reference) {
        assert($reference['file'] === 'src/ClassNameConsumer.php');
        assert($reference['rule'] === 'NINFA-YII2-DEP-006');
        assert($reference['kind'] === 'class-name');
        assert($reference['replacement'] === 'class');
        assert($reference['line'] > 0);
        assert($reference['offset'] >= 0);
        assert($reference['length'] > 0);
    }

    // `assist` precisa reutilizar exatamente a mesma evidência usada por `fix`.
    $model = Yii2SemanticModel::fromContext($context);
    $findings = (new Yii2RuleEngine())->analyse($model, $context);
    $classNameFindings = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::CLASS_NAME_DEPRECATED,
    ));
    assert(count($classNameFindings) === 5);
    foreach ($classNameFindings as $finding) {
        assert($finding->file === 'src/ClassNameConsumer.php');
        assert($finding->severity === 'warning');
        assert($finding->confidence === 'high');
        assert($finding->evidenceType === 'framework-deprecation');
        assert($finding->provenance === ['ninfa:yii2-classname-deprecation-analyzer']);
        assert(($finding->metadata['category'] ?? null) === 'deprecation');
        assert(($finding->metadata['replacement'] ?? null) === 'class');
        assert(($finding->metadata['remediation_risk'] ?? null) === 'safe');
        assert(($finding->metadata['autofix'] ?? null) === true);
    }

    $result = (new Yii2SafeRemediator())->apply($context);
    assert($result['changed_files'] === ['src/ClassNameConsumer.php']);
    assert($result['changes'] === 5);

    $fixed = (string) file_get_contents($root . '/src/ClassNameConsumer.php');
    assert(substr_count($fixed, 'LocalObject::class') === 2);
    assert(substr_count($fixed, 'ChildObject::class') === 1);
    assert(substr_count($fixed, 'BaseObject::class') === 1);
    assert(substr_count($fixed, 'static::class') === 1);
    assert(str_contains($fixed, 'self::className()'));
    assert(str_contains($fixed, 'parent::className()'));
    assert(str_contains($fixed, "'LocalObject::className()'"));
    assert(str_contains($fixed, '// LocalObject::className() não pode ser interpretado como código.'));
    assert(str_contains($fixed, 'return static::className();'));
    assert(str_contains($fixed, 'return RuntimeBase::className();'));

    // Segunda execução comprova idempotência do patch SAFE.
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
