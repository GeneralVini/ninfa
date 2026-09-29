<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Yii2CachingDeprecationAnalyzer.php';
require_once dirname(__DIR__) . '/src/Yii2SafeRemediator.php';

$token = bin2hex(random_bytes(4));
$root = sys_get_temp_dir() . '/ninfa-yii2-typed-deprecation-' . $token;
$workspaceRoot = sys_get_temp_dir() . '/ninfa-yii2-typed-deprecation-workspace-' . $token;
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
        $root . '/src/CacheConsumer.php',
        <<<'PHP'
<?php

namespace app;

use yii\caching\Cache;
use yii\caching\Dependency;

final class LocalCache extends Cache
{
}

final class LocalDependency extends Dependency
{
}

final class CacheConsumer
{
    private Cache $cache;
    private LocalDependency $dependency;

    public function byParameter(Cache $cache, Dependency $dependency): void
    {
        $cache->mget(['a', 'b']);
        $cache->mset(['a' => 1]);
        $cache->madd(['b' => 2]);
        $dependency->getHasChanged($cache);
    }

    public function byProperty(): void
    {
        $this->cache->mget(['a']);
        $this->dependency->getHasChanged($this->cache);
    }

    public function byLocalNew(): void
    {
        $cache = new LocalCache();
        $dependency = new LocalDependency();
        $cache->mset(['a' => 1]);
        $dependency->getHasChanged($cache);
    }

    public function noProof($cache, $dependency): void
    {
        $cache->mget(['x']);
        $dependency->getHasChanged($cache);
    }
}
PHP,
    );

    file_put_contents(
        $root . '/src/UnknownConsumer.php',
        <<<'PHP'
<?php

namespace app;

final class UnknownConsumer
{
    public function run(RuntimeCache $cache): void
    {
        $cache->mget(['x']);
    }
}
PHP,
    );

    $context = ProjectContext::fromRoot($root);
    assert($context->profile() === 'yii2');

    $references = (new Yii2CachingDeprecationAnalyzer())->references($context);
    assert(count($references) === 8);
    assert(array_count_values(array_column($references, 'rule')) === [
        'NINFA-YII2-DEP-004' => 5,
        'NINFA-YII2-DEP-005' => 3,
    ]);
    assert(array_count_values(array_column($references, 'replacement')) === [
        'multiGet' => 2,
        'multiSet' => 2,
        'multiAdd' => 1,
        'isChanged' => 3,
    ]);

    foreach ($references as $reference) {
        assert($reference['file'] === 'src/CacheConsumer.php');
        assert($reference['line'] > 0);
        assert($reference['offset'] >= 0);
        assert($reference['length'] > 0);
    }

    $result = (new Yii2SafeRemediator())->apply($context);
    assert($result['changed_files'] === ['src/CacheConsumer.php']);
    assert($result['changes'] === 8);

    $fixed = (string) file_get_contents($root . '/src/CacheConsumer.php');
    assert(substr_count($fixed, '->multiGet(') === 2);
    assert(substr_count($fixed, '->multiSet(') === 2);
    assert(substr_count($fixed, '->multiAdd(') === 1);
    assert(substr_count($fixed, '->isChanged(') === 3);
    assert(str_contains($fixed, '$cache->mget([\'x\']);'));
    assert(str_contains($fixed, '$dependency->getHasChanged($cache);'));

    $unknown = (string) file_get_contents($root . '/src/UnknownConsumer.php');
    assert(str_contains($unknown, '$cache->mget([\'x\']);'));

    // Reexecutar sobre o resultado corrigido precisa ser idempotente.
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
