<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/FrontendDetector.php';

/**
 * Produz o plano declarativo das operações públicas executadas pelo runner.
 *
 * `check` define as verificações de qualidade e adiciona ESLint/Prettier
 * somente quando o FrontendDetector indicar suporte. `fix` deriva apenas as
 * etapas marcadas como corrigíveis. `security` lista Composer Audit, OSV,
 * Psalm Taint e Semgrep.
 *
 * Esta classe não resolve binários nem inicia processos; ela apenas descreve
 * a ordem, o modo e a capacidade de correção das etapas.
 */
final class PipelinePlan
{
    /**
     * Cria o planejador com o detector frontend usado para etapas condicionais.
     *
     * @param FrontendDetector $frontendDetector Detector reutilizado durante a montagem do plano.
     */
    public function __construct(private readonly FrontendDetector $frontendDetector = new FrontendDetector())
    {
    }

    /**
     * Monta a sequência de `check` aplicável ao contexto.
     *
     * ECS, Rector, PHPStan e Psalm são sempre planejados para profiles suportados.
     * ESLint e Prettier entram somente quando seus sinais específicos existem; a
     * etapa de testes é sempre adicionada e poderá ser marcada como não aplicável
     * posteriormente pelo runner quando não houver comando de teste.
     *
     * @param ProjectContext $context Contexto já detectado do projeto consumidor.
     * @return list<array{id:string,mode:string,fixable:bool}> Hooks em ordem de execução.
     */
    public function check(ProjectContext $context): array
    {
        $this->assertSupported($context);

        /** @var list<array{id:string,mode:string,fixable:bool}> $hooks */
        $hooks = [
            ['id' => 'ecs', 'mode' => 'check', 'fixable' => true],
            ['id' => 'rector', 'mode' => 'dry-run', 'fixable' => true],
            ['id' => 'phpstan', 'mode' => 'check', 'fixable' => false],
            ['id' => 'psalm', 'mode' => 'check', 'fixable' => false],
        ];

        // Frontend é opcional e só entra quando o consumidor fornece sinais concretos.
        if ($this->frontendDetector->hasEslint($context->root())) {
            $hooks[] = ['id' => 'eslint', 'mode' => 'check', 'fixable' => true];
        }
        if ($this->frontendDetector->hasPrettier($context->root())) {
            $hooks[] = ['id' => 'prettier', 'mode' => 'check', 'fixable' => true];
        }

        $hooks[] = ['id' => 'test', 'mode' => 'check', 'fixable' => false];

        return $hooks;
    }

    /**
     * Deriva o plano de `fix` exclusivamente das etapas corrigíveis do `check`.
     *
     * A derivação evita manter duas listas divergentes: cada hook corrigível
     * preserva o mesmo `id` e passa a usar modo `fix`. O recheck posterior não
     * pertence a este plano; é responsabilidade de RecheckingPipelineRunner.
     *
     * @param ProjectContext $context Contexto usado para obter o mesmo conjunto de hooks do check.
     * @return list<array{id:string,mode:string,fixable:bool}> Hooks mutadores em ordem estável.
     */
    public function fix(ProjectContext $context): array
    {
        return array_map(
            static fn (array $hook): array => [
                'id' => $hook['id'],
                'mode' => 'fix',
                'fixable' => true,
            ],
            array_values(array_filter(
                $this->check($context),
                static fn (array $hook): bool => $hook['fixable'],
            )),
        );
    }

    /**
     * Retorna o plano de segurança independente do pipeline de qualidade.
     *
     * A ordem atual executa Composer Audit e OSV antes dos scanners SAST para
     * que inventário/SCA sejam produzidos mesmo quando SAST falhar depois.
     *
     * @param ProjectContext $context Contexto cujo profile precisa ser suportado.
     * @return list<array{id:string,mode:string}> Etapas de segurança em ordem de execução.
     */
    public function security(ProjectContext $context): array
    {
        $this->assertSupported($context);

        return [
            ['id' => 'composer-audit', 'mode' => 'check'],
            ['id' => 'osv', 'mode' => 'check'],
            ['id' => 'psalm-taint', 'mode' => 'check'],
            ['id' => 'semgrep', 'mode' => 'check'],
        ];
    }

    /**
     * Expõe somente os IDs corrigíveis usados por integrações de hook.
     *
     * @param ProjectContext $context Contexto usado para derivar o plano de fix.
     * @return list<string> Identificadores das etapas corrigíveis em ordem de execução.
     */
    public function lefthookFixHooks(ProjectContext $context): array
    {
        return array_map(
            static fn (array $hook): string => $hook['id'],
            $this->fix($context),
        );
    }

    /**
     * Impede que um profile desconhecido receba silenciosamente um plano genérico.
     *
     * @param ProjectContext $context Contexto cujo identificador de profile será validado.
     * @throws LogicException Quando o profile não possui pipeline definido pelo Ninfa.
     */
    private function assertSupported(ProjectContext $context): void
    {
        if (!in_array($context->profile(), ['glpi-plugin', 'yii2', 'yii3', 'php-generic'], true)) {
            throw new LogicException('Profile sem pipeline Ninfa: ' . $context->profile());
        }
    }
}
