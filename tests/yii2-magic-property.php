<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-magic-property-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-magic-property-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/common/models', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents($root . '/common/models/Customer.php', <<<'PHP'
<?php
namespace app\common\models;
final class Customer extends \yii\db\ActiveRecord
{
}
PHP);

    file_put_contents($root . '/common/models/Item.php', <<<'PHP'
<?php
namespace app\common\models;
final class Item extends \yii\db\ActiveRecord
{
}
PHP);

    file_put_contents($root . '/common/models/Order.php', <<<'PHP'
<?php
namespace app\common\models;
/**
 * Pedido com contrato PHPDoc já mantido pelo projeto.
 *
 * @property-read int $id
 */
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
}
PHP);

    file_put_contents($root . '/common/models/Invoice.php', <<<'PHP'
<?php
namespace app\common\models;
/**
 * Fatura cuja relação já está documentada.
 *
 * @property-read \app\common\models\Customer|null $customer
 */
final class Invoice extends \yii\db\ActiveRecord
{
    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }
}
PHP);

    file_put_contents($root . '/common/models/Undocumented.php', <<<'PHP'
<?php
namespace app\common\models;
/** Modelo que ainda não adotou tags de properties. */
final class Undocumented extends \yii\db\ActiveRecord
{
    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }
}
PHP);

    file_put_contents($root . '/common/models/CustomMagic.php', <<<'PHP'
<?php
namespace app\common\models;
/**
 * Modelo com resolução mágica própria.
 *
 * @property-read int $id
 */
final class CustomMagic extends \yii\db\ActiveRecord
{
    public function __get($name): mixed
    {
        return parent::__get($name);
    }

    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }
}
PHP);

    file_put_contents($root . '/common/models/NativeProperty.php', <<<'PHP'
<?php
namespace app\common\models;
/**
 * Modelo que expõe a relação como propriedade nativa.
 *
 * @property-read int $id
 */
final class NativeProperty extends \yii\db\ActiveRecord
{
    public ?Customer $customer = null;

    public function getCustomer(): mixed
    {
        return $this->hasOne(Customer::class, ['id' => 'customer_id']);
    }
}
PHP);

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');
    $model = Yii2SemanticModel::fromContext($context);

    $references = (new Yii2MagicPropertyAnalyzer())->references($context, $model);
    assert(count($references) === 2);
    assert(array_column($references, 'relation') === ['customer', 'items']);
    assert($references[0]['model'] === 'app\\common\\models\\Order');
    assert($references[0]['expected_type'] === '\\app\\common\\models\\Customer|null');
    assert($references[0]['expected_tag'] === '@property-read \\app\\common\\models\\Customer|null $customer');
    assert($references[1]['expected_type'] === '\\app\\common\\models\\Item[]');

    $findings = (new Yii2RuleEngine())->analyse($model, $context);
    $typeFindings = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => $finding->rule === Yii2RuleEngine::MAGIC_PROPERTY_MISSING,
    ));
    assert(count($typeFindings) === 2);
    foreach ($typeFindings as $finding) {
        assert($finding->severity === 'warning');
        assert($finding->confidence === 'high');
        assert(($finding->metadata['category'] ?? null) === 'static-analysis');
        assert(($finding->metadata['remediation_risk'] ?? null) === 'semantic');
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
