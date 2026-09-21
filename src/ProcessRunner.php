<?php

declare(strict_types=1);

require_once __DIR__ . '/CliStyle.php';
require_once __DIR__ . '/Finding.php';

/**
 * Valor imutável retornado por execuções capturadas de processo.
 *
 * Preserva exatamente o exit code observado e o conteúdo capturado de stdout
 * e stderr. Não interpreta a semântica da ferramenta executada.
 */
final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}

/**
 * Normaliza e renderiza findings das ferramentas estáticas já estruturadas.
 *
 * Atualmente entende o JSON de PHPStan e Psalm, convertendo mensagens para
 * Finding com caminho relativo ao projeto. Também renderiza findings no
 * terminal e produz sugestões simples baseadas em regra/mensagem.
 *
 * Não inicia processos e não decide o exit code da pipeline. A normalização
 * SAST específica de Psalm Taint/Semgrep ainda não está implementada aqui.
 */
final class FindingRenderer
{
    /** @return list<Finding> */
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
                $findings[] = new Finding(
                    tool: 'phpstan',
                    file: self::relativePath((string) $file, $root),
                    line: (int) ($message['line'] ?? 0),
                    rule: $rule,
                    problem: rtrim($raw, '.'),
                    correction: self::suggestion($rule, $raw),
                    evidenceType: 'static-analysis',
                    provenance: ['phpstan'],
                );
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
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
            $findings[] = new Finding(
                tool: 'psalm',
                file: self::relativePath((string) ($issue['file_name'] ?? '.'), $root),
                line: (int) ($issue['line_from'] ?? 0),
                rule: $rule,
                problem: rtrim($raw, '.'),
                correction: self::suggestion($rule, $raw),
                evidenceType: 'static-analysis',
                provenance: ['psalm'],
            );
        }

        return $findings;
    }

    /** @param list<Finding> $findings */
    public static function render(array $findings, bool $withCorrection): void
    {
        foreach ($findings as $finding) {
            $tool = match ($finding->tool) {
                'phpstan' => 'PHPStan',
                'psalm' => 'Psalm',
                'composer-audit' => 'Composer Audit',
                default => $finding->tool,
            };
            $where = $finding->file . ($finding->line > 0 ? ':' . $finding->line : '');

            echo '╭─ ' . CliStyle::info($tool) . ' ' . str_repeat('─', max(8, 61 - strlen($tool))) . PHP_EOL;
            echo '│ Arquivo: ' . $where . PHP_EOL;
            echo '│ Regra: ' . $finding->rule . PHP_EOL;
            if ($finding->severity !== null) {
                echo '│ Severidade: ' . $finding->severity . PHP_EOL;
            }
            self::renderMetadata($finding);
            echo '│' . PHP_EOL;
            echo '│ ' . CliStyle::warning('Corrigir:') . PHP_EOL;
            self::renderWrapped($finding->problem);

            if ($withCorrection && $finding->correction !== '') {
                echo '│' . PHP_EOL;
                echo '│ ' . CliStyle::success('Correção:') . PHP_EOL;
                self::renderWrapped($finding->correction);
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

    private static function renderMetadata(Finding $finding): void
    {
        $component = $finding->metadata['component'] ?? null;
        if (is_array($component) && is_string($component['name'] ?? null)) {
            $label = $component['name'];
            if (is_string($component['version'] ?? null)) {
                $label .= ' ' . $component['version'];
            }
            $qualifiers = [];
            foreach (['scope', 'relationship'] as $key) {
                if (is_string($component[$key] ?? null) && $component[$key] !== 'unknown') {
                    $qualifiers[] = $component[$key];
                }
            }
            echo '│ Componente: ' . $label
                . ($qualifiers !== [] ? ' (' . implode(', ', $qualifiers) . ')' : '')
                . PHP_EOL;
        }

        $aliases = $finding->metadata['advisory']['aliases'] ?? null;
        if (is_array($aliases) && $aliases !== []) {
            echo '│ Aliases: ' . implode(', ', array_map('strval', $aliases)) . PHP_EOL;
        }
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

/**
 * Inicia ferramentas externas no diretório de trabalho informado.
 *
 * `run()` conecta stdin/stdout/stderr diretamente ao processo atual e retorna
 * somente o exit code. `runCaptured()` usa arquivos temporários para capturar
 * stdout/stderr e devolve ProcessResult. Ambos rejeitam comandos vazios e
 * lançam RuntimeException quando `proc_open()` não consegue iniciar o processo.
 *
 * A classe não resolve o caminho das ferramentas; essa responsabilidade é de
 * ToolResolver.
 */
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
