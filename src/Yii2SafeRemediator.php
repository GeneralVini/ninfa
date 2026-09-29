<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2DeprecationAnalyzer.php';

/**
 * Aplica remediações Yii2 classificadas como SAFE a partir de evidência já normalizada.
 *
 * O remediator não redetecta semântica por conta própria: ele consome offsets e replacements
 * produzidos por Yii2DeprecationAnalyzer, agrupa por arquivo e aplica do maior offset para o
 * menor para preservar posições. Nenhuma transformação REVIEW/SEMANTIC pertence a esta classe.
 */
final class Yii2SafeRemediator
{
    /**
     * Aplica todas as remediações SAFE detectadas e retorna um relatório determinístico.
     *
     * Arquivos sem alteração não são regravados. O método falha se um arquivo desaparecer
     * entre análise e escrita, porque aplicar offsets sobre conteúdo diferente seria inseguro.
     *
     * @param ProjectContext $context Contexto Yii2 cujo root será modificado explicitamente.
     * @return array{changed_files:list<string>,changes:int} Arquivos alterados e total de substituições.
     * @throws RuntimeException Quando uma evidência aponta para arquivo ausente.
     */
    public function apply(ProjectContext $context): array
    {
        $references = (new Yii2DeprecationAnalyzer())->references($context);
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

            $source = (string) file_get_contents($absolute);
            usort($replacements, static fn (array $left, array $right): int => $right['offset'] <=> $left['offset']);

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
}
