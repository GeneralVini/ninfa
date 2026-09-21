<?php

declare(strict_types=1);

require_once __DIR__ . '/Finding.php';

final class ScaFindingDeduplicator
{
    /**
     * @param list<Finding> $findings
     * @return list<array<string,mixed>>
     */
    public static function canonicalize(array $findings): array
    {
        $advisories = [];
        foreach ($findings as $index => $finding) {
            if (!$finding instanceof Finding || $finding->evidenceType !== 'sca-advisory') {
                continue;
            }
            $identifiers = self::identifiers($finding);
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

        /** @var array<string,string> $parent */
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

        /** @var array<string,list<array{finding:Finding,identifiers:list<string>}>> $groups */
        $groups = [];
        foreach ($advisories as $entry) {
            $root = self::find($parent, $entry['identifiers'][0]);
            $groups[$root][] = $entry;
        }

        $canonical = [];
        foreach ($groups as $entries) {
            $allIdentifiers = [];
            $sources = [];
            $provenance = [];
            $components = [];
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

                $component = $finding->metadata['component'] ?? null;
                if (is_array($component)) {
                    $key = json_encode($component, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $components[$key] = $component;
                }

                $advisory = $finding->metadata['advisory'] ?? [];
                $sourceId = [
                    'source' => $finding->tool,
                    'id' => is_array($advisory) && is_string($advisory['id'] ?? null)
                        ? $advisory['id']
                        : $finding->rule,
                ];
                $sourceIds[$sourceId['source'] . '|' . $sourceId['id']] = $sourceId;
            }

            $ids = array_keys($allIdentifiers);
            sort($ids);
            $canonicalId = self::canonicalId($ids);
            $aliases = array_values(array_filter($ids, static fn (string $id): bool => $id !== $canonicalId));
            $sourceList = array_keys($sources);
            sort($sourceList);
            $provenanceList = array_keys($provenance);
            sort($provenanceList);
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

    /** @return list<string> */
    private static function identifiers(Finding $finding): array
    {
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

    /** @param array<string,true> $ids */
    private static function addIdentifier(array &$ids, mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        $id = strtoupper(trim($value));
        if (in_array($id, ['COMPOSER-ADVISORY', 'COMPOSER.ABANDONED'], true)) {
            return;
        }
        $ids[$id] = true;
    }

    /** @param array<string,string> $parent */
    private static function find(array &$parent, string $id): string
    {
        $parent[$id] ??= $id;
        if ($parent[$id] !== $id) {
            $parent[$id] = self::find($parent, $parent[$id]);
        }
        return $parent[$id];
    }

    /** @param array<string,string> $parent */
    private static function union(array &$parent, string $a, string $b): void
    {
        $rootA = self::find($parent, $a);
        $rootB = self::find($parent, $b);
        if ($rootA !== $rootB) {
            $parent[$rootB] = $rootA;
        }
    }

    /** @param list<string> $ids */
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

    private static function strongerSeverity(?string $current, ?string $candidate): ?string
    {
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
