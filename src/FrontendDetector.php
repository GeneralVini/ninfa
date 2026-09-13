<?php

declare(strict_types=1);

final class FrontendDetector
{
    public function hasJavaScript(string $root): bool
    {
        if ($this->packageHasAny($root, ['eslint', 'prettier', 'typescript', 'vite', 'webpack', 'rollup', 'esbuild'])) {
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

    public function hasEslint(string $root): bool
    {
        if (is_file($root . '/node_modules/.bin/eslint') || $this->packageHasAny($root, ['eslint'])) {
            return true;
        }

        foreach (['eslint.config.js', 'eslint.config.mjs', 'eslint.config.cjs', '.eslintrc', '.eslintrc.js', '.eslintrc.cjs', '.eslintrc.json'] as $file) {
            if (is_file($root . '/' . $file)) {
                return true;
            }
        }

        return false;
    }

    public function hasPrettier(string $root): bool
    {
        if (is_file($root . '/node_modules/.bin/prettier') || $this->packageHasAny($root, ['prettier'])) {
            return true;
        }

        foreach (['.prettierrc', '.prettierrc.json', '.prettierrc.yaml', '.prettierrc.yml', '.prettierrc.js', '.prettierrc.cjs', 'prettier.config.js', 'prettier.config.cjs'] as $file) {
            if (is_file($root . '/' . $file)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $tools */
    private function packageHasAny(string $root, array $tools): bool
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

        foreach ($tools as $tool) {
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
