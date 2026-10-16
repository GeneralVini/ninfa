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
        if ($context->profile() === 'glpi-plugin') {
            foreach (self::CANDIDATES as $tool => $names) {
                foreach ($names as $name) {
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
