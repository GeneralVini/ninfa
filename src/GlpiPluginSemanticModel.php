<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Extrai fatos estáticos de entrypoints do ciclo de vida de um plugin GLPI 11.
 *
 * Não carrega nem executa código do consumidor/host. A presença de includes,
 * namespaces ou callbacks declarados em escopo condicional deixa a ausência
 * inconclusiva (null) em vez de produzir falso positivo. As informações servem
 * à engine GLPI, mas não determinam severidade nem política de remediação.
 */
final class GlpiPluginSemanticModel implements JsonSerializable
{
    /**
     * Congela os fatos derivados de entrypoints sem reexecutar a análise.
     *
     * O objeto mantém separados inventários conclusivos e referências unknown;
     * somente a rule engine pode convertê-los posteriormente em findings.
     *
     * @param string $plugin Identificador técnico obtido do diretório do plugin.
     * @param string $hostVersion Versão do host GLPI já validada por ProjectContext.
     * @param array<string,array{file:string,exists:bool,complete:bool,functions:list<string>,uncertainty:list<string>}> $entrypoints Contrato estático de cada entrypoint.
     * @param list<array{file:string,callback:string,exists:bool|null}> $callbacks Contrato tri-state dos callbacks convencionais.
     */
    private function __construct(
        private readonly string $plugin,
        private readonly string $hostVersion,
        private readonly array $entrypoints,
        private readonly array $callbacks,
    ) {
    }

    /**
     * Examina somente setup.php e hook.php, sem modificar a árvore consumidora.
     *
     * Um callback não observado só é ausente quando o arquivo existe e seu
     * universo de declarações é conclusivo. Includes e namespaces podem alterar
     * resolução em runtime; nomes técnicos não convencionais não são negados.
     *
     * @param ProjectContext $context Contexto GLPI 11 previamente resolvido.
     * @return self Snapshot imutável para indexação e regra de lifecycle.
     * @throws InvalidArgumentException Quando o profile não é glpi-plugin.
     */
    public static function fromContext(ProjectContext $context): self
    {
        if ($context->profile() !== 'glpi-plugin') {
            throw new InvalidArgumentException('O modelo GLPI exige profile glpi-plugin.');
        }

        $plugin = basename($context->root());
        $technicalName = preg_match('/^[a-z][a-z0-9_]*$/D', $plugin) === 1;
        /** @var array<string,array{file:string,exists:bool,complete:bool,functions:list<string>,uncertainty:list<string>}> $entrypoints */
        $entrypoints = [];
        foreach (['setup.php', 'hook.php'] as $file) {
            $entrypoints[$file] = self::inspectFile($context->root() . '/' . $file, $file);
        }

        /** @var list<array{file:string,callback:string,exists:bool|null}> $callbacks */
        $callbacks = [];
        if ($technicalName) {
            $expected = [
                'setup.php' => ['plugin_init_' . $plugin, 'plugin_version_' . $plugin],
                'hook.php' => ['plugin_' . $plugin . '_install', 'plugin_' . $plugin . '_uninstall'],
            ];
            foreach ($expected as $file => $names) {
                $entry = $entrypoints[$file];
                foreach ($names as $name) {
                    $exists = in_array(strtolower($name), $entry['functions'], true)
                        ? true
                        : ($entry['exists'] && $entry['complete'] ? false : null);
                    $callbacks[] = ['file' => $file, 'callback' => $name, 'exists' => $exists];
                }
            }
        }

        return new self($plugin, $context->glpiVersion() ?? 'unknown', $entrypoints, $callbacks);
    }

    /** Retorna o identificador técnico original do diretório. */
    public function plugin(): string
    {
        return $this->plugin;
    }

    /**
     * Retorna o contrato dos entrypoints por caminho relativo.
     *
     * @return array<string,array{file:string,exists:bool,complete:bool,functions:list<string>,uncertainty:list<string>}> Inventário estático.
     */
    public function entrypoints(): array
    {
        return $this->entrypoints;
    }

    /**
     * Retorna a existência tri-state de cada callback obrigatório.
     *
     * @return list<array{file:string,callback:string,exists:bool|null}> Callbacks e evidência.
     */
    public function callbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * Serializa o snapshot sem dados mutáveis do consumidor.
     *
     * @return array{plugin:string,host_version:string,entrypoints:array<string,array{file:string,exists:bool,complete:bool,functions:list<string>,uncertainty:list<string>}>,required_callbacks:list<array{file:string,callback:string,exists:bool|null}>} Snapshot auditável.
     */
    public function jsonSerialize(): array
    {
        return [
            'plugin' => $this->plugin,
            'host_version' => $this->hostVersion,
            'entrypoints' => $this->entrypoints,
            'required_callbacks' => $this->callbacks,
        ];
    }

    /**
     * Lê um arquivo de entrypoint e extrai funções globais via tokenizer do PHP.
     *
     * Métodos de classes, closures, strings e comentários não viram declarações
     * globais. Uma função convencional encontrada sob escopo condicional será
     * marcada como indeterminada quando não existir no escopo global.
     *
     * @param string $absolute Caminho absoluto sob a raiz consumidora.
     * @param string $relative Nome relativo do entrypoint.
     * @return array{file:string,exists:bool,complete:bool,functions:list<string>,uncertainty:list<string>} Contrato estático.
     */
    private static function inspectFile(string $absolute, string $relative): array
    {
        if (!is_file($absolute)) {
            return ['file' => $relative, 'exists' => false, 'complete' => false, 'functions' => [], 'uncertainty' => ['missing-entrypoint']];
        }

        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            return ['file' => $relative, 'exists' => true, 'complete' => false, 'functions' => [], 'uncertainty' => ['unreadable-entrypoint']];
        }

        /** @var list<array{0:int,1:string,2:int}|string> $tokens */
        $tokens = token_get_all($source);
        /** @var list<string> $functions */
        $functions = [];
        /** @var list<string> $nestedFunctions */
        $nestedFunctions = [];
        /** @var list<string> $uncertainty */
        $uncertainty = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($token === '{') {
                $depth++;
                continue;
            }
            if ($token === '}') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $uncertainty[] = 'external-declarations';
            }
            if ($token[0] === T_NAMESPACE) {
                $uncertainty[] = 'namespace';
            }
            if ($token[0] !== T_FUNCTION) {
                continue;
            }

            // Uma closure possui '(' em vez de nome. '&' pode anteceder o nome.
            $cursor = $index + 1;
            while (isset($tokens[$cursor])
                && (
                    (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_WHITESPACE)
                    || $tokens[$cursor] === '&'
                    || (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)
                )
            ) {
                $cursor++;
            }

            $nameToken = $tokens[$cursor] ?? null;
            if (!is_array($nameToken) || $nameToken[0] !== T_STRING) {
                continue;
            }
            $name = strtolower($nameToken[1]);
            if ($depth === 0) {
                $functions[] = $name;
            } else {
                $nestedFunctions[] = $name;
            }
        }

        // Uma definição em bloco condicional ou método homônimo não prova existência global.
        // Marcá-la unknown é deliberadamente mais seguro do que acusar ausência.
        foreach ($nestedFunctions as $name) {
            if (str_starts_with($name, 'plugin_')) {
                $uncertainty[] = 'nested-plugin-function';
                break;
            }
        }
        $uncertainty = array_values(array_unique($uncertainty));
        // Declarações sob namespace não provam o contrato de callbacks globais do GLPI.
        if (in_array('namespace', $uncertainty, true)) {
            /** @var list<string> $functions Funções não verificadas como globais não entram no inventário. */
            $functions = [];
        }
        $functions = array_values(array_unique($functions));
        sort($functions);

        return [
            'file' => $relative,
            'exists' => true,
            'complete' => $uncertainty === [],
            'functions' => $functions,
            'uncertainty' => $uncertainty,
        ];
    }
}
