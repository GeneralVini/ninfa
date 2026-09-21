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
    /**
     * Detecta o profile respeitando a precedência dos detectores especializados.
     *
     * GLPI é verificado antes dos pacotes Yii porque seu contrato depende de
     * estrutura de plugin. Yii2 depende da presença exata de `yiisoft/yii2`;
     * Yii3 exige combinação de marcador de aplicação e infraestrutura. O
     * fallback genérico só ocorre quando existe código PHP observável.
     *
     * @param string $projectRoot Raiz física do projeto consumidor.
     * @param array<string,mixed> $composer composer.json decodificado ou array vazio.
     * @return string Identificador de profile suportado.
     * @throws RuntimeException Quando nenhuma evidência permite classificar o projeto.
     */
    public function detect(string $projectRoot, array $composer): string
    {
        /** @var list<string> $packages Nomes declarados em require e require-dev. */
        $packages = array_keys(array_merge(
            is_array($composer['require'] ?? null) ? $composer['require'] : [],
            is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
        ));

        // Profiles especializados têm precedência para não cair silenciosamente no baseline genérico.
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

    /**
     * Reconhece Yii3 somente pela combinação mínima de pacotes de aplicação e base.
     *
     * Uma dependência `yiisoft/*` isolada não é suficiente: é necessário pelo
     * menos um runner/componente de aplicação e um marcador de infraestrutura.
     *
     * @param list<string> $packages Nomes de pacotes Composer declarados.
     */
    private function isYii3(array $packages): bool
    {
        /** @var list<string> $applicationMarkers Pacotes que indicam aplicação/runner Yii3. */
        $applicationMarkers = ['yiisoft/yii-http', 'yiisoft/yii-console', 'yiisoft/yii-runner-http', 'yiisoft/yii-runner-console'];
        /** @var list<string> $foundationMarkers Pacotes de infraestrutura esperados em aplicação Yii3. */
        $foundationMarkers = ['yiisoft/config', 'yiisoft/di', 'yiisoft/aliases'];

        return $this->containsAny($packages, $applicationMarkers)
            && $this->containsAny($packages, $foundationMarkers);
    }

    /**
     * Testa interseção entre uma lista de pacotes e marcadores conhecidos.
     *
     * @param list<string> $packages Pacotes observados no projeto.
     * @param list<string> $markers Marcadores aceitos pelo detector chamador.
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

    /**
     * Decide se resta evidência suficiente para o profile PHP genérico.
     *
     * Diretórios convencionais precisam conter ao menos um `.php`. Na raiz,
     * um projeto com composer.json precisa de um PHP; sem Composer são exigidos
     * ao menos dois arquivos para reduzir falsos positivos de diretórios soltos.
     *
     * @param string $projectRoot Raiz do projeto consumidor.
     * @param array<string,mixed> $composer composer.json decodificado ou vazio.
     */
    private function isGenericPhpProject(string $projectRoot, array $composer): bool
    {
        foreach (['src', 'app', 'lib', 'include', 'includes', 'public', 'bin', 'tests'] as $directory) {
            $path = $projectRoot . '/' . $directory;
            if (is_dir($path) && $this->directoryContainsPhp($path)) {
                return true;
            }
        }

        /** @var list<string> $rootPhpFiles Arquivos PHP encontrados diretamente na raiz. */
        $rootPhpFiles = glob($projectRoot . '/*.php') ?: [];
        if ($composer !== [] && $rootPhpFiles !== []) {
            return true;
        }

        return count($rootPhpFiles) >= 2;
    }

    /**
     * Procura recursivamente o primeiro arquivo PHP em um diretório existente.
     *
     * @param string $directory Diretório a percorrer.
     */
    private function directoryContainsPhp(string $directory): bool
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                return true;
            }
        }
        return false;
    }

    /**
     * Calcula evidência estrutural de que a raiz representa um plugin GLPI.
     *
     * O score combina existência de setup/hook, funções convencionais de plugin
     * e namespace `GlpiPlugin` em arquivos diretamente sob `src/`. O limiar 8
     * exige mais de um sinal e evita classificar qualquer diretório com setup.php.
     *
     * @param string $projectRoot Raiz candidata a plugin GLPI.
     */
    private function isGlpiPlugin(string $projectRoot): bool
    {
        $setup = is_file($projectRoot . '/setup.php') ? (string) file_get_contents($projectRoot . '/setup.php') : '';
        $hook = is_file($projectRoot . '/hook.php') ? (string) file_get_contents($projectRoot . '/hook.php') : '';

        $score = 0;
        $score += is_file($projectRoot . '/setup.php') ? 5 : 0;
        $score += is_file($projectRoot . '/hook.php') ? 4 : 0;
        $score += preg_match('/function\s+plugin_init_[a-z0-9]+\s*\(/i', $setup) === 1 ? 4 : 0;
        $score += preg_match('/function\s+plugin_[a-z0-9]+_install\s*\(/i', $hook) === 1 ? 3 : 0;

        // Namespace de plugin é um reforço semântico adicional, não requisito isolado.
        foreach (glob($projectRoot . '/src/*.php') ?: [] as $sourceFile) {
            if (preg_match('/namespace\s+GlpiPlugin\\\\[A-Za-z0-9_\\\\]+\s*;/', (string) file_get_contents($sourceFile)) === 1) {
                $score += 4;
                break;
            }
        }

        return $score >= 8;
    }
}
