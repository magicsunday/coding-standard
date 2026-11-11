<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Copies the shared release files of templates/webtrees-module/ into a webtrees module.
 *
 * Every file of the template directory is copied to the same relative path in the module, except
 * module.mk.example, which only documents the parameter file. The module keeps its own values in
 * Make/module.mk, which the script never writes. The copy is byte for byte apart from the identity
 * lines of the PHP files. These are the package name of the file header and of the link tag, and the
 * namespace. They are rendered from MODULE_NAME and MODULE_NS of the parameter file, because the header
 * and the namespace of a PHP file belong to the module and the code style gate of the module enforces
 * them. A file of the module that is not in the template is never touched.
 *
 * A module without Make/module.mk, with a required parameter missing or with a parameter of the wrong
 * shape is refused before any file is written. A second run changes nothing. The script reads local
 * files only. It is a tool for the person who updates a module, not a gate, and nothing runs it.
 *
 * Usage: sync-webtrees-module.php [--check] <module-directory>
 *
 * --check writes nothing and only lists what a run would change.
 *
 * Exit 0 means the module is in step or the files were written. Exit 1 means that --check found at
 * least one file that differs. Exit 2 means the script refused to run, because of the usage, a missing
 * parameter file, a missing or malformed parameter, or an unreadable or unwritable file.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

// This is a global-namespace entry script, so built-in functions are called
// unqualified (a `use function` import would be a no-op here).

// readModuleParameters() reads Make/module.mk the way make does, see its header for the grammar.
require_once __DIR__ . '/support/read-module-parameters.php';

/**
 * The directory of the shared files, relative to the root of this package.
 */
const TEMPLATE_DIRECTORY = '/templates/webtrees-module';

/**
 * The template file that documents the parameter file and is never copied.
 */
const EXAMPLE_FILE = 'module.mk.example';

/**
 * The parameter file of a module, relative to the module directory.
 */
const PARAMETER_FILE = 'Make/module.mk';

/**
 * The parameters that every module has to set to a non-empty value, with the pattern of the value.
 * The identity parameters end up in PHP files, so their shape is checked and not only their presence.
 */
const REQUIRED_PARAMETERS = [
    'MODULE_NAME'         => '/^webtrees-[a-z0-9]+(?:-[a-z0-9]+)*$/',
    'MODULE_NS'           => '/^[A-Za-z][A-Za-z0-9]*$/',
    'SCOPE_NS'            => '/^[A-Za-z][A-Za-z0-9]*$/',
    'WEBTREES_CONSTRAINT' => '/^\S.*$/',
    'CHART_LIB_MARKER'    => '/^[A-Za-z0-9][A-Za-z0-9._-]*$/',
];

/**
 * Prints a refusal and exits 2.
 *
 * @param string $message The reason
 *
 * @return never
 */
function refuse(string $message): never
{
    fwrite(\STDERR, 'sync-webtrees-module: ' . $message . "\n");

    exit(2);
}

/**
 * Checks the parameters and refuses a module that lacks one or holds one of the wrong shape.
 *
 * @param array<string, string> $parameters The parameters of the module
 *
 * @return void
 */
function validateParameters(array $parameters): void
{
    foreach (REQUIRED_PARAMETERS as $name => $pattern) {
        if (!array_key_exists($name, $parameters)) {
            refuse(PARAMETER_FILE . ' does not define ' . $name . '.');
        }

        if ($parameters[$name] === '') {
            refuse(PARAMETER_FILE . ' leaves ' . $name . ' empty.');
        }

        if (preg_match($pattern, $parameters[$name]) !== 1) {
            refuse(PARAMETER_FILE . ' holds a value for ' . $name . ' that does not have the expected shape.');
        }
    }

    // May be empty, but it has to be defined, so that a module without shared views says so.
    if (!array_key_exists('SHARED_VIEWS', $parameters)) {
        refuse(PARAMETER_FILE . ' does not define SHARED_VIEWS, define it empty for a module without shared views.');
    }

    foreach (preg_split('/\s+/', $parameters['SHARED_VIEWS'], -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $view) {
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $view) !== 1) {
            refuse(PARAMETER_FILE . ' holds a value for SHARED_VIEWS that does not have the expected shape.');
        }
    }
}

/**
 * Lists the files of the template that a module receives, relative to the template directory, in a stable order.
 *
 * @param string $templateDirectory The template directory
 *
 * @return list<string>
 */
function templateFiles(string $templateDirectory): array
{
    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($templateDirectory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($templateDirectory) + 1);

        if ($relative !== EXAMPLE_FILE) {
            $files[] = $relative;
        }
    }

    sort($files);

    return $files;
}

/**
 * Renders the identity lines of a PHP file for the module and leaves every other file as it is.
 *
 * @param string                $relative   The path of the file
 * @param string                $contents   The contents of the template file
 * @param array<string, string> $parameters The parameters of the module
 *
 * @return string The contents for the module
 */
function render(string $relative, string $contents, array $parameters): string
{
    if (!str_ends_with($relative, '.php')) {
        return $contents;
    }

    $rendered = str_replace(
        ['webtrees-<name>', 'Webtrees\\ModuleName\\'],
        [$parameters['MODULE_NAME'], 'Webtrees\\' . $parameters['MODULE_NS'] . '\\'],
        $contents
    );

    if (str_contains($rendered, '<name>') || str_contains($rendered, 'ModuleName')) {
        refuse('the template file ' . $relative . ' holds a placeholder that the script does not render.');
    }

    return $rendered;
}

/**
 * Writes a file through a temporary file in the same directory, so that a failed write leaves the old file.
 *
 * @param string $path     The target path
 * @param string $contents The contents
 *
 * @return void
 */
function writeFile(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
        refuse('cannot create the directory ' . $directory . '.');
    }

    $temporary = tempnam($directory, '.sync-');

    if ($temporary === false) {
        refuse('cannot create a temporary file in ' . $directory . '.');
    }

    if (file_put_contents($temporary, $contents) !== strlen($contents) || !chmod($temporary, 0o644) || !rename($temporary, $path)) {
        unlink($temporary);

        refuse('cannot write ' . $path . '.');
    }
}

$arguments = array_slice($argv, 1);
$check     = in_array('--check', $arguments, true);
$paths     = array_values(array_filter($arguments, static fn (string $argument): bool => $argument !== '--check'));

if (count($paths) !== 1 || str_starts_with($paths[0], '-')) {
    refuse('usage: sync-webtrees-module.php [--check] <module-directory>');
}

$moduleDirectory = rtrim($paths[0], '/');

if (!is_dir($moduleDirectory)) {
    refuse('the module directory does not exist.');
}

$parameterFile = $moduleDirectory . '/' . PARAMETER_FILE;

if (!is_file($parameterFile)) {
    refuse('the module has no ' . PARAMETER_FILE . ', copy module.mk.example there and set the values of the module.');
}

$parameterContents = file_get_contents($parameterFile);

if ($parameterContents === false) {
    refuse('cannot read ' . PARAMETER_FILE . '.');
}

$parameters = readModuleParameters($parameterContents);

validateParameters($parameters);

$templateDirectory = dirname(__DIR__) . TEMPLATE_DIRECTORY;
$differing         = [];

foreach (templateFiles($templateDirectory) as $relative) {
    $template = file_get_contents($templateDirectory . '/' . $relative);

    if ($template === false) {
        refuse('cannot read the template file ' . $relative . '.');
    }

    $rendered = render($relative, $template, $parameters);
    $target   = $moduleDirectory . '/' . $relative;
    $exists   = is_file($target);

    if ($exists && file_get_contents($target) === $rendered) {
        continue;
    }

    $differing[] = ($exists ? 'updated ' : 'created ') . $relative;

    if (!$check) {
        writeFile($target, $rendered);
    }
}

if ($differing === []) {
    fwrite(\STDOUT, "The module is in step with the template, nothing changed.\n");

    exit(0);
}

foreach ($differing as $line) {
    fwrite(\STDOUT, ($check ? 'would be ' : '') . $line . "\n");
}

exit($check ? 1 : 0);
