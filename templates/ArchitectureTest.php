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
 * Deptrac first, phpat only where Deptrac cannot: layer dependencies belong in
 * deptrac.yaml. A rule here is either a structural invariant (a class property
 * such as a modifier or a name, which Deptrac cannot inspect) or a sub-layer
 * boundary (an edge granted to one sub-namespace of a Deptrac layer and denied
 * to the rest of it, which Deptrac cannot express because it checks a class
 * against every layer it belongs to) or a shared layer narrowed for this package
 * (Deptrac unites rulesets across imports, so deptrac.yaml can only widen a shared
 * layer). Every rule says which of the three it is.
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
 * trait), so check each new subject against a deliberate violation once.
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

    // Example sub-layer boundary — one #[TestRule] method per boundary. Plain
    // layer dependencies ("Model must not depend on Service") go to deptrac.yaml
    // instead, never here.
    //
    // Sub-layer boundary (Deptrac cannot grant Support\Database an edge the rest
    // of the Support layer is denied): only the repositories and the database
    // support code may touch the database manager.
    //
    // #[TestRule]
    // public function onlyTheDatabaseSubLayerUsesTheManager(): Rule
    // {
    //     return PHPat::rule()
    //         ->classes(Selector::inNamespace('Vendor\Package'))
    //         ->excluding(
    //             Selector::inNamespace('Vendor\Package\Repository'),
    //             Selector::inNamespace('Vendor\Package\Support\Database'),
    //         )
    //         ->shouldNot()->dependOn()
    //         ->classes(Selector::classname('Illuminate\Database\Capsule\Manager'))
    //         ->because('Database access is confined to repositories.');
    // }
}
