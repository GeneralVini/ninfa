<?php

declare(strict_types=1);

require_once __DIR__ . '/FrontendAwarePipelineRunner.php';

final class RecheckingPipelineRunner
{
    public function __construct(
        private readonly FrontendAwarePipelineRunner $runner = new FrontendAwarePipelineRunner(),
    ) {
    }

    public function run(string $operation, ProjectContext $context): int
    {
        $status = $this->runner->run($operation, $context);
        if ($status !== 0 || $operation !== 'fix') {
            return $status;
        }

        return $this->runner->run('check', $context);
    }
}
