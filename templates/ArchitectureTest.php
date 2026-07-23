<?php

/**
 * This file is part of the package magicsunday/<repo>.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Vendor\Package\Test\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced by PHPat (runs as part of PHPStan).
 *
 * Copy this file to tests/Architecture/ArchitectureTest.php, adjust the namespace
 * to the consuming package, and register it in phpstan.neon:
 *
 *     services:
 *         -
 *             class: Vendor\Package\Test\Architecture\ArchitectureTest
 *             tags:
 *                 - phpat.test
 *
 * The two structural rules below (Abstract* naming, final leaves) are house-wide
 * and generic — keep them. Add the package's own layer-dependency rules following
 * the commented example.
 *
 * @internal
 */
final class ArchitectureTest
{
    /**
     * Every abstract class carries the `Abstract` name prefix — the mechanical
     * counterpart of php-reviewer S52. The regex is matched against the FQCN, so
     * `[^\\]*$` pins it to the last segment (the short class name).
     *
     * @return Rule
     */
    #[TestRule]
    public function abstractClassesAreAbstractPrefixed(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::isAbstract())
            ->should()->beNamed('/\\\\Abstract[^\\\\]*$/', true)
            ->because('House rule: abstract classes are named Abstract<Name>.');
    }

    /**
     * Leaf classes are final. Replace the selector with the package's own value
     * objects / leaf namespaces; exclude the abstract bases they extend.
     *
     * @return Rule
     */
    #[TestRule]
    public function leafClassesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Vendor\Package\Model'))
            ->excluding(Selector::isAbstract())
            ->shouldBeFinal()
            ->because('House rule: value objects and leaf classes are final.');
    }

    // Example layer-dependency rule — one #[TestRule] method per boundary:
    //
    // #[TestRule]
    // public function modelDoesNotDependOnIo(): Rule
    // {
    //     return PHPat::rule()
    //         ->classes(Selector::inNamespace('Vendor\Package\Model'))
    //         ->shouldNot()->dependOn()
    //         ->classes(Selector::inNamespace('Vendor\Package\Io'))
    //         ->because('Model holds data; Io performs the reads.');
    // }
}
