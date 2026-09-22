<?php

declare(strict_types=1);

/** Consulta scores EPSS para uma lista deduplicada de CVEs. */
final class EpssClient
{
    private const URL = 'https://api.first.org/data/v1/epss';

    /** @var Closure(list<string>):array<string,mixed>|null */
    private readonly ?Closure $transport;

    /** Configura transporte determinístico para consulta EPSS, quando fornecido. @param Closure(list<string>):array<string,mixed>|null $transport */
    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    /** Consulta apenas CVEs deduplicados e normaliza score, percentil e data. @param list<string> $cves @return array<string,array<string,mixed>> */
    public function lookup(array $cves): array
    {
        $cves = array_values(array_unique($cves));
        if ($cves === []) {
            return [];
        }
        $payload = $this->transport !== null
            ? ($this->transport)($cves)
            : $this->request($cves);
        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            throw new UnexpectedValueException('EPSS retornou data inválido.');
        }
        /** @var array<string,array<string,mixed>> $result */
        $result = [];
        // Ignora linhas incompletas para preservar matches válidos de outras CVEs.
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['cve'] ?? null)) {
                continue;
            }
            $result[strtoupper($row['cve'])] = [
                'score' => is_numeric($row['epss'] ?? null) ? (float) $row['epss'] : null,
                'percentile' => is_numeric($row['percentile'] ?? null) ? (float) $row['percentile'] : null,
                'as_of' => is_string($row['date'] ?? null) ? $row['date'] : null,
            ];
        }
        return $result;
    }

    /** Executa a chamada HTTP sem persistir resposta localmente. @param list<string> $cves @return array<string,mixed> */
    private function request(array $cves): array
    {
        $url = self::URL . '?cve=' . rawurlencode(implode(',', $cves));
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]));
        if ($body === false) {
            throw new RuntimeException('EPSS indisponível.');
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('EPSS retornou JSON inválido.');
        }
        return $decoded;
    }
}
