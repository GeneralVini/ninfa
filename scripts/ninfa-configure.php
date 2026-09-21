<?php

declare(strict_types=1);

/**
 * Gera artefatos auxiliares do Ninfa e executa o modo `assist`.
 *
 * Uso normal: `php scripts/ninfa-configure.php [root] [--force]`.
 * Uso assist: `php scripts/ninfa-configure.php [root] --assist`.
 *
 * No modo normal, gera configurações externas, `lefthook.yml` e
 * `semantic-index.json` no workspace do projeto. `--force` é aceito apenas
 * por compatibilidade e não muda a regeneração do workspace.
 *
 * No modo assist, executa PHPStan e Psalm em saída JSON, grava stdout/stderr e
 * `findings.json` em `<workspace>/assist`, renderiza os achados e retorna
 * código não zero quando há findings ou quando uma ferramenta falha sem
 * produzir finding estruturado.
 *
 * Este entrypoint não altera arquivos do projeto consumidor. Seus efeitos de
 * escrita ficam restritos ao workspace externo resolvido por ProjectContext.
 */

require_once dirname(__DIR__) . '/src/ProjectContext.php';
require_once dirname(__DIR__) . '/src/ExternalConfigGenerator.php';
require_once dirname(__DIR__) . '/src/SemanticHints.php';
require_once dirname(__DIR__) . '/src/LefthookConfigGenerator.php';
require_once dirname(__DIR__) . '/src/ProcessRunner.php';
require_once dirname(__DIR__) . '/src/ToolResolver.php';

/**
 * Executa PHPStan e Psalm para assistência auditável sem modificar o consumidor.
 *
 * O fluxo preserva stdout/stderr brutos das ferramentas antes de normalizar os
 * achados. Findings estruturados têm precedência sobre o exit code bruto: se
 * houver finding, o assist retorna 1. Se não houver finding, um exit code não
 * zero é tratado como falha de ferramenta e propagado.
 *
 * Efeitos externos:
 * - cria `<workspace>/assist`;
 * - grava `phpstan.json`, `phpstan.stderr.log`, `psalm.json`,
 *   `psalm.stderr.log` e `findings.json`;
 * - escreve findings renderizados em stdout e erros de ferramenta em stderr.
 *
 * @param ProjectContext $context Contexto imutável do projeto consumidor.
 * @param array<string,string> $configs Caminhos das configurações geradas por ferramenta.
 * @return int 0 sem findings/falhas; 1 quando há findings; ou o exit code da
 *             primeira ferramenta que falhar sem finding estruturado.
 * @throws RuntimeException Quando o diretório de auditoria não pode ser criado
 *                          ou uma ferramenta não pode ser resolvida/executada.
 * @throws JsonException Quando a saída esperada como JSON não pode ser decodificada.
 */
function runAssist(ProjectContext $context, array $configs): int
{
    $dir = $context->workspace()->file('assist');

    // A auditoria deve existir fora do consumidor; falha de criação interrompe
    // o assist para não perder evidência enquanto ainda retorna um resultado.
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a auditoria do assist.');
    }

    $resolver = new ToolResolver();
    $runner = new ProcessRunner();

    // Captura saída estruturada e stderr separadamente para preservar a evidência
    // original antes de qualquer normalização em Finding.
    /** @var ProcessResult $phpstan */
    $phpstan = $runner->runCaptured([
        $resolver->resolve('phpstan', $context->root()),
        'analyse', '--configuration', $configs['phpstan'], '--error-format=json', '--no-progress',
    ], $context->root());

    /** @var ProcessResult $psalm */
    $psalm = $runner->runCaptured([
        $resolver->resolve('psalm', $context->root()),
        '--config=' . $configs['psalm'], '--output-format=json', '--no-progress',
    ], $context->root());

    // Os arquivos brutos são parte da auditoria: mesmo quando o renderer evoluir,
    // permanece possível reconstruir o que cada ferramenta efetivamente retornou.
    file_put_contents($dir . '/phpstan.json', $phpstan->stdout);
    file_put_contents($dir . '/phpstan.stderr.log', $phpstan->stderr);
    file_put_contents($dir . '/psalm.json', $psalm->stdout);
    file_put_contents($dir . '/psalm.stderr.log', $psalm->stderr);

    /** @var list<Finding> $findings */
    $findings = [
        ...FindingRenderer::phpStan($phpstan->stdout, $context->root()),
        ...FindingRenderer::psalm($psalm->stdout, $context->root()),
    ];

    // Consolida uma visão estável dos findings sem substituir os artefatos brutos.
    $audit = $dir . '/findings.json';
    file_put_contents(
        $audit,
        json_encode([
            'schema' => 1,
            'generated_at' => gmdate(DATE_ATOM),
            'project' => $context->root(),
            'profile' => $context->profile(),
            'findings' => $findings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
    );

    FindingRenderer::render($findings, true);
    echo '[NINFA][assist] Achados: ' . count($findings) . PHP_EOL;
    echo '[NINFA][assist] Auditoria: ' . $audit . PHP_EOL;

    // Finding estruturado é uma condição conhecida do projeto, portanto o assist
    // retorna 1 sem reclassificar o mesmo caso como erro de infraestrutura.
    if ($findings !== []) {
        return 1;
    }

    /** @var list<array{0:string,1:ProcessResult}> $toolResults */
    $toolResults = [['PHPStan', $phpstan], ['Psalm', $psalm]];

    // Sem findings, exit code não zero indica falha que o parser não conseguiu
    // representar como achado; o diagnóstico bruto é então preservado em stderr.
    foreach ($toolResults as [$name, $result]) {
        if ($result->exitCode === 0) {
            continue;
        }

        fwrite(STDERR, '[ERRO] ' . $name . ' terminou com código ' . $result->exitCode . ' sem finding estruturado.' . PHP_EOL);
        if (trim($result->stderr) !== '') {
            fwrite(STDERR, $result->stderr . PHP_EOL);
        }
        if (trim($result->stdout) !== '') {
            fwrite(STDERR, $result->stdout . PHP_EOL);
        }

        return $result->exitCode;
    }

    return 0;
}

/** @var list<string> $args */
$args = $argv;
array_shift($args);

// Flags são removidas antes de resolver o argumento posicional de raiz para
// aceitar a ordem já suportada pelo script sem confundir uma flag com path.
$force = in_array('--force', $args, true);
$assist = in_array('--assist', $args, true);
$args = array_values(array_filter($args, static fn (string $arg): bool => !in_array($arg, ['--force', '--assist'], true)));

$projectRoot = $args[0] ?? getcwd();
if (!is_string($projectRoot) || $projectRoot === '') {
    fwrite(STDERR, "[ERRO] Informe a raiz do projeto.\n");
    exit(1);
}

try {
    // Contexto e configurações são comuns aos dois modos; assist diverge somente
    // depois que as configurações externas já existem no workspace.
    $context = ProjectContext::fromRoot($projectRoot);

    /** @var array<string,string> $configs */
    $configs = (new ExternalConfigGenerator())->generate($context);

    if ($assist) {
        exit(runAssist($context, $configs));
    }

    // O modo normal produz apenas artefatos auxiliares externos. O índice
    // semântico continua documental e não deve ser interpretado como call graph.
    $lefthookConfig = (new LefthookConfigGenerator())->generate($context);
    $semanticHints = SemanticHints::fromProject($context->root());
    $semanticIndex = $context->workspace()->file('semantic-index.json');
    file_put_contents(
        $semanticIndex,
        json_encode([
            'profile' => $context->profile(),
            'files' => $semanticHints->files(),
            'symbols' => $semanticHints->symbols(),
            'profile_signals' => $semanticHints->profileSignals(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    );
} catch (Throwable $error) {
    // Entry point converte qualquer falha de preparação em erro CLI simples;
    // stack trace não faz parte da interface pública deste script.
    fwrite(STDERR, '[ERRO] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

// `--force` permanece reconhecido para compatibilidade, mas não muda o modelo
// atual de workspace externo regenerável.
if ($force) {
    echo "[NINFA] --force mantido apenas por compatibilidade; o workspace externo e regenerado a cada execucao.\n";
}

// Resumo do contexto torna os artefatos gerados localizáveis durante diagnóstico.
echo '[NINFA] Profile: ' . $context->profile() . PHP_EOL;
echo '[NINFA] Projeto: ' . $context->root() . PHP_EOL;
echo '[NINFA] PHP: ' . $context->phpVersion() . PHP_EOL;
echo '[NINFA] Workspace: ' . $context->workspace()->path() . PHP_EOL;
echo '[NINFA] Caminhos: ' . implode(', ', $context->paths()) . PHP_EOL;
echo '[NINFA] Lefthook: ' . $lefthookConfig . PHP_EOL;
echo '[NINFA] Semantica: ' . $semanticIndex . PHP_EOL;
echo '[NINFA] Docs semanticos: ' . ($semanticHints->files() === [] ? 'nenhum' : implode(', ', $semanticHints->files())) . PHP_EOL;
echo '[NINFA] Simbolos documentados: ' . count($semanticHints->symbols()) . PHP_EOL;

// GLPI expõe contexto adicional porque o host externo influencia diretamente
// as configurações geradas para PHPStan/Psalm.
if ($context->profile() === 'glpi-plugin') {
    echo '[NINFA] GLPI host: ' . $context->glpiRoot() . PHP_EOL;
    echo '[NINFA] GLPI versao: ' . ($context->glpiVersion() ?? 'desconhecida') . PHP_EOL;
    echo '[NINFA] PHPStan level: ' . $context->phpStanLevel() . PHP_EOL;
    echo '[NINFA] Psalm level: ' . $context->psalmLevel() . PHP_EOL;
}

// Lista todos os arquivos de configuração retornados pelo gerador, incluindo
// artefatos condicionais como glpi-bootstrap quando aplicável.
foreach ($configs as $name => $path) {
    echo '[NINFA] Config ' . $name . ': ' . $path . PHP_EOL;
}
