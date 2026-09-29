<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/FrontendDetector.php';

/**
 * Produz o plano declarativo das operações públicas executadas pelo runner.
 *
 * `check` define as verificações de qualidade e adiciona ESLint/Prettier
 * somente quando o FrontendDetector indicar suporte. `fix` deriva as etapas
 * externas corrigíveis e, no profile Yii2, prefixa a remediação SAFE nativa.
 * `security` lista Composer Audit, OSV, Psalm Taint e Semgrep.
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
     * Monta o plano de `fix` preservando a ordem entre remediação nativa e ferramentas externas.
     *
     * Hooks corrigíveis do `check` mantêm o mesmo `id` e passam a modo `fix`. Yii2 recebe
     * antes deles `yii2-safe-remediation`, etapa interna que aplica somente regras catalogadas
     * como SAFE. O recheck posterior não pertence a este plano; continua responsabilidade de
     * RecheckingPipelineRunner, que executa exatamente um `check` ao final do fix bem-sucedido.
     *
     * @param ProjectContext $context Contexto usado para derivar o conjunto de hooks.
     * @return list<array{id:string,mode:string,fixable:bool}> Hooks mutadores em ordem estável.
     */
    public function fix(ProjectContext $context): array
    {
        /** @var list<array{id:string,mode:string,fixable:bool}> $hooks */
        $hooks = [];
        if ($context->profile() === 'yii2') {
            $hooks[] = ['id' => 'yii2-safe-remediation', 'mode' => 'fix', 'fixable' => true];
        }

        foreach ($this->check($context) as $hook) {
            if (!$hook['fixable']) {
                continue;
            }
            $hooks[] = [
                'id' => $hook['id'],
                'mode' => 'fix',
                'fixable' => true,
            ];
        }

        return $hooks;
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
