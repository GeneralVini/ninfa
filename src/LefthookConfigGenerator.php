<?php

declare(strict_types=1);

/**
 * Gera a configuração Lefthook consumida pelo projeto sem tocar no consumidor.
 *
 * O arquivo é escrito no workspace externo. `pre-commit` chama `ninfa fix`
 * com `stage_fixed: true`; `pre-push` chama `ninfa check`. Os comandos usam
 * caminhos absolutos para o CLI e para a raiz do projeto e são escapados como
 * strings YAML single-quoted.
 */
final class LefthookConfigGenerator
{
    public function generate(ProjectContext $context): string
    {
        $config = $context->workspace()->file('lefthook.yml');
        $ninfa = str_replace('\\', '/', dirname(__DIR__) . '/bin/ninfa');

        $fixCommand = $this->yamlSingleQuoted(
            'php ' . escapeshellarg($ninfa) . ' fix ' . escapeshellarg($context->root()),
        );
        $checkCommand = $this->yamlSingleQuoted(
            'php ' . escapeshellarg($ninfa) . ' check ' . escapeshellarg($context->root()),
        );

        $content = "pre-commit:\n"
            . "  commands:\n"
            . "    ninfa-fix:\n"
            . "      run: {$fixCommand}\n"
            . "      stage_fixed: true\n"
            . "pre-push:\n"
            . "  commands:\n"
            . "    ninfa-check:\n"
            . "      run: {$checkCommand}\n";

        file_put_contents($config, $content);

        return $config;
    }

    private function yamlSingleQuoted(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
