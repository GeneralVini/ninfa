<?php

declare(strict_types=1);

/**
 * Relaciona callbacks literais de hooks com declarações globais visíveis.
 *
 * Não afirma ausência definitiva porque includes, autoload e rotas de registro
 * podem disponibilizar callbacks fora dos entrypoints. A ausência observada é
 * representada por null/unknown; nenhuma vulnerabilidade nem autofix deriva
 * diretamente destes fatos.
 */
final class GlpiHookCallbackIndex
{
    /** @var list<string> Hooks do GLPI 11 que aceitam callback de função simples. */
    private const FUNCTION_HOOKS = ['change_profile', 'change_entity', 'init_session', 'post_init'];

    /**
     * Cruza referências literais com funções globais visíveis nos entrypoints.
     *
     * Somente chamadas com chave de plugin e string simples de função são
     * registradas. Strings como caminhos de config, classes ou callables
     * compostos não são convertidas em callbacks nesta tranche.
     *
     * @param list<array{file:string,line:int,hook:string,plugin:string|null,scope:string,scalar_value:bool,value_literal:string|null}> $registrations Evidências do registry.
     * @param array<string,array{file:string,exists:bool,complete:bool,functions:list<string>,uncertainty:list<string>}> $entrypoints Inventários sem execução.
     * @return list<array{hook:string,plugin:string,line:int,callback:string,declared:bool|null}> Fatos declarados ou unknown.
     */
    public function build(array $registrations, array $entrypoints): array
    {
        // Somente presença sintática em entrypoints globais prova declaração;
        // ausência não prova que outro arquivo/autoload não forneça a função.
        /** @var array<string,true> $defined Funções globais comprovadamente declaradas. */
        $defined = [];
        foreach ($entrypoints as $entrypoint) {
            foreach ($entrypoint['functions'] as $function) {
                $defined[strtolower($function)] = true;
            }
        }

        /** @var list<array{hook:string,plugin:string,line:int,callback:string,declared:bool|null}> $callbacks Referências locais demonstráveis. */
        $callbacks = [];
        foreach ($registrations as $registration) {
            $literal = $registration['value_literal'];
            if ($registration['scope'] !== 'plugin'
                || $registration['plugin'] === null
                || !in_array($registration['hook'], self::FUNCTION_HOOKS, true)
                || !is_string($literal)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $literal) !== 1) {
                continue;
            }

            $callbacks[] = [
                'hook' => $registration['hook'],
                'plugin' => $registration['plugin'],
                'line' => $registration['line'],
                'callback' => $literal,
                'declared' => isset($defined[strtolower($literal)]) ? true : null,
            ];
        }

        return $callbacks;
    }
}
