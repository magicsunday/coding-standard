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
 * Architecture rules enforced by phpat (runs as part of PHPStan), for the opt-in
 * preset phpstan/phpat.neon.
 *
 * Deptrac for dependencies, phpat for structure: every "X must not depend on Y"
 * rule belongs in deptrac.yaml — including a boundary for one sub-namespace or a
 * shared layer narrowed for this package, both expressible there with an overlay
 * layer (see the README's Deptrac section). A rule here is a structural invariant:
 * a class property such as a modifier, a name or a required interface, which
 * Deptrac cannot inspect.
 *
 * Copy this file to tests/Architecture/ArchitectureTest.php, adjust the namespace
 * to the consuming package, `composer require --dev phpat/phpat`, include the
 * preset next to base.neon and register the class in phpstan.neon:
 *
 *     includes:
 *         - .build/vendor/magicsunday/coding-standard/phpstan/base.neon
 *         - .build/vendor/magicsunday/coding-standard/phpstan/phpat.neon
 *
 *     services:
 *         -
 *             class: Vendor\Package\Test\Architecture\ArchitectureTest
 *             tags:
 *                 - phpat.test
 *
 * The two structural rules below (Abstract* naming, final leaves) are house-wide
 * and generic — keep them. A subject that matches no class enforces nothing while
 * PHPStan stays green (e.g. a namespace holding only traits: phpat never visits a
 * trait), so wire the subject-liveness guard next to the preset — a
 * `"ci:test:php:phpat-subjects": ["check-phpat-subjects.php ."]` composer script,
 * see the README. It evaluates each subject statically, as the set of src/
 * classes it selects: inNamespace(), classname(), implements(), extends() (each
 * optionally as a regex), isInterface(), isAbstract(), isEnum(), isTrait(), all(),
 * composed with AllOf()/AnyOf()/NoneOf()/Not(), over single-quoted literals,
 * `self::NAMESPACE_ROOT`, `Foo::class` and `.`-concatenations of those. Any other
 * selector or argument shape fails closed.
 *
 * @internal
 */
final class ArchitectureTest
{
    /**
     * Structural invariant (a class name; Deptrac cannot inspect it): every
     * abstract class carries the `Abstract` name prefix — the mechanical
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
     * Structural invariant (a class modifier; Deptrac cannot inspect it): leaf
     * classes are final. Replace the selector with the package's own value
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
            ->should()->beFinal()
            ->because('House rule: value objects and leaf classes are final.');
    }
}
