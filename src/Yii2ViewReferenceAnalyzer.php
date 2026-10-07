<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';
require_once __DIR__ . '/Yii2SemanticModel.php';

/**
 * Resolve referências literais a views Yii2 sem executar código do consumidor.
 *
 * A resolução combina contexto comprovado de controller/view, aliases e view
 * paths fornecidos externamente por variáveis NINFA. Quando o receiver, o path
 * ou a configuração necessária não podem ser demonstrados, a referência fica
 * fora do resultado em vez de gerar ausência especulativa.
 */
final class Yii2ViewReferenceAnalyzer
{
    /** @var list<string> Métodos de controller cujo primeiro argumento é um nome de view. */
    private const CONTROLLER_METHODS = ['render', 'renderPartial', 'renderAjax'];

    /** @var list<string> Diretórios reconhecidos como raiz convencional de controllers. */
    private const CONTROLLER_DIRECTORIES = ['controllers', 'Controllers'];

    /** @var list<string> Diretórios reconhecidos como raiz convencional de views. */
    private const VIEW_DIRECTORIES = ['views', 'Views'];

    /**
     * Resolve todas as referências demonstráveis para o contexto Yii2 informado.
     *
     * Variáveis externas opcionais:
     * - NINFA_YII2_VIEW_ALIASES_JSON: objeto JSON alias => path;
     * - NINFA_YII2_VIEW_PATHS_JSON: objeto JSON namespace => views path;
     * - NINFA_YII2_VIEW_EXTENSIONS: lista separada por vírgula, default php.
     *
     * @param ProjectContext $context Contexto Yii2 do consumidor.
     * @param Yii2SemanticModel $model Snapshot usado para identificar controllers conhecidos.
     * @return list<array{file:string,line:int,method:string,view:string,context:string,resolved_path:string,exists:bool,source:string}> Referências com path resolvido.
     */
    public function references(ProjectContext $context, Yii2SemanticModel $model): array
    {
        if ($context->profile() !== 'yii2') {
            return [];
        }

        $configuration = $this->configuration($context);
        /** @var array<string,array{class:string,id:string}> $controllersByFile Controllers conhecidos indexados por arquivo. */
        $controllersByFile = [];
        foreach ($model->controllers() as $controller) {
            $controllersByFile[$controller['file']] = [
                'class' => $controller['class'],
                'id' => $controller['id'],
            ];
        }

        /** @var list<array{file:string,line:int,method:string,view:string,context:string,resolved_path:string,exists:bool,source:string}> $references */
        $references = [];
        foreach ($this->phpFiles($context) as $absoluteFile) {
            $relativeFile = $this->relativePath($context->root(), $absoluteFile);
            $source = (string) file_get_contents($absoluteFile);

            if (isset($controllersByFile[$relativeFile])) {
                $controller = $controllersByFile[$relativeFile];
                $location = $this->controllerLocation(
                    $context,
                    $absoluteFile,
                    $controller['class'],
                    $controller['id'],
                    $configuration['view_paths'],
                );

                // Controller só é analisado para receivers $this, evitando render() de objetos arbitrários.
                foreach ($this->renderCalls($source, self::CONTROLLER_METHODS, 'this') as $call) {
                    $resolution = $this->resolveView(
                        $context,
                        $call['view'],
                        $location,
                        $configuration['aliases'],
                        $configuration['extensions'],
                    );
                    if ($resolution !== null) {
                        $references[] = $this->reference(
                            $context,
                            $relativeFile,
                            $call,
                            'controller',
                            $resolution,
                        );
                    }
                }
            }

            $viewLocation = $this->viewFileLocation($absoluteFile);
            if ($viewLocation !== null) {
                // Em arquivo de view, $this é yii\base\View; terceiro argumento muda o contexto relativo.
                foreach ($this->renderCalls($source, ['render'], 'this') as $call) {
                    $location = $call['argument_count'] >= 3 ? null : $viewLocation;
                    $resolution = $this->resolveView(
                        $context,
                        $call['view'],
                        $location,
                        $configuration['aliases'],
                        $configuration['extensions'],
                    );
                    if ($resolution !== null) {
                        $references[] = $this->reference(
                            $context,
                            $relativeFile,
                            $call,
                            'nested-view',
                            $resolution,
                        );
                    }
                }
                continue;
            }

            if (!isset($controllersByFile[$relativeFile])) {
                // Fora de controller/view, só receivers Yii::$app->view/getView são aceitos como View comprovada.
                foreach ($this->renderCalls($source, ['render'], 'yii-view') as $call) {
                    $resolution = $this->resolveView(
                        $context,
                        $call['view'],
                        null,
                        $configuration['aliases'],
                        $configuration['extensions'],
                    );
                    if ($resolution !== null) {
                        $references[] = $this->reference(
                            $context,
                            $relativeFile,
                            $call,
                            'view-service',
                            $resolution,
                        );
                    }
                }
            }
        }

        usort(
            $references,
            static fn (array $left, array $right): int => [
                $left['file'],
                $left['line'],
                $left['method'],
                $left['view'],
            ] <=> [
                $right['file'],
                $right['line'],
                $right['method'],
                $right['view'],
            ],
        );

        return $references;
    }

    /**
     * Normaliza uma referência resolvida para o contrato público do analyzer.
     *
     * @param ProjectContext $context Contexto do consumidor.
     * @param string $file Arquivo relativo da chamada.
     * @param array{method:string,view:string,line:int,argument_count:int} $call Chamada literal.
     * @param string $callContext Tipo de contexto comprovado.
     * @param array{path:string,exists:bool,source:string} $resolution Resolução física.
     * @return array{file:string,line:int,method:string,view:string,context:string,resolved_path:string,exists:bool,source:string} Referência estável.
     */
    private function reference(
        ProjectContext $context,
        string $file,
        array $call,
        string $callContext,
        array $resolution,
    ): array {
        return [
            'file' => $file,
            'line' => $call['line'],
            'method' => $call['method'],
            'view' => $call['view'],
            'context' => $callContext,
            'resolved_path' => $this->relativePath($context->root(), $resolution['path']),
            'exists' => $resolution['exists'],
            'source' => $resolution['source'],
        ];
    }

    /**
     * Carrega configuração externa de resolução sem exigir arquivo no consumidor.
     *
     * Paths relativos em aliases/view paths são ancorados na raiz do projeto.
     * JSON inválido ou valores não-string falham explicitamente para não produzir
     * resolução diferente da configuração que o usuário acredita ter fornecido.
     *
     * @param ProjectContext $context Contexto usado para ancorar paths relativos.
     * @return array{aliases:array<string,string>,view_paths:array<string,string>,extensions:list<string>} Configuração validada.
     */
    private function configuration(ProjectContext $context): array
    {
        $aliases = $this->jsonPathMap($context, 'NINFA_YII2_VIEW_ALIASES_JSON');
        $viewPaths = $this->jsonPathMap($context, 'NINFA_YII2_VIEW_PATHS_JSON');

        $rawExtensions = getenv('NINFA_YII2_VIEW_EXTENSIONS');
        $extensions = $rawExtensions === false || trim($rawExtensions) === ''
            ? ['php']
            : array_values(array_filter(array_map(
                static fn (string $value): string => ltrim(trim($value), '.'),
                explode(',', $rawExtensions),
            )));

        if ($extensions === []) {
            $extensions = ['php'];
        }
        foreach ($extensions as $extension) {
            if (preg_match('/^[A-Za-z0-9]+$/', $extension) !== 1) {
                throw new RuntimeException('NINFA_YII2_VIEW_EXTENSIONS contém extensão inválida.');
            }
        }

        return [
            'aliases' => $aliases,
            'view_paths' => $viewPaths,
            'extensions' => array_values(array_unique($extensions)),
        ];
    }

    /**
     * Decodifica um mapa JSON de paths vindo de variável de ambiente.
     *
     * @param ProjectContext $context Contexto que fornece a raiz do consumidor.
     * @param string $name Nome da variável NINFA.
     * @return array<string,string> Mapa normalizado; vazio quando a variável não existe.
     */
    private function jsonPathMap(ProjectContext $context, string $name): array
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        /** @var mixed $decoded Conteúdo JSON fornecido externamente. */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException($name . ' deve ser um objeto JSON.');
        }

        /** @var array<string,string> $result Mapa validado e ancorado. */
        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || trim($key) === '' || !is_string($value) || trim($value) === '') {
                throw new RuntimeException($name . ' deve mapear strings não vazias para paths não vazios.');
            }
            $path = trim($value);
            if (!$this->isAbsolutePath($path)) {
                $path = $context->root() . '/' . ltrim($path, '/\\');
            }
            $result[trim($key, '\\')] = rtrim(str_replace('\\', '/', $path), '/');
        }

        return $result;
    }

    /**
     * Resolve o diretório de views de um controller por configuração ou convenção.
     *
     * Um view path configurado por namespace tem precedência. Sem configuração,
     * somente uma árvore física contendo controllers/Controllers é usada como
     * evidência para derivar o diretório irmão views/Views e subdiretórios.
     *
     * @param ProjectContext $context Contexto do consumidor.
     * @param string $controllerFile Arquivo absoluto do controller.
     * @param string $controllerClass FQCN observada.
     * @param string $controllerId ID Yii2 normalizado.
     * @param array<string,string> $viewPaths Namespace => diretório de views.
     * @return array{views_root:string,directory:string,app_root:string|null,source:string}|null Localização comprovada.
     */
    private function controllerLocation(
        ProjectContext $context,
        string $controllerFile,
        string $controllerClass,
        string $controllerId,
        array $viewPaths,
    ): ?array {
        $classNamespace = str_contains($controllerClass, '\\')
            ? substr($controllerClass, 0, strrpos($controllerClass, '\\'))
            : '';

        /** @var list<string> $configuredNamespaces Namespaces compatíveis ordenados do mais específico. */
        $configuredNamespaces = [];
        foreach (array_keys($viewPaths) as $namespace) {
            $normalized = trim($namespace, '\\');
            if ($classNamespace === $normalized || str_starts_with($classNamespace, $normalized . '\\')) {
                $configuredNamespaces[] = $normalized;
            }
        }
        usort($configuredNamespaces, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        if ($configuredNamespaces !== []) {
            $namespace = $configuredNamespaces[0];
            $viewsRoot = $viewPaths[$namespace];
            $suffix = trim(substr($classNamespace, strlen($namespace)), '\\');
            $directory = $viewsRoot;
            if ($suffix !== '') {
                $directory .= '/' . str_replace('\\', '/', $suffix);
            }
            $directory .= '/' . $controllerId;
            return [
                'views_root' => $viewsRoot,
                'directory' => $directory,
                'app_root' => $this->configuredAppRoot($viewPaths, $context),
                'source' => 'configured-view-path',
            ];
        }

        $normalizedFile = str_replace('\\', '/', $controllerFile);
        foreach (self::CONTROLLER_DIRECTORIES as $directoryName) {
            $marker = '/' . $directoryName . '/';
            $position = strrpos($normalizedFile, $marker);
            if ($position === false) {
                continue;
            }

            $root = substr($normalizedFile, 0, $position);
            $after = substr($normalizedFile, $position + strlen($marker));
            $subdirectory = str_replace('\\', '/', dirname($after));
            $viewsRoot = $this->viewsDirectoryIn($root);
            $directory = $viewsRoot;
            if ($subdirectory !== '.' && $subdirectory !== '') {
                $directory .= '/' . trim($subdirectory, '/');
            }
            $directory .= '/' . $controllerId;

            return [
                'views_root' => $viewsRoot,
                'directory' => $directory,
                'app_root' => $root,
                'source' => 'controller-convention',
            ];
        }

        return null;
    }

    /**
     * Retorna @app configurado quando disponível para resolução de //.
     *
     * @param array<string,string> $viewPaths View paths configurados.
     * @param ProjectContext $context Contexto atual.
     * @return string|null Raiz de aplicação explícita ou null.
     */
    private function configuredAppRoot(array $viewPaths, ProjectContext $context): ?string
    {
        $aliases = $this->jsonPathMap($context, 'NINFA_YII2_VIEW_ALIASES_JSON');
        return $aliases['@app'] ?? null;
    }

    /**
     * Localiza o contexto de um arquivo que vive sob views/Views.
     *
     * @param string $file Arquivo absoluto candidato.
     * @return array{views_root:string,directory:string,app_root:string|null,source:string}|null Contexto da view.
     */
    private function viewFileLocation(string $file): ?array
    {
        $normalized = str_replace('\\', '/', $file);
        $best = null;
        foreach (self::VIEW_DIRECTORIES as $directoryName) {
            $marker = '/' . $directoryName . '/';
            $position = strrpos($normalized, $marker);
            if ($position === false || ($best !== null && $position <= $best['position'])) {
                continue;
            }
            $best = [
                'position' => $position,
                'root' => substr($normalized, 0, $position),
                'views_root' => substr($normalized, 0, $position) . '/' . $directoryName,
            ];
        }

        if ($best === null) {
            return null;
        }

        return [
            'views_root' => $best['views_root'],
            'directory' => dirname($normalized),
            'app_root' => $best['root'],
            'source' => 'view-file-context',
        ];
    }

    /**
     * Resolve um nome de view para path físico somente com evidência suficiente.
     *
     * @param ProjectContext $context Contexto para aliases e paths relativos.
     * @param string $view Nome literal observado.
     * @param array{views_root:string,directory:string,app_root:string|null,source:string}|null $location Contexto relativo comprovado.
     * @param array<string,string> $aliases Aliases explicitamente configurados.
     * @param list<string> $extensions Extensões tentadas em ordem.
     * @return array{path:string,exists:bool,source:string}|null Resolução ou null quando desconhecida.
     */
    private function resolveView(
        ProjectContext $context,
        string $view,
        ?array $location,
        array $aliases,
        array $extensions,
    ): ?array {
        if ($view === '' || str_contains($view, "\0")) {
            return null;
        }

        $basePath = null;
        $source = $location['source'] ?? 'explicit';
        if (str_starts_with($view, '@')) {
            $basePath = $this->resolveAlias($view, $aliases);
            $source = 'configured-alias';
        } elseif (str_starts_with($view, '//')) {
            $app = $aliases['@app'] ?? null;
            if ($app === null) {
                return null;
            }
            $basePath = $this->viewsDirectoryIn($app) . '/' . ltrim($view, '/');
            $source = 'app-alias';
        } elseif (str_starts_with($view, '/')) {
            if ($location === null) {
                return null;
            }
            $basePath = $location['views_root'] . '/' . ltrim($view, '/');
        } else {
            if ($location === null || str_contains($view, '..')) {
                return null;
            }
            $basePath = $location['directory'] . '/' . $view;
        }

        if ($basePath === null || str_contains($basePath, '/../') || str_ends_with($basePath, '/..')) {
            return null;
        }

        return $this->resolveExtension($basePath, $extensions, $source);
    }

    /**
     * Resolve alias pelo primeiro segmento, sem inferir aliases do runtime Yii2.
     *
     * @param string $view Nome iniciado por @.
     * @param array<string,string> $aliases Mapa explicitamente configurado.
     * @return string|null Path físico base ou null para alias desconhecido.
     */
    private function resolveAlias(string $view, array $aliases): ?string
    {
        $separator = strpos($view, '/');
        $root = $separator === false ? $view : substr($view, 0, $separator);
        if (!isset($aliases[$root])) {
            return null;
        }

        return rtrim($aliases[$root], '/') . substr($view, strlen($root));
    }

    /**
     * Aplica política de extensões e informa path esperado para views ausentes.
     *
     * @param string $basePath Path semântica já resolvido.
     * @param list<string> $extensions Extensões aceitas.
     * @param string $source Origem da resolução.
     * @return array{path:string,exists:bool,source:string} Resultado com path existente ou esperado.
     */
    private function resolveExtension(string $basePath, array $extensions, string $source): array
    {
        if (pathinfo($basePath, PATHINFO_EXTENSION) !== '') {
            return ['path' => $basePath, 'exists' => is_file($basePath), 'source' => $source];
        }

        foreach ($extensions as $extension) {
            $candidate = $basePath . '.' . $extension;
            if (is_file($candidate)) {
                return ['path' => $candidate, 'exists' => true, 'source' => $source];
            }
        }

        return [
            'path' => $basePath . '.' . $extensions[0],
            'exists' => false,
            'source' => $source,
        ];
    }

    /**
     * Extrai chamadas de render com primeiro argumento string literal.
     *
     * O parser trabalha sobre tokens de PHP, portanto ocorrências em comentários
     * e strings não são confundidas com chamadas. Receiver arbitrário é recusado.
     *
     * @param string $source Código-fonte completo.
     * @param list<string> $methods Métodos aceitos preservando case original.
     * @param 'this'|'yii-view' $receiver Receiver comprovado exigido.
     * @return list<array{method:string,view:string,line:int,argument_count:int}> Chamadas literais.
     */
    private function renderCalls(string $source, array $methods, string $receiver): array
    {
        /** @var list<array{id:int|null,text:string,line:int}> $tokens Tokens simplificados. */
        $tokens = [];
        foreach (token_get_all($source) as $rawToken) {
            $tokens[] = [
                'id' => is_array($rawToken) ? $rawToken[0] : null,
                'text' => is_array($rawToken) ? $rawToken[1] : $rawToken,
                'line' => is_array($rawToken) ? $rawToken[2] : ($tokens === [] ? 1 : $tokens[array_key_last($tokens)]['line']),
            ];
        }

        $allowed = array_map('strtolower', $methods);
        /** @var list<array{method:string,view:string,line:int,argument_count:int}> $calls */
        $calls = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_STRING || !in_array(strtolower($token['text']), $allowed, true)) {
                continue;
            }
            if (!$this->receiverMatches($tokens, $index, $receiver)) {
                continue;
            }

            $open = $this->next($tokens, $index + 1);
            if ($open === null || $tokens[$open]['text'] !== '(') {
                continue;
            }
            $close = $this->matching($tokens, $open, '(', ')');
            if ($close === null) {
                continue;
            }
            $arguments = $this->segments($tokens, $open + 1, $close - 1);
            if ($arguments === []) {
                continue;
            }
            $literal = $this->literal($tokens, $arguments[0][0], $arguments[0][1]);
            if ($literal === null) {
                continue;
            }

            $calls[] = [
                'method' => $token['text'],
                'view' => $literal['value'],
                'line' => $literal['line'],
                'argument_count' => count($arguments),
            ];
        }

        return $calls;
    }

    /**
     * Verifica o receiver imediatamente antes de um método render.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $methodIndex Índice do nome do método.
     * @param 'this'|'yii-view' $receiver Receiver esperado.
     * @return bool True apenas para shape reconhecida.
     */
    private function receiverMatches(array $tokens, int $methodIndex, string $receiver): bool
    {
        $indices = $this->previousSignificant($tokens, $methodIndex, 10);
        if ($indices === [] || $tokens[$indices[0]]['text'] !== '->') {
            return false;
        }

        if ($receiver === 'this') {
            return isset($indices[1]) && $tokens[$indices[1]]['id'] === T_VARIABLE && $tokens[$indices[1]]['text'] === '$this';
        }

        $texts = array_map(static fn (int $index): string => $tokens[$index]['text'], $indices);
        $viewShape = ['->', 'view', '->', '$app', '::'];
        if (array_slice($texts, 0, 5) === $viewShape && isset($indices[5])) {
            return ltrim($tokens[$indices[5]]['text'], '\\') === 'Yii';
        }

        $getViewShape = ['->', ')', '(', 'getView', '->', '$app', '::'];
        if (array_slice($texts, 0, 7) === $getViewShape && isset($indices[7])) {
            return ltrim($tokens[$indices[7]]['text'], '\\') === 'Yii';
        }

        return false;
    }

    /**
     * Retorna índices significativos anteriores em ordem reversa.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $before Índice exclusivo inicial.
     * @param int $limit Quantidade máxima.
     * @return list<int> Índices encontrados do mais próximo ao mais distante.
     */
    private function previousSignificant(array $tokens, int $before, int $limit): array
    {
        /** @var list<int> $indices */
        $indices = [];
        for ($i = $before - 1; $i >= 0 && count($indices) < $limit; $i--) {
            if (in_array($tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $indices[] = $i;
        }
        return $indices;
    }

    /**
     * Divide argumentos/arrays por vírgula de primeiro nível.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return list<array{int,int}> Segmentos não vazios.
     */
    private function segments(array $tokens, int $start, int $end): array
    {
        if ($start > $end) {
            return [];
        }

        /** @var list<array{int,int}> $result */
        $result = [];
        $segment = $start;
        $round = $square = $curly = 0;
        for ($i = $start; $i <= $end; $i++) {
            $text = $tokens[$i]['text'];
            $round += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
            $square += $text === '[' ? 1 : ($text === ']' ? -1 : 0);
            $curly += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
            if ($text === ',' && $round === 0 && $square === 0 && $curly === 0) {
                if ($this->next($tokens, $segment, $i - 1) !== null) {
                    $result[] = [$segment, $i - 1];
                }
                $segment = $i + 1;
            }
        }
        if ($this->next($tokens, $segment, $end) !== null) {
            $result[] = [$segment, $end];
        }
        return $result;
    }

    /**
     * Extrai string literal isolada sem avaliar expressão PHP.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Início inclusivo.
     * @param int $end Fim inclusivo.
     * @return array{value:string,line:int}|null Literal normalizado.
     */
    private function literal(array $tokens, int $start, int $end): ?array
    {
        $first = $this->next($tokens, $start, $end);
        if ($first === null || $tokens[$first]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        if ($this->next($tokens, $first + 1, $end) !== null) {
            return null;
        }

        $raw = $tokens[$first]['text'];
        if (strlen($raw) < 2 || !in_array($raw[0], ["'", '"'], true) || $raw[strlen($raw) - 1] !== $raw[0]) {
            return null;
        }
        $value = substr($raw, 1, -1);
        if ($raw[0] === '"' && (str_contains($value, '$') || str_contains($value, '\\'))) {
            return null;
        }
        if ($raw[0] === "'") {
            $value = str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }

        return ['value' => $value, 'line' => $tokens[$first]['line']];
    }

    /**
     * Busca o fechamento correspondente de um delimitador.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $openIndex Índice do delimitador inicial.
     * @param string $open Delimitador de abertura.
     * @param string $close Delimitador de fechamento.
     * @return int|null Índice de fechamento ou null.
     */
    private function matching(array $tokens, int $openIndex, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
            $depth += $tokens[$i]['text'] === $open ? 1 : ($tokens[$i]['text'] === $close ? -1 : 0);
            if ($depth === 0) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Retorna o próximo token significativo no range.
     *
     * @param list<array{id:int|null,text:string,line:int}> $tokens Tokens do arquivo.
     * @param int $start Índice inicial.
     * @param int|null $end Limite inclusivo.
     * @return int|null Índice encontrado.
     */
    private function next(array $tokens, int $start, ?int $end = null): ?int
    {
        $limit = $end ?? count($tokens) - 1;
        for ($i = $start; $i <= $limit; $i++) {
            if (!in_array($tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Lista PHPs sob os paths autorizados do profile.
     *
     * @param ProjectContext $context Contexto que limita a árvore analisável.
     * @return list<string> Arquivos absolutos ordenados.
     */
    private function phpFiles(ProjectContext $context): array
    {
        /** @var list<string> $files */
        $files = [];
        foreach ($context->paths() as $path) {
            $absolute = $context->root() . '/' . $path;
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
     * Escolhe views/Views existente sob uma raiz, preferindo a forma lowercase.
     *
     * @param string $parent Diretório pai.
     * @return string Path esperado/existente do diretório de views.
     */
    private function viewsDirectoryIn(string $parent): string
    {
        foreach (self::VIEW_DIRECTORIES as $name) {
            if (is_dir(rtrim($parent, '/') . '/' . $name)) {
                return rtrim($parent, '/') . '/' . $name;
            }
        }
        return rtrim($parent, '/') . '/views';
    }

    /**
     * Verifica se um path é absoluto em Unix ou Windows.
     *
     * @param string $path Path informado externamente.
     * @return bool True quando não deve ser ancorado na raiz do consumidor.
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * Converte path absoluto sob a raiz em path relativo para findings estáveis.
     *
     * @param string $root Raiz física do consumidor.
     * @param string $file Path absoluto ou externo.
     * @return string Path relativo quando aplicável.
     */
    private function relativePath(string $root, string $file): string
    {
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalized = str_replace('\\', '/', $file);
        return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
    }
}
