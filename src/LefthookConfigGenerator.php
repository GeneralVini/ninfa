<?php

declare(strict_types=1);

final class LefthookConfigGenerator
{
    public function generate(ProjectContext $context): string
    {
        $config = $context->workspace()->file('lefthook.yml');
        $root = str_replace("'", "'\\''", $context->root());
        $ninfa = str_replace('\\', '/', dirname(__DIR__) . '/bin/ninfa');

        $content = "pre-commit:\n"
            . "  commands:\n"
            . "    ninfa-fix:\n"
            . "      run: 'php {$ninfa} fix {$root}'\n"
            . "      stage_fixed: true\n"
            . "pre-push:\n"
            . "  commands:\n"
            . "    ninfa-check:\n"
            . "      run: 'php {$ninfa} check {$root}'\n";

        file_put_contents($config, $content);

        return $config;
    }
}
