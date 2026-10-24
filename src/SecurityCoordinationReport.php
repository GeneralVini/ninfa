<?php

declare(strict_types=1);

require_once __DIR__ . '/SecurityScanPlan.php';

/**
 * Explica decisões SEC-04 sem confundir supressão de testes com deduplicação de findings.
 *
 * O snapshot do journal é histórico: a geração do relatório não revalida sessão,
 * prazo ou bytes de evidência e nunca autoriza reutilização de cobertura.
 */
final class SecurityCoordinationReport implements JsonSerializable
{
    /**
     * Preserva a projeção auditável e independente do schema 3 de findings.
     * @param array<string,mixed> $document Dados de coordenação filtrados para um plano.
     */
    private function __construct(private readonly array $document)
    {
    }

    /**
     * Relaciona exclusões do plano e decisões do ledger sem atribuir eventos de outro run.
     *
     * A referência de uma reutilização vem do snapshot da decisão, nunca do mapa
     * mutável de cobertura atual. Decisões antigas sem esse snapshot são marcadas.
     *
     * @param array<string,mixed> $journal Snapshot obtido por inspect() sob trava.
     */
    public static function fromPlan(SecurityScanPlan $plan, array $journal): self
    {
        if (($journal['ledger_version'] ?? null) !== 1 || !is_array($journal['decisions'] ?? [])
            || !is_array($journal['attempts'] ?? null)) {
            throw new InvalidArgumentException('SecurityCoordinationReport exige snapshot completo do ledger; busy não é estado vazio.');
        }
        $journal['decisions'] ??= [];
        $document = $plan->jsonSerialize();
        /** @var list<array<string,mixed>> $suppressed Exclusões por identidade ou reuso histórico. */
        $suppressed = [];
        /** @var list<array<string,mixed>> $otherExclusions Exclusões de escopo/profile sem equivalência. */
        $otherExclusions = [];
        foreach ($document['excluded'] as $excluded) {
            $row = ['capability' => $excluded['capability'], 'unit' => $excluded['identity']['key'],
                'reason' => $excluded['reason']];
            if ($excluded['reason'] === 'identical-verifiable-unit') {
                $suppressed[] = $row + ['kind' => 'duplicate-proposal', 'covered_by' => $excluded['covered_by'],
                    'explanation' => 'Mesma unidade verificável agendada uma vez no plano; nenhuma execução anterior presumida.'];
            } else {
                $otherExclusions[] = $row + ['explanation' => 'Exclusão por escopo/profile; não comprova equivalência ou cobertura.'];
            }
        }
        /** @var array<string,array<string,mixed>> $latest Última decisão de cada grupo neste plano. */
        $latest = [];
        foreach ($journal['decisions'] as $decision) {
            if (($decision['plan_id'] ?? null) === $document['plan_id'] && is_string($decision['execution_id'] ?? null)) {
                $latest[$decision['execution_id']] = $decision;
            }
        }
        /** @var list<array<string,mixed>> $units Unidades selecionadas, inclusive as ainda não executadas. */
        $units = [];
        $attemptCount = 0;
        $reuseEvents = 0;
        foreach ($document['executions'] as $group) {
            foreach ($journal['attempts'][$group['execution_id']] ?? [] as $attempt) {
                if (($attempt['plan_id'] ?? null) === $document['plan_id']) {
                    $attemptCount++;
                }
            }
            $decision = $latest[$group['execution_id']] ?? null;
            $result = $decision['result'] ?? [];
            foreach ($group['units'] as $unit) {
                $key = $unit['identity']['key'];
                $units[] = ['capability' => $unit['capability'], 'unit' => $key, 'owner' => $unit['owner'],
                    'execution_id' => $group['execution_id'], 'latest_decision' => $result['decision'] ?? 'not-started'];
            }
        }
        // Todos os eventos de reuso são auditáveis, mesmo após retry/replanejamento posterior.
        $byKey = array_column($units, null, 'unit');
        $groupsById = array_column($document['executions'], null, 'execution_id');
        foreach ($journal['decisions'] as $decision) {
            if (($decision['plan_id'] ?? null) !== $document['plan_id'] || ($decision['result']['decision'] ?? null) !== 'reused') {
                continue;
            }
            $group = $groupsById[$decision['execution_id'] ?? ''] ?? null;
            $reuseKeys = $decision['result']['units'] ?? null;
            if ($group === null || !is_int($decision['at'] ?? null) || !is_array($reuseKeys) || !array_is_list($reuseKeys)) {
                throw new UnexpectedValueException('Decisão de reuso incompatível com o plano.');
            }
            $expected = array_column(array_column($group['units'], 'identity'), 'key');
            $actual = $reuseKeys;
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw new UnexpectedValueException('Decisão de reuso não corresponde exatamente ao grupo.');
            }
            $reuseEvents++;
            foreach ($decision['result']['units'] ?? [] as $key) {
                if (!isset($byKey[$key])) {
                    throw new UnexpectedValueException('Decisão de reuso referencia unidade fora do plano.');
                }
                $snapshot = $decision['result']['coverage_snapshot'][$key] ?? null;
                $provenance = self::provenance($snapshot, $decision['at']);
                $suppressed[] = ['kind' => 'valid-coverage-reused', 'capability' => $byKey[$key]['capability'],
                    'unit' => $key, 'execution_id' => $decision['execution_id'], 'at' => $decision['at'],
                    'reason' => 'valid-coverage-at-decision-time', 'provenance' => $provenance,
                    'explanation' => 'Ledger validou identidade, contexto, operação, cobertura, sessão, prazo e evidência no momento da decisão; adapter não chamado.'];
            }
        }
        return new self(['coordination_schema_version' => 1, 'plan_id' => $document['plan_id'],
            'run_id' => $document['run_id'], 'scope' => $document['scope'],
            'required_sources' => $document['required_sources'], 'source_selection_complete' => $document['source_selection_complete'],
            'attempts_in_this_plan' => $attemptCount, 'reuse_events_in_this_plan' => $reuseEvents,
            'selected_units' => $units, 'suppression_events' => $suppressed,
            'other_exclusions' => $otherExclusions, 'blocked' => $document['blocked'],
            'unrequested_capabilities' => $document['unrequested_capabilities'], 'warnings' => $document['warnings'],
            'limits' => ['Histórico de coordenação, sem revalidação atual de cobertura/evidência.',
                'Eventos de supressão não são contagem de vulnerabilidades nem de requisições HTTP poupadas.',
                'Seleção das fontes não comprova baseline completo ou auditoria concluída.',
                'Sem equivalência entre motores aprovada no catálogo v1.']]);
    }

    /**
     * Exporta a projeção para futura composição com o relatório canônico.
     * @return array<string,mixed> Coordenação versionada, separada de findings.
     */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /** Renderiza seção Markdown com motivo e referência para cada evento de supressão. */
    public function markdown(): string
    {
        $lines = ['# Coordenação dos testes', '', 'Plano: `' . $this->document['plan_id'] . '`.', '',
            'Tentativas neste plano: ' . $this->document['attempts_in_this_plan'] . '. Eventos de reuso: ' . $this->document['reuse_events_in_this_plan'] . '.', '',
            '## Eventos de supressão', '', '| Capacidade | Decisão | Motivo e referência |', '| --- | --- | --- |'];
        foreach ($this->document['suppression_events'] as $event) {
            $reference = $event['covered_by'] ?? ($event['provenance']['attempt'] ?? 'proveniência histórica não capturada');
            $lines[] = '| ' . self::cell($event['capability']) . ' | ' . self::cell($event['kind']) . ' | '
                . self::cell($event['explanation'] . ' Referência: ' . $reference) . ' |';
        }
        if ($this->document['suppression_events'] === []) {
            $lines[] = '| — | — | Nenhum evento de supressão registrado para este plano. |';
        }
        $lines = [...$lines, '', '## Outras decisões', '',
            'Exclusões por escopo/profile: ' . count($this->document['other_exclusions']) . '. Unidades bloqueadas no plano: '
            . count($this->document['blocked']) . '. Capacidades não solicitadas: ' . count($this->document['unrequested_capabilities']) . '.', '',
            '## Avisos de sobreposição e cobertura', ''];
        foreach ($this->document['warnings'] as $warning) {
            $lines[] = '- ' . self::cell($warning['code']) . ': ' . self::cell($warning['reason']);
        }
        $lines = [...$lines, '', '## Limites', ''];
        foreach ($this->document['limits'] as $limit) {
            $lines[] = '- ' . $limit;
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Extrai somente referências restritas do snapshot histórico, sem consultar cobertura atual.
     * @param mixed $snapshot Registro de cobertura congelado na decisão de reuso.
     * @return array<string,mixed> Proveniência capturada ou limitação de decisão legada.
     */
    private static function provenance(mixed $snapshot, int $at): array
    {
        if ($snapshot === null) {
            return ['status' => 'legacy-decision-without-provenance'];
        }
        if (!is_array($snapshot) || ($snapshot['coverage'] ?? null) !== 'complete'
            || ($snapshot['operation'] ?? null) !== 'completed' || ($snapshot['session_valid'] ?? null) !== true
            || !is_int($snapshot['created_at'] ?? null) || $snapshot['created_at'] > $at
            || !is_int($snapshot['valid_until'] ?? null) || $snapshot['valid_until'] <= $at
            || ($snapshot['evidence']['raw'] ?? null) !== false
            || !is_int($snapshot['evidence']['expires_at'] ?? null) || $snapshot['evidence']['expires_at'] <= $at
            || !is_string($snapshot['attempt'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $snapshot['attempt']) !== 1
            || !is_string($snapshot['evidence']['reference'] ?? null)
            || preg_match('/^safe-[a-f0-9]{24}\.json$/D', $snapshot['evidence']['reference']) !== 1
            || !is_string($snapshot['evidence']['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $snapshot['evidence']['sha256']) !== 1) {
            throw new UnexpectedValueException('Snapshot histórico de cobertura inválido.');
        }
        return ['status' => 'captured-at-decision-time', 'attempt' => $snapshot['attempt'],
            'source_plan_id' => is_string($snapshot['plan_id'] ?? null)
                && preg_match('/^sec-plan-v1:[a-f0-9]{64}$/D', $snapshot['plan_id']) === 1 ? $snapshot['plan_id'] : null,
            'evidence_reference' => $snapshot['evidence']['reference'], 'evidence_sha256' => $snapshot['evidence']['sha256']];
    }

    /** Escapa células Markdown para que identificadores/avisos não criem tabelas ou HTML ativos. */
    private static function cell(string $value): string
    {
        return str_replace(['|', '`', "\r", "\n"], ['\\|', "'", ' ', ' '], htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}
