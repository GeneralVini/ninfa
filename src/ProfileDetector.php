<?php

declare(strict_types=1);

/**
 * Classifica o projeto consumidor em um dos profiles suportados pelo Ninfa.
 *
 * A precedência atual é GLPI Plugin, Yii2, Yii3 e PHP genérico. GLPI usa
 * sinais estruturais/semânticos do plugin; Yii2 e Yii3 usam dependências
 * Composer; o fallback genérico exige evidência concreta de arquivos PHP.
 *
 * O detector não resolve capabilities nem semântica de segurança de
 * framework: sua responsabilidade termina ao retornar o identificador do
 * profile aplicável ou falhar quando nenhum profile é reconhecido.
 */
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
        if ($this->isGenericPhpProject($projectRoot, $composer)) {
            return 'php-generic';
        }

        throw new RuntimeException('Profile não reconhecido. Suportados: glpi-plugin, yii3, yii2, php-generic.');
    }

    /** @param list<string> $packages */
    private function isYii3(array $packages): bool
    {
        $applicationMarkers = ['yiisoft/yii-http', 'yiisoft/yii-console', 'yiisoft/yii-runner-http', 'yiisoft/yii-runner-console'];
        $foundationMarkers = ['yiisoft/config', 'yiisoft/di', 'yiisoft/aliases'];
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

    /** @param array<string, mixed> $composer */
    private function isGenericPhpProject(string $projectRoot, array $composer): bool
    {
        foreach (['src', 'app', 'lib', 'include', 'includes', 'public', 'bin', 'tests'] as $directory) {
            $path = $projectRoot . '/' . $directory;
            if (is_dir($path) && $this->directoryContainsPhp($path)) {
                return true;
            }
        }

        $rootPhpFiles = glob($projectRoot . '/*.php') ?: [];
        if ($composer !== [] && $rootPhpFiles !== []) {
            return true;
        }

        return count($rootPhpFiles) >= 2;
    }

    private function directoryContainsPhp(string $directory): bool
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                return true;
            }
        }
        return false;
    }

    private function isGlpiPlugin(string $projectRoot): bool
    {
        $setup = is_file($projectRoot . '/setup.php') ? (string) file_get_contents($projectRoot . '/setup.php') : '';
        $hook = is_file($projectRoot . '/hook.php') ? (string) file_get_contents($projectRoot . '/hook.php') : '';

        $score = 0;
        $score += is_file($projectRoot . '/setup.php') ? 5 : 0;
        $score += is_file($projectRoot . '/hook.php') ? 4 : 0;
        $score += preg_match('/function\s+plugin_init_[a-z0-9]+\s*\(/i', $setup) === 1 ? 4 : 0;
        $score += preg_match('/function\s+plugin_[a-z0-9]+_install\s*\(/i', $hook) === 1 ? 3 : 0;

        foreach (glob($projectRoot . '/src/*.php') ?: [] as $sourceFile) {
            if (preg_match('/namespace\s+GlpiPlugin\\\\[A-Za-z0-9_\\\\]+\s*;/', (string) file_get_contents($sourceFile)) === 1) {
                $score += 4;
                break;
            }
        }

        return $score >= 8;
    }
}
