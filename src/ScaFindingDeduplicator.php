<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';

/**
 * Consolida findings SCA equivalentes em vulnerabilidades canônicas.
 *
 * Considera somente findings `sca-advisory`, normaliza IDs para maiúsculas e
 * agrupa advisories que compartilham rule, advisory id, CVE ou aliases. O
 * agrupamento usa união de conjuntos, de forma que aliases transitivos também
 * conectem findings de fontes diferentes.
 *
 * A preferência de ID canônico é CVE, GHSA, PKSA, OSV e depois qualquer outro
 * identificador disponível. O resultado preserva fontes, proveniência,
 * componentes e IDs de origem e escolhe a severidade mais forte conhecida.
 * Esta classe não consulta fontes externas nem decide exposure/prioridade.
 */
final class ScaFindingDeduplicator
{
    /**
     * Agrupa findings SCA conectados por qualquer identificador compartilhado.
     *
     * Findings de outro evidence type são ignorados. Quando uma fonte não
     * fornece ID utilizável, um identificador sintético estável na execução
     * impede perda do finding sem conectá-lo indevidamente a outro advisory.
     * A saída é ordenada pelo ID canônico para produzir relatórios determinísticos.
     *
     * @param list<Finding> $findings Findings potencialmente oriundos de múltiplas fontes SCA.
     * @return list<array<string,mixed>> Vulnerabilidades canônicas com aliases, fontes e componentes.
     * @throws JsonException Quando metadata de componente não pode ser serializada para chave de deduplicação.
     */
    public static function canonicalize(array $findings): array
    {
        /** @var list<array{finding:Finding,identifiers:list<string>}> $advisories */
        $advisories = [];
        foreach ($findings as $index => $finding) {
            if (!$finding instanceof Finding || $finding->evidenceType !== 'sca-advisory') {
                continue;
            }

            $identifiers = self::identifiers($finding);
            // Sem ID compartilhável, cria identidade local para não fundir findings por acidente.
            if ($identifiers === []) {
                $identifiers = ['NINFA-SCA-' . sha1($finding->tool . '|' . $finding->rule . '|' . $index)];
            }
            $advisories[] = [
                'finding' => $finding,
                'identifiers' => $identifiers,
            ];
        }

        if ($advisories === []) {
            return [];
        }

        /** @var array<string,string> $parent Estrutura union-find indexada por identificador normalizado. */
        $parent = [];
        foreach ($advisories as $entry) {
            $ids = $entry['identifiers'];
            $first = $ids[0];
            $parent[$first] ??= $first;
            foreach ($ids as $id) {
                $parent[$id] ??= $id;
                self::union($parent, $first, $id);
            }
        }

        /** @var array<string,list<array{finding:Finding,identifiers:list<string>}>> $groups Findings agrupados pela raiz union-find. */
        $groups = [];
        foreach ($advisories as $entry) {
            $root = self::find($parent, $entry['identifiers'][0]);
            $groups[$root][] = $entry;
        }

        /** @var list<array<string,mixed>> $canonical Vulnerabilidades canônicas materializadas. */
        $canonical = [];
        foreach ($groups as $entries) {
            /** @var array<string,true> $allIdentifiers Conjunto de IDs/aliases do grupo. */
            $allIdentifiers = [];
            /** @var array<string,true> $sources Conjunto de ferramentas/fontes que confirmaram o grupo. */
            $sources = [];
            /** @var array<string,true> $provenance Proveniências únicas herdadas dos findings. */
            $provenance = [];
            /** @var array<string,array<string,mixed>> $components Componentes únicos indexados por JSON estável. */
            $components = [];
            /** @var array<string,array{source:string,id:string}> $sourceIds IDs originais por fonte. */
            $sourceIds = [];
            $summary = null;
            $severity = null;

            foreach ($entries as $entry) {
                $finding = $entry['finding'];
                foreach ($entry['identifiers'] as $id) {
                    $allIdentifiers[$id] = true;
                }
                $sources[$finding->tool] = true;
                foreach ($finding->provenance as $item) {
                    $provenance[$item] = true;
                }
                $severity = self::strongerSeverity($severity, $finding->severity);
                $summary ??= $finding->problem;

                // Componentes idênticos são colapsados pelo conteúdo, preservando registros distintos de versão/escopo.
                $component = $finding->metadata['component'] ?? null;
                if (is_array($component)) {
                    $key = json_encode($component, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $components[$key] = $component;
                }

                // source_ids preserva o identificador que cada fonte efetivamente apresentou.
                $advisory = $finding->metadata['advisory'] ?? [];
                $sourceId = [
                    'source' => $finding->tool,
                    'id' => is_array($advisory) && is_string($advisory['id'] ?? null)
                        ? $advisory['id']
                        : $finding->rule,
                ];
                $sourceIds[$sourceId['source'] . '|' . $sourceId['id']] = $sourceId;
            }

            /** @var list<string> $ids Identificadores únicos ordenados antes da escolha canônica. */
            $ids = array_keys($allIdentifiers);
            sort($ids);
            $canonicalId = self::canonicalId($ids);
            /** @var list<string> $aliases IDs restantes após remover o canônico. */
            $aliases = array_values(array_filter($ids, static fn (string $id): bool => $id !== $canonicalId));
            /** @var list<string> $sourceList Fontes confirmadoras ordenadas. */
            $sourceList = array_keys($sources);
            sort($sourceList);
            /** @var list<string> $provenanceList Proveniência ordenada para relatório determinístico. */
            $provenanceList = array_keys($provenance);
            sort($provenanceList);
            /** @var list<array<string,mixed>> $componentList Componentes únicos ordenados por nome/versão/escopo. */
            $componentList = array_values($components);
            usort(
                $componentList,
                static fn (array $a, array $b): int => [
                    (string) ($a['name'] ?? ''),
                    (string) ($a['version'] ?? ''),
                    (string) ($a['scope'] ?? ''),
                ] <=> [
                    (string) ($b['name'] ?? ''),
                    (string) ($b['version'] ?? ''),
                    (string) ($b['scope'] ?? ''),
                ],
            );
            /** @var list<array{source:string,id:string}> $sourceIdList IDs por fonte em ordem estável. */
            $sourceIdList = array_values($sourceIds);
            usort(
                $sourceIdList,
                static fn (array $a, array $b): int => [$a['source'], $a['id']] <=> [$b['source'], $b['id']],
            );

            $canonical[] = array_filter([
                'canonical_id' => $canonicalId,
                'aliases' => $aliases,
                'severity' => $severity,
                'summary' => $summary,
                'sources' => $sourceList,
                'source_ids' => $sourceIdList,
                'provenance' => $provenanceList,
                'components' => $componentList,
            ], static fn (mixed $value): bool => $value !== null && $value !== []);
        }

        usort(
            $canonical,
            static fn (array $a, array $b): int => (string) ($a['canonical_id'] ?? '') <=> (string) ($b['canonical_id'] ?? ''),
        );

        return $canonical;
    }

    /**
     * Extrai IDs úteis de rule e metadata advisory de um Finding SCA.
     *
     * IDs são normalizados/deduplicados por `addIdentifier()`; valores genéricos
     * do Composer que não identificam advisory real são descartados.
     *
     * @param Finding $finding Finding SCA a inspecionar.
     * @return list<string> Identificadores normalizados sem duplicatas.
     */
    private static function identifiers(Finding $finding): array
    {
        /** @var array<string,true> $ids Conjunto de identificadores normalizados. */
        $ids = [];
        self::addIdentifier($ids, $finding->rule);
        $advisory = $finding->metadata['advisory'] ?? null;
        if (is_array($advisory)) {
            self::addIdentifier($ids, $advisory['id'] ?? null);
            self::addIdentifier($ids, $advisory['cve'] ?? null);
            $aliases = $advisory['aliases'] ?? [];
            if (is_array($aliases)) {
                foreach ($aliases as $alias) {
                    self::addIdentifier($ids, $alias);
                }
            }
        }
        return array_keys($ids);
    }

    /**
     * Adiciona um identificador textual válido ao conjunto normalizado.
     *
     * @param array<string,true> $ids Conjunto mutável indexado pelo próprio ID.
     * @param mixed $value Valor candidato vindo de regra/metadata.
     */
    private static function addIdentifier(array &$ids, mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        $id = strtoupper(trim($value));
        // Marcadores genéricos não identificam vulnerabilidade e não podem unir findings distintos.
        if (in_array($id, ['COMPOSER-ADVISORY', 'COMPOSER.ABANDONED'], true)) {
            return;
        }
        $ids[$id] = true;
    }

    /**
     * Localiza a raiz union-find de um identificador aplicando path compression.
     *
     * @param array<string,string> $parent Mapa mutável de pais por identificador.
     * @param string $id Identificador cuja raiz será resolvida.
     * @return string Raiz representativa do conjunto.
     */
    private static function find(array &$parent, string $id): string
    {
        $parent[$id] ??= $id;
        if ($parent[$id] !== $id) {
            $parent[$id] = self::find($parent, $parent[$id]);
        }
        return $parent[$id];
    }

    /**
     * Une dois identificadores quando pertencem a conjuntos diferentes.
     *
     * @param array<string,string> $parent Estrutura union-find mutável.
     * @param string $a Primeiro identificador.
     * @param string $b Segundo identificador conectado ao primeiro.
     */
    private static function union(array &$parent, string $a, string $b): void
    {
        $rootA = self::find($parent, $a);
        $rootB = self::find($parent, $b);
        if ($rootA !== $rootB) {
            $parent[$rootB] = $rootA;
        }
    }

    /**
     * Escolhe o ID canônico usando a preferência CVE > GHSA > PKSA > OSV > outro.
     *
     * @param list<string> $ids Lista não vazia de IDs já normalizados/ordenados.
     * @return string Identificador escolhido para representar o grupo.
     */
    private static function canonicalId(array $ids): string
    {
        foreach ([
            '/^CVE-\d{4}-\d+$/',
            '/^GHSA-/',
            '/^PKSA-/',
            '/^OSV-/',
        ] as $pattern) {
            foreach ($ids as $id) {
                if (preg_match($pattern, $id) === 1) {
                    return $id;
                }
            }
        }
        return $ids[0];
    }

    /**
     * Retém a severidade reconhecida mais forte entre duas fontes.
     *
     * `moderate` é normalizado para `medium`; severidades desconhecidas não
     * substituem uma conhecida. Este método não calcula CVSS nem prioridade.
     *
     * @param string|null $current Severidade já acumulada.
     * @param string|null $candidate Severidade da próxima fonte.
     * @return string|null Severidade canônica mais forte conhecida.
     */
    private static function strongerSeverity(?string $current, ?string $candidate): ?string
    {
        /** @var array<string,int> $rank Ordem técnica simplificada usada apenas para consolidação. */
        $rank = [
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'moderate' => 2,
            'low' => 1,
        ];
        $currentNormalized = is_string($current) ? strtolower($current) : null;
        $candidateNormalized = is_string($candidate) ? strtolower($candidate) : null;
        if ($candidateNormalized === null || !isset($rank[$candidateNormalized])) {
            return $currentNormalized;
        }
        if ($currentNormalized === null || !isset($rank[$currentNormalized])) {
            return $candidateNormalized === 'moderate' ? 'medium' : $candidateNormalized;
        }
        return $rank[$candidateNormalized] > $rank[$currentNormalized]
            ? ($candidateNormalized === 'moderate' ? 'medium' : $candidateNormalized)
            : ($currentNormalized === 'moderate' ? 'medium' : $currentNormalized);
    }
}
