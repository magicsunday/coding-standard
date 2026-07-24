#!/usr/bin/env bash
#
# This file is part of the package magicsunday/coding-standard.
#
# For the full copyright and license information, please read the
# LICENSE file that was distributed with this source code.
#
# Fixture-driven cases for bin/check-phpat-subjects.php. Proves the guard ACCEPTS an
# ArchitectureTest whose rule subjects all match a real class, and REJECTS the vacuous
# cases — the trait-only namespace subject (the manifested bug), an empty namespace, a
# missing classname target, and an unparseable subject (fail-closed) — while treating an
# isAbstract() subject with no abstract class as a legitimate conditional guard.
#
# Run from the package root: bash tests/check-phpat-subjects-cases.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GATE="$ROOT/bin/check-phpat-subjects.php"

fails=0

assert_accepts() {
    local dir="$1" label="$2" out rc
    out="$(php "$GATE" "$dir" 2>&1)" && rc=0 || rc=$?
    if [ "$rc" -ne 0 ]; then
        printf 'FAIL (expected accept): %s\n%s\n' "$label" "$out"
        fails=$((fails + 1))
    else
        printf 'ok (accepted): %s\n' "$label"
    fi
}

assert_rejects() {
    local dir="$1" label="$2" expected="$3" out rc
    out="$(php "$GATE" "$dir" 2>&1)" && rc=0 || rc=$?
    if [ "$rc" -eq 0 ]; then
        printf 'FAIL (expected reject): %s\n%s\n' "$label" "$out"
        fails=$((fails + 1))
    elif ! grep -qF "$expected" <<<"$out"; then
        printf 'FAIL (rejected, but not for the tested reason): %s\n  expected substring: %s\n%s\n' "$label" "$expected" "$out"
        fails=$((fails + 1))
    else
        printf 'ok (rejected on the tested violation): %s\n' "$label"
    fi
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# write_class <dir> <relpath-under-src> <namespace> <kind> <name>
write_class() {
    local dir="$1" rel="$2" ns="$3" kind="$4" name="$5"
    mkdir -p "$dir/src/$(dirname "$rel")"
    {
        printf '<?php\n\ndeclare(strict_types=1);\n\n'
        printf 'namespace %s;\n\n' "$ns"
        printf '%s %s\n{\n}\n' "$kind" "$name"
    } > "$dir/src/$rel"
}

# write_archtest <dir> <rule-methods-block>
write_archtest() {
    local dir="$1" methods="$2"
    mkdir -p "$dir/tests/Architecture"
    {
        printf '<?php\n\ndeclare(strict_types=1);\n\n'
        printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
        printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
        printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
        printf 'final class ArchitectureTest\n{\n'
        printf "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
        printf '%s\n}\n' "$methods"
    } > "$dir/tests/Architecture/ArchitectureTest.php"
}

# Rule-method templates. Quoted heredocs → literal content, so the single backslashes
# in the selector suffixes are written exactly as a real ArchitectureTest carries them.
MODEL_RULE="$(cat <<'RULE'
    #[TestRule]
    public function modelIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Model is a leaf.');
    }
RULE
)"

MODEL_RULE_ON_TRAITS="$(cat <<'RULE'
    #[TestRule]
    public function modelIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Traits'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Model is a leaf.');
    }
RULE
)"

CONFIG_RULE="$(cat <<'RULE'
    #[TestRule]
    public function configurationIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('Configuration is a leaf.');
    }
RULE
)"

ABSTRACT_RULE="$(cat <<'RULE'
    #[TestRule]
    public function abstractClassesAreAbstractPrefixed(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::isAbstract())
            ->should()->beNamed('/Abstract/', true)
            ->because('House rule.');
    }
RULE
)"

BROKEN_RULE="$(cat <<'RULE'
    #[TestRule]
    public function brokenSubject(): Rule
    {
        return PHPat::rule()
            ->classes($this->dynamicSelector())
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Broken.');
    }
RULE
)"

# A rule whose SUBJECT targets an abstract class by name — exercises the
# 'abstract-class' inventory kind as a valid classname target.
ABSTRACT_TARGET_RULE="$(cat <<'RULE'
    #[TestRule]
    public function baseNodeIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\AbstractNode'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('The base node is a leaf.');
    }
RULE
)"

# A rule whose subject is a real but UNHANDLED selector (not isAbstract / inNamespace
# / classname) — exercises the distinct "unhandled subject selector" fail-closed path.
UNKNOWN_SELECTOR_RULE="$(cat <<'RULE'
    #[TestRule]
    public function implementorRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::implement(self::NAMESPACE_ROOT . '\SomeInterface'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Implementor rule.');
    }
RULE
)"

# A rule whose subject argument cannot be resolved (a variable, not NAMESPACE_ROOT or a
# literal) — exercises the "could not resolve the argument" fail-closed path.
UNRESOLVABLE_ARG_RULE="$(cat <<'RULE'
    #[TestRule]
    public function dynamicNamespaceRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace($this->rootNamespace))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Dynamic namespace.');
    }
RULE
)"

# A non-#[TestRule] method — a class with only this exercises "no #[TestRule] methods".
NON_RULE_METHOD="$(cat <<'RULE'
    public function helper(): string
    {
        return 'not a rule';
    }
RULE
)"

# A malformed #[TestRule] (delegating, no ->classes(Selector) in its own body) followed
# by a helper method that DOES have one — the search must stop at the helper's declaration
# and fail closed, not adopt the helper's selector.
MALFORMED_WITH_HELPER="$(cat <<'RULE'
    #[TestRule]
    public function malformedRule(): Rule
    {
        return $this->buildRule();
    }

    public function buildRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Helper.');
    }
RULE
)"

# A subject argument composed with ANOTHER constant (not a plain literal suffix) — the
# checker does not model it, so the anchored resolver must fail closed rather than
# silently resolve to just the root and test the wrong namespace.
COMPOSED_ARG_RULE="$(cat <<'RULE'
    #[TestRule]
    public function composedNamespaceRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . self::MODEL_SUFFIX))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Composed namespace.');
    }
RULE
)"

# An inNamespace subject on a namespace that holds only an ENUM. phpat's rules are
# Rule<InClassNode> and InClassNode fires for enums, so this subject DOES match — the
# guard must treat an enum-only namespace as live, not vacuous.
ENUM_NAMESPACE_RULE="$(cat <<'RULE'
    #[TestRule]
    public function enumIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Enum'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('Enum is a leaf.');
    }
RULE
)"

# A classname subject naming an ENUM directly — the classname branch must accept an
# enum kind as a valid, live target (not only class / abstract-class).
ENUM_CLASSNAME_RULE="$(cat <<'RULE'
    #[TestRule]
    public function sexEnumIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Enum\Sex'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('The Sex enum is a leaf.');
    }
RULE
)"

# A subject wrapped in Selector::AllOf(...): the positive inNamespace lives, the rest
# are Selector::Not(...) exclusions the guard must skip. Liveness follows the positive.
ALLOF_LIVE_RULE="$(cat <<'RULE'
    #[TestRule]
    public function databaseAccessIsConfined(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('DB access is confined.');
    }
RULE
)"

# An AllOf(...) whose POSITIVE inNamespace targets an empty namespace — the Not()
# exclusions must not rescue it; the rule is vacuous and must be rejected.
ALLOF_EMPTY_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfEmptyPositive(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Nope'),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('AllOf with an empty positive.');
    }
RULE
)"

# An AnyOf(...) disjunction where one positive is empty and one lives — the rule is
# live if ANY positive matches, so it must be accepted.
ANYOF_LIVE_RULE="$(cat <<'RULE'
    #[TestRule]
    public function anyOfHasALivePositive(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AnyOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Nope'),
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AnyOf with a live positive.');
    }
RULE
)"

# An AnyOf(...) whose every positive targets an empty namespace — no disjunct can
# match, so the rule is vacuous and must be rejected.
ANYOF_EMPTY_RULE="$(cat <<'RULE'
    #[TestRule]
    public function anyOfAllEmpty(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AnyOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Nope'),
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Nada'),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AnyOf with only empty positives.');
    }
RULE
)"

# A splat helper subject `->classes(...$this->helper())`, where the helper builds the
# selector list with array_map over a class-constant string array. The guard must
# inline the helper and expand the const array so each ROOT\Sub namespace is checked.
SPLAT_LIVE_BLOCK="$(cat <<'RULE'
    private const array SPLAT_SUBS = [
        'Chord',
        'LineChart',
    ];

    private function splatSelectors(): array
    {
        return array_map(
            static fn (string $s): SelectorInterface => Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s),
            self::SPLAT_SUBS,
        );
    }

    #[TestRule]
    public function splatDtosAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(...$this->splatSelectors())
            ->should()->beFinal()
            ->because('Splat DTOs are final.');
    }
RULE
)"

# The same splat helper shape, but every expanded namespace is empty — the whole
# disjunction is vacuous and must be rejected.
SPLAT_EMPTY_BLOCK="$(cat <<'RULE'
    private const array SPLAT_SUBS = [
        'Nope',
        'Nada',
    ];

    private function splatSelectors(): array
    {
        return array_map(
            static fn (string $s): SelectorInterface => Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s),
            self::SPLAT_SUBS,
        );
    }

    #[TestRule]
    public function splatDtosAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(...$this->splatSelectors())
            ->should()->beFinal()
            ->because('Splat DTOs are final.');
    }
RULE
)"

# A splat helper whose body does NOT match the array_map-over-const pattern the guard
# models — it must fail closed, not silently accept.
SPLAT_UNRESOLVABLE_BLOCK="$(cat <<'RULE'
    private function splatSelectors(): array
    {
        return $this->buildFromRuntimeConfig();
    }

    #[TestRule]
    public function splatDynamic(): Rule
    {
        return PHPat::rule()
            ->classes(...$this->splatSelectors())
            ->should()->beFinal()
            ->because('Dynamic splat.');
    }
RULE
)"

# A DIRECT `...array_map(…)` splat (not routed through a helper) with a positive
# inNamespace callback — exercises the direct-array_map dispatch and expansion.
DIRECT_ARRAY_MAP_BLOCK="$(cat <<'RULE'
    private const array DIRECT_SUBS = [
        'Chord',
    ];

    #[TestRule]
    public function directArrayMapSplat(): Rule
    {
        return PHPat::rule()
            ->classes(
                ...array_map(
                    static fn (string $s): SelectorInterface => Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s),
                    self::DIRECT_SUBS,
                ),
            )
            ->should()->beFinal()
            ->because('Direct array_map splat.');
    }
RULE
)"

# An AllOf(...) narrowed by a `...array_map(Selector::Not(…), self::CONST)` EXCLUSION
# splat — the real modelDoesNotDependOnAnyOtherProductionLayer idiom. The exclusion splat
# contributes no positive, so liveness follows the single positive inNamespace.
ALLOF_EXCLUSION_SPLAT_BLOCK="$(cat <<'RULE'
    private const array EXCLUDED_SUBS = [
        'Chord',
    ];

    #[TestRule]
    public function modelExcludesDtoSubnamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    ...array_map(
                        static fn (string $s): SelectorInterface => Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s)),
                        self::EXCLUDED_SUBS,
                    ),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Model excludes DTO sub-namespaces.');
    }
RULE
)"

# A subject that is ONLY an exclusion splat — no positive selector survives, so it must
# fail closed (could not identify a subject).
EXCLUSION_SPLAT_ONLY_BLOCK="$(cat <<'RULE'
    private const array EXCLUDED_SUBS = [
        'Chord',
    ];

    #[TestRule]
    public function onlyExclusions(): Rule
    {
        return PHPat::rule()
            ->classes(
                ...array_map(
                    static fn (string $s): SelectorInterface => Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s)),
                    self::EXCLUDED_SUBS,
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Only exclusions.');
    }
RULE
)"

# A top-level varargs disjunction `->classes(a, b)` (no AllOf/AnyOf wrapper) — phpat ORs
# the arguments, so one live positive makes the subject live.
VARARGS_LIVE_RULE="$(cat <<'RULE'
    #[TestRule]
    public function varargsHasLivePositive(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\Nope'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Varargs with a live positive.');
    }
RULE
)"

# The same top-level varargs disjunction with only empty positives — vacuous, rejected.
VARARGS_EMPTY_RULE="$(cat <<'RULE'
    #[TestRule]
    public function varargsAllEmpty(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ROOT . '\Nope'),
                Selector::inNamespace(self::NAMESPACE_ROOT . '\Nada'),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Varargs with only empty positives.');
    }
RULE
)"

# An AllOf(...) with two resolvable positive namespaces — an empty intersection the guard
# cannot model, so it must fail closed rather than OR them live.
ALLOF_MULTI_POSITIVE_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfTwoPositives(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Enum'),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AllOf with two positives.');
    }
RULE
)"

# An AllOf(...) whose single positive is cancelled by a Selector::Not(...) over the SAME
# namespace — the intersection is empty even though the namespace holds a class, so it
# must be rejected (a Not over a strict sub-namespace would instead narrow-and-survive).
ALLOF_NOT_CANCELS_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfNotCancelsPositive(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AllOf whose Not cancels the positive.');
    }
RULE
)"

# `->classes (…)` with whitespace before the parenthesis — valid PHP, must still be found.
CLASSES_WHITESPACE_RULE="$(cat <<'RULE'
    #[TestRule]
    public function classesWithWhitespace(): Rule
    {
        return PHPat::rule()
            ->classes (Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Whitespace before the classes parenthesis.');
    }
RULE
)"

# A bare `...$var` splat that is neither `$this->helper()` nor `array_map(…)` — the guard
# cannot model it and must fail closed on the splat subject.
BARE_SPLAT_RULE="$(cat <<'RULE'
    #[TestRule]
    public function bareSplatSubject(): Rule
    {
        return PHPat::rule()
            ->classes(...$this->selectors)
            ->should()->beFinal()
            ->because('Bare splat property.');
    }
RULE
)"

# A direct `...array_map(…)` whose callback uses a CONSTANT template (no per-element
# variable) — exercises the constant-template branch of the array_map expander.
CONST_TEMPLATE_LIVE_BLOCK="$(cat <<'RULE'
    private const array CONST_SUBS = [
        'a',
        'b',
    ];

    #[TestRule]
    public function constTemplateArrayMap(): Rule
    {
        return PHPat::rule()
            ->classes(
                ...array_map(
                    static fn (string $s): SelectorInterface => Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    self::CONST_SUBS,
                ),
            )
            ->should()->beFinal()
            ->because('Constant-template array_map.');
    }
RULE
)"

# The same constant-template array_map, but the constant namespace is empty — vacuous.
CONST_TEMPLATE_EMPTY_BLOCK="$(cat <<'RULE'
    private const array CONST_SUBS = [
        'a',
        'b',
    ];

    #[TestRule]
    public function constTemplateArrayMapEmpty(): Rule
    {
        return PHPat::rule()
            ->classes(
                ...array_map(
                    static fn (string $s): SelectorInterface => Selector::inNamespace(self::NAMESPACE_ROOT . '\Nope'),
                    self::CONST_SUBS,
                ),
            )
            ->should()->beFinal()
            ->because('Constant-template array_map over an empty namespace.');
    }
RULE
)"

# AllOf(inNamespace(X), Not(inNamespace(X\Sub))). Whether this is live depends on where
# the classes of X actually sit — the same rule is used for a REJECT fixture (every class
# under X lives in the excluded X\Sub) and an ACCEPT fixture (a class sits outside X\Sub).
ALLOF_NOT_STRICT_SUB_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfExcludesStrictSubNamespace(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model\Sub')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AllOf excluding a strict sub-namespace.');
    }
RULE
)"

# AllOf(inNamespace(X), Not(classname(X))). A classname exclusion removes only the exact
# class X, NOT the namespace — so a class inside X survives and the rule is live. (With an
# untyped exclusion the classname target would be misread as a namespace ancestor and the
# rule wrongly rejected.)
ALLOF_CLASSNAME_EXCLUSION_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfWithClassnameExclusion(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::Not(Selector::classname(self::NAMESPACE_ROOT . '\Model')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AllOf with a classname exclusion.');
    }
RULE
)"

# AllOf narrowed by an exclusion splat whose `Selector::Not (` carries whitespace before
# the parenthesis — it must still be recognised as an exclusion (contributing no positive),
# so liveness follows the single positive. (If missed, the splat is mis-expanded into a
# second positive and the rule fails closed as a multi-positive AllOf.)
ALLOF_WS_NOT_SPLAT_BLOCK="$(cat <<'RULE'
    private const array WS_SUBS = [
        'Chord',
    ];

    #[TestRule]
    public function allOfWhitespaceNotSplat(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    ...array_map(
                        static fn (string $s): SelectorInterface => Selector::Not (Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s)),
                        self::WS_SUBS,
                    ),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AllOf with a whitespace Not splat.');
    }
RULE
)"

# A direct `...array_map(…)` whose SOURCE is a runtime call, not a class-constant — the
# expander cannot resolve the element set and must fail closed.
DIRECT_ARRAY_MAP_UNRESOLVABLE_RULE="$(cat <<'RULE'
    #[TestRule]
    public function directArrayMapRuntimeSource(): Rule
    {
        return PHPat::rule()
            ->classes(
                ...array_map(
                    static fn (string $s): SelectorInterface => Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s),
                    $this->runtimeSubs(),
                ),
            )
            ->should()->beFinal()
            ->because('Direct array_map over a runtime source.');
    }
RULE
)"

# AllOf(inNamespace(X), Not(classname(X\Only))) where X\Only is the only class — a classname
# exclusion removing the sole matching class empties the intersection (exercises the
# exact-match removal arm of the typed classname exclusion).
ALLOF_CLASSNAME_CANCELS_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfClassnameCancelsSoleClass(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::Not(Selector::classname(self::NAMESPACE_ROOT . '\Model\Node')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Classname exclusion cancels the sole class.');
    }
RULE
)"

# AllOf with a direct Not whose target is a runtime expression the checker cannot resolve —
# the exclusion must not be silently dropped (fail-open); it fails closed.
ALLOF_UNRESOLVABLE_NOT_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfUnresolvableNot(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::Not(Selector::inNamespace($this->runtimeNamespace())),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Unresolvable Not target.');
    }
RULE
)"

# AllOf whose direct Not wraps an AllOf (not a plain inNamespace/classname) — an exclusion
# shape the checker cannot model, so it fails closed.
ALLOF_NOT_WRAPS_ALLOF_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfNotWrapsAllOf(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::Not(
                        Selector::AllOf(
                            Selector::inNamespace(self::NAMESPACE_ROOT . '\Model\A'),
                            Selector::inNamespace(self::NAMESPACE_ROOT . '\Model\B'),
                        ),
                    ),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Not wraps AllOf.');
    }
RULE
)"

# A direct `...array_map(…)` whose callback wraps its selector in a composite (AnyOf) — a
# shape the simple first-selector expander would misread, so it fails closed.
DIRECT_ARRAY_MAP_COMPOSITE_BLOCK="$(cat <<'RULE'
    private const array COMPOSITE_SUBS = [
        'Chord',
    ];

    #[TestRule]
    public function directArrayMapComposite(): Rule
    {
        return PHPat::rule()
            ->classes(
                ...array_map(
                    static fn (string $s): SelectorInterface => Selector::AnyOf(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model\\' . $s)),
                    self::COMPOSITE_SUBS,
                ),
            )
            ->should()->beFinal()
            ->because('Direct array_map with a composite callback.');
    }
RULE
)"

# AllOf with a nested AnyOf argument holding a Not — a composite intersection term the
# checker does not reduce; it must fail closed rather than silently ignore the AnyOf and
# accept the surviving lone positive.
ALLOF_ANYOF_ARG_RULE="$(cat <<'RULE'
    #[TestRule]
    public function allOfWithAnyOfArgument(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                    Selector::AnyOf(
                        Selector::Not(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model')),
                    ),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('AllOf with an AnyOf argument.');
    }
RULE
)"

# A NESTED AllOf — a composite intersection term the checker does not reduce; it fails
# closed rather than silently merge or drop the outer/inner exclusion sets.
NESTED_ALLOF_RULE="$(cat <<'RULE'
    #[TestRule]
    public function nestedAllOf(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::AllOf(
                        Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'),
                        Selector::Not(Selector::classname(self::NAMESPACE_ROOT . '\Model\A')),
                    ),
                    Selector::Not(Selector::classname(self::NAMESPACE_ROOT . '\Model\B')),
                ),
            )
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Repository'))
            ->because('Nested AllOf.');
    }
RULE
)"

# --- POSITIVE: every subject matches a real class (isAbstract with no abstract class → conditional) ---
d="$work/good"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE

$ABSTRACT_RULE"
assert_accepts "$d" "all subjects live (isAbstract with no abstract class = conditional)"

# --- REJECT: modelIsALeaf subject repointed at a trait-only namespace (the #155 bug) ---
d="$work/trait-subject"
write_class "$d" "Traits/ModuleTrait.php" "Vendor\\Mod\\Traits" "trait" "ModuleTrait"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE_ON_TRAITS

$CONFIG_RULE"
assert_rejects "$d" "inNamespace subject on a trait-only namespace" "matches no class"

# --- REJECT: inNamespace(Model) with no Model class ---
d="$work/empty-namespace"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_rejects "$d" "inNamespace(Model) with no Model class" "inNamespace(Vendor\\Mod\\Model)"

# --- REJECT: classname(Configuration) with no Configuration class ---
d="$work/missing-classname"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_rejects "$d" "classname(Configuration) with no Configuration class" "classname(Vendor\\Mod\\Configuration)"

# --- REJECT (fail-closed): a #[TestRule] method whose subject cannot be parsed ---
d="$work/unparseable"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$BROKEN_RULE"
assert_rejects "$d" "unparseable subject fails closed" "could not identify a subject selector"

# --- ACCEPT: a classname subject targeting an ABSTRACT class (abstract-class kind) ---
# Discriminates the abstract-class accepting branch: baseNodeIsALeaf targets AbstractNode,
# so the gate must accept an abstract class as a valid classname target.
d="$work/abstract-target"
write_class "$d" "AbstractNode.php" "Vendor\\Mod" "abstract class" "AbstractNode"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ABSTRACT_TARGET_RULE"
assert_accepts "$d" "classname subject targeting an abstract class"

# --- ACCEPT: a subject class written as `final readonly class` (value-object form) ---
d="$work/readonly-subject"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final readonly class" "Configuration"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_accepts "$d" "classname subject on a final readonly class"

# --- REJECT: classname subject targeting an existing TRAIT (wrong kind, not absent) ---
d="$work/classname-on-trait"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "trait" "Configuration"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_rejects "$d" "classname subject on a trait (wrong kind)" "classname(Vendor\\Mod\\Configuration)"

# --- REJECT: an unhandled selector (Selector::implement) fails closed distinctly ---
d="$work/unknown-selector"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$UNKNOWN_SELECTOR_RULE"
assert_rejects "$d" "unhandled selector fails closed" "unhandled subject selector Selector::implement"

# --- REJECT: an unresolvable subject argument fails closed distinctly ---
d="$work/unresolvable-arg"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$UNRESOLVABLE_ARG_RULE"
assert_rejects "$d" "unresolvable subject argument fails closed" "could not resolve"

# --- REJECT: a subject composed with another constant is not silently resolved to root ---
d="$work/composed-arg"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$COMPOSED_ARG_RULE"
assert_rejects "$d" "composed self::NAMESPACE_ROOT . self::CONST fails closed" "could not resolve"

# --- REJECT: an ArchitectureTest with no #[TestRule] method at all ---
d="$work/no-testrule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$NON_RULE_METHOD"
assert_rejects "$d" "no #[TestRule] methods" "no #[TestRule] methods found"

# --- REJECT (exit 2): an ArchitectureTest present but no src/ directory ---
d="$work/no-src"
write_archtest "$d" "$MODEL_RULE"
assert_rejects "$d" "ArchitectureTest but no src/" "no src/ directory"

# --- REJECT: a malformed rule must not adopt a following helper's selector ---
# Model/ HAS a class, so if the search leaked into buildRule() it would wrongly ACCEPT
# the Model selector; bounded to the method, it fails closed instead.
d="$work/malformed-with-helper"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$MALFORMED_WITH_HELPER"
assert_rejects "$d" "malformed rule does not adopt a helper's selector" "could not identify a subject selector"

# --- ACCEPT: a commented-out #[TestRule] example is ignored (not parsed as a rule) ---
# The canonical ArchitectureTest template ships a commented example. A live rule plus a
# commented rule with a VACUOUS subject must be ACCEPTED — the commented one is ignored.
d="$work/commented-rule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
COMMENTED_RULE="$(cat <<'RULE'
    // Example — one #[TestRule] per boundary:
    //
    // #[TestRule]
    // public function exampleRule(): Rule
    // {
    //     return PHPat::rule()
    //         ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\DoesNotExist'))
    //         ->shouldNot()->dependOn()
    //         ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
    //         ->because('Example.');
    // }
RULE
)"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE

$COMMENTED_RULE"
assert_accepts "$d" "commented-out #[TestRule] example is ignored"

# --- REJECT: a commented-out class is not counted in the inventory ---
# Model/ holds only a file whose class is inside a block comment, so inNamespace(Model)
# matches no real class and modelIsALeaf must be reported vacuous.
d="$work/commented-class"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
mkdir -p "$d/src/Model"
cat > "$d/src/Model/Node.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\Mod\Model;

/*
final class Node
{
}
*/
PHP
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_rejects "$d" "commented-out class not counted in the inventory" "inNamespace(Vendor\\Mod\\Model)"

# --- ACCEPT: no ArchitectureTest at all → nothing to check ---
d="$work/no-archtest"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
assert_accepts "$d" "no ArchitectureTest present"

# --- ACCEPT: inNamespace subject on an ENUM-only namespace (phpat fires on enums) ---
d="$work/enum-namespace"
write_class "$d" "Enum/Sex.php" "Vendor\\Mod\\Enum" "enum" "Sex"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ENUM_NAMESPACE_RULE"
assert_accepts "$d" "inNamespace subject on an enum-only namespace"

# --- ACCEPT: classname subject naming an ENUM directly ---
d="$work/enum-classname"
write_class "$d" "Enum/Sex.php" "Vendor\\Mod\\Enum" "enum" "Sex"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ENUM_CLASSNAME_RULE"
assert_accepts "$d" "classname subject naming an enum"

# --- ACCEPT: AllOf(...) with a live positive inNamespace (Not() exclusions skipped) ---
d="$work/allof-live"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_LIVE_RULE"
assert_accepts "$d" "AllOf with a live positive inNamespace"

# --- REJECT: AllOf(...) whose positive inNamespace is empty (Not() must not rescue it) ---
d="$work/allof-empty"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_EMPTY_RULE"
assert_rejects "$d" "AllOf with an empty positive namespace" "matches no class"

# --- ACCEPT: AnyOf(...) with at least one live positive ---
d="$work/anyof-live"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ANYOF_LIVE_RULE"
assert_accepts "$d" "AnyOf with a live positive"

# --- REJECT: AnyOf(...) with only empty positives ---
d="$work/anyof-empty"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ANYOF_EMPTY_RULE"
assert_rejects "$d" "AnyOf with only empty positives" "matches no class"

# --- ACCEPT: splat `...$this->helper()` (array_map over a const string array), one sub lives ---
d="$work/splat-live"
write_class "$d" "Model/Chord/ChordPayload.php" "Vendor\\Mod\\Model\\Chord" "final class" "ChordPayload"
write_archtest "$d" "$SPLAT_LIVE_BLOCK"
assert_accepts "$d" "splat helper subject with a live expanded namespace"

# --- REJECT: splat helper whose every expanded namespace is empty ---
d="$work/splat-empty"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$SPLAT_EMPTY_BLOCK"
assert_rejects "$d" "splat helper with only empty expanded namespaces" "matches no class"

# --- REJECT (fail-closed): splat helper that does not match the modelled array_map shape ---
d="$work/splat-unresolvable"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$SPLAT_UNRESOLVABLE_BLOCK"
assert_rejects "$d" "unresolvable splat helper fails closed" "splat helper"

# --- ACCEPT: a direct `...array_map(...)` splat (not via a helper) with a positive callback ---
d="$work/direct-array-map"
write_class "$d" "Model/Chord/ChordPayload.php" "Vendor\\Mod\\Model\\Chord" "final class" "ChordPayload"
write_archtest "$d" "$DIRECT_ARRAY_MAP_BLOCK"
assert_accepts "$d" "direct array_map splat with a live positive"

# --- ACCEPT: AllOf narrowed by a `...array_map(Selector::Not(…), …)` exclusion splat ---
d="$work/allof-exclusion-splat"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_EXCLUSION_SPLAT_BLOCK"
assert_accepts "$d" "AllOf with an exclusion-splat and a live positive"

# --- REJECT: a subject that is only an exclusion splat has no positive (fail-closed) ---
d="$work/exclusion-splat-only"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$EXCLUSION_SPLAT_ONLY_BLOCK"
assert_rejects "$d" "exclusion-splat-only subject fails closed" "could not identify a subject selector"

# --- ACCEPT: top-level varargs disjunction with a live positive ---
d="$work/varargs-live"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$VARARGS_LIVE_RULE"
assert_accepts "$d" "top-level varargs disjunction with a live positive"

# --- REJECT: top-level varargs disjunction with only empty positives ---
d="$work/varargs-empty"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$VARARGS_EMPTY_RULE"
assert_rejects "$d" "top-level varargs with only empty positives" "matches no class"

# --- REJECT (fail-closed): AllOf with two resolvable positives (empty intersection) ---
d="$work/allof-multi-positive"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Enum/Sex.php" "Vendor\\Mod\\Enum" "enum" "Sex"
write_archtest "$d" "$ALLOF_MULTI_POSITIVE_RULE"
assert_rejects "$d" "AllOf with two positives fails closed" "cannot model an AllOf intersection"

# --- REJECT: AllOf positive cancelled by a Not(...) over the same namespace (vacuous) ---
# Model HAS a class, so without the Not-cancellation the guard would wrongly accept it.
d="$work/allof-not-cancels"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_NOT_CANCELS_RULE"
assert_rejects "$d" "AllOf whose Not cancels the positive is vacuous" "matches no class outside"

# --- ACCEPT: `->classes (…)` with whitespace before the parenthesis ---
d="$work/classes-whitespace"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$CLASSES_WHITESPACE_RULE"
assert_accepts "$d" "whitespace before the classes parenthesis"

# --- REJECT (fail-closed): a bare `...$var` splat the guard cannot model ---
d="$work/bare-splat"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$BARE_SPLAT_RULE"
assert_rejects "$d" "bare splat property fails closed" "could not resolve the splat subject"

# --- ACCEPT: direct array_map with a CONSTANT-template callback (no per-element var) ---
d="$work/const-template-live"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$CONST_TEMPLATE_LIVE_BLOCK"
assert_accepts "$d" "constant-template array_map with a live namespace"

# --- REJECT: constant-template array_map over an empty namespace (vacuous) ---
d="$work/const-template-empty"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$CONST_TEMPLATE_EMPTY_BLOCK"
assert_rejects "$d" "constant-template array_map over an empty namespace" "matches no class"

# --- REJECT: AllOf whose Not(sub) excludes EVERY class of the positive (inventory-aware) ---
# Every class under Model lives in the excluded Model\Sub, so the intersection is empty even
# though inNamespace(Model) alone would match. Without inventory-aware liveness this would
# wrongly ACCEPT.
d="$work/allof-excludes-all"
write_class "$d" "Model/Sub/Foo.php" "Vendor\\Mod\\Model\\Sub" "final class" "Foo"
write_archtest "$d" "$ALLOF_NOT_STRICT_SUB_RULE"
assert_rejects "$d" "AllOf Not(sub) excluding every class is vacuous" "matches no class outside"

# --- ACCEPT: the same rule when a class sits OUTSIDE the excluded sub-namespace ---
d="$work/allof-strict-sub-live"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Model/Sub/Foo.php" "Vendor\\Mod\\Model\\Sub" "final class" "Foo"
write_archtest "$d" "$ALLOF_NOT_STRICT_SUB_RULE"
assert_accepts "$d" "AllOf excluding a strict sub-namespace stays live via an outside class"

# --- ACCEPT: a classname exclusion does not remove the whole namespace (typed exclusions) ---
d="$work/allof-classname-exclusion"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_CLASSNAME_EXCLUSION_RULE"
assert_accepts "$d" "AllOf classname exclusion does not remove the namespace"

# --- ACCEPT: an exclusion splat with whitespace before Not's paren is still an exclusion ---
d="$work/allof-ws-not-splat"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_WS_NOT_SPLAT_BLOCK"
assert_accepts "$d" "whitespace before Not's paren in an exclusion splat"

# --- REJECT (fail-closed): direct array_map over a non-constant runtime source ---
d="$work/direct-array-map-runtime"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$DIRECT_ARRAY_MAP_UNRESOLVABLE_RULE"
assert_rejects "$d" "direct array_map over a runtime source fails closed" "could not resolve the splat subject"

# --- REJECT: a classname exclusion removing the sole matching class is vacuous ---
d="$work/allof-classname-cancels"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_CLASSNAME_CANCELS_RULE"
assert_rejects "$d" "AllOf classname exclusion removing its sole class is vacuous" "matches no class outside"

# --- REJECT (fail-closed): a direct Not whose target cannot be resolved (no fail-open) ---
# Model has a class, so a dropped exclusion would wrongly ACCEPT.
d="$work/allof-unresolvable-not"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_UNRESOLVABLE_NOT_RULE"
assert_rejects "$d" "unresolvable direct Not fails closed" "cannot model a Not"

# --- REJECT (fail-closed): a direct Not wrapping an AllOf (unmodellable exclusion shape) ---
d="$work/allof-not-wraps-allof"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_NOT_WRAPS_ALLOF_RULE"
assert_rejects "$d" "Not wrapping an AllOf fails closed" "cannot model a Not"

# --- REJECT (fail-closed): a direct array_map whose callback wraps a composite ---
d="$work/direct-array-map-composite"
write_class "$d" "Model/Chord/ChordPayload.php" "Vendor\\Mod\\Model\\Chord" "final class" "ChordPayload"
write_archtest "$d" "$DIRECT_ARRAY_MAP_COMPOSITE_BLOCK"
assert_rejects "$d" "direct array_map with a composite callback fails closed" "could not resolve the splat subject"

# --- REJECT (fail-closed): an AllOf with a nested AnyOf argument (unreducible term) ---
# Model has a class, so silently ignoring the AnyOf(Not(Model)) would wrongly ACCEPT.
d="$work/allof-anyof-arg"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$ALLOF_ANYOF_ARG_RULE"
assert_rejects "$d" "AllOf with a nested AnyOf argument fails closed" "cannot model an AllOf argument"

# --- REJECT (fail-closed): a nested AllOf argument (unreducible term) ---
d="$work/nested-allof"
write_class "$d" "Model/A.php" "Vendor\\Mod\\Model" "final class" "A"
write_class "$d" "Model/B.php" "Vendor\\Mod\\Model" "final class" "B"
write_archtest "$d" "$NESTED_ALLOF_RULE"
assert_rejects "$d" "nested AllOf fails closed" "cannot model an AllOf argument"

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails"
    exit 1
fi

printf '\nAll cases passed.\n'
