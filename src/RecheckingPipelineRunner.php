<?php

declare(strict_types=1);

require_once __DIR__ . '/PipelineRunner.php';

/**
 * Adiciona a política de revalidação ao `fix` sem duplicar o runner principal.
 *
 * A operação solicitada é executada uma vez. Apenas quando `fix` termina com
 * código 0 esta classe dispara exatamente um `check` completo. Se o recheck
 * falhar, a saída orienta o usuário a executar `ninfa assist`.
 */
final class RecheckingPipelineRunner
{
    /**
     * Recebe o runner responsável pela execução real das operações.
     *
     * @param PipelineRunner $runner Runner reutilizado tanto na operação inicial quanto no recheck.
     */
    public function __construct(
        private readonly PipelineRunner $runner = new PipelineRunner(),
    ) {
    }

    /**
     * Executa a operação e aplica exatamente um `check` adicional após `fix` bem-sucedido.
     *
     * Operações diferentes de `fix`, ou um `fix` que já falhou, retornam o status
     * original sem reexecução. O segundo check nunca dispara outro recheck porque
     * é chamado diretamente no PipelineRunner subjacente.
     *
     * @param string $operation Operação pública recebida pelo CLI.
     * @param ProjectContext $context Contexto imutável do projeto consumidor.
     * @return int Exit code da operação original ou do check posterior ao fix.
     */
    public function run(string $operation, ProjectContext $context): int
    {
        $status = $this->runner->run($operation, $context);

        // Revalidação só faz sentido depois de um fix que realmente concluiu sem erro.
        if ($status !== 0 || $operation !== 'fix') {
            return $status;
        }

        $status = $this->runner->run('check', $context);

        // Falha do recheck é preservada e acompanhada de orientação não mutadora.
        if ($status !== 0) {
            echo '[NINFA] Permanecem achados sem correção automática.' . PHP_EOL;
            echo '[NINFA] Execute para orientação auditável: ninfa assist ' . $context->root() . PHP_EOL;
        }

        return $status;
    }
}
