<?php

declare(strict_types=1);

$projectRoot = $argv[1] ?? getcwd();
$projectRoot = realpath($projectRoot) ?: $projectRoot;

if (!is_file($projectRoot . '/composer.json')) {
    fwrite(STDERR, "[ERRO] composer.json nÃ£o encontrado.\n");
    exit(1);
}

$composer = json_decode((string) file_get_contents($projectRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$packages = array_merge(array_keys($composer['require'] ?? []), array_keys($composer['require-dev'] ?? []));

$framework = 'PHP genÃ©rico';
if (in_array('laravel/framework', $packages, true)) {
    $framework = 'Laravel';
} elseif (in_array('yiisoft/yii2', $packages, true)) {
    $framework = 'Yii 2';
} elseif (array_filter($packages, static fn(string $p): bool => str_starts_with($p, 'yiisoft/'))) {
    $framework = 'Yii 3';
} elseif (in_array('symfony/framework-bundle', $packages, true)) {
    $framework = 'Symfony';
}

$candidates = ['src', 'app', 'config', 'modules', 'console', 'commands', 'public', 'web', 'tests'];
$paths = [];
foreach ($candidates as $path) {
    if (is_dir($projectRoot . '/' . $path)) {
        $paths[] = $path;
    }
}
if ($paths === []) {
    $paths = ['src'];
    echo "[AVISO] Estrutura nÃ£o convencional; usando src como baseline.\n";
}

$docs = [];
if (is_file($projectRoot . '/README.md')) {
    $docs[] = 'README.md';
}
foreach (glob($projectRoot . '/docs/*.md') ?: [] as $file) {
    $docs[] = 'docs/' . basename($file);
}

$contextDir = $projectRoot . '/.ninfa';
if (!is_dir($contextDir)) {
    mkdir($contextDir, 0775, true);
}
file_put_contents(
    $contextDir . '/context.json',
    json_encode(['framework' => $framework, 'paths' => $paths, 'documentation' => $docs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
);

$phpPaths = implode(",\n", array_map(static fn(string $p): string => "        __DIR__ . '/{$p}'", $paths));
$yamlPaths = implode("\n", array_map(static fn(string $p): string => "    - {$p}", $paths));
$xmlPaths = implode("\n", array_map(static fn(string $p): string => "        <directory name=\"{$p}\" />", $paths));

$configs = [
    'ecs.php' => "<?php\n\ndeclare(strict_types=1);\n\nuse Symplify\\EasyCodingStandard\\Config\\ECSConfig;\n\nreturn ECSConfig::configure()\n    ->withPaths([\n{$phpPaths|±q¸€€€t¥q¸€€€€´ùÝ¥Ñ¡I½½Ñ¥±•Ì ¥q¸€€€€´ùÝ¥Ñ¡AÉ•Á…É•‘M•ÑÌ¡ÁÍÈÄÈèÑÉÕ”¤íq¸ˆ°(€€€€É•Ñ½È¹Á¡Àœ€ôø€ˆðýÁ¡Áq¹q¹‘•±…É”¡ÍÑÉ¥Ñ}ÑåÁ•ÌôÄ¤íq¹q¹ÕÍ”I•Ñ½Éqq½¹™¥qqI•Ñ½É½¹™¥œíq¹q¹É•ÑÕÉ¸I•Ñ½É½¹™¥œèé½¹™¥ÕÉ” ¥q¸€€€€´ùÝ¥Ñ¡A…Ñ¡Ì¡mq¹ì‘Á¡ÁA…Ñ¡Íô±q¸€€€t¥q¸€€€€´ùÝ¥Ñ¡AÉ•Á…É•‘M•ÑÌ¡‘•…‘½‘”èÑÉÕ”°½‘•EÕ…±¥ÑäèÑÉÕ”°ÑåÁ••±…É…Ñ¥½¹ÌèÑÉÕ”¤íq¸ˆ°(€€€€Á¡ÁÍÑ…¸¹¹•½¸¹‘¥ÍÐœ€ôø€‰Á…É…µ•Ñ•ÉÌéq¸€±•Ù•°èµ…áq¸€Á…Ñ¡Ìéq¹ì‘å…µ±A…Ñ¡Íõq¸€ÑµÁ¥ÈèÉÕ¹Ñ¥µ”½Á¡ÁÍÑ…¹q¸ˆ°(€€€€ÁÍ…±´¹áµ°œ€ôø€ˆðýáµ°Ù•ÉÍ¥½¸õpˆÄ¸ÁpˆüøñÁÍ…±´•ÉÉ½É1•Ù•°õpˆÅpˆÉ•Í½±Ù•É½µ½¹™¥¥±”õp‰ÑÉÕ•pˆáµ±¹Ìõp‰¡ÑÑÁÌè¼½•ÑÁÍ…±´¹½Éœ½Í¡•µ„½½¹™¥pˆùq¸€€€€ñÁÉ½©•Ñ¥±•Ìùq¹íáµ±A…Ñ¡Íõq¸€€€€€€€€ñ¥¹½É•¥±•Ìùq¸€€€€€€€€€€€€ñ‘¥É•Ñ½Éä¹…µ”õp‰Ù•¹‘½Épˆ€¼ùq¸€€€€€€€€€€€€ñ‘¥É•Ñ½Éä¹…µ”õpˆ¹Ñ½½±Ípˆ€¼ùq¸€€€€€€€€ð½¥¹½É•¥±•Ìùq¸€€€€ð½ÁÉ½©•Ñ¥±•Ìùq¸ð½ÁÍ…±´ùq¸ˆ°)tì()¥˜€¡¥¹}…ÉÉ…ä Ñ•ÍÑÌœ°€‘Á…Ñ¡Ì°ÑÉÕ”¤¤ì(€€€€‘½¹™¥ÍlÁ¡ÁÕ¹¥Ð¹áµ°¹‘¥ÍÐt€ô€ˆðýáµ°Ù•ÉÍ¥½¸õpˆÄ¸Ápˆ•¹½‘¥¹œõp‰UQ´ápˆüùq¸ñÁ¡ÁÕ¹¥Ð‰½½ÑÍÑÉ…Àõp‰Ù•¹‘½È½…ÕÑ½±½…¹Á¡Ápˆ…¡•¥É•Ñ½Éäõp‰ÉÕ¹Ñ¥µ”½Á¡ÁÕ¹¥Ñpˆùq¸€€€€ñÑ•ÍÑÍÕ¥Ñ•Ìùq¸€€€€€€€€ñÑ•ÍÑÍÕ¥Ñ”¹…µ”õp‰AÉ½©•Ñpˆùq¸€€€€€€€€€€€€ñ‘¥É•Ñ½ÉäùÑ•ÍÑÌð½‘¥É•Ñ½Éäùq¸€€€€€€€€ð½Ñ•ÍÑÍÕ¥Ñ”ùq¸€€€€ð½Ñ•ÍÑÍÕ¥Ñ•Ìùq¸ð½Á¡ÁÕ¹¥Ðùq¸ˆì)ô()™½É•… € ‘½¹™¥Ì…Ì€‘É•±…Ñ¥Ù”€ôø€‘‰½‘ä¤ì(€€€€‘Ñ…É•Ð€ô€‘ÁÉ½©•ÑI½½Ð€¸€œ¼œ€¸€‘É•±…Ñ¥Ù”ì(€€€¥˜€¡¥Í}™¥±” ‘Ñ…É•Ð¤¤ì(€€€€€€€•¡¼€‰m59Q%=t€‘ì‘É•±…Ñ¥Ù•ô«„•á¥ÍÑ”¹q¸ˆì(€€€€€€€½¹Ñ¥¹Õ”ì(€€€ô(€€€™¥±•}ÁÕÑ}½¹Ñ•¹ÑÌ ‘Ñ…É•Ð°€‘‰½‘ä¤ì(€€€•¡¼€‰mI=t€‘ì‘É•±…Ñ¥Ù•õq¸ˆì)ô()™¥±•}ÁÕÑ}½¹Ñ•¹ÑÌ ‘½¹Ñ•áÑ¥È€¸€œ½Í•µÉ•ÀµÑ…É•ÑÌ¹ÑáÐœ°¥µÁ±½‘”¡A!A}=0°€‘Á…Ñ¡Ì¤€¸A!A}=0¤ì()•¡¼€‰q¹m9%9tÉ…µ•Ý½É¬èì‘™É…µ•Ý½É­õq¸ˆì)•¡¼€m9%9t…µ¥¹¡½Ì‘•Ñ•Ñ…‘½Ìè€œ€¸¥µÁ±½‘” œ°€œ°€‘Á…Ñ¡Ì¤€¸A!A}=0ì)•¡¼€m9%9t½Õµ•¹Ñ‡Ÿ¼½¹ÍÕ±Ñ…‘„è€œ€¸€ ‘‘½Ì€ôôômt€ü€¹•¹¡Õµ„œ€è¥µÁ±½‘” œ°€œ°€‘‘½Ì¤¤€¸!A}=0ì(