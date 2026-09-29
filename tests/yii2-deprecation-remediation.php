<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2RuleEngine.php';
require_once dirname(__DIR__) . '/src/Yii2SafeRemediator.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-deprecation-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-deprecation-workspace-' . $token;
putenv('NINFA_WORKSPACE_ROOT=' . $workspaceRoot);

try {
    mkdir($root . '/console/controllers', 0775, true);
    file_put_contents($root . '/composer.json', json_encode([
        'require' => [
            'php' => '>=8.2',
            'yiisoft/yii2' => '^2.0.53',
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents(
        $root . '/console/controllers/HealthController.php',
        <<<'PHP'
<?php

namespace app\console\controllers;

use yii\console\Controller;

final class HealthController extends Controller
{
    public function actionOk(): int
    {
        \Yii::trace('health');
        return 0;
    }

    public function actionFail(): int
    {
        return 1;
    }

    public function legacyConstant(): int
    {
        return Controller::EXIT_CODE_ERROR;
    }
}
PHP,
    );

    file_put_contents(
        $root . '/console/controllers/OtherController.php',
        <<<'PHP'
<?php

namespace app\console\controllers;

final class OtherController extends RuntimeController
{
    public const EXIT_CODE_ERROR = 99;

    public function actionNoProof(): int
    {
        return 1;
    }

    public function legacyConstant(): int
    {
        return self::EXIT_CODE_ERROR;
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2DeprecationAnalyzer())->references($context);
    assert(count($references) === 4);
    assert(array_count_values(array_column($references, 'rule')) === [
        'NINFA-YII2-DEP-001' => 1,
        'NINFA-YII2-DEP-003' => 2,
        'NINFA-YII2-DEP-002' => 1,
    ]);
    foreach ($references as $reference) {
        assert($reference['file'] === 'console/controllers/HealthController.php');
        assert($reference['line'] > 0);
        assert($reference['offset'] >= 0);
        assert($reference['length'] > 0);
    }

    $model = Yii2SemanticModel::fromContext($context);
    $findings = (new Yii2RuleEngine())->analyse($model, $context);
    $deprecations = array_values(array_filter(
        $findings,
        static fn (Finding $finding): bool => str_starts_with($finding->rule, 'NINFA-YII2-DEP-'),
    ));
    assert(count($deprecations) === 4);
    foreach ($deprecations as $finding) {
        assert($finding->severity === 'warning');
        assert($finding->confidence === 'high');
        assert(($finding->metadata['category'] ?? null) === 'deprecation');
        assert(($finding->metadata['remediation_risk'] ?? null) === 'safe');
        assert(($finding->metadata['autofix'] ?? null) === true);
    }

    $result = (new Yii2SafeRemediator())->apply($context);
    assert($result['changed_files'] === ['console/controllers/HealthController.php']);
    assert($result['changes'] === 4);

    $fixed = (string) file_get_contents($root . '/console/controllers/HealthController.php');
    assert(str_contains($fixed, '\\Yii::debug('));
    assert(str_contains($fixed, 'return \\yii\\console\\ExitCode::OK;'));
    assert(str_contains($fixed, 'return \\yii\\console\\ExitCode::UNSPECIFIED_ERROR;'));
    assert(str_contains($fixed, 'return \\yii\\console\\ExitCode::UNSPECIFIED_ERROR;'));
    assert(!str_contains($fixed, 'Yii::trace('));
    assert(!str_contains($fixed, 'Controller::EXIT_CODE_ERROR'));

    // Segunda aplicação precisa ser idempotente e não regravar nenhum arquivo.
    $second = (new Yii2SafeRemediator())->apply($context);
    assert($second['changed_files'] === []);
    assert($second['changes'] === 0);

    $other = (string) file_get_contents($root . '/console/controllers/OtherController.php');
    assert(str_contains($other, 'return 1;'));
    assert(str_contains($other, 'self::EXIT_CODE_ERROR'));
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
