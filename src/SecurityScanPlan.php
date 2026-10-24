<?php

declare(strict_types=1);

require_once __DIR__ . '/SecurityTestIdentity.php';
require_once __DIR__ . '/SecurityContract.php';

/**
 * Gera o plano SEC-04.03 antes de selecionar/iniciar processos externos.
 *
 * O catálogo v1 não aprova equivalências entre motores. Somente unidades
 * verificáveis idênticas são removidas; execuções compartilhadas são agrupadas
 * sem confundir seleção, cobertura concluída ou validade futura de evidência.
 */
final class SecurityScanPlan implements JsonSerializable
{
    /**
     * Preserva um snapshot serializável do planejamento sem executar ferramentas.
     * @param array<string,mixed> $document Plano validado com unidades e decisões.
     */
    private function __construct(private readonly array $document)
    {
    }

    /**
     * Planeja propostas explícitas de adapters, com fatos e controles comprovados.
     *
     * Nenhum digest é descoberto aqui. O adapter deve comprovar os metadados antes
     * de afirmar applicability_verified, selection_control e limits_verified.
     * Fontes obrigatórias ausentes continuam visíveis como missing.
     *
     * @param list<array{capability:string,input:array<string,mixed>,applicability_verified:bool,selection_control:string,limits_verified:bool}> $proposals Unidades candidatas no mesmo run/projeto/profile.
     */
    public static function build(CapabilityCatalog $catalog, string $scope, array $proposals): self
    {
        if (!in_array($scope, ['code', 'plugin', 'web-full'], true) || !array_is_list($proposals)) {
            throw new InvalidArgumentException('SecurityScanPlan: escopo ou lista de propostas inválidos.');
        }
        $catalogData = $catalog->jsonSerialize();
        $capabilities = array_column($catalogData['capabilities'], null, 'id');
        $required = ['composer-audit', 'osv', 'semgrep', 'psalm'];
        if ($scope === 'web-full') {
            $required = [...$required, 'zap', 'nuclei'];
        }
        /** @var array<string,string> $sources Estado de seleção das fontes obrigatórias. */
        $sources = array_fill_keys($required, 'missing');
        /** @var array<string,array<string,mixed>> $groups Execuções com fatos efetivos compatíveis. */
        $groups = [];
        /** @var array<string,array<string,mixed>> $units Unidades selecionadas indexadas pela identidade. */
        $units = [];
        /** @var list<array<string,mixed>> $excluded Decisões de exclusão com justificativa. */
        $excluded = [];
        /** @var list<array<string,mixed>> $blocked Unidades impedidas por falta de controles/fatos. */
        $blocked = [];
        /** @var array<string,array<string,mixed>> $warnings Avisos indexados para evitar repetição textual. */
        $warnings = [];
        $runContext = null;
        foreach ($proposals as $proposal) {
            self::proposal($proposal);
            $capability = $capabilities[$proposal['capability']] ?? null;
            if ($capability === null) {
                throw new InvalidArgumentException('SecurityScanPlan: capacidade fora do catálogo.');
            }
            $identity = SecurityTestIdentity::create($catalog, $proposal['capability'], $proposal['input']);
            $serialized = $identity->jsonSerialize();
            $descriptor = $serialized['descriptor'];
            if ($scope === 'plugin' && $descriptor['context']['profile'] !== 'glpi-plugin') {
                throw new InvalidArgumentException('SecurityScanPlan: escopo plugin exige profile glpi-plugin.');
            }
            $context = [$proposal['input']['run_id'], $descriptor['context']['project_digest'], $descriptor['context']['profile']];
            if ($runContext !== null && $runContext !== $context) {
                throw new InvalidArgumentException('SecurityScanPlan: propostas de runs/projetos/profiles distintos.');
            }
            $runContext = $context;
            $owner = $capability['owner'];
            $web = in_array($owner, ['zap', 'nuclei'], true);
            $unit = ['identity' => $serialized, 'capability' => $proposal['capability'], 'owner' => $owner,
                'selectors' => $capability['selectors'], 'controls' => [
                    'applicability_verified' => $proposal['applicability_verified'],
                    'selection_control' => $proposal['selection_control'], 'limits_verified' => $proposal['limits_verified']]];

            // Não aplicabilidade de escopo/profile não é exclusão por equivalência.
            if ($scope !== 'web-full' && $web) {
                $excluded[] = $unit + ['reason' => $scope === 'plugin' ? 'outside-plugin-code-scope' : 'outside-code-scope', 'equivalent' => false];
                continue;
            }
            if ($owner === 'semgrep') {
                $configs = SecurityContract::forProfile($descriptor['context']['profile'])->semgrepConfigs;
                foreach ($capability['selectors'] as $selector) {
                    $path = $catalogData['artifacts'][$selector['artifact']]['path'];
                    if (!in_array($path, $configs, true)) {
                        $excluded[] = $unit + ['reason' => 'profile-not-applicable', 'equivalent' => false,
                            'evidence' => ['profile' => $descriptor['context']['profile'], 'allowed_configs' => $configs, 'rule_config' => $path]];
                        continue 2;
                    }
                }
            }
            /** @var list<string> $reasons Controles que impedem iniciar esta unidade. */
            $reasons = [];
            if (!$proposal['applicability_verified']) {
                $reasons[] = 'applicability-unverified';
            }
            if ($proposal['selection_control'] !== 'exact') {
                $reasons[] = 'selection-uncontrolled';
                $warnings['uncontrolled:' . $owner] = ['code' => 'uncontrolled-overlap', 'owner' => $owner,
                    'reason' => 'Adapter não comprova seleção exata; pode executar regras não catalogadas.'];
            }
            if ($web && !$proposal['limits_verified']) {
                $reasons[] = 'dast-limits-unverified';
            }
            if ($reasons !== []) {
                $blocked[] = $unit + ['reasons' => $reasons];
                if ($sources[$owner] === 'missing') {
                    $sources[$owner] = 'blocked';
                }
                continue;
            }
            $sources[$owner] = 'selected';
            if ($identity->verifiable && isset($units[$identity->key])) {
                $excluded[] = $unit + ['reason' => 'identical-verifiable-unit', 'equivalent' => true,
                    'covered_by' => $identity->key, 'justification' => 'Mesma capacidade, contexto, vetor e bindings; agendada uma vez neste plano.'];
                continue;
            }
            $units[$identity->key] = $unit;
            if (!$identity->verifiable) {
                $warnings[$identity->key] = ['code' => 'identity-unverifiable', 'unit' => $identity->key,
                    'limitations' => $identity->limitations, 'reason' => 'Sem deduplicação/reutilização; execuções separadas até comprovação dos fatos.'];
            }
            if ($owner === 'psalm') {
                $warnings['psalm-program'] = ['code' => 'indivisible-engine-analysis', 'owner' => 'psalm',
                    'reason' => 'Tipos de issue compartilham análise do programa; checks fora do catálogo podem ocorrer. Seletores são projeções de cobertura, não filtros de execução.'];
            }

            // Remove apenas a dimensão de regra ao agrupar uma passagem do motor.
            $bindings = $descriptor['bindings'];
            unset($bindings['rules_digest']);
            $phase = match ($owner) {
                'composer-audit', 'osv' => 'sca', 'semgrep', 'psalm' => 'sast',
                'zap' => $capability['technique'] === 'passive-response' ? 'zap-passive' : 'zap-active',
                default => 'nuclei-http',
            };
            $groupKey = self::hash(['owner' => $owner, 'phase' => $phase, 'context' => $descriptor['context'],
                'asset' => $descriptor['asset'], 'request' => $descriptor['request'], 'bindings' => $bindings,
                'isolation' => $identity->verifiable ? null : $identity->key]);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['owner' => $owner, 'phase' => $phase,
                    'selection_mode' => $owner === 'psalm' ? 'whole-program' : 'exact-selectors', 'units' => []];
            }
            $groups[$groupKey]['units'][] = $unit;
        }

        // Relações candidatas e complementares nunca autorizam exclusão cruzada na v1.
        $selectedIds = array_column($units, 'capability');
        foreach ($catalogData['relationships'] as $relationship) {
            if (in_array($relationship['left'], $selectedIds, true) && in_array($relationship['right'], $selectedIds, true)) {
                $key = $relationship['left'] . ':' . $relationship['right'];
                $warnings[$key] = ['code' => $relationship['status'] === 'candidate' ? 'uncontrolled-overlap' : 'complementary-overlap',
                    'left' => $relationship['left'], 'right' => $relationship['right'],
                    'reason' => $relationship['reason'], 'suppressed' => false];
            }
        }
        foreach ($sources as $owner => $status) {
            if ($status === 'missing') {
                $warnings['missing:' . $owner] = ['code' => 'required-source-missing', 'owner' => $owner,
                    'reason' => 'Nenhuma proposta aplicável foi selecionada; não implica não aplicabilidade.'];
            }
        }
        foreach ($groups as &$group) {
            usort($group['units'], static fn (array $a, array $b): int => strcmp($a['identity']['key'], $b['identity']['key']));
            $group['execution_id'] = 'sec-execution-v1:' . self::hash($group);
        }
        unset($group);
        $groups = array_values($groups);
        $order = ['composer-audit:sca', 'osv:sca', 'semgrep:sast', 'psalm:sast', 'zap:zap-passive', 'zap:zap-active', 'nuclei:nuclei-http'];
        usort($groups, static fn (array $a, array $b): int =>
            (array_search($a['owner'] . ':' . $a['phase'], $order, true) <=> array_search($b['owner'] . ':' . $b['phase'], $order, true))
            ?: strcmp($a['execution_id'], $b['execution_id']));
        usort($excluded, static fn (array $a, array $b): int => strcmp(self::hash($a), self::hash($b)));
        usort($blocked, static fn (array $a, array $b): int => strcmp(self::hash($a), self::hash($b)));
        ksort($warnings, SORT_STRING);
        $document = ['plan_version' => 1, 'catalog_version' => $catalogData['catalog_version'],
            'run_id' => $runContext[0] ?? null, 'project_digest' => $runContext[1] ?? null, 'profile' => $runContext[2] ?? null,
            'scope' => $scope, 'execution_status' => 'not-started', 'required_sources' => $sources,
            'source_selection_complete' => !in_array('missing', $sources, true) && !in_array('blocked', $sources, true) && $blocked === [],
            'executions' => $groups, 'excluded' => $excluded, 'blocked' => $blocked,
            'unrequested_capabilities' => array_values(array_diff(array_keys($capabilities), array_column($proposals, 'capability'))),
            'cross_engine_equivalence_exclusions' => [], 'warnings' => array_values($warnings)];
        $document['plan_id'] = 'sec-plan-v1:' . self::hash($document);
        return new self($document);
    }

    /**
     * Exporta decisões para persistência antes de qualquer processo externo.
     * @return array<string,mixed> Plano sem comandos executáveis ou segredos brutos.
     */
    public function jsonSerialize(): array
    {
        return $this->document;
    }

    /**
     * Exige shape explícito para não assumir prova de controles ausentes.
     * @param mixed $proposal Proposta recebida de um adapter.
     */
    private static function proposal(mixed $proposal): void
    {
        if (!is_array($proposal)) {
            throw new InvalidArgumentException('SecurityScanPlan: proposta inválida.');
        }
        $keys = array_keys($proposal);
        sort($keys);
        if ($keys !== ['applicability_verified', 'capability', 'input', 'limits_verified', 'selection_control']
            || !is_string($proposal['capability']) || !is_array($proposal['input'])
            || !is_bool($proposal['applicability_verified']) || !is_bool($proposal['limits_verified'])
            || !in_array($proposal['selection_control'], ['exact', 'uncontrolled'], true)) {
            throw new InvalidArgumentException('SecurityScanPlan: campos ou controles inválidos.');
        }
    }

    /**
     * Ordena mapas recursivamente preservando listas para hashes de plano/execução.
     * @param array<array-key,mixed> $value Estrutura de planejamento sem objetos.
     * @return array<array-key,mixed> Estrutura canônica.
     */
    private static function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonical($child);
            }
        }
        return $value;
    }

    /**
     * Calcula fingerprint do snapshot, incluindo seleção efetiva de unidades.
     * @param array<array-key,mixed> $value Descritor estruturado de planejamento.
     */
    private static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
