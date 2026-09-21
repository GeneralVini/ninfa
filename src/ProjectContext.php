<?php

declare(strict_types=1);

require_once __DIR__ . '/ProfileDetector.php';
require_once __DIR__ . '/Workspace.php';

/**
 * Materializa o contexto de execução de um único projeto consumidor.
 *
 * A construção resolve a raiz real, carrega composer.json quando presente,
 * detecta o profile e os paths analisáveis, registra o PHP efetivamente em
 * execução e cria o workspace externo usado pelos geradores e runners.
 *
 * Para o profile glpi-plugin, também resolve um host GLPI 11 e falha quando o
 * host ou sua versão não podem ser determinados. Esta classe não executa
 * ferramentas de análise; ela fornece os fatos locais usados pelo pipeline.
 */
final class ProjectContext
{
    /** @var array<string,mixed> Conteúdo associativo de composer.json ou array vazio. */
    private array $composer;
    /** @var list<string> Paths relativos existentes e elegíveis para análise. */
    private array $paths;
    /** Identificador do profile detectado para o projeto. */
    private string $profile;
    /** Versão major.minor do runtime PHP que executa o Ninfa. */
    private string $phpVersion;
    /** Versão completa `PHP_VERSION` do runtime efetivo. */
    private string $runtimePhpVersion;
    /** Constraint declarada em composer require.php, sem inferir runtime. */
    private ?string $phpConstraint;
    /** Raiz real do host GLPI 11 quando profile = glpi-plugin. */
    private ?string $glpiRoot = null;
    /** Versão GLPI extraída de constants.php quando aplicável. */
    private ?string $glpiVersion = null;
    /** Workspace externo associado deterministicamente ao projeto. */
    private Workspace $workspace;

    /**
     * Valida a raiz informada e cria o contexto completo do projeto.
     *
     * @param string $root Caminho informado pelo usuário/chamador.
     * @return self Contexto inicializado com raiz real, profile, paths e workspace.
     * @throws RuntimeException Quando a raiz não existe/não é diretório ou alguma etapa obrigatória de contexto falha.
     */
    public static function fromRoot(string $root): self
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new RuntimeException('Informe a raiz de um projeto PHP válido.');
        }

        return new self($realRoot);
    }

    /**
     * Constrói o contexto a partir de uma raiz já resolvida e validada.
     *
     * A ordem é intencional: composer alimenta detecção de profile/constraint;
     * profile define paths; workspace é criado fora do consumidor; host GLPI é
     * resolvido somente quando o profile especializado exige esse contexto.
     *
     * @param string $root Raiz física já normalizada por `fromRoot()`.
     */
    private function __construct(private readonly string $root)
    {
        $this->composer = $this->loadComposer();
        $this->profile = (new ProfileDetector())->detect($root, $this->composer);
        $this->phpConstraint = $this->detectPhpConstraint();
        $this->runtimePhpVersion = PHP_VERSION;
        $this->phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $this->paths = $this->detectPaths();
        $this->workspace = Workspace::forProject($root);

        // GLPI Plugin depende de semântica/contexto do host e não pode operar sem host 11 identificável.
        if ($this->profile === 'glpi-plugin') {
            $this->resolveGlpiHost();
        }
    }

    /** Retorna a raiz física validada do projeto consumidor. */
    public function root(): string
    {
        return $this->root;
    }

    /** Retorna o identificador do profile escolhido pelo ProfileDetector. */
    public function profile(): string
    {
        return $this->profile;
    }

    /**
     * Retorna somente major.minor do PHP que está executando o Ninfa.
     *
     * Este valor é runtime real e não deve ser confundido com a constraint Composer.
     */
    public function phpVersion(): string
    {
        return $this->phpVersion;
    }

    /** Retorna `PHP_VERSION` completo do runtime efetivo que executa a análise. */
    public function runtimePhpVersion(): string
    {
        return $this->runtimePhpVersion;
    }

    /** Retorna a constraint `require.php` declarada pelo consumidor, quando existente. */
    public function phpConstraint(): ?string
    {
        return $this->phpConstraint;
    }

    /**
     * Expõe o composer.json decodificado sem reinterpretar suas chaves.
     *
     * @return array<string,mixed> Objeto Composer associativo ou array vazio quando ausente.
     */
    public function composer(): array
    {
        return $this->composer;
    }

    /**
     * Retorna os paths relativos existentes selecionados para análise do profile.
     *
     * @return list<string> Paths relativos em ordem de candidatos do profile.
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /** Retorna o workspace externo já criado para este projeto. */
    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    /**
     * Retorna o nível PHPStan definido pela política atual do profile.
     *
     * GLPI Plugin usa nível 8; demais profiles usam `max`.
     */
    public function phpStanLevel(): int|string
    {
        return $this->profile === 'glpi-plugin' ? 8 : 'max';
    }

    /**
     * Retorna o nível Psalm definido pela política atual do profile.
     *
     * GLPI Plugin usa 8 e os demais profiles usam 1.
     */
    public function psalmLevel(): int
    {
        return $this->profile === 'glpi-plugin' ? 8 : 1;
    }

    /** Retorna a raiz do host GLPI resolvido, ou null fora de glpi-plugin. */
    public function glpiRoot(): ?string
    {
        return $this->glpiRoot;
    }

    /** Retorna a versão GLPI 11 detectada, ou null quando o profile não usa host GLPI. */
    public function glpiVersion(): ?string
    {
        return $this->glpiVersion;
    }

    /**
     * Verifica se composer.json declara um script utilizável com o nome informado.
     *
     * Strings vazias e arrays vazios não tornam o script aplicável. O método não
     * executa Composer e não valida a sintaxe do comando declarado.
     *
     * @param string $name Nome exato da chave em `scripts`.
     */
    public function hasComposerScript(string $name): bool
    {
        $script = $this->composer['scripts'][$name] ?? null;

        return (is_string($script) && trim($script) !== '') || (is_array($script) && $script !== []);
    }

    /**
     * Carrega composer.json quando presente e exige objeto JSON associativo válido.
     *
     * Ausência de Composer é permitida para projetos PHP genéricos. JSON inválido
     * propaga `JsonException`; valor decodificado não-array é rejeitado explicitamente.
     *
     * @return array<string,mixed> Conteúdo de composer.json ou array vazio quando ausente.
     * @throws JsonException Quando o arquivo contém JSON inválido.
     * @throws RuntimeException Quando o JSON válido não representa estrutura de objeto/array.
     */
    private function loadComposer(): array
    {
        $file = $this->root . '/composer.json';
        if (!is_file($file)) {
            return [];
        }

        /** @var mixed $decoded Conteúdo JSON decodificado. */
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('composer.json inválido.');
        }
        return $decoded;
    }

    /**
     * Extrai a constraint PHP declarada sem convertê-la em versão de runtime.
     *
     * @return string|null Constraint textual não vazia de `require.php` ou null.
     */
    private function detectPhpConstraint(): ?string
    {
        $constraint = $this->composer['require']['php'] ?? null;
        return is_string($constraint) && trim($constraint) !== '' ? trim($constraint) : null;
    }

    /**
     * Seleciona somente paths existentes previstos pelo profile detectado.
     *
     * Para PHP genérico sem diretórios convencionais, arquivos `.php` da raiz
     * viram fallback. Ausência total de path analisável é erro porque executar
     * ferramentas com alvo vazio produziria sinal enganoso de sucesso.
     *
     * @return list<string> Paths relativos existentes e elegíveis para análise.
     * @throws RuntimeException Quando nenhum path analisável é encontrado.
     */
    private function detectPaths(): array
    {
        /** @var list<string> $candidates Paths convencionais do profile atual. */
        $candidates = match ($this->profile) {
            'glpi-plugin' => ['setup.php', 'hook.php', 'src', 'inc', 'front', 'ajax', 'tests'],
            'yii2' => ['common', 'frontend', 'backend', 'console', 'src', 'app', 'modules', 'commands', 'tests'],
            'yii3' => ['src', 'app', 'config', 'public', 'modules', 'console', 'commands', 'tests'],
            'php-generic' => ['src', 'app', 'lib', 'include', 'includes', 'public', 'bin', 'modules', 'tests'],
            default => [],
        };

        /** @var list<string> $paths Paths candidatos que realmente existem. */
        $paths = [];
        foreach ($candidates as $candidate) {
            if (is_dir($this->root . '/' . $candidate) || is_file($this->root . '/' . $candidate)) {
                $paths[] = $candidate;
            }
        }

        // Fallback genérico cobre projetos pequenos compostos por PHP diretamente na raiz.
        if ($this->profile === 'php-generic' && $paths === []) {
            foreach (glob($this->root . '/*.php') ?: [] as $file) {
                $paths[] = basename($file);
            }
        }

        if ($paths === []) {
            throw new RuntimeException('Nenhum caminho analisável detectado para ' . $this->profile . '.');
        }
        return $paths;
    }

    /**
     * Localiza e valida o host GLPI 11 necessário ao profile `glpi-plugin`.
     *
     * A precedência é `NINFA_GLPI_ROOT` e depois a árvore pai quando o plugin
     * está em `<glpi>/plugins/<plugin>`. O marcador `src/autoload/constants.php`
     * precisa existir e declarar `GLPI_VERSION` iniciando por `11.`.
     *
     * @throws RuntimeException Quando o host não é encontrado, a versão não pode ser extraída ou não é GLPI 11.
     */
    private function resolveGlpiHost(): void
    {
        /** @var list<string> $candidates Raízes de host em ordem de precedência. */
        $candidates = [];
        $configured = getenv('NINFA_GLPI_ROOT');
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        if (basename(dirname($this->root)) === 'plugins') {
            $candidates[] = dirname(dirname($this->root));
        }

        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            // Um candidato só é host GLPI reconhecível quando possui o arquivo oficial de constantes.
            if ($root === false || !is_file($root . '/src/autoload/constants.php')) {
                continue;
            }

            $constants = (string) file_get_contents($root . '/src/autoload/constants.php');
            if (preg_match("/define\\('GLPI_VERSION',\\s*'([^']+)'\\);/", $constants, $matches) !== 1) {
                throw new RuntimeException('Host GLPI localizado, mas não foi possível determinar a versão.');
            }

            $version = $matches[1];
            if (!str_starts_with($version, '11.')) {
                throw new RuntimeException('O profile glpi-plugin suporta somente GLPI 11. Detectado: ' . $version);
            }

            $this->glpiRoot = $root;
            $this->glpiVersion = $version;
            return;
        }

        throw new RuntimeException('Plugin GLPI detectado, mas host GLPI 11 não localizado. Defina NINFA_GLPI_ROOT.');
    }
}
