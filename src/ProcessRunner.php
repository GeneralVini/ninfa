<?php

declare(strict_types=1);

require_once __DIR__ . '/CliStyle.php';

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}

final class FindingRenderer
{
    /** @return list<array{tool:string,file:string,line:int,rule:string,problem:string,correction:string}> */
    public static function phpStan(string $json, string $root): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $findings = [];

        foreach (($data['files'] ?? []) as $file => $details) {
            foreach (($details['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $raw = self::clean((string) ($message['message'] ?? 'Achado PHPStan'));
                $rule = (string) ($message['identifier'] ?? 'phpstan');
                $findings[] = [
                    'tool' => 'phpstan',
                    'file' => self::relativePath((string) $file, $root),
                    'line' => (int) ($message['line'] ?? 0),
                    'rule' => $rule,
                    'problem' => rtrim($raw, '.'),
                    'correction' => self::suggestion($rule, $raw),
                ];
            }
        }

        return $findings;
    }

    /** @return list<array{tool:string,file:string,line:int,rule:string,problem:string,correction:string}> */
    public static function psalm(string $json, string $root): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $issues = array_is_list($data) ? $data : ($data['issues'] ?? []);
        $findings = [];

        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $raw = self::clean((string) ($issue['message'] ?? 'Achado Psalm'));
            $rule = (string) ($issue['type'] ?? ($issue['shortcode'] ?? 'psalm'));
            $findings[] = [
                'tool' => 'psalm',
                'file' => self::relativePath((string) ($issue['file_name'] ?? '.'), $root),
                'line' => (int) ($issue['line_from'] ?? 0),
                'rule' => $rule,
                'problem' => rtrim($raw, '.'),
                'correction' => self::suggestion($rule, $raw),
            ];
        }

        return $findings;
    }

    /** @param list<array{tool:string,file:string,line:int,rule:string,problem:string,correction:string}> $findings */
    public static function render(array $findings, bool $withCorrection): void
    {
        foreach ($findings as $finding) {
            $tool = match ($finding['tool']) {
                'phpstan' => 'PHPStan',
                'psalm' => 'Psalm',
                default => $finding['tool'],
            };
            $where = $finding['file'] . ($finding['line'] > 0 ? ':' . $finding['line'] : '');

            echo '╭─ ' . CliStyle::info($tool) . ' ' . str_repeat('─', max(8, 61 - strlen($tool))) . PHP_EOL;
            echo '│ Arquivo: ' . $where . PHP_EOL;
            echo '│ Regra: ' . $finding['rule'] . PHP_EOL;
            echo '│' . PHP_EOL;
            echo '│ ' . CliStyle::warning('Corrigir:') . PHP_EOL;
            self::renderWrapped($finding['problem']);

            if ($withCorrection) {
                echo '│' . PHP_EOL;
                echo '│ ' . CliStyle::success('Correção:') . PHP_EOL;
                self::renderWrapped($finding['correction']);
            }

            echo '╰' . str_repeat('─', 72) . PHP_EOL . PHP_EOL;
        }
    }

    public static function suggestion(string $rule, string $message): string
    {
        $normalizedRule = strtolower($rule);
        $normalizedMessage = strtolower($message);

        if (str_contains($normalizedMessage, 'mixed') && str_contains($normalizedMessage, 'string')) {
            return 'Valide/refine o valor como string na origem antes do uso; evite cast cego de mixed.';
        }
        if (str_contains($normalizedMessage, 'mixed') && str_contains($normalizedMessage, 'int')) {
            return 'Valide/refine o valor como inteiro antes do uso e trate entradas inválidas.';
        }
        if ($normalizedRule === 'argument.type' || str_contains($normalizedMessage, 'expects')) {
            return 'Faça o valor atender ao contrato exigido antes da chamada, por validação/narrowing ou corrigindo o tipo na origem.';
        }
        if (str_contains($normalizedRule, 'always') || str_contains($normalizedMessage, 'always true') || str_contains($normalizedMessage, 'always false')) {
            return 'Remova a condição/assertiva redundante ou substitua por uma verificação que realmente possa falhar.';
        }

        return 'Corrija o contrato/tipo na origem do dado; não suprima o achado apenas para obter resultado verde.';
    }

    private static function relativePath(string $file, string $root): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }

    private static function clean(string $message): string
    {
        return preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
    }

    private static function renderWrapped(string $text): void
    {
        foreach (explode("\n", wordwrap($text, 66, "\n", false)) as $line) {
            echo '│   ' . $line . PHP_EOL;
        }
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
