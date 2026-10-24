<?php

declare(strict_types=1);

require_once __DIR__ . '/SecurityExecutionLedger.php';
require_once __DIR__ . '/ProcessRunner.php';
require_once __DIR__ . '/ZapParser.php';

/** Executa descoberta GET controlada e ZAP passivo offline sob reserva SEC-04. */
final class ZapPassiveAdapter
{
    /** @var array<string,mixed> $policy Política canônica validada pelo transporte, sem credenciais. */
    private readonly array $policy;
    /** @var array<string,mixed> $lock Snapshot das ferramentas fixadas no repositório. */
    private readonly array $lock;
    /** @var array<string,mixed>|null $last Último resultado sanitizado, incluindo limites e medidas. */
    private ?array $last = null;

    /**
     * Valida política sem iniciar ZAP ou acessar o consumidor.
     * @param array<string,mixed> $policy Origem/IP, URLs GET classificadas e limites explícitos.
     */
    public function __construct(array $policy)
    {
        $this->policy = $this->transport(['policy' => $policy], true);
        $this->lock = json_decode((string) file_get_contents(dirname(__DIR__) . '/security/web-tools.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Gera propostas com fingerprints efetivos; contexto/deploy continuam fatos do chamador.
     * @param CapabilityCatalog $catalog Catálogo fixado e validado antes do planejamento.
     * @param array<string,mixed> $input Contexto SEC-04 com run_id e context; demais dimensões vêm deste adapter.
     * @param list<string> $capabilities Capacidades passivas explicitamente solicitadas.
     * @return list<array<string,mixed>> Propostas para SecurityScanPlan::build(web-full).
     */
    public function proposals(CapabilityCatalog $catalog, array $input, array $capabilities): array
    {
        $known = array_column($catalog->jsonSerialize()['capabilities'], null, 'id');
        /** @var list<array<string,mixed>> $proposals Propostas com seleção explícita e cobertura ainda não executada. */
        $proposals = [];
        foreach ($capabilities as $id) {
            if (($known[$id]['owner'] ?? null) !== 'zap' || ($known[$id]['technique'] ?? null) !== 'passive-response') {
                throw new InvalidArgumentException('Adapter aceita somente capacidades ZAP passivas catalogadas.');
            }
            $facts = $input;
            // Sem credenciais, cookie jar ou login: não herdar o papel autenticado de outro estágio.
            $facts['context']['role_digest'] = hash('sha256', 'ninfa-anonymous-get-v1');
            $facts['context']['session_context_digest'] = null;
            $facts['asset'] = ['kind' => 'http-response', 'digest' => $this->configurationDigest()];
            $facts['request'] = ['method' => 'GET', 'parameter_location' => null, 'parameter_digest' => null];
            $facts['bindings'] = $this->bindings() + ['rules_digest' => SecurityTestIdentity::rulesDigest($catalog, $id)];
            $proposals[] = ['capability' => $id, 'input' => $facts, 'applicability_verified' => true,
                'selection_control' => 'exact', 'limits_verified' => true];
        }
        return $proposals;
    }

    /**
     * Delega ao ledger antes de executar; reuso/retry nunca é decidido pelo transporte.
     * @param SecurityExecutionLedger $ledger Reserva e journal externos ao consumidor.
     * @param SecurityScanPlan $plan Plano persistido pelo ledger antes de efeitos externos.
     * @param string $executionId Grupo ZAP passivo selecionado.
     * @param EvidenceStore $store Store para achados e medidas sanitizados.
     * @return array<string,mixed> Decisão do ledger; detalhe disponível em lastResult().
     */
    public function run(SecurityExecutionLedger $ledger, SecurityScanPlan $plan, string $executionId, EvidenceStore $store, bool $contextValidated, ?string $retryReason = null): array
    {
        $this->last = null;
        return $ledger->run($plan, $executionId, function (array $group) use ($store): array {
            if ($group['owner'] !== 'zap' || $group['phase'] !== 'zap-passive') {
                throw new InvalidArgumentException('Grupo incompatível com adapter passivo.');
            }
            /** @var list<string> $rules Seletores nativos sem transformar alertas em prova de cobertura. */
            $rules = [];
            foreach ($group['units'] as $unit) {
                $descriptor = $unit['identity']['descriptor'];
                foreach ($this->bindings() as $key => $value) {
                    if ($descriptor['bindings'][$key] !== $value) {
                        throw new InvalidArgumentException('Bindings efetivos divergentes do plano.');
                    }
                }
                if ($descriptor['asset']['digest'] !== $this->configurationDigest()) {
                    throw new InvalidArgumentException('Ativo diverge da política planejada.');
                }
                foreach ($unit['selectors'] as $selector) {
                    if ($selector['kind'] !== 'zap-passive-rule') {
                        throw new InvalidArgumentException('Seletor não passivo.');
                    }
                    $rules[] = $selector['id'];
                }
            }
            $result = $this->transport(['policy' => $this->policy, 'rules' => array_values(array_unique($rules))]);
            if (($result['scanner_stopped'] ?? null) !== true) {
                throw new RuntimeException('Término do scanner não comprovado; reserva permanece incerta.');
            }
            $parsed = ZapParser::parse(json_encode($result['alerts'], JSON_THROW_ON_ERROR));
            unset($result['alerts']);
            $result['findings'] = $parsed['findings'];
            $result['native_exit_code'] = null; // Daemon encerrado pelo coordenador; não é um CLI de scan.
            $result['parser_coverage'] = $parsed['coverage'];
            $result['configuration_digest'] = $this->configurationDigest();
            $this->last = EvidenceSanitizer::value($result);
            $evidence = $store->put($this->last);
            /** @var array<string,array<string,mixed>> $units Cobertura conservadora por unidade solicitada. */
            $units = [];
            foreach ($group['units'] as $unit) {
                $units[$unit['identity']['key']] = ['coverage' => 'partial', 'session_valid' => false,
                    'valid_until' => time(), 'evidence' => $evidence];
            }
            return ['status' => $result['operation'] === 'completed' ? 'completed' : 'failed', 'units' => $units];
        }, $contextValidated, $retryReason);
    }

    /**
     * Expõe resultado sanitizado desta chamada, sem inventar detalhe quando o ledger bloqueou.
     * @return array<string,mixed>|null Medidas, findings, operação e limitações.
     */
    public function lastResult(): ?array
    {
        return $this->last;
    }

    /** Fingerprint vincula política, adapter e transporte para impedir reuso sob configuração diferente. */
    public function configurationDigest(): string
    {
        return hash('sha256', json_encode($this->policy, JSON_THROW_ON_ERROR) . hash_file('sha256', __FILE__)
            . hash_file('sha256', dirname(__DIR__) . '/scripts/zap_passive.py')
            . hash_file('sha256', dirname(__DIR__) . '/security/web-tools.lock.json'));
    }

    /**
     * Declara fingerprints de payload/runtime, cuja integridade o transporte verifica antes do tráfego.
     * @return array<string,mixed> Bindings comuns às regras do grupo.
     */
    private function bindings(): array
    {
        return ['engine_version' => $this->lock['tools']['zap']['version'],
            'engine_digest' => hash('sha256', json_encode($this->lock['tools']['zap']['files'], JSON_THROW_ON_ERROR)),
            'runtime_digest' => $this->lock['host_baseline']['java']['sha256'],
            'configuration_digest' => $this->configurationDigest(), 'data_digest' => null,
            'conditions_digest' => $this->configurationDigest(), 'payload_family_digest' => null];
    }

    /**
     * Usa arquivo de entrada privado e captura stdout/stderr sem shell ou segredos em argumentos.
     * @param array<string,mixed> $document Política e seleção explícitas.
     * @return array<string,mixed> Resposta estruturada; falha de transporte mantém reserva incerta.
     */
    private function transport(array $document, bool $validate = false): array
    {
        $directory = sys_get_temp_dir() . '/ninfa-zap-input-' . bin2hex(random_bytes(12));
        EvidenceStore::privateDirectory($directory);
        $input = EvidenceStore::atomicWrite($directory . '/input.json', json_encode($document, JSON_THROW_ON_ERROR));
        try {
            $command = ['python3', dirname(__DIR__) . '/scripts/zap_passive.py', $input];
            if ($validate) {
                $command[] = '--validate';
            }
            $result = (new ProcessRunner())->runCaptured($command, dirname(__DIR__));
            if ($result->exitCode !== 0) {
                throw new RuntimeException('Transporte ZAP recusou entrada ou não confirmou encerramento.');
            }
            return json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            unlink($input);
            rmdir($directory);
        }
    }
}
