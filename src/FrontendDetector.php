<?php

declare(strict_types=1);

final class FrontendDetector
{
    public function hasJavaScript(string $root): bool
    {
        if ($this->packageDeclaresFrontendTooling($root)) {
            return true;
        }

        foreach (['js', 'ts', 'assets', 'resources', 'frontend', 'web'] as $directory) {
            $path = $root . '/' . $directory;
            if (is_dir($path) && $this->directoryContainsJavaScript($path)) {
                return true;
            }
        }

        foreach (['*.js', '*.mjs', '*.cjs', '*.ts', '*.tsx', '*.jsx'] as $pattern) {
            if ((glob($root . '/' . $pattern) ?: []) !== []) {
                return true;
            }
        }

        return false;
    }

    private function packageDeclaresFrontendTooling(string $root): bool
    {
        $file = $root . '/package.json';
        if (!is_file($file)) {
            return false;
        }

        try {
            $package = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($package)) {
            return false;
        }

        $dependencies = array_merge(
            is_array($package['dependencies'] ?? null) ? array_keys($package['dependencies']) : [],
            is_array($package['devDependencies'] ?? null) ? array_keys($package['devDependencies']) : [],
        );

        foreach (['eslint', 'prettier', 'typescript', 'vite', 'webpack', 'rollup', 'esbuild'] as $tool) {
            if (in_array($tool, $dependencies, true)) {
                return true;
            }
        }

        return false;
    }

    private function directoryContainsJavaScript(string $directory): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx'], true)) {
                return true;
            }
        }

        return false;
    }
}
