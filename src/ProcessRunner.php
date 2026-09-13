<?php

declare(strict_types=1);

final class ProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, string $cwd): int
    {
        if ($command === []) {
            throw new InvalidArgumentException('Comando vazio.');
        }

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException(
                'Falha ao iniciar a ferramenta "' . $command[0] . '". Verifique instalação e permissões.',
            );
        }

        return proc_close($process);
    }
}
