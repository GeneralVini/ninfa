<?php

declare(strict_types=1);

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}

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

    /** @param list<string> $command */
    public function runCaptured(array $command, string $cwd): ProcessResult
    {
        if ($command === []) {
            throw new InvalidArgumentException('Comando vazio.');
        }

        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Não foi possível criar buffers temporários para a ferramenta.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => $stdout,
            2 => $stderr,
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            fclose($stdout);
            fclose($stderr);
            throw new RuntimeException(
                'Falha ao iniciar a ferramenta "' . $command[0] . '". Verifique instalação e permissões.',
            );
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $exitCode = proc_close($process);
        rewind($stdout);
        rewind($stderr);
        $stdoutContents = stream_get_contents($stdout);
        $stderrContents = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);

        return new ProcessResult(
            $exitCode,
            $stdoutContents === false ? '' : $stdoutContents,
            $stderrContents === false ? '' : $stderrContents,
        );
    }
}
