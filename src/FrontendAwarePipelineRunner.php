<?php

declare(strict_types=1);

require_once __DIR__ . '/PipelineRunner.php';
require_once __DIR__ . '/FrontendCommandBuilder.php';
require_once __DIR__ . '/ToolResolver.php';
require_once __DIR__ . '/ProcessRunner.php';

final class FrontendAwarePipelineRunner
{
    public function __construct(
        private readonly PipelineRunner $pipelineRunner = new PipelineRunner(),
        private readonly FrontendCommandBuilder $frontendCommandBuilder = new FrontendCommandBuilder(),
        private readonly ToolResolver $toolResolver = new ToolResolver(),
        private readonly ProcessRunner $processRunner = new ProcessRunner(),
    ) {
    }

    public function run(string $operation, ProjectContext $context): int
    {
        $status = $this->pipelineRunner->run($operation, $context);
        if ($status !== 0 || !$context->hasJavaScript() || !in_array($operation, ['check', 'fix'], true)) {
            return $status;
        }

        foreach (['eslint', 'prettier'] as $id) {
            $command = $this->frontendCommandBuilder->build(
                $id,
                $operation === 'fix' ? 'fix' : 'check',
                fn (string $tool): string => $this->toolResolver->resolve($tool, $context->root()),
            );
            if ($command === null) {
                continue;
            }

            echo '[NINFA] ' . $id . ' (' . $operation . ')' . PHP_EOL;
            $status = $this->processRunner->run($command, $context->root());
            if ($status !== 0) {
                return $status;
            }
        }

        return 0;
    }
}
