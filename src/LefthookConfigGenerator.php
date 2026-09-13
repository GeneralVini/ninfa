<?php

declare(strict_types=1);

require_once __DIR__ . '/PipelinePlan.php';

final class LefthookConfigGenerator
{
    public function generate(ProjectContext $context): string
    {
        $plan = new PipelinePlan();
        $fixHooks = $plan->lefthookFixHooks($context);
        if ($fixHooks === []) {
            throw new RuntimeException('Nenhum hook corrigível disponível para o profile.');
        }

        $config = $context->workspace()->file('lefthook.yml');
        $root = str_replace("'", "'\\''", $context->root());

        $content = "pre-commit:\n"
            . "  jobs:\n"
            . "    - name: ninfa-fix\n"
            . "      run: \"${NINFA_BIN:-ninfa} fix '{$root}'\"\n"
            . "      stage_fixed: true\n"
            . "pre-push:\n"
            . "  jobs:\n"
            . "    - name: ninfa-check\n"
            . "      run: \"${NINFA_BIN:-ninfa} check '{$root}'\"\n";

        file_put_contents($config, $content);

        return $config;
    }
}
