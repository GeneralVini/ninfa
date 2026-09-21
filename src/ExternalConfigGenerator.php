<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

/**
 * Gera no workspace as configurações consumidas pelas ferramentas de qualidade.
 *
 * A entrada é o ProjectContext; os paths analisáveis e níveis vêm dele. A
 * classe escreve `phpstan.neon`, `psalm.xml`, `ecs.php` e `rector.php` fora do
 * projeto consumidor e retorna os caminhos gerados.
 *
 * Para `glpi-plugin`, também pode localizar a extensão phpstan-glpi, gerar
 * `glpi-bootstrap.php`, adicionar source/stubs do host ao PHPStan e declarar o
 * global `$DB`/exceção específica de InvalidGlobal no Psalm. Não instala
 * dependências e não altera configurações versionadas do consumidor.
 */
final class ExternalConfigGenerator
{
    /** @return array<string,string> */
    public function generate(ProjectContext $context): array
    {
        $workspace = $context->workspace();
        $root = str_replace('\\', '/', $context->root());
        $paths = array_map(static fn (string $p): string => $root . '/' . $p, $context->paths());

        $phpstan = $workspace->file('phpstan.neon');
        $psalm = $workspace->file('psalm.xml');
        $ecs = $workspace->file('ecs.php');
        $rector = $workspace->file('rector.php');

        $includes = '';
        $phpstanExtra = '';
        $bootstrap = null;
        $psalmExtra = '';
        $hasGlpiPhpStanExtension = false;

        if ($context->profile() === 'glpi-plugin') {
            $glpiRoot = $context->glpiRoot();
            if ($glpiRoot === null) {
                throw new RuntimeException('Host GLPI obrigatório para gerar configuração do profile glpi-plugin.');
            }

            $glpi = str_replace('\\', '/', $glpiRoot);
            foreach ([
                $context->root() . '/vendor/glpi-project/phpstan-glpi/extension.neon',
                $glpiRoot . '/vendor/glpi-project/phpstan-glpi/extension.neon',
            ] as $candidate) {
                if (is_file($candidate)) {
                    $includes = "includes:\n  - " . str_replace('\\', '/', $candidate) . "\n\n";
                    $hasGlpiPhpStanExtension = true;
                    break;
                }
            }

            $bootstrap = $workspace->file('glpi-bootstrap.php');
            $quoted = var_export($glpiRoot, true);
            file_put_contents(
                $bootstrap,
                "<?php\n\ndeclare(strict_types=1);\n\n\$glpiRoot = {$quoted};\n\n\$autoload = \$glpiRoot . '/vendor/autoload.php';\nif (is_file(\$autoload)) {\n    require_once \$autoload;\n}\n\nspl_autoload_register(static function (string \$class) use (\$glpiRoot): void {\n    if (str_starts_with(\$class, 'Glpi\\\\')) {\n        \$relative = substr(\$class, strlen('Glpi\\\\'));\n        \$file = \$glpiRoot . '/src/Glpi/' . str_replace('\\\\', '/', \$relative) . '.php';\n    } elseif (!str_contains(\$class, '\\\\')) {\n        \$file = \$glpiRoot . '/src/' . \$class . '.php';\n    } else {\n        return;\n    }\n\n    if (is_file(\$file)) {\n        require_once \$file;\n    }\n});\n",
            );

            $scanFiles = [];
            foreach ([
                $glpiRoot . '/src/autoload/constants.php',
                $glpiRoot . '/src/autoload/dbutils-aliases.php',
                $glpiRoot . '/src/autoload/i18n.php',
                $glpiRoot . '/src/autoload/misc-functions.php',
            ] as $file) {
                if (is_file($file)) {
                    $scanFiles[] = str_replace('\\', '/', $file);
                }
            }

            $bootstrapFiles = [str_replace('\\', '/', $bootstrap)];
            foreach ([
                $glpiRoot . '/stubs/db_config_classes.php',
                $glpiRoot . '/stubs/glpi_constants.php',
                $glpiRoot . '/stubs/plugins_migrations_classes.php',
            ] as $file) {
                if (is_file($file)) {
                    $bootstrapFiles[] = str_replace('\\', '/', $file);
                }
            }

            $phpstanExtra .= "  scanDirectories:\n    - {$glpi}/src\n";
            if ($scanFiles !== []) {
                $phpstanExtra .= "  scanFiles:\n" . implode("\n", array_map(static fn (string $file): string => '    - ' . $file, $scanFiles)) . "\n";
            }
            $phpstanExtra .= "  bootstrapFiles:\n" . implode("\n", array_map(static fn (string $file): string => '    - ' . $file, $bootstrapFiles)) . "\n";
            if ($hasGlpiPhpStanExtension) {
                $phpstanExtra .= "  glpi:\n    glpiPath: {$glpi}\n";
                if ($context->glpiVersion() !== null) {
                    $phpstanExtra .= "    glpiVersion: '" . $context->glpiVersion() . "'\n";
                }
            }

            $glpiIncludes = htmlspecialchars($glpiRoot . '/inc/includes.php', ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $psalmExtra = "    <globals>\n        <var name=\"DB\" type=\"DBmysql\" />\n    </globals>\n"
                . "    <issueHandlers>\n        <InvalidGlobal>\n            <errorLevel type=\"suppress\">\n                <file name=\"{$glpiIncludes}\" />\n            </errorLevel>\n        </InvalidGlobal>\n    </issueHandlers>\n";
        }

        $pathLines = implode("\n", array_map(static fn (string $p): string => '    - ' . $p, $paths));
        file_put_contents(
            $phpstan,
            $includes
            . "parameters:\n  level: " . $context->phpStanLevel() . "\n  paths:\n{$pathLines}\n"
            . $phpstanExtra
            . '  tmpDir: ' . str_replace('\\', '/', $workspace->file('phpstan-tmp')) . "\n",
        );

        $xmlPaths = implode("\n", array_map(
            static fn (string $p): string => '        <' . (is_dir($p) ? 'directory' : 'file') . ' name="'
                . htmlspecialchars($p, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" />',
            $paths,
        ));
        $psalmAttrs = '    errorLevel="' . $context->psalmLevel() . '"' . "\n"
            . '    phpVersion="' . $context->phpVersion() . '"' . "\n";
        if ($context->profile() === 'glpi-plugin' && $bootstrap !== null) {
            $psalmAttrs .= '    findUnusedCode="false"' . "\n"
                . '    ensureOverrideAttribute="' . (version_compare($context->phpVersion(), '8.3', '>=') ? 'true' : 'false') . '"' . "\n"
                . '    autoloader="' . htmlspecialchars($bootstrap, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"' . "\n";
        }
        file_put_contents(
            $psalm,
            "<?xml version=\"1.0\"?>\n<psalm\n{$psalmAttrs}    resolveFromConfigFile=\"true\"\n    xmlns=\"https://getpsalm.org/schema/config\"\n>\n    <projectFiles>\n{$xmlPaths}\n    </projectFiles>\n{$psalmExtra}</psalm>\n",
        );

        $phpPaths = implode(",\n", array_map(static fn (string $p): string => '        ' . var_export($p, true), $paths));
        file_put_contents(
            $ecs,
            "<?php\n\ndeclare(strict_types=1);\n\nuse Symplify\\EasyCodingStandard\\Config\\ECSConfig;\n\nreturn ECSConfig::configure()\n    ->withPaths([\n{$phpPaths},\n    ])\n    ->withRootFiles()\n    ->withPreparedSets(psr12: true);\n",
        );
        file_put_contents(
            $rector,
            "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure()\n    ->withPaths([\n{$phpPaths},\n    ])\n    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);\n",
        );

        return [
            'phpstan' => $phpstan,
            'psalm' => $psalm,
            'ecs' => $ecs,
            'rector' => $rector,
            ...($bootstrap !== null ? ['glpi-bootstrap' => $bootstrap] : []),
        ];
    }
}
