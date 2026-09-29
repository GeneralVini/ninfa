<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2DeprecationAnalyzer.php';
require_once __DIR__ . '/Yii2TypedDeprecationAnalyzer.php';
require_once __DIR__ . '/Yii2FindShortcutAnalyzer.php';

/**
 * Aplica remediações Yii2 classificadas como SAFE a partir de evidência já normalizada.
 *
 * O remediator não inventa transformações: ele agrega apenas analyzers cujo contrato já
 * fornece offset, length e replacement exatos. As substituições são validadas contra
 * sobreposição e aplicadas do maior offset para o menor, preservando as posições capturadas
 * sobre o snapshot original. Nenhuma transformação REVIEW/SEMANTIC pertence a esta classe.
 */
final class Yii2SafeRemediator
{
    /**
     * Aplica todas as remediações SAFE detectadas e retorna um relatório determinístico.
     *
     * Arquivos sem alteração não são regravados. O método falha se um arquivo desaparecer
     * entre análise e escrita ou se duas regras tentarem editar intervalos sobrepostos,
     * pois nesses casos os offsets deixam de representar uma mutação inequivocamente segura.
     *
     * @param ProjectContext $context Contexto Yii2 cujo root será modificado explicitamente.
     * @return array{changed_files:list<string>,changes:int} Arquivos alterados e total de substituições.
     * @throws RuntimeException Quando a evidência não pode mais ser aplicada com segurança.
     */
    public function apply(ProjectContext $context): array
    {
        /** @var list<array{file:string,offset:int,length:int,replacement:string}> $references Evidências SAFE normalizadas. */
        $references = [];

        // Cada analyzer é responsável por provar equivalência; esta camada só consolida patches autorizados.
        foreach ((new Yii2DeprecationAnalyzer())->references($context) as $reference) {
            $references[] = $this->patchReference($reference);
        }
        foreach ((new Yii2TypedDeprecationAnalyzer())->references($context) as $reference) {
            $references[] = $this->patchReference($reference);
        }
        foreach ((new Yii2FindShortcutAnalyzer())->references($context) as $reference) {
            $references[] = $this->patchReference($reference);
        }

        /** @var array<string,list<array{offset:int,length:int,replacement:string}>> $byFile Substituições agrupadas por path relativo. */
        $byFile = [];
        foreach ($references as $reference) {
            $byFile[$reference['file']][] = [
                'offset' => $reference['offset'],
                'length' => $reference['length'],
                'replacement' => $reference['replacement'],
            ];
        }

        /** @var list<string> $changedFiles Paths efetivamente regravados. */
        $changedFiles = [];
        $changes = 0;
        foreach ($byFile as $file => $replacements) {
            $absolute = $context->root() . '/' . $file;
            if (!is_file($absolute)) {
                throw new RuntimeException('Arquivo Yii2 desapareceu antes da remediação: ' . $file);
            }

            usort($replacements, static fn (array $left, array $right): int => $right['offset'] <=> $left['offset']);
            $this->assertNoOverlap($file, $replacements);
            $source = (string) file_get_contents($absolute);

            // Aplicação reversa mantém válidos todos os offsets capturados sobre o snapshot original.
            foreach ($replacements as $replacement) {
                $source = substr_replace(
                    $source,
                    $replacement['replacement'],
                    $replacement['offset'],
                    $replacement['length'],
                );
                $changes++;
            }

            file_put_contents($absolute, $source);
            $changedFiles[] = $file;
        }

        sort($changedFiles);
        return ['changed_files' => $changedFiles, 'changes' => $changes];
    }

    /**
     * Reduz shapes específicos dos analyzers ao contrato mínimo de patch SAFE.
     *
     * @param array{file:string,offset:int,length:int,replacement:string} $reference Evidência com intervalo exato.
     * @return array{file:string,offset:int,length:int,replacement:string} Shape comum usado pelo aplicador.
     */
    private function patchReference(array $reference): array
    {
        return [
            'file' => $reference['file'],
            'offset' => $reference['offset'],
            'length' => $reference['length'],
            'replacement' => $reference['replacement'],
        ];
    }

    /**
     * Rejeita edições que toquem o mesmo intervalo do source original.
     *
     * A lista chega em offset decrescente. Se o início da edição anterior estiver antes do
     * fim da edição seguinte, duas regras estão competindo pelo mesmo texto e nenhuma deve
     * ser aplicada silenciosamente.
     *
     * @param string $file Path relativo usado na mensagem de erro.
     * @param list<array{offset:int,length:int,replacement:string}> $replacements Patches ordenados em offset decrescente.
     * @throws RuntimeException Quando dois intervalos se sobrepõem.
     */
    private function assertNoOverlap(string $file, array $replacements): void
    {
        $previousStart = null;
        foreach ($replacements as $replacement) {
            $end = $replacement['offset'] + $replacement['length'];
            if ($previousStart !== null && $end > $previousStart) {
                throw new RuntimeException('Remediações Yii2 SAFE sobrepostas em: ' . $file);
            }
            $previousStart = $replacement['offset'];
        }
    }
}
