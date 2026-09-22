<?php

declare(strict_types=1);

/** Consulta o catálogo CISA KEV para CVEs canonizados. */
final class CisaKevClient
{
    private const URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';

    /** @var Closure():array<string,mixed>|null */
    private readonly ?Closure $transport;

    /** Configura transporte determinístico para o catálogo KEV, quando fornecido. @param Closure():array<string,mixed>|null $transport */
    public function __construct(?Closure $transport = null)
    {
        $this->transport = $transport;
    }

    /** Retorna somente entradas KEV correspondentes aos CVEs solicitados. @param list<string> $cves @return array<string,array<string,mixed>> */
    public function lookup(array $cves): array
    {
        $wanted = array_fill_keys(array_map('strtoupper', array_values(array_unique($cves))), true);
        if ($wanted === []) {
            return [];
        }
        $payload = $this->transport !== null ? ($this->transport)() : $this->request();
        $vulnerabilities = $payload['vulnerabilities'] ?? [];
        if (!is_array($vulnerabilities)) {
            throw new UnexpectedValueException('CISA KEV retornou vulnerabilities inválido.');
        }
        /** @var array<string,array<string,mixed>> $result */
        $result = [];
        // O catálogo é filtrado somente pelos CVEs solicitados, sem inferência por pacote.
        foreach ($vulnerabilities as $row) {
            if (!is_array($row) || !is_string($row['cveID'] ?? null)) {
                continue;
            }
            $id = strtoupper($row['cveID']);
            if (!isset($wanted[$id])) {
                continue;
            }
            $result[$id] = [
                'known_exploited' => true,
                'date_added' => $row['dateAdded'] ?? null,
                'due_date' => $row['dueDate'] ?? null,
                'required_action' => $row['requiredAction'] ?? null,
                'source' => 'cisa-kev',
            ];
        }
        return $result;
    }

    /** Baixa e decodifica o catálogo oficial sem cache persistente. @return array<string,mixed> */
    private function request(): array
    {
        $body = @file_get_contents(self::URL, false, stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]));
        if ($body === false) {
            throw new RuntimeException('CISA KEV indisponível.');
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('CISA KEV retornou JSON inválido.');
        }
        return $decoded;
    }
}
