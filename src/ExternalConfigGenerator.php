<?php

declare(strict_types=1);

require_once __DIR__ . '/ProjectContext.php';

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
        $extra = '';
        $bootstrap = null;

        if ($context->profile() === 'glpi-plugin') {
            $glpiRoot = $context->glpiRoot();
            if ($glpiRoot === null) {
                throw new RuntimeException('Host GLPI obrigatório para gerar configuração do profile glpi-plugin.');
            }
            $glpi = str_replace('\\', '/', $glpiRoot);
            $extensionCandidates = [
                $context->root() . '/vendor/glpi-project/phpstan-glpi/extension.neon',
                $glpiRoot . '/vendor/glpi-project/phpstan-glpi/extension.neon',
            ];
            foreach ($extensionCandidates as $candidate) {
                if (is_file($candidate)) {
                    $includes = "includes:\n  - " . str_replace('\\', '/', $candidate) . "\n\n";
                    break;
                }
            }

            $bootstrap = $workspace->file('glpi-bootstrap.php');
            $quoted = var_export($glpiRoot, true);
            file_put_contents($bootstrap, "<?php\n\ndeclare(strict_types=1);\n\n\$glpiRoot = {$quoted};\n\$autoload = \$glpiRoot . '/vendor/autoload.php';\nif (is_file(\$autoload)) { require_once \$autoload; }\n");

            $extra = "  scanDirectories:\n    - {$glpi}/src\n  bootstrapFiles:\n    - " . str_replace('\\', '/', $bootstrap) . "\n  glpi:\n    glpiPath: {$glpi}\n";
            if ($context->glpiVersion() !== null) {
                $extra .= "    glpiVersion: '" . $context->glpiVersion() . "'\n";
            }
        }

        $pathLines = implode("\n", array_map(static fn (string $p): string => '    - ' . $p, $paths));
        file_put_contents($phpstan, $includes . "parameters:\n  level: " . $context->phpStanLevel() . "\n  paths:\n{$pathLines}\n{$extra}  tmpDir: " . str_replace('\\', '/', $workspace->file('phpstan-tmp')) . "\n");

        $xmlPaths = implode("\n", array_map(static fn (string $p): string => '        <directory name="' . htmlspecialchars($p, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" />', $paths));
        $psalmAttrs = '    errorLevel="' . $context->psalmLevel() . '"' . "\n" . '    phpVersion="' . $context->phpVersion() . '"' . "\n";
        $psalmExtra = '';
        if ($context->profile() === 'glpi-plugin' && $bootstrap !== null) {
            $psalmAttrs .= '    findUnusedCode="false"' . "\n" . '    ensureOverrideAttribute="' . (version_compare($context->phpVersion(), '8.3', '>=') ? 'true' : 'false') . '"' . "\n" . '    autoloader="' . htmlspecialchars($bootstrap, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"' . "\n";
            $psalmExtra = "    <globals>\n        <var name=\"DB\" type=\"DBmysql\" />\n    </globals>\n";
        }
        file_put_contents($psalm, "<?xml version=\"1.0\"?>\n<psalm\n{$psalmAttrs}    resolveFromConfigFile=\"true\"\n    xmlns=\"https://getpsalm.org/schema/config\"\n>\n    <projectFiles>\n{$xmlPaths}\n    </projectFiles>\n{$psalmExtra}</psalm>\n");

        $phpPaths = implode(",\n", array_map(static fn (string $p): string => '        ' . var_export($p, true), $paths));
        file_put_contents($ecs, "<?php\n\ndeclare(strict_types=1);\n\nuse Symplify\\EasyCodingStandard\\Config\\ECSConfig;\n\nreturn ECSConfig::configure()->withPaths([\n{$phpPaths},\n])->withPreparedSets(psr12: true);\n");
        file_put_contents($rector, "<?php\n\ndeclare(strict_types=1);\n\nuse Rector\\Config\\RectorConfig;\n\nreturn RectorConfig::configure()->withPaths([\n{$phpPaths},\n])->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);\n");

        return ['phpstan' => $phpstan, 'psalm' => $psalm, 'ecs' => $ecs, 'rector' => $rector];
    }
}
