<?php

declare(strict_types=1);
require_once __DIR__ . '/Finding.php';

/** Interpreta exclusivamente o relatório traditional-json e a lista de alertas da API ZAP. */
final class ZapParser
{
    /**
     * Preserva alertas e instâncias sem inferir conclusão de jobs, crawl ou sessão.
     * @param string $json Documento nativo, não stdout misturado com logs.
     * @return array{findings:list<Finding>,coverage:array<string,mixed>} Eventos e limites observáveis.
     * @throws UnexpectedValueException Quando coleções ou identidade nativa são incompatíveis.
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof stdClass) {
            throw new UnexpectedValueException('ZAP exige objeto de relatório/API.');
        }
        /** @var list<stdClass> $alerts Coleção agregada sem perder a ordem das instâncias. */
        $alerts = [];
        // Relatório e API têm envelopes distintos, mas ambos exigem listas explícitas.
        if (property_exists($data, 'site') && is_array($data->site)) {
            foreach ($data->site as $site) {
                if (!$site instanceof stdClass || !is_array($site->alerts ?? null)) {
                    throw new UnexpectedValueException('ZAP site/alerts inválido.');
                }
                array_push($alerts, ...$site->alerts);
            }
        } elseif (is_array($data->alerts ?? null)) {
            $alerts = $data->alerts;
        } else {
            throw new UnexpectedValueException('ZAP não retornou lista site ou alerts.');
        }
        /** @var list<Finding> $findings Alertas nativos convertidos em achados independentes. */
        $findings = [];
        foreach ($alerts as $alert) {
            if (!$alert instanceof stdClass) {
                throw new UnexpectedValueException('ZAP alerta inválido.');
            }
            $a = (array) $alert;
            $id = $a['pluginid'] ?? $a['pluginId'] ?? null;
            $name = $a['name'] ?? $a['alert'] ?? null;
            if ((!is_string($id) && !is_int($id)) || !preg_match('/^\d+$/', (string) $id) || !is_string($name) || trim($name) === '') {
                throw new UnexpectedValueException('ZAP alerta sem plugin/nome válido.');
            }
            $instances = $a['instances'] ?? null;
            if ($instances !== null && !is_array($instances)) {
                throw new UnexpectedValueException('ZAP instances não é lista.');
            }
            if ($instances === null) {
                if (!is_string($a['url'] ?? null)) {
                    throw new UnexpectedValueException('ZAP alerta sem instâncias/alvo.');
                }
                $instances = [(object) ['uri' => $a['url'], 'method' => $a['method'] ?? null, 'param' => $a['param'] ?? null, 'evidence' => $a['evidence'] ?? null]];
            }
            /** @var list<array<string,mixed>> $locations Instâncias com alvo e dados específicos preservados. */
            $locations = [];
            foreach ($instances as $instance) {
                if (!$instance instanceof stdClass || !is_string($instance->uri ?? null) || $instance->uri === '') {
                    throw new UnexpectedValueException('ZAP instância sem URI.');
                }
                $locations[] = (array) $instance;
            }
            $risk = $a['riskcode'] ?? $a['risk'] ?? null;
            $severity = match ((string) $risk) {
                '0', 'Informational' => 'info', '1', 'Low' => 'low', '2', 'Medium' => 'medium', '3', 'High' => 'high',
                default => null,
            };
            if ($severity === null) {
                throw new UnexpectedValueException('ZAP risco desconhecido.');
            }
            $findings[] = new Finding(
                tool: 'zap', file: (string) ($locations[0]['uri'] ?? '.'), line: 0, rule: (string) $id,
                problem: $name, correction: is_string($a['solution'] ?? null) ? $a['solution'] : '',
                severity: $severity, confidence: isset($a['confidence']) ? (string) $a['confidence'] : null,
                evidenceType: 'dast-alert', provenance: ['zap', 'zap:' . $id],
                metadata: ['native_id' => $a['alertRef'] ?? $a['id'] ?? null, 'instances' => $locations,
                    'description' => $a['desc'] ?? $a['description'] ?? null, 'references' => $a['reference'] ?? null,
                    'cwe' => $a['cweid'] ?? $a['cweId'] ?? null, 'native_risk' => $risk,
                    'confirmation' => 'unverified', 'report_version' => $data->{'@version'} ?? null],
            );
        }
        return ['findings' => $findings, 'coverage' => ['status' => 'unknown', 'alerts' => count($alerts),
            'reason' => 'alert report does not prove jobs, crawl, session or queue completion']];
    }
}
