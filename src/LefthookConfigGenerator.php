<?php

declare(strict_types=1);

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
