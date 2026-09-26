<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use MagicSunday\CodingStandard\Test\Support\AbstractConsumerConfigTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

use function copy;
use function file_put_contents;
use function mkdir;

/**
 * Fixture-driven cases for the extends-chain resolution in
 * bin/consumer-checks/check-biome-tsconfig.php ($resolveExtendsLayers,
 * $foldExtendsChain) and its bin/check-js-config.mjs twin (GH-36): the
 * contract is checked against the EFFECTIVE config every `extends` entry
 * folds into, not just the document's own top level — local extends
 * targets, their order relative to the shared entry, single rules switched
 * off by name, and the merge edge cases (empty objects, an object-shaped
 * `extends`, a literal `__proto__` key). Every case runs BOTH gates against
 * the same fixture through the assertBoth*() helpers; see
 * AbstractConsumerConfigTestCase for the shared scaffolding.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class CheckConsumerConfigBiomeTsconfigExtendsChainTest extends AbstractConsumerConfigTestCase
{
    // -------------------------------------------------------------------
    // Fixture builders only this contract's cases use — the shared ones
    // (mkCase()/mkJsCase()/...) live in AbstractConsumerConfigTestCase.
    // -------------------------------------------------------------------

    /**
     * Writes a minimal biome.loose.json (`{ "linter": { "enabled": false } }`)
     * — the local `extends` target fixtures use to disable the linter one
     * level removed from the biome.json under test.
     *
     * @param string $dir The directory to write biome.loose.json into.
     *
     * @return void
     */
    private static function writeMinimalBiomeLooseJson(string $dir): void
    {
        file_put_contents(
            $dir . '/biome.loose.json',
            "{ \"linter\": { \"enabled\": false } }\n",
        );
    }

    /**
     * Writes a minimal tsconfig.loose.json (`{ "compilerOptions": {
     * "noUncheckedIndexedAccess": false } }`) — the local `extends` target
     * fixtures use to disable the flag one level removed from the
     * tsconfig.json under test.
     *
     * @param string $dir The directory to write tsconfig.loose.json into.
     *
     * @return void
     */
    private static function writeMinimalTsconfigLooseJson(string $dir): void
    {
        file_put_contents(
            $dir . '/tsconfig.loose.json',
            "{ \"compilerOptions\": { \"noUncheckedIndexedAccess\": false } }\n",
        );
    }

    // -------------------------------------------------------------------
    // GH-36: the extends chain resolves to the EFFECTIVE config, not just
    // the document's own top level.
    // -------------------------------------------------------------------

    /**
     * Route 1, reproduced from the issue almost verbatim: the shared entry
     * is listed FIRST, a LOCAL second entry disables the linter wholesale,
     * and the document's own top level never mentions `linter` at all — so
     * a check reading only the top level would miss the disable entirely.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeLaterLocalExtendsTargetDisablingLinter(): void
    {
        $dir = $this->mkJsCase();
        self::writeBiomeWithLocalLooseExtendsTarget($dir);
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothReject($dir, '`linter.enabled` must not be false', 'biome.json whose LATER local extends target disables the linter');
    }

    /**
     * The order-sensitive half in the OTHER direction: the shared entry
     * listed AFTER a local override wins the fold and undoes it — the
     * shared base sets `linter.enabled: true` explicitly, so it wins back.
     * A fix that treats the shared entry as a no-op regardless of position
     * passes route 1 above but must still accept HERE.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeSharedExtendsEntryFollowingAndUndoingLocalOverride(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"./biome.loose.json\", \"@magicsunday/coding-standard/biome/base.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothAccept($dir, 'biome.json whose shared extends entry follows and undoes a local override');
    }

    /**
     * Biome.json whose local extends target is a legitimate, non-drifting relaxation.
     */
    #[Test]
    public function acceptsBiomeLocalExtendsTargetLegitimateRelaxation(): void
    {
        // A local target that does something ordinary and touches none of
        // the checked toggles must not be reported. `lineWidth` is a
        // formatter ergonomic this gate does not pin.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"./biome.wide.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");
        file_put_contents($dir . '/biome.wide.json', "{ \"formatter\": { \"lineWidth\": 120 } }\n");

        $this->assertBothAccept($dir, 'biome.json whose local extends target is a legitimate, non-drifting relaxation');
    }

    /**
     * Biome.json whose second extends entry is an uninstalled package, not a local file.
     */
    #[Test]
    public function acceptsBiomeSecondExtendsEntryUninstalledPackage(): void
    {
        // A package-scoped entry other than the shared one resolves to no
        // file in this repository, so it must be silently skipped rather
        // than reported or crashed on.
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"@some/other-package\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");

        $this->assertBothAccept($dir, 'biome.json whose second extends entry is an uninstalled package, not a local file');
    }

    /**
     * Biome.json whose local extends target escapes the repository via ../.
     */
    #[Test]
    public function acceptsBiomeLocalExtendsTargetEscapingRepositoryViaDotDot(): void
    {
        // A specifier that escapes the repository must not be followed —
        // the escape target genuinely disables the linter, so if it were
        // followed this case would reject; accepting proves it is not.
        //
        // The "repository" is nested one level under this test's own
        // fixture root (rather than using that root's parent, sys_get_temp_dir()
        // itself, as the escape target's home) so the write stays inside a
        // directory FixtureDirectory::cleanup() actually owns and removes.
        $root = $this->fixture()->path();
        $dir  = $root . '/repo';
        mkdir($dir, 0o700);
        copy(self::canon() . '/phpunit.xml', $dir . '/phpunit.xml');
        copy(self::canon() . '/tsconfig.json', $dir . '/tsconfig.json');
        self::writeAdoptingPackageJson($dir);
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"../escape.json\"],\n    \"files\": { \"includes\": [\"src/**\"] }\n}\n");
        file_put_contents($root . '/escape.json', "{ \"linter\": { \"enabled\": false } }\n");

        $this->assertBothAccept($dir, 'biome.json whose local extends target escapes the repository via ../');
    }

    /**
     * Route 2: one rule switched off by name survives every check that
     * only looks at the group-level `recommended`/`preset` floor. Both
     * value shapes Biome's schema allows are driven — a bare string and an
     * options object.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeRuleOffByBareStringValue(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": \"off\" } } }\n}\n");

        $this->assertBothReject($dir, '`linter.rules.suspicious.noDoubleEquals` must not be "off"', 'biome.json switching a shared rule off by its bare-string value');
    }

    /**
     * Biome.json switching a shared rule off via its options-object level.
     */
    #[Test]
    public function rejectsBiomeRuleOffByOptionsObjectLevel(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": { \"level\": \"off\" } } } }\n}\n");

        $this->assertBothReject($dir, '`linter.rules.suspicious.noDoubleEquals` must not be "off"', 'biome.json switching a shared rule off via its options-object level');
    }

    /**
     * Biome.json switching a shared rule off inside an overrides entry.
     */
    #[Test]
    public function rejectsBiomeRuleOffInOverride(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"overrides\": [\n        { \"includes\": [\"**/*.legacy.js\"], \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": \"off\" } } } }\n    ]\n}\n");

        $this->assertBothReject($dir, 'overrides[0].linter.rules.suspicious.noDoubleEquals` must not be "off"', 'biome.json switching a shared rule off inside an overrides entry');
    }

    /**
     * Biome.json tightening a shared rule's severity (not drift).
     */
    #[Test]
    public function acceptsBiomeRuleSeverityEscalatedNotOff(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noDoubleEquals\": \"error\" } } }\n}\n");

        $this->assertBothAccept($dir, "biome.json tightening a shared rule's severity (not drift)");
    }

    /**
     * Biome.json switching off a rule the shared config never turns on itself.
     */
    #[Test]
    public function acceptsBiomeRuleOffThatSharedConfigNeverTurnsOn(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"suspicious\": { \"noExplicitAny\": \"off\" } } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json switching off a rule the shared config never turns on itself');
    }

    /**
     * A rule GROUP literally named after an inherited Object.prototype
     * member: the Node gate's `sharedRules[group]` lookup resolves through
     * the prototype chain rather than `undefined`, so `?? []` never
     * applies — `for...of` over a function throws and crashes the whole
     * gate. The gate must never CRASH on a config, whatever Biome does
     * with it. PHP is unaffected — an array key lookup has no prototype
     * chain.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeRuleGroupNamedToStringDoesNotCrashTheGate(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"linter\": { \"rules\": { \"toString\": { \"someRule\": \"off\" } } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json with a rule group literally named toString does not crash the gate');
    }

    /**
     * Tsconfig.json whose LATER local extends target disables noUncheckedIndexedAccess.
     */
    #[Test]
    public function rejectsTsconfigLaterLocalExtendsTargetDisablingFlag(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/tsconfig/base.json\", \"./tsconfig.loose.json\"],\n    \"include\": [\"src\"]\n}\n");
        self::writeMinimalTsconfigLooseJson($dir);

        $this->assertBothReject($dir, 'noUncheckedIndexedAccess', 'tsconfig.json whose LATER local extends target disables noUncheckedIndexedAccess');
    }

    /**
     * Tsconfig.json whose shared extends entry follows and undoes a local override.
     */
    #[Test]
    public function acceptsTsconfigSharedExtendsEntryFollowingAndUndoingLocalOverride(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": [\"./tsconfig.loose.json\", \"@magicsunday/coding-standard/tsconfig/base.json\"],\n    \"include\": [\"src\"]\n}\n");
        self::writeMinimalTsconfigLooseJson($dir);

        $this->assertBothAccept($dir, 'tsconfig.json whose shared extends entry follows and undoes a local override');
    }

    /**
     * Tsconfig.json whose local extends target only adds paths (not drift).
     */
    #[Test]
    public function acceptsTsconfigLocalExtendsTargetOnlyAddingPaths(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/tsconfig.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/tsconfig/base.json\", \"./tsconfig.paths-only.json\"],\n    \"include\": [\"src\"]\n}\n");
        file_put_contents($dir . '/tsconfig.paths-only.json', "{ \"compilerOptions\": { \"paths\": { \"@app/*\": [\"src/*\"] } } }\n");

        $this->assertBothAccept($dir, 'tsconfig.json whose local extends target only adds paths (not drift)');
    }

    /**
     * An empty JSON object decodes to PHP's `[]`, indistinguishable from an
     * empty JSON array — so a naive merge treats an empty overlay OBJECT as
     * a list and replaces the accumulated value outright instead of
     * leaving it untouched. Only the REJECT direction is fixture-tested:
     * wiping a re-enabled `linter` object down to `{}` and correctly
     * preserving it both report the identical (accept) verdict, so only
     * wiping a *disabled* section — removing the very key the check reads
     * — actually diverges into a false accept.
     *
     * @return void
     */
    #[Test]
    public function rejectsBiomeEmptyTopLevelObjectDoesNotMaskUnresolvedDisable(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\", \"./biome.loose.json\"],\n    \"files\": { \"includes\": [\"src/**\"] },\n    \"linter\": {}\n}\n");
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothReject($dir, '`linter.enabled` must not be false', 'biome.json whose empty top-level object does not mask an unresolved disable');
    }

    /**
     * `extends` shaped as a JSON OBJECT rather than an array or string is
     * not accepted by either real tool: `Array.isArray` is false for a
     * plain object, so both sides must agree on ACCEPT, matching neither
     * resolving the local target inside it. Found during this suite's own
     * audit round: an early implementation iterated an object's VALUES as
     * extends candidates in PHP but not in Node, so the same config could
     * reach a different verdict on each side — this case pins both sides to
     * the same ACCEPT so that divergence cannot come back unnoticed.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeExtendsAsJsonObjectRatherThanArray(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": { \"shared\": \"@magicsunday/coding-standard/biome/base.json\", \"local\": \"./biome.loose.json\" }\n}\n");
        self::writeMinimalBiomeLooseJson($dir);

        $this->assertBothAccept($dir, 'biome.json whose extends is a JSON object rather than an array');
    }

    /**
     * Prototype pollution via a literal `"__proto__"` key (CWE-1321):
     * `JSON.parse` creates a real OWN `__proto__` property, but
     * `merged[key]` on the read side falls through to the inherited
     * accessor once the accumulator carries no own `__proto__`, returning
     * the object's actual prototype — treated as ordinary data and
     * reassigned via `[[SetPrototypeOf]]`. Verified: without the fix this
     * fabricated a `files.includes` violation on a document that never set
     * `files` at all. PHP is unaffected — plain arrays have no prototype
     * mechanism.
     *
     * @return void
     */
    #[Test]
    public function acceptsBiomeProtoKeyDoesNotFabricateFilesIncludesViolation(): void
    {
        $dir = $this->mkJsCase();
        file_put_contents($dir . '/biome.json', "{\n    \"extends\": [\"@magicsunday/coding-standard/biome/base.json\"],\n    \"__proto__\": { \"files\": { \"includes\": [\"!**/*\"] } }\n}\n");

        $this->assertBothAccept($dir, 'biome.json carrying a __proto__ key does not fabricate a files.includes violation');
    }
}
