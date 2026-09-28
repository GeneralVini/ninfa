<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Constrói um inventário semântico conservador de projetos Yii2 analisados pelo Ninfa.
 *
 * O modelo extrai apenas fatos demonstráveis a partir do código-fonte e do Composer:
 * capabilities de storage, controllers/actions, referências literais a views e
 * relações ActiveRecord declaradas por `hasOne()`/`hasMany()`. Referências dinâmicas
 * permanecem desconhecidas em vez de produzirem falso positivo.
 *
 * Esta classe não produz Finding, não aplica política de severidade e não modifica o
 * consumidor. Ela fornece uma camada semântica reutilizável por regras posteriores.
 */
final class Yii2SemanticModel implements JsonSerializable
{
    /** @var array{redis:bool,mongodb:bool} Capabilities Yii2 observadas no Composer. */
    private array $capabilities;

    /**
     * @var list<array{
     *   class:string,
     *   file:string,
     *   id:string,
     *   actions:list<string>,
     *   views:list<array{name:string,line:int,resolved_path:string|null,exists:bool|null}>
     * }> Controllers e referências literais observadas.
     */
    private array $controllers;

    /**
     * @var list<array{model:string,file:string,name:string,kind:'hasOne'|'hasMany',target:string|null}>
     * Relações ActiveRecord observadas em getters convencionais.
     */
    private array $relations;

    /**
     * Cria o modelo exclusivamente para um ProjectContext classificado como Yii2.
     *
     * @param ProjectContext $context Contexto já validado pelo detector de profile.
     * @return self Modelo imutável com fatos semânticos encontrados no consumidor.
     * @throws InvalidArgumentException Quando o contexto não pertence ao profile Yii2.
     */
    public static function fromContext(ProjectContext $context): self
    {
        if ($context->profile() !== 'yii2') {
            throw new InvalidArgumentException('Yii2SemanticModel exige profile yii2.');
        }

        return new self($context->root(), $context->composer(), $context->paths());
    }

    /**
     * Executa a coleta semântica sem alterar arquivos do projeto consumidor.
     *
     * @param string $root Raiz física validada do consumidor.
     * @param array<string,mixed> $composer composer.json já decodificado pelo ProjectContext.
     * @param list<string> $paths Paths relativos elegíveis definidos pelo profile Yii2.
     */
    private function __construct(
        private readonly string $root,
        array $composer,
        array $paths,
    ) {
        $this->capabilities = $this->detectCapabilities($composer);
        $files = $this->phpFiles($paths);
        $this->controllers = $this->discoverControllers($files);
        $this->relations = $this->discoverRelations($files);
    }

    /**
     * Retorna as extensões de storage Yii2 declaradas pelo projeto.
     *
     * @return array{redis:bool,mongodb:bool} Flags observadas em require/require-dev.
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Retorna controllers, actions e referências literais a views já normalizados.
     *
     * @return list<array{
     *   class:string,
     *   file:string,
     *   id:string,
     *   actions:list<string>,
     *   views:list<array{name:string,line:int,resolved_path:string|null,exists:bool|null}>
     * }> Inventário de controllers em ordem estável de arquivo.
     */
    public function controllers(): array
    {
        return $this->controllers;
    }

    /**
     * Retorna relações ActiveRecord inferíveis por getters com hasOne/hasMany literais.
     *
     * @return list<array{model:string,file:string,name:string,kind:'hasOne'|'hasMany',target:string|null}>
     * Relações encontradas em ordem estável de arquivo e ocorrência.
     */
    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * Serializa o modelo para artefatos de auditoria sem introduzir política de findings.
     *
     * @return array{
     *   capabilities:array{redis:bool,mongodb:bool},
     *   controllers:list<array<string,mixed>>,
     *   relations:list<array<string,mixed>>
     * } Snapshot semântico consumível por ferramentas internas.
     */
    public function jsonSerialize(): array
    {
        return [
            'capabilities' => $this->capabilities,
            'controllers' => $this->controllers,
            'relations' => $this->relations,
        ];
    }

    /**
     * Detecta yiisoft/yii2-redis e yiisoft/yii2-mongodb sem criar profiles adicionais.
     *
     * Dependências de runtime e desenvolvimento são combinadas porque ambas podem
     * fornecer classes necessárias à análise estática do workspace.
     *
     * @param array<string,mixed> $composer Estrutura decodificada do composer.json.
     * @return array{redis:bool,mongodb:bool} Capabilities estáveis do profile Yii2.
     */
    private function detectCapabilities(array $composer): array
    {
        /** @var array<string,mixed> $packages Dependências declaradas combinadas. */
        $packages = [];
        foreach (['require', 'require-dev'] as $section) {
            if (is_array($composer[$section] ?? null)) {
                $packages = array_merge($packages, $composer[$section]);
            }
        }

        return [
            'redis' => array_key_exists('yiisoft/yii2-redis', $packages),
            'mongodb' => array_key_exists('yiisoft/yii2-mongodb', $packages),
        ];
    }

    /**
     * Lista arquivos PHP somente dentro dos paths autorizados pelo ProjectContext.
     *
     * Arquivos de vendor/runtime não entram porque não fazem parte dos paths do profile.
     * A ordenação torna snapshots e testes determinísticos entre filesystems.
     *
     * @param list<string> $paths Paths relativos elegíveis do consumidor.
     * @return list<string> Paths absolutos de arquivos PHP em ordem lexical.
     */
    private function phpFiles(array $paths): array
    {
        /** @var list<string> $files Arquivos PHP descobertos nos paths autorizados. */
        $files = [];

        foreach ($paths as $path) {
            $absolute = $this->root . '/' . $path;
            if (is_file($absolute) && strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'php') {
                $files[] = $absolute;
                continue;
            }
            if (!is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $item) {
                if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);
        return array_values(array_unique($files));
    }

    /**
     * Descobre controllers Yii2 por convenção de classe e extrai actions/views literais.
     *
     * Actions declaradas como `actionXxx()` e chaves literais de `actions()` são
     * reconhecidas. Chamadas de render com nome calculado são deliberadamente ignoradas.
     *
     * @param list<string> $files Arquivos PHP candidatos já limitados ao projeto.
     * @return list<array{
     *   class:string,
     *   file:string,
     *   id:string,
     *   actions:list<string>,
     *   views:list<array{name:string,line:int,resolved_path:string|null,exists:bool|null}>
     * }> Controllers normalizados.
     */
    private function discoverControllers(array $files): array
    {
        /** @var list<array{class:string,file:string,id:string,actions:list<string>,views:list<array{name:string,line:int,resolved_path:string|null,exists:bool|null}>}> $controllers */
        $controllers = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*Controller)\b/', $source, $classMatch) !== 1) {
                continue;
            }

            $shortClass = $classMatch[1];
            $namespace = '';
            if (preg_match('/\bnamespace\s+([^;]+);/', $source, $namespaceMatch) === 1) {
                $namespace = trim($namespaceMatch[1]);
            }
            $class = $namespace === '' ? $shortClass : $namespace . '\\' . $shortClass;
            $controllerId = $this->controllerId($shortClass);

            /** @var list<string> $actions Actions observadas sem duplicação. */
            $actions = [];
            if (preg_match_all('/\bfunction\s+action([A-Z][A-Za-z0-9_]*)\s*\(/', $source, $actionMatches) > 0) {
                foreach ($actionMatches[1] as $actionSuffix) {
                    $actions[] = $this->camelToId((string) $actionSuffix);
                }
            }

            $actionsBody = $this->methodBody($source, 'actions');
            if ($actionsBody !== null && preg_match_all('/[\'\"]([a-zA-Z0-9_-]+)[\'\"]\s*=>/', $actionsBody, $externalActions) > 0) {
                foreach ($externalActions[1] as $actionId) {
                    $actions[] = (string) $actionId;
                }
            }
            $actions = array_values(array_unique($actions));
            sort($actions);

            /** @var list<array{name:string,line:int,resolved_path:string|null,exists:bool|null}> $views */
            $views = [];
            $pattern = '/->(render|renderPartial|renderAjax|renderFile)\s*\(\s*([\'\"])([^\'\"]+)\2/';
            if (preg_match_all($pattern, $source, $renderMatches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($renderMatches[3] as $index => $viewMatch) {
                    $name = (string) $viewMatch[0];
                    $offset = (int) $viewMatch[1];
                    $method = (string) $renderMatches[1][$index][0];
                    $resolved = $this->resolveViewPath($file, $controllerId, $method, $name);
                    $views[] = [
                        'name' => $name,
                        'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                        'resolved_path' => $resolved === null ? null : $this->relativePath($resolved),
                        'exists' => $resolved === null ? null : is_file($resolved),
                    ];
                }
            }

            $controllers[] = [
                'class' => $class,
                'file' => $this->relativePath($file),
                'id' => $controllerId,
                'actions' => $actions,
                'views' => $views,
            ];
        }

        return $controllers;
    }

    /**
     * Descobre relações por getters convencionais que retornam hasOne/hasMany.
     *
     * O alvo é preenchido apenas quando a chamada usa `Foo::class`; configurações
     * calculadas continuam com target null e podem ser refinadas por analisadores AST.
     *
     * @param list<string> $files Arquivos PHP candidatos do consumidor.
     * @return list<array{model:string,file:string,name:string,kind:'hasOne'|'hasMany',target:string|null}>
     * Relações estaticamente observadas.
     */
    private function discoverRelations(array $files): array
    {
        /** @var list<array{model:string,file:string,name:string,kind:'hasOne'|'hasMany',target:string|null}> $relations */
        $relations = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $source, $classMatch) !== 1) {
                continue;
            }

            $shortClass = $classMatch[1];
            $namespace = '';
            if (preg_match('/\bnamespace\s+([^;]+);/', $source, $namespaceMatch) === 1) {
                $namespace = trim($namespaceMatch[1]);
            }
            $class = $namespace === '' ? $shortClass : $namespace . '\\' . $shortClass;

            if (preg_match_all('/\bfunction\s+get([A-Z][A-Za-z0-9_]*)\s*\(/', $source, $getterMatches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($getterMatches[1] as $getterMatch) {
                $suffix = (string) $getterMatch[0];
                $body = $this->methodBody($source, 'get' . $suffix);
                if ($body === null || preg_match('/->(hasOne|hasMany)\s*\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/', $body, $relationMatch) !== 1) {
                    continue;
                }

                $relations[] = [
                    'model' => $class,
                    'file' => $this->relativePath($file),
                    'name' => lcfirst($suffix),
                    'kind' => $relationMatch[1] === 'hasMany' ? 'hasMany' : 'hasOne',
                    'target' => $relationMatch[2],
                ];
            }
        }

        return $relations;
    }

    /**
     * Extrai o corpo textual de um método nomeado equilibrando chaves de bloco.
     *
     * O parser é propositalmente limitado: ele serve ao inventário conservador e
     * retorna null quando a declaração/corpo não pode ser identificado com segurança.
     *
     * @param string $source Código-fonte PHP completo.
     * @param string $method Nome exato do método sem parênteses.
     * @return string|null Corpo entre chaves ou null quando não resolvível.
     */
    private function methodBody(string $source, string $method): ?string
    {
        if (preg_match('/\bfunction\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{;]*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $opening = strpos($source, '{', (int) $match[0][1]);
        if ($opening === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);
        for ($cursor = $opening; $cursor < $length; $cursor++) {
            if ($source[$cursor] === '{') {
                $depth++;
            } elseif ($source[$cursor] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $opening + 1, $cursor - $opening - 1);
                }
            }
        }

        return null;
    }

    /**
     * Resolve uma view relativa pela convenção `controllers` -> `views/<id>` do Yii2.
     *
     * Alias, caminho absoluto, `//`, traversal e renderFile são deixados como
     * desconhecidos nesta primeira camada porque dependem de configuração/runtime.
     *
     * @param string $controllerFile Arquivo físico do controller chamador.
     * @param string $controllerId ID normalizado derivado do nome da classe.
     * @param string $method Método de render observado.
     * @param string $view Nome literal informado ao método.
     * @return string|null Path físico candidato ou null quando a resolução seria heurística.
     */
    private function resolveViewPath(string $controllerFile, string $controllerId, string $method, string $view): ?string
    {
        if ($method === 'renderFile' || $view === '' || str_starts_with($view, '@') || str_starts_with($view, '/')) {
            return null;
        }
        if (str_contains($view, '..')) {
            return null;
        }

        $normalized = str_replace('\\', '/', $controllerFile);
        $marker = '/controllers/';
        $position = strrpos($normalized, $marker);
        if ($position === false) {
            return null;
        }

        $applicationRoot = substr($normalized, 0, $position);
        $viewName = str_ends_with($view, '.php') ? $view : $view . '.php';
        if (str_starts_with($viewName, '/')) {
            return null;
        }

        return $applicationRoot . '/views/' . $controllerId . '/' . $viewName;
    }

    /**
     * Converte `SiteController`/`AdminUserController` para o ID convencional Yii2.
     *
     * @param string $class Nome curto da classe controller.
     * @return string ID kebab-case sem o sufixo Controller.
     */
    private function controllerId(string $class): string
    {
        $base = preg_replace('/Controller$/', '', $class) ?? $class;
        return $this->camelToId($base);
    }

    /**
     * Converte um identificador CamelCase em kebab-case compatível com actions Yii2.
     *
     * @param string $value Identificador sem separadores.
     * @return string Identificador normalizado em lowercase.
     */
    private function camelToId(string $value): string
    {
        $id = preg_replace('/(?<!^)[A-Z]/', '-$0', $value) ?? $value;
        return strtolower($id);
    }

    /**
     * Relativiza um path físico conhecido contra a raiz do consumidor.
     *
     * @param string $file Path físico absoluto.
     * @return string Path relativo quando pertencente ao projeto; caso contrário, path original.
     */
    private function relativePath(string $file): string
    {
        $root = rtrim(str_replace('\\', '/', $this->root), '/') . '/';
        $normalized = str_replace('\\', '/', $file);
        return str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : $normalized;
    }
}
