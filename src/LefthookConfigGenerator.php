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
    /**
     * Materializa `lefthook.yml` no workspace do contexto informado.
     *
     * Os comandos são construídos com caminhos absolutos e argumentos de shell
     * escapados antes de entrarem no YAML. O método escreve somente no workspace
     * externo e retorna o caminho do arquivo gerado.
     *
     * @param ProjectContext $context Contexto do projeto que fornece raiz e workspace.
     * @return string Caminho absoluto do `lefthook.yml` gerado.
     */
    public function generate(ProjectContext $context): string
    {
        $config = $context->workspace()->file('lefthook.yml');
        $ninfa = str_replace('\\', '/', dirname(__DIR__) . '/bin/ninfa');

        // Cada hook chama o CLI externo contra a raiz explícita do consumidor.
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

    /**
     * Escapa uma string para o formato YAML single-quoted.
     *
     * Em YAML, aspas simples internas são representadas por duas aspas simples;
     * essa transformação evita que paths/argumentos quebrem o valor `run`.
     *
     * @param string $value Valor já montado para o comando do hook.
     * @return string Valor seguro delimitado por aspas simples YAML.
     */
    private function yamlSingleQuoted(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
