<?php

declare(strict_types=1);

/**
 * Detecta sinais de JavaScript/TypeScript e ferramentas frontend no consumidor.
 *
 * A detecção usa dependências de `package.json`, binários em
 * `node_modules/.bin`, arquivos de configuração conhecidos e extensões de
 * arquivos em diretórios candidatos. O resultado é usado pelo PipelinePlan
 * para decidir se ESLint e Prettier entram no `check`/`fix`.
 */
final class FrontendDetector
{
    /**
     * Detecta presença de código/ecossistema JavaScript sem exigir Node instalado.
     *
     * A decisão combina dependências conhecidas, diretórios convencionais e
     * arquivos JS/TS na raiz. O método não valida configuração nem executa
     * ferramentas; apenas responde se existe evidência suficiente de frontend.
     *
     * @param string $root Raiz do projeto consumidor.
     */
    public function hasJavaScript(string $root): bool
    {
        // Dependências de build/lint são evidência suficiente mesmo sem fonte em diretório convencional.
        if ($this->packageHasAny($root, ['eslint', 'prettier', 'typescript', 'vite', 'webpack', 'rollup', 'esbuild'])) {
            return true;
        }

        // Diretórios candidatos são percorridos somente quando realmente existem.
        foreach (['js', 'ts', 'assets', 'resources', 'frontend', 'web'] as $directory) {
            $path = $root . '/' . $directory;
            if (is_dir($path) && $this->directoryContainsJavaScript($path)) {
                return true;
            }
        }

        // Projetos pequenos podem manter JS/TS diretamente na raiz e não ter package.json.
        foreach (['*.js', '*.mjs', '*.cjs', '*.ts', '*.tsx', '*.jsx'] as $pattern) {
            if ((glob($root . '/' . $pattern) ?: []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determina se ESLint está configurado ou instalado no projeto consumidor.
     *
     * Binário local, dependência declarada ou arquivo de configuração conhecido
     * são aceitos como evidência. A função não considera um ESLint global no PATH;
     * a resolução do executável permanece responsabilidade de ToolResolver.
     *
     * @param string $root Raiz do projeto consumidor.
     */
    public function hasEslint(string $root): bool
    {
        if (is_file($root . '/node_modules/.bin/eslint') || $this->packageHasAny($root, ['eslint'])) {
            return true;
        }

        // Configuração versionada torna a etapa aplicável mesmo antes de node_modules existir.
        foreach (['eslint.config.js', 'eslint.config.mjs', 'eslint.config.cjs', '.eslintrc', '.eslintrc.js', '.eslintrc.cjs', '.eslintrc.json'] as $file) {
            if (is_file($root . '/' . $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determina se Prettier está configurado ou instalado no projeto consumidor.
     *
     * Assim como ESLint, aceita binário local, dependência ou configuração
     * versionada como evidência e não resolve executáveis globais.
     *
     * @param string $root Raiz do projeto consumidor.
     */
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

    /**
     * Verifica se qualquer pacote informado está declarado em dependencies/devDependencies.
     *
     * `package.json` ausente, JSON inválido ou formato inesperado são tratados
     * como ausência de evidência, porque o detector não deve transformar uma
     * heurística frontend em falha global do pipeline PHP.
     *
     * @param string $root Raiz do projeto consumidor.
     * @param list<string> $tools Nomes exatos de pacotes a procurar.
     */
    private function packageHasAny(string $root, array $tools): bool
    {
        $file = $root . '/package.json';
        if (!is_file($file)) {
            return false;
        }

        try {
            /** @var mixed $package Conteúdo decodificado de package.json. */
            $package = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($package)) {
            return false;
        }

        /** @var list<string> $dependencies Nomes declarados nos dois escopos npm relevantes. */
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

    /**
     * Procura recursivamente ao menos um arquivo JavaScript/TypeScript reconhecido.
     *
     * A busca para no primeiro arquivo compatível e ignora conteúdo dos arquivos;
     * o objetivo é somente detectar aplicabilidade do pipeline frontend.
     *
     * @param string $directory Diretório existente a percorrer.
     */
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
