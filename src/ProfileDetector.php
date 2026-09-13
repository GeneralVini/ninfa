<?php

declare(strict_types=1);

final class ProfileDetector
{
    /** @param array<string, mixed> $composer */
    public function detect(string $projectRoot, array $composer): string
    {
        $packages = array_keys(array_merge(
            is_array($composer['require'] ?? null) ? $composer['require'] : [],
            is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
        ));

        if ($this->isGlpiPlugin($projectRoot)) {
            return 'glpi-plugin';
        }

        if (in_array('yiisoft/yii2', $packages, true)) {
            return 'yii2';
        }

        if ($this->isYii3($packages)) {
            return 'yii3';
        }

        throw new RuntimeException(
            'Profile não reconhecido. Suportados: glpi-plugin, yii3, yii2.',
        );
    }

    /** @param list<string> $packages */
    private function isYii3(array $packages): bool
    {
        $applicationMarkers = [
            'yiisoft/yii-http',
            'yiisoft/yii-console',
            'yiisoft/yii-runner-http',
            'yiisoft/yii-runner-console',
        ];
        $foundationMarkers = [
            'yiisoft/config',
            'yiisoft/di',
            'yiisoft/aliases',
        ];

        return $this->containsAny($packages, $applicationMarkers)
            && $this->containsAny($packages, $foundationMarkers);
    }

    /** @param list<string> $packages
     *  @param list<string> $markers
     */
    private function containsAny(array $packages, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (in_array($marker, $packages, true)) {
                return true;
            }
        }

        return false;
    }

    private function isGlpiPlugin(string $projectRoot): bool
    {
        $setup = is_file($projectRoot . '/setup.php')
            ? (string) file_get_contents($projectRoot . '/setup.php')
            : '';
        $hook = is_file($projectRoot . '/hook.php')
            ? (string) file_get_contents($projectRoot . '/hook.php')
            : '';

        $score = 0;
        $score += is_file($projectRoot . '/setup.php') ? 5 : 0;
        $score += is_file($projectRoot . '/hook.php') ? 4 : 0;
        $score += preg_match('/function\s+plugin_init_[a-z0-9]+\s*\(/i', $setup) === 1 ? 4 : 0;
        $score += preg_match('/function\s+plugin_[a-z0-9]+_install\s*\(/i', $hook) === 1 ? 3 : 0;

        foreach (glob($projectRoot . '/src/*.php') ?: [] as $sourceFile) {
            if (preg_match(
                '/namespace\s+GlpiPlugin\\\\[A-Za-z0-9_\\\\]+\s*;/',
                (string) file_get_contents($sourceFile),
            ) === 1) {
                $score += 4;
                break;
            }
        }

        return $score >= 8;
    }
}
