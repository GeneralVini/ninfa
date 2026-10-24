<?php

declare(strict_types=1);

require_once __DIR__ . '/SecurityScanPlan.php';
require_once __DIR__ . '/EvidenceStore.php';

/**
 * Coordena execução síncrona SEC-04.04 com trava entre processos e journal privado.
 *
 * A trava permanece durante o adapter: não há lease que expire e permita iniciar
 * outro motor enquanto o primeiro ainda trabalha. Reuso exige todas as unidades
 * verificáveis, contexto revalidado e evidência sanitizada íntegra e vigente.
 */
final class SecurityExecutionLedger
{
    /** Raiz física privada do ledger. */
    private readonly string $directory;
    /** Raiz física privada dos envelopes EvidenceStore. */
    private readonly string $evidenceDirectory;

    /** Configura armazenamento fora do consumidor e um prazo máximo para cobertura reutilizável. */
    public function __construct(string $directory, string $evidenceDirectory, private readonly int $maxReuseSeconds = 3600, private readonly ?Closure $clock = null)
    {
        if ($maxReuseSeconds < 1 || $maxReuseSeconds > 86400) {
            throw new InvalidArgumentException('Prazo de reutilização inválido.');
        }
        EvidenceStore::privateDirectory($directory);
        EvidenceStore::privateDirectory($evidenceDirectory);
        $this->directory = (string) realpath($directory);
        $this->evidenceDirectory = (string) realpath($evidenceDirectory);
    }

    /**
     * Persiste o plano, reserva o grupo e chama o adapter sob trava exclusiva.
     *
     * O adapter só retorna após comprovar término do motor. Exceção/interrupção
     * exige confirmação externa de parada antes de retry explícito. Contexto e
     * sessão devem ser revalidados pelo chamador inclusive para reuso.
     *
     * @param callable(array<string,mixed>):array{status:string,units:array<string,array{coverage:string,session_valid:bool,valid_until:int,evidence:array<string,mixed>}>} $adapter Operação síncrona e resultado por unidade.
     * @return array<string,mixed> Decisão, referências de tentativa ou cobertura reutilizada.
     */
    public function run(SecurityScanPlan $plan, string $executionId, callable $adapter, bool $contextValidated, ?string $retryReason = null, bool $previousStopped = false): array
    {
        if ($retryReason !== null && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $retryReason) !== 1) {
            throw new InvalidArgumentException('Retry exige código de motivo sem texto sensível.');
        }
        return $this->locked(function (array &$state) use ($plan, $executionId, $adapter, $contextValidated, $retryReason, $previousStopped): array {
            $document = $plan->jsonSerialize();
            $group = self::group($document, $executionId);
            $this->persistPlan($document);
            // Persistir decisões de reuso/bloqueio também permite explicar supressões no relatório.
            $decide = function (array $decision) use (&$state, $document, $executionId): array {
                $state['decisions'][] = ['plan_id' => $document['plan_id'], 'execution_id' => $executionId,
                    'at' => $this->now(), 'result' => $decision];
                $this->save($state);
                return $decision;
            };
            if (!$contextValidated) {
                return $decide(['decision' => 'context-invalid']);
            }
            $currentKeys = array_column(array_column($group['units'], 'identity'), 'key');
            // Reserva incerta é uma barreira global, inclusive se um novo plano mudou nonce/grupo.
            foreach ($state['attempts'] as $attempts) {
                foreach ($attempts as $previous) {
                    if (in_array($previous['status'], ['running', 'interrupted'], true)
                        && (!$previousStopped || $retryReason === null || array_intersect($currentKeys, $previous['units']) === [])) {
                        return $decide(['decision' => 'recovery-required', 'attempt' => $previous['token']]);
                    }
                }
            }
            /** @var list<string> $covered Unidades cuja cobertura ainda pode ser reutilizada. */
            $covered = [];
            foreach ($group['units'] as $unit) {
                $key = $unit['identity']['key'];
                if ($unit['identity']['verifiable'] && $this->validCoverage($state['coverage'][$key] ?? null)) {
                    $covered[] = $key;
                }
            }
            if (count($covered) === count($group['units'])) {
                return $decide(['decision' => 'reused', 'units' => $covered,
                    'coverage_snapshot' => array_intersect_key($state['coverage'], array_fill_keys($covered, true))]);
            }
            if ($covered !== []) {
                return $decide(['decision' => 'replan-required', 'covered' => $covered,
                    'reason' => 'Não repetir unidades cobertas em um grupo parcialmente concluído.']);
            }
            $unitKeys = array_column(array_column($group['units'], 'identity'), 'key');
            /** @var list<array{execution:string,index:int,record:array<string,mixed>}> $history Tentativas que intersectam unidades, mesmo com outro agrupamento. */
            $history = [];
            foreach ($state['attempts'] as $previousExecution => $attempts) {
                foreach ($attempts as $previousIndex => $previous) {
                    if (array_intersect($unitKeys, $previous['units']) !== []) {
                        $history[] = ['execution' => $previousExecution, 'index' => $previousIndex, 'record' => $previous];
                    }
                }
            }
            $last = $history === [] ? null : $history[count($history) - 1]['record'];
            if ($last !== null) {
                foreach ($group['units'] as $unit) {
                    if (!$unit['identity']['verifiable']) {
                        return $decide(['decision' => 'unverifiable-context', 'reason' => 'Criar novo plano após reconstruir os fatos; retomada inválida.']);
                    }
                }
                foreach ($history as $previous) {
                    if (in_array($previous['record']['status'], ['running', 'interrupted'], true) && !$previousStopped) {
                        return $decide(['decision' => 'recovery-required', 'attempt' => $previous['record']['token']]);
                    }
                }
                if ($retryReason === null) {
                    return $decide(['decision' => 'retry-required', 'attempt' => $last['token']]);
                }
                foreach ($history as $previous) {
                    if (in_array($previous['record']['status'], ['running', 'interrupted'], true)) {
                        $state['attempts'][$previous['execution']][$previous['index']]['status'] = 'recovered';
                        $state['attempts'][$previous['execution']][$previous['index']]['confirmed_stopped_at'] = $this->now();
                    }
                }
            }
            $token = bin2hex(random_bytes(16));
            $attempt = ['token' => $token, 'plan_id' => $document['plan_id'], 'run_id' => $document['run_id'],
                'status' => 'running', 'started_at' => $this->now(), 'retry_reason' => $retryReason,
                'previous_stopped' => $previousStopped, 'units' => array_column(array_column($group['units'], 'identity'), 'key')];
            $state['attempts'][$executionId][] = $attempt;
            $index = count($state['attempts'][$executionId]) - 1;
            $this->save($state); // Publicar reserva antes de qualquer efeito externo do adapter.
            try {
                $result = $adapter($group);
                $records = $this->result($group, $result);
                foreach ($records as $key => $record) {
                    $state['coverage'][$key] = $record + ['attempt' => $token, 'plan_id' => $document['plan_id']];
                }
                $state['attempts'][$executionId][$index]['status'] = $result['status'];
                $state['attempts'][$executionId][$index]['result'] = $records;
            } catch (Throwable $error) {
                // Não persistir mensagens arbitrárias que possam conter URL/token/saída nativa.
                $state['attempts'][$executionId][$index]['status'] = 'interrupted';
                $state['attempts'][$executionId][$index]['error_type'] = get_class($error);
            }
            $state['attempts'][$executionId][$index]['ended_at'] = $this->now();
            return $decide(['decision' => $state['attempts'][$executionId][$index]['status'], 'attempt' => $token]);
        });
    }

    /**
     * Avalia fallback sem iniciar alternativa ou inferir equivalência por CWE/URL.
     *
     * O catálogo atual não aprova substitutos. Esta fronteira exige primeiro
     * falta de cobertura e tentativa terminada; nova versão deverá adicionar
     * prova de equivalência e mapeamento exato antes de liberar alternativa.
     *
     * @return array<string,mixed> Motivo auditável de não execução do fallback.
     */
    public function fallbackDecision(CapabilityCatalog $catalog, SecurityScanPlan $plan, string $unitKey, string $alternative, bool $contextValidated): array
    {
        return $this->locked(function (array &$state) use ($catalog, $plan, $unitKey, $alternative, $contextValidated): array {
            if (!$contextValidated) {
                return ['decision' => 'context-invalid'];
            }
            $document = $plan->jsonSerialize();
            foreach ($document['executions'] as $group) {
                foreach ($group['units'] as $unit) {
                    if ($unit['identity']['key'] !== $unitKey) {
                        continue;
                    }
                    if (!$unit['identity']['verifiable']) {
                        return ['decision' => 'unverifiable-context'];
                    }
                    if ($unit['identity']['verifiable'] && $this->validCoverage($state['coverage'][$unitKey] ?? null)) {
                        return ['decision' => 'fallback-not-needed'];
                    }
                    $attempts = $state['attempts'][$group['execution_id']] ?? [];
                    $last = $attempts === [] ? null : $attempts[count($attempts) - 1];
                    if ($last === null || !in_array($last['status'], ['completed', 'failed'], true)) {
                        return ['decision' => 'primary-not-settled'];
                    }
                    $caps = array_column($catalog->jsonSerialize()['capabilities'], null, 'id');
                    return ['decision' => 'fallback-not-approved', 'primary' => $unit['capability'],
                        'alternative' => isset($caps[$alternative]) ? $alternative : null,
                        'reason' => 'Catálogo v1 não aprova equivalências; falta de cobertura sozinha não autoriza alternativa.'];
                }
            }
            throw new InvalidArgumentException('Unidade fora do plano.');
        });
    }

    /**
     * Consulta o journal sob a mesma trava, sem permitir leitura parcial.
     * @return array<string,mixed> Estado privado ou busy enquanto um motor está ativo.
     */
    public function inspect(): array
    {
        return $this->locked(static fn (array &$state): array => $state);
    }

    /**
     * Registra confirmação externa da parada de uma tentativa incerta específica.
     *
     * Não prova término por inspeção de PID. O chamador deve verificar o job e
     * seus descendentes; esta operação permite criar novo plano após contexto
     * desconhecido, sem liberar retomada nem fabricar cobertura.
     *
     * @return array<string,mixed> Confirmação registrada ou busy.
     */
    public function confirmStopped(string $attemptToken, string $reason): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $attemptToken) !== 1 || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $reason) !== 1) {
            throw new InvalidArgumentException('Confirmação de parada inválida.');
        }
        return $this->locked(function (array &$state) use ($attemptToken, $reason): array {
            foreach ($state['attempts'] as &$attempts) {
                foreach ($attempts as &$attempt) {
                    if ($attempt['token'] === $attemptToken && in_array($attempt['status'], ['running', 'interrupted'], true)) {
                        $attempt['status'] = 'recovered';
                        $attempt['confirmed_stopped_at'] = $this->now();
                        $attempt['stop_reason'] = $reason;
                        $this->save($state);
                        return ['decision' => 'stop-confirmed', 'attempt' => $attemptToken];
                    }
                }
            }
            throw new InvalidArgumentException('Tentativa incerta não encontrada.');
        });
    }

    /**
     * Executa transação não bloqueante; a trava cobre também o callback do motor.
     * @param callable(array<string,mixed>&):array<string,mixed> $operation Transação no estado carregado.
     * @return array<string,mixed> Resultado da operação ou busy.
     */
    private function locked(callable $operation): array
    {
        $path = $this->directory . '/ledger.lock';
        if (is_link($path)) {
            throw new RuntimeException('Trava simbólica recusada.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Trava indisponível.');
        }
        if (!chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('Não foi possível proteger a trava.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return ['decision' => 'busy'];
            }
            $statePath = $this->directory . '/ledger.json';
            if (is_link($statePath)) {
                throw new RuntimeException('Journal simbólico recusado.');
            }
            $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR)
                : ['ledger_version' => 1, 'attempts' => [], 'coverage' => []];
            if (!is_array($state) || ($state['ledger_version'] ?? null) !== 1
                || !is_array($state['attempts'] ?? null) || !is_array($state['coverage'] ?? null)) {
                throw new RuntimeException('Journal inválido; recuperação exige revisão.');
            }
            return $operation($state);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Valida resultado fechado do adapter e constrói cobertura por unidade.
     * @param array<string,mixed> $group Grupo efetivamente reservado.
     * @param mixed $result Resultado devolvido pelo adapter após parada do motor.
     * @return array<string,array<string,mixed>> Cobertura validada para o journal.
     */
    private function result(array $group, mixed $result): array
    {
        $expected = array_column(array_column($group['units'], 'identity'), 'key');
        if (!is_array($result) || !in_array($result['status'] ?? null, ['completed', 'failed'], true)
            || !is_array($result['units'] ?? null) || array_diff(array_keys($result['units']), $expected) !== []
            || array_diff($expected, array_keys($result['units'])) !== []) {
            throw new InvalidArgumentException('Resultado não contempla exatamente o grupo reservado.');
        }
        /** @var array<string,array<string,mixed>> $records Cobertura normalizada sem saída nativa bruta. */
        $records = [];
        foreach ($result['units'] as $key => $unit) {
            if (!is_array($unit) || !in_array($unit['coverage'] ?? null, ['complete', 'partial', 'unknown'], true)
                || !is_bool($unit['session_valid'] ?? null) || !is_int($unit['valid_until'] ?? null)
                || !is_array($unit['evidence'] ?? null)) {
                throw new InvalidArgumentException('Cobertura inválida.');
            }
            $evidence = $unit['evidence'];
            $record = ['coverage' => $unit['coverage'], 'operation' => $result['status'],
                'session_valid' => $unit['session_valid'], 'created_at' => $this->now(),
                'valid_until' => min($unit['valid_until'], $this->now() + $this->maxReuseSeconds),
                'evidence' => ['reference' => $evidence['reference'] ?? null, 'sha256' => $evidence['sha256'] ?? null,
                    'expires_at' => $evidence['expires_at'] ?? null, 'raw' => $evidence['raw'] ?? null]];
            if (!$this->evidenceValid($record['evidence'])) {
                throw new InvalidArgumentException('Evidência ausente, expirada ou divergente.');
            }
            $records[$key] = $record;
        }
        return $records;
    }

    /**
     * Exige operação/cobertura completas, sessão válida, prazo e evidência íntegra.
     * @param mixed $record Registro anterior, nunca aceito apenas pela presença de findings.
     */
    private function validCoverage(mixed $record): bool
    {
        return is_array($record) && ($record['coverage'] ?? null) === 'complete'
            && ($record['operation'] ?? null) === 'completed' && ($record['session_valid'] ?? null) === true
            && is_int($record['valid_until'] ?? null) && $record['valid_until'] > $this->now()
            && is_int($record['created_at'] ?? null) && $record['created_at'] <= $this->now()
            && $record['created_at'] + $this->maxReuseSeconds > $this->now()
            && $this->evidenceValid($record['evidence'] ?? null);
    }

    /**
     * Confere bytes do envelope e payload EvidenceStore sem seguir links ou aceitar raw.
     * @param mixed $reference Referência privada à evidência sanitizada.
     */
    private function evidenceValid(mixed $reference): bool
    {
        if (!is_array($reference) || ($reference['raw'] ?? null) !== false
            || !is_string($reference['reference'] ?? null)
            || preg_match('/^safe-[a-f0-9]{24}\.json$/D', $reference['reference']) !== 1
            || !is_string($reference['sha256'] ?? null) || !is_int($reference['expires_at'] ?? null)) {
            return false;
        }
        $path = $this->evidenceDirectory . '/' . $reference['reference'];
        if (is_link($path) || !is_file($path)) {
            return false;
        }
        $bytes = (string) file_get_contents($path);
        if (!hash_equals($reference['sha256'], hash('sha256', $bytes))) {
            return false;
        }
        try {
            $envelope = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        return is_array($envelope) && ($envelope['raw'] ?? null) === false
            && ($envelope['expires_at'] ?? null) === $reference['expires_at'] && $reference['expires_at'] > $this->now()
            && is_string($envelope['payload'] ?? null) && is_string($envelope['sha256'] ?? null)
            && hash_equals($envelope['sha256'], hash('sha256', $envelope['payload']));
    }

    /**
     * Publica o journal completo atomicamente sem trocar o inode da trava.
     * @param array<string,mixed> $state Estado protegido pelo flock.
     */
    private function save(array $state): void
    {
        EvidenceStore::atomicWrite($this->directory . '/ledger.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * Guarda o plano imutável antes de reservar/iniciar a primeira tentativa.
     * @param array<string,mixed> $plan Snapshot produzido por SecurityScanPlan.
     */
    private function persistPlan(array $plan): void
    {
        $name = substr($plan['plan_id'], strlen('sec-plan-v1:'));
        if (preg_match('/^[a-f0-9]{64}$/D', $name) !== 1) {
            throw new InvalidArgumentException('ID de plano inválido.');
        }
        $path = $this->directory . '/plans/' . $name . '.json';
        $bytes = json_encode($plan, JSON_THROW_ON_ERROR);
        if (is_file($path) && (is_link($path) || file_get_contents($path) !== $bytes)) {
            throw new RuntimeException('Snapshot do plano divergiu.');
        }
        EvidenceStore::atomicWrite($path, $bytes);
    }

    /**
     * Localiza somente grupos selecionados, nunca exclusões ou unidades bloqueadas.
     * @param array<string,mixed> $plan Plano validado pela classe de origem.
     * @return array<string,mixed> Grupo reservado.
     */
    private static function group(array $plan, string $executionId): array
    {
        foreach ($plan['executions'] as $group) {
            if ($group['execution_id'] === $executionId) {
                return $group;
            }
        }
        throw new InvalidArgumentException('Execução fora do plano.');
    }

    /** Lê relógio explícito para testes de expiração, usando epoch UTC em produção. */
    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
