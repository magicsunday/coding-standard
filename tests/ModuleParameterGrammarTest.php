<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function copy;
use function explode;
use function file_put_contents;
use function implode;
use function is_array;
use function json_decode;
use function mkdir;
use function readModuleParameters;
use function sprintf;
use function trim;

use const JSON_THROW_ON_ERROR;

require_once __DIR__ . '/../bin/support/read-module-parameters.php';

/**
 * Pins the grammar of Make/module.mk (GH-344). The same fixture goes through the three readers of the file, the
 * function of the sync script, ModuleParameters.php of the release tests and real make, and all of them have to
 * return the value that the case documents.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class ModuleParameterGrammarTest extends GateTestCase
{
    private const string BASE = "MODULE_NAME := webtrees-base\nSCOPE_NS := BaseVendor\nSHARED_VIEWS := one two\n";

    /**
     * The sync script reads the file the way make does.
     *
     * @param array{string, string, string} $expected The module name, the scope prefix and the views
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function theSyncScriptReadsTheFileLikeMake(string $contents, array $expected): void
    {
        $parameters = readModuleParameters($contents);

        self::assertSame($expected, [$parameters['MODULE_NAME'] ?? null, $parameters['SCOPE_NS'] ?? null, $parameters['SHARED_VIEWS'] ?? null]);
    }

    /**
     * The release tests read the file the way make does.
     *
     * @param array{string, string, string} $expected The module name, the scope prefix and the views
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function theReleaseTestsReadTheFileLikeMake(string $contents, array $expected): void
    {
        $directory = $this->fixture()->path() . '/module';

        mkdir($directory . '/Make', 0o700, true);
        mkdir($directory . '/tests/Release', 0o700, true);

        file_put_contents($directory . '/Make/module.mk', $contents);
        copy(self::root() . '/templates/webtrees-module/tests/Release/ModuleParameters.php', $directory . '/tests/Release/ModuleParameters.php');
        file_put_contents(
            $directory . '/run.php',
            sprintf(
                <<<'PHP'
                    <?php
                    require %s;
                    require __DIR__ . '/tests/Release/ModuleParameters.php';
                    use MagicSunday\Webtrees\ModuleName\Test\Release\ModuleParameters as P;
                    echo json_encode([P::moduleName(), P::scopeNamespace(), implode(' ', P::sharedViews())]);
                    PHP,
                "'" . self::root() . "/.build/vendor/autoload.php'"
            )
        );

        $result = (new Support\GateProcess())->runRaw(['php', 'run.php'], $directory);
        $values = json_decode($result->output, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue(is_array($values), $result->output);
        self::assertSame($expected, $values);
    }

    /**
     * Make itself returns the documented values, which keeps the expectations of the cases honest.
     *
     * @param array{string, string, string} $expected The module name, the scope prefix and the views
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function makeReturnsTheDocumentedValues(string $contents, array $expected): void
    {
        $directory = $this->fixture()->path() . '/make';

        mkdir($directory, 0o700, true);

        file_put_contents($directory . '/module.mk', $contents);
        file_put_contents($directory . '/printer.mk', "all:\n\t@echo '$(MODULE_NAME)|$(SCOPE_NS)|$(SHARED_VIEWS)'\n");

        $result = (new Support\GateProcess())->runRaw(['make', '--no-print-directory', '-f', 'module.mk', '-f', 'printer.mk'], $directory);

        self::assertSame(0, $result->exitCode, $result->output);
        self::assertSame($expected, explode('|', trim($result->output)));
    }

    /**
     * Each case is a file and the values that make takes from it. The dimension that the cases span is the form of
     * the assignment, the order of the assignments of one name, the line endings and the lines that are no assignment.
     *
     * @return array<string, array{string, array{string, string, string}}>
     */
    public static function fixtures(): array
    {
        $base = ['webtrees-base', 'BaseVendor', 'one two'];

        return [
            'plain assignments'                      => [self::BASE, $base],
            'doubled simple assignment'              => [self::BASE . "MODULE_NAME := webtrees-later\n", ['webtrees-later', 'BaseVendor', 'one two']],
            'doubled recursive assignment'           => [self::BASE . "MODULE_NAME = webtrees-later\n", ['webtrees-later', 'BaseVendor', 'one two']],
            'doubled colon assignment'               => [self::BASE . "MODULE_NAME ::= webtrees-later\n", ['webtrees-later', 'BaseVendor', 'one two']],
            'later conditional assignment'           => [self::BASE . "SCOPE_NS ?= OtherVendor\n", $base],
            'conditional assignment alone'           => ["MODULE_NAME := webtrees-base\nSCOPE_NS ?= AloneVendor\nSHARED_VIEWS :=\n", ['webtrees-base', 'AloneVendor', '']],
            'conditional assignment then assignment' => ["MODULE_NAME := webtrees-base\nSCOPE_NS ?= FirstVendor\nSCOPE_NS = FinalVendor\nSHARED_VIEWS :=\n", ['webtrees-base', 'FinalVendor', '']],
            'doubled conditional assignment'         => ["MODULE_NAME := webtrees-base\nSCOPE_NS ?= FirstVendor\nSCOPE_NS ?= SecondVendor\nSHARED_VIEWS :=\n", ['webtrees-base', 'FirstVendor', '']],
            'indented assignment'                    => [self::BASE . "  MODULE_NAME := webtrees-indented\n", ['webtrees-indented', 'BaseVendor', 'one two']],
            'comment lines'                          => [self::BASE . "# MODULE_NAME := webtrees-commented\n\n#SCOPE_NS := CommentedVendor\n", $base],
            'carriage returns'                       => [implode("\r\n", explode("\n", self::BASE . "SCOPE_NS := LaterVendor\n")), ['webtrees-base', 'LaterVendor', 'one two']],
            'empty value'                            => ["MODULE_NAME := webtrees-base\nSCOPE_NS := BaseVendor\nSHARED_VIEWS :=\n", ['webtrees-base', 'BaseVendor', '']],
        ];
    }
}
