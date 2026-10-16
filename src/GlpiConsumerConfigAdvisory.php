<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Registra configurações do consumidor que o perfil GLPI ainda não compõe.
 *
 * O Ninfa gera e passa explicitamente configurações externas para seus
 * analyzers. Portanto preservar configs versionadas não significa aplicar
 * regras/customizações contidas nelas. Este inventário não lê ou executa essas
 * configurações, nem altera o consumidor ou a classificação de findings.
 */
final class GlpiConsumerConfigAdvisory implements JsonSerializable
{
    /** @var array<string,list<string>> Configurações usuais dos analyzers com override externo no pipeline. */
    private const CANDIDATES = [
        'phpstan' => ['phpstan.neon', 'phpstan.neon.dist'],
        'psalm' => ['psalm.xml', 'psalm.xml.dist'],
        'ecs' => ['ecs.php', 'ecs.php.dist'],
        'rector' => ['rector.php', 'rector.php.dist'],
    ];

    /**
     * Mantém somente os caminhos relativos das configurações encontradas.
     *
     * @param array<string,list<string>> $detected Configurações externas do consumidor por ferramenta.
     */
    private function __construct(private readonly array $detected)
    {
    }

    /**
     * Inspeciona apenas a presença de nomes convencionais no plugin GLPI.
     *
     * Não diagnostica PHPUnit, cujo comando de teste tem contrato próprio, e
     * nunca conclui que a configuração do consumidor foi usada pelos scanners.
     *
     * @param ProjectContext $context Contexto do consumidor sem execução de PHP.
     * @return self Snapshot de risco de cobertura das configurações.
     */
    public static function fromContext(ProjectContext $context): self
    {
        /** @var array<string,list<string>> $detected Presença de configs convencionais por analyzer. */
        $detected = [];
        // Só enumera configurações usuais para plugins GLPI; arquivos encontrados
        // não são lidos nem compostos ao config gerado, pois isso exigiria prova
        // das precedências de cada ferramenta e poderia ocultar regras do projeto.
        if ($context->profile() === 'glpi-plugin') {
            // Cada ferramenta tem seu conjunto explícito de arquivos convencionais.
            foreach (self::CANDIDATES as $tool => $names) {
                // Apenas presença sintática: a regra customizada não é executada.
                foreach ($names as $name) {
                    // Um arquivo existente é advisory, não prova de uso no pipeline.
                    if (is_file($context->root() . '/' . $name)) {
                        $detected[$tool][] = $name;
                    }
                }
            }
        }
        return new self($detected);
    }

    /** Informa se uma configuração do consumidor pode estar sendo ignorada. */
    public function requiresReview(): bool
    {
        return $this->detected !== [];
    }

    /**
     * Retorna o inventário por analyzer com nomes relativos apenas.
     *
     * @return array<string,list<string>> Configs identificadas no consumidor.
     */
    public function detected(): array
    {
        return $this->detected;
    }

    /**
     * Serializa o limite de cobertura sem presumir aplicação de regras.
     *
     * @return array{schema_version:int,profile:string,consumer_config_policy:string,detected:array<string,list<string>>,review_required:bool} Evidência auditável.
     */
    /**
     * Comprova somente o config gerado e selecionado pelo NINFA.
     * Não prova carregamento interno pelo scanner, nem aplicação de regras do consumidor.
     *
     * @param array<string,string> $configs Paths efetivos gerados no workspace.
     * @return array<string,mixed> Snapshot sem conteúdo proprietário.
     */
    public function coverage(array $configs): array
    {
        $report = $this->jsonSerialize();
        $report['coverage_schema_version'] = 1;
        $report['mode'] = 'generated-only';
        $report['analyzers'] = [];
        foreach (['phpstan', 'psalm', 'ecs', 'rector'] as $tool) {
            $path = $configs[$tool] ?? null;
            $valid = is_string($path) && is_file($path);
            if (!$valid) {
                throw new RuntimeException('Config externa GLPI ausente: ' . $tool);
            }
            $digest = hash_file('sha256', $path);
            if (!is_string($digest)) {
                throw new RuntimeException('Falha ao calcular digest do config GLPI: ' . $tool);
            }
            $report['analyzers'][$tool] = [
                'consumer_config_detected' => ($this->detected[$tool] ?? []) !== [],
                'consumer_config_applied' => false,
                'effective_config_origin' => 'ninfa-generated',
                'effective_config_sha256' => $digest,
                'consumer_config_policy' => 'not_merged_with_generated_configs',
                'verification_scope' => 'generated_config_file_only',
            ];
        }
        return $report;
    }

    public function jsonSerialize(): array
    {
        return [
            'schema_version' => 1,
            'profile' => 'glpi-plugin',
            'consumer_config_policy' => 'not_merged_with_generated_configs',
            'detected' => $this->detected,
            'review_required' => $this->requiresReview(),
        ];
    }
}
