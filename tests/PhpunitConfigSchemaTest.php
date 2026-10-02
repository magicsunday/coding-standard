<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use Composer\InstalledVersions;
use DOMDocument;
use LibXMLError;
use MagicSunday\CodingStandard\Test\Support\FixtureDirectory;
use MagicSunday\CodingStandard\Test\Support\ScrubbedDiagnostics;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function file_put_contents;
use function implode;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Validates every PHPUnit configuration this package owns or ships against
 * the schema of the PHPUnit version actually installed.
 *
 * Under these strict configs, a value a new PHPUnit release drops from its
 * schema turns every run red, even with all tests green. Only this
 * repository's own phpunit.xml.dist is ever loaded by a PHPUnit run here.
 * The shipped template and the consumer fixture are not, so without this
 * check a template that went stale against a new PHPUnit would surface first
 * in a consumer's build. Each CI leg resolves its own PHPUnit version, so
 * each checks the configs against the schema it installed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class PhpunitConfigSchemaTest extends TestCase
{
    use ScrubbedDiagnostics;

    /**
     * The PHPUnit configurations this package owns or ships, relative to
     * the repository root.
     *
     * @return array<string, array{0: string}>
     */
    public static function configProvider(): array
    {
        return [
            'own suite'        => ['phpunit.xml.dist'],
            'shipped template' => ['templates/phpunit.xml.dist'],
            'consumer fixture' => ['tests/consumer/phpunit.xml'],
        ];
    }

    /**
     * Verifies that the configuration validates against the installed
     * PHPUnit's own schema.
     *
     * @param string $relativePath The configuration file, relative to the repository root.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function configValidatesAgainstTheInstalledPhpunitSchema(string $relativePath): void
    {
        $schema = self::installedPhpunitSchema();
        $config = dirname(__DIR__) . '/' . $relativePath;

        self::assertFileExists($config);

        $errors = self::schemaErrors($config, $schema);

        if ($errors !== []) {
            self::fail(
                self::diagnosticMessage(
                    sprintf('%s does not validate against %s:', $relativePath, $schema),
                    implode(' | ', $errors),
                ),
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Configurations the schema check must reject, each with a fragment its
     * report has to name.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedConfigProvider(): array
    {
        return [
            'value outside the schema' => [
                '<?xml version="1.0" encoding="UTF-8"?><phpunit executionOrder="not-an-order"/>',
                'executionOrder',
            ],
            'unknown root attribute' => [
                '<?xml version="1.0" encoding="UTF-8"?><phpunit notAPhpunitAttribute="true"/>',
                'notAPhpunitAttribute',
            ],
            'malformed XML' => [
                '<?xml version="1.0" encoding="UTF-8"?><phpunit',
                'Start Tag',
            ],
        ];
    }

    /**
     * Verifies that the schema check reports a configuration the installed
     * PHPUnit schema does not accept, so the check cannot pass vacuously.
     *
     * @param string $xml              The configuration content to check.
     * @param string $expectedFragment A fragment the report must contain.
     *
     * @return void
     */
    #[Test]
    #[DataProvider('rejectedConfigProvider')]
    public function schemaCheckRejectsAConfigTheInstalledSchemaDoesNotAccept(
        string $xml,
        string $expectedFragment,
    ): void {
        $fixture = new FixtureDirectory();

        try {
            $config = $fixture->path() . '/phpunit.xml';

            self::assertNotFalse(file_put_contents($config, $xml));

            $errors = self::schemaErrors($config, self::installedPhpunitSchema());
        } finally {
            $fixture->cleanup();
        }

        if ($errors === []) {
            self::fail('The schema check accepted a configuration the installed PHPUnit schema rejects.');
        }

        $report = implode(' | ', $errors);

        if (!str_contains($report, $expectedFragment)) {
            self::fail(
                self::diagnosticMessage(
                    sprintf('The schema report does not name "%s":', $expectedFragment),
                    $report,
                ),
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Loads the configuration and validates it against the schema, returning
     * one message per libxml error, or an empty list when it validates.
     *
     * @param string $config The configuration file to check.
     * @param string $schema The schema to validate against.
     *
     * @return list<string>
     */
    private static function schemaErrors(string $config, string $schema): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument();
            $valid    = $document->load($config) && $document->schemaValidate($schema);
            $errors   = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($valid) {
            return [];
        }

        return array_map(
            static fn (LibXMLError $error): string => sprintf(
                'line %d: %s',
                $error->line,
                trim($error->message),
            ),
            $errors,
        );
    }

    /**
     * Returns the path of the phpunit.xsd that ships with the installed
     * PHPUnit version.
     *
     * @return string
     */
    private static function installedPhpunitSchema(): string
    {
        $schema = InstalledVersions::getInstallPath('phpunit/phpunit') . '/phpunit.xsd';

        self::assertFileExists($schema);

        return $schema;
    }
}
