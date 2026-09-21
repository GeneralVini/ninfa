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
    /**
     * Registra o resultado bruto de um processo já encerrado.
     *
     * @param int $exitCode Código devolvido por `proc_close()`.
     * @param string $stdout Conteúdo integral capturado de stdout.
     * @param string $stderr Conteúdo integral capturado de stderr.
     */
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
    /**
     * Converte o JSON `--error-format=json` do PHPStan em findings comuns.
     *
     * Cada mensagem recebe arquivo relativo à raiz, linha, identifier e uma
     * sugestão conservadora. Campos desconhecidos do payload são ignorados e a
     * função não decide se o exit code da ferramenta deve bloquear a execução.
     *
     * @param string $json Saída JSON integral produzida pelo PHPStan.
     * @param string $root Raiz do consumidor usada para relativizar paths.
     * @return list<Finding> Findings na ordem em que aparecem no payload.
     * @throws JsonException Quando a saída não é JSON válido.
     */
    public static function phpStan(string $json, string $root): array
    {
        /** @var array<string,mixed> $data Payload associativo decodificado. */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        /** @var list<Finding> $findings */
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

    /**
     * Converte a saída JSON do Psalm em findings comuns.
     *
     * Suporta tanto a lista direta de issues quanto payload com chave `issues`.
     * O método preserva type/shortcode como regra e não interpreta categorias de
     * taint; Psalm Taint será normalizado por contrato SAST próprio na Etapa 3.
     *
     * @param string $json Saída JSON integral produzida pelo Psalm.
     * @param string $root Raiz do consumidor usada para relativizar paths.
     * @return list<Finding> Issues normalizadas na ordem da ferramenta.
     * @throws JsonException Quando a saída não é JSON válido.
     */
    public static function psalm(string $json, string $root): array
    {
        /** @var mixed $data Payload decodificado, que pode ser lista ou objeto com issues. */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        /** @var list<mixed> $issues Lista de issues extraída do formato aceito. */
        $issues = array_is_list($data) ? $data : ($data['issues'] ?? []);
        /** @var list<Finding> $findings */
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

    /**
     * Renderiza findings em cartões de terminal sem alterar os objetos recebidos.
     *
     * Metadata de componente/advisory é exibida quando conhecida. A orientação
     * de correção só aparece quando `$withCorrection` é true e o Finding fornece
     * texto não vazio.
     *
     * @param list<Finding> $findings Achados normalizados a apresentar.
     * @param bool $withCorrection Define se a seção de correção deve ser mostrada.
     */
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

    /**
     * Gera uma orientação curta a partir de regras/mensagens já observadas.
     *
     * As heurísticas cobrem narrowing de mixed, incompatibilidade de argumento e
     * condições sempre verdadeiras/falsas. O fallback recomenda corrigir o
     * contrato na origem em vez de suprimir o finding.
     *
     * @param string $rule Identificador normalizado da regra da ferramenta.
     * @param string $message Mensagem original já limpa.
     * @return string Sugestão humana conservadora, sem alteração automática do código.
     */
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

    /**
     * Renderiza metadata SCA opcional sem assumir que todo Finding possui componente/advisory.
     *
     * @param Finding $finding Achado cuja metadata será inspecionada somente para apresentação.
     */
    private static function renderMetadata(Finding $finding): void
    {
        $component = $finding->metadata['component'] ?? null;
        if (is_array($component) && is_string($component['name'] ?? null)) {
            $label = $component['name'];
            if (is_string($component['version'] ?? null)) {
                $label .= ' ' . $component['version'];
            }

            /** @var list<string> $qualifiers Escopo/relação conhecidos usados no rótulo humano. */
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

    /**
     * Converte caminho absoluto sob a raiz do consumidor em path relativo estável.
     *
     * @param string $file Path informado pela ferramenta.
     * @param string $root Raiz do consumidor.
     * @return string Path relativo quando possível; caso contrário preserva o path normalizado.
     */
    private static function relativePath(string $file, string $root): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }

    /**
     * Normaliza whitespace de uma mensagem sem alterar seu conteúdo semântico.
     *
     * @param string $message Texto bruto da ferramenta.
     * @return string Texto trimado com sequências de whitespace reduzidas a um espaço.
     */
    private static function clean(string $message): string
    {
        return preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
    }

    /**
     * Quebra texto longo em linhas compatíveis com a largura do cartão do CLI.
     *
     * @param string $text Texto humano a renderizar; palavras não são cortadas à força.
     */
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
    /**
     * Executa um comando herdando stdin/stdout/stderr do processo Ninfa.
     *
     * @param list<string> $command Executável e argumentos já resolvidos, sem shell intermediário.
     * @param string $cwd Diretório de trabalho do processo filho.
     * @return int Exit code devolvido por `proc_close()`.
     * @throws InvalidArgumentException Quando a lista de comando está vazia.
     * @throws RuntimeException Quando `proc_open()` não consegue iniciar o processo.
     */
    public function run(array $command, string $cwd): int
    {
        if ($command === []) {
            throw new InvalidArgumentException('Comando vazio.');
        }

        /** @var array<int,resource> $descriptorSpec Descritores herdados do processo atual. */
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

    /**
     * Executa um comando preservando stdout/stderr integralmente em ProcessResult.
     *
     * Arquivos temporários são usados como buffers para evitar deadlock por pipes
     * cheios. stdin do filho é fechado imediatamente porque scanners executados
     * pelo Ninfa não devem aguardar interação. Os buffers são sempre fechados antes
     * do retorno ou da exceção de inicialização.
     *
     * @param list<string> $command Executável e argumentos já resolvidos.
     * @param string $cwd Diretório de trabalho do processo filho.
     * @return ProcessResult Exit code e conteúdo capturado dos dois streams.
     * @throws InvalidArgumentException Quando a lista de comando está vazia.
     * @throws RuntimeException Quando buffers ou processo não podem ser criados.
     */
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

        /** @var array<int,mixed> $descriptorSpec Descritores usados para fechar stdin e capturar os streams de saída. */
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

        // Nenhuma ferramenta deve ficar aguardando input interativo do Ninfa.
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
