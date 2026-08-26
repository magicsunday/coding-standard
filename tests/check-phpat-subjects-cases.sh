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

# CDPATH= because the target `tests/..` starts with neither /, ./ nor ../ and is
# therefore searched in CDPATH — which both redirects it and echoes the resolved
# path, making ROOT a two-line value that opens nothing.
ROOT="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. "$ROOT/tests/harness.sh"
harness_workdir

GATE="$ROOT/bin/check-phpat-subjects.php"

assert_accepts() { harness_accepts "$GATE" "$@"; }
assert_rejects() { harness_rejects "$GATE" "$@"; }

# Thin wrappers over the shared definitions in tests/harness.sh.
assert_usage_error()     { harness_usage_error     "$GATE" "$@"; }
assert_report_is_inert() { harness_report_is_inert "$GATE" "$@"; }

# Every helper above is a one-line wrapper whose increment lives in harness.sh and
# is probed there. What this file must still prove is that it grew no report site
# of its own — see harness_assert_no_stray_increments.
harness_assert_no_stray_increments 0

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

# Derives a test*-named (no-attribute) rule body from an existing #[TestRule]-attributed
# one, so the two discovery-path fixtures that must prove IDENTICAL subject-selector
# behaviour share one body instead of two independently-maintained copies that could
# drift. <rule-body> <old-method-name> <new-test-method-name>
as_test_named_rule() {
    sed -e '/#\[TestRule\]/d' -e "s/public function $2(/public function $3(/" <<<"$1"
}

# Derives a modifier-variant rule body from an existing one, keeping the method name —
# the narrower sibling of as_test_named_rule() above for the T_STATIC/T_FINAL/T_ABSTRACT
# order-sensitivity fixtures, which need neither the attribute stripped nor the name
# renamed. <rule-body> <replacement-modifiers>
as_modifier_variant() {
    sed "s/public function/$2 function/" <<<"$1"
}

# Builds a #[<attribute>]-attributed rule method whose subject is the fixed, vacuous
# inNamespace(...NoSuchNamespace)/classname(...Configuration) chain the whole GH-58
# alias-tracking fixture cluster shares — only the attribute token, method name,
# return type and rejection message actually vary per fixture (aliasing shape, casing,
# import form). <attribute-token> <method-name> <return-type> <because-message>
as_vacuous_alias_rule() {
    cat <<RULE
    #[$1]
    public function $2(): $3
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('$4');
    }
RULE
}

# The ACCEPT-side mirror of as_vacuous_alias_rule() above: a #[X]-attributed method
# whose subject is the SAME fixed chain, for the fixtures proving X must NOT be
# recognised as TestRule at all (a function/const/trait-adaptation import, never a
# class alias) — the subject is never reached if the fix holds, so its content only
# needs to look plausible. <method-name> <because-message>
as_not_a_real_rule() {
    as_vacuous_alias_rule 'X' "$1" 'Rule' "$2"
}

# write_archtest <dir> <rule-methods-block> [preamble] [test-rule-import-line]
#
# <preamble>, when given, is written between the `use` imports and the
# `final class ArchitectureTest` declaration — for a top-level declaration
# (e.g. a trait) that a fixture needs in the SAME file this gate tokenises,
# and that the <rule-methods-block> argument (already inside the class body)
# cannot express.
#
# <test-rule-import-line>, when given, REPLACES the plain `use
# PHPat\Test\Attributes\TestRule;` line verbatim — for a fixture proving the gate
# follows an import shape other than one-name-per-`use`-statement the same as the
# literal `#[TestRule]`: a single alias (`use ...\TestRule as X;`), a comma-separated
# multi-import (`use ...\Rule as Y, ...\TestRule as X;`), or a brace-grouped import
# (`use ...\Attributes\{TestRule as X};`) — all of which real consumer code is free to
# write on an ordinary name collision with another `TestRule`-named symbol.
write_archtest() {
    local dir="$1" methods="$2" preamble="${3:-}" testRuleImportLine="${4:-}"
    mkdir -p "$dir/tests/Architecture"
    {
        printf '<?php\n\ndeclare(strict_types=1);\n\n'
        printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
        printf 'use PHPat\\Selector\\Selector;\n'
        if [ -n "$testRuleImportLine" ]; then
            printf '%s\n' "$testRuleImportLine"
        else
            printf 'use PHPat\\Test\\Attributes\\TestRule;\n'
        fi
        printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
        if [ -n "$preamble" ]; then
            printf '%s\n\n' "$preamble"
        fi
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

# phpat's OTHER discovery path (GH-58): PHPat\Test\TestParser also accepts a public
# method named `test*`, no attribute required. This carries no #[TestRule] on purpose —
# a repository writing its rules this way must get the same vacuous-subject rejection
# as the attribute style, not "no rule methods found". Derived from the existing
# attribute-style bodies (same selectors, same vacuous/live split) rather than
# duplicated, so the two discovery-path fixtures cannot silently drift apart.
TEST_NAMED_RULE_ON_TRAITS="$(as_test_named_rule "$MODEL_RULE_ON_TRAITS" modelIsALeaf testModelIsALeaf)"

# The accepting twin: a test*-named rule (still no attribute) whose subject is live.
TEST_NAMED_RULE_LIVE="$(as_test_named_rule "$CONFIG_RULE" configurationIsALeaf testConfigurationIsALeaf)"

# A test*-named method phpat itself would never run: TestParser only reflects
# `ReflectionMethod::IS_PUBLIC` methods, so this PRIVATE one is not a rule under
# EITHER discovery path. Its body carries no ->classes(Selector::…) at all, so if this
# gate wrongly picked it up it would fail closed with "could not identify a subject
# selector" — the fixture that uses this asserts ACCEPT, which only holds if the
# helper is correctly ignored.
TEST_NAMED_PRIVATE_HELPER="$(cat <<'RULE'
    private function testHelperNotARule(): string
    {
        return 'not a rule';
    }
RULE
)"

# The mirror case on the ATTRIBUTE path: `getMethods(IS_PUBLIC)` gates BOTH of phpat's
# discovery paths, not just the name-based one, so a #[TestRule] method that is not
# public is equally invisible to phpat. Its subject is deliberately vacuous
# (inNamespace(Traits), no Traits class in this fixture) so that if the visibility
# guard were dropped, the fixture using this would flip from accept to reject.
# Derived from MODEL_RULE_ON_TRAITS (same selector shape) rather than hand-typed, so
# the two cannot silently drift apart — same rationale as as_test_named_rule() above.
PROTECTED_TESTRULE_IGNORED="$(sed -e 's/public function modelIsALeaf/protected function protectedRuleIsIgnored/' \
    -e "s/Model is a leaf\./Should never run./" <<<"$MODEL_RULE_ON_TRAITS")"

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

# --- REJECT: three attribute and return-type spellings the text scan could not see ---
# Each of these made the gate exit 0 on a rule it had never looked at. The subjects are
# deliberately vacuous, so an accept means the rule was skipped rather than analysed.
#
# `qualifiedReturn` is GH-50's case: the old head pattern spelled the return type as the
# bare `Rule`. The token walk reads the attribute, not the signature, so the rule is now
# ANALYSED — the report names its vacuous subject rather than a parse failure.
d="$work/unrecognised-rule-head"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$MODEL_RULE

    #[TestRule]
    public function qualifiedReturn(): \\PHPat\\Test\\Builder\\Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\DoesNotExist'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Qualified return type.');
    }"
# The full sentence, not just its head: this is the one positive assertion in the
# file for the classname arm's wording, and the src-unreadable must-not-carry
# elsewhere in this suite greps for "matches no class" assuming both liveness arms
# use it — true today only because this line pins it for the classname half too.
assert_rejects "$d" "a #[TestRule] with a qualified return type is analysed, not skipped" \
    "qualifiedReturn: subject classname(Vendor\Mod\DoesNotExist) matches no class"

d="$work/attribute-with-parens"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$MODEL_RULE

    #[TestRule()]
    public function parenthesised(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\DoesNotExist'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Attribute written with parentheses.');
    }"
assert_rejects "$d" "a #[TestRule()] written with parentheses is analysed" \
    "parenthesised: subject classname"

# The spelling a consumer without the `use` writes.
d="$work/attribute-fully-qualified"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$MODEL_RULE

    #[\\PHPat\\Test\\Attributes\\TestRule]
    public function fullyQualified(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\DoesNotExist'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Fully qualified attribute.');
    }"
assert_rejects "$d" "a fully qualified #[TestRule] attribute is analysed" \
    "fullyQualified: subject classname"

# --- REJECT: a #[TestRule] that attaches to no method ---
# Written on a property, it reads a subject from nothing. The walk must not carry it
# forward onto the next method either — that would make an ordinary helper a rule and
# hide the misplaced attribute. Both directions are in this one case: the count reports
# the orphan, and modelIsALeaf keeps working beside it.
d="$work/attribute-on-a-property"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "    #[TestRule]
    private string \$notAMethod = 'x';

    public function helper(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because('Not a rule.');
    }"
assert_rejects "$d" "a #[TestRule] on a property is reported, not carried onto the next method" \
    "attribute(s) found but only 0 resolved"

# The accepting twin: an ordinary attribute beside the rules must not be counted.
# Totalling every attribute rather than the TestRule ones reds this.
d="$work/other-attribute-present"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[\\PHPUnit\\Framework\\Attributes\\CoversNothing]
    public function notARule(): void
    {
    }

$MODEL_RULE

$CONFIG_RULE"
assert_accepts "$d" "an ordinary attribute beside the rules is not counted as one"

# --- The brace counter: only DELIMITER tokens bound a body ---
# `"$x{"` lexes the brace as T_ENCAPSED_AND_WHITESPACE whose text is exactly `{`.
# Counting that made a vacuous rule's body run past its own method into the helper
# below, whose subject is live — one added character turned a fail-closed reject into
# `OK`. This is the repo's own malformed-with-helper shape plus that character.
d="$work/brace-in-interpolated-string"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "    #[TestRule]
    public function vacuous(): Rule
    {
        \$x    = 'note';
        \$note = \"prefix \$x{\";

        return \$this->build(\$note);
    }

    public function build(string \$note): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Model\\Node'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT))
            ->because(\$note);
    }"
assert_rejects "$d" "an interpolated brace does not extend a body into the next method" \
    "could not identify a subject selector"

# The mirror direction, which is a false RED on correct code: the stray `}` cut the
# body short and a live rule was reported unparseable.
d="$work/closing-brace-in-string"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[TestRule]
    public function live(): Rule
    {
        \$what = 'x';
        \$note = \"a \$what}\";

        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because(\$note);
    }"
assert_accepts "$d" "a closing brace inside a string does not cut a body short"

# The two interpolation openers, whose CLOSING brace is an ordinary CHAR token. Count
# only CHAR tokens and that `}` decrements against nothing, cutting the body.
d="$work/curly-interpolation"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[TestRule]
    public function live(): Rule
    {
        \$what = 'x';
        \$note = \"a {\$what} and \${what}\";

        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because(\$note);
    }"
assert_accepts "$d" "both interpolation openers keep the brace depth balanced"

# --- The attribute scan must not re-walk its own group ---
# A `::class` argument in a FOLLOWING attribute lexes as T_CLASS. Re-walking the group
# let it hit the declaration barrier and clear the flag `#[TestRule]` had just set, so
# a live rule was reported as absent.
d="$work/attribute-with-class-argument"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[TestRule]
    #[\\PHPUnit\\Framework\\Attributes\\CoversClass(\\Vendor\\Mod\\Configuration::class)]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because('Live.');
    }"
assert_accepts "$d" "a ::class argument in a neighbouring attribute does not hide the rule"

# The same walk, one comma further. Bracket depth alone does not tell an attribute
# SEPARATOR from an ARGUMENT separator — both sit at depth 1 — so a comma inside an
# argument list re-armed name position and the second argument was read as an
# attribute name. Measured before the paren counter: this file produced
# `notARule: could not identify a subject selector` and exit 1 for a helper that
# carries no rule at all.
d="$work/attribute-with-two-arguments"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[\\PHPUnit\\Framework\\Attributes\\UsesClass(\\Vendor\\Mod\\Model\\Node::class, \\Vendor\\Mod\\TestRule::class)]
    public function notARule(): void
    {
    }

    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because('Live.');
    }"
assert_accepts "$d" "a TestRule name as a SECOND attribute argument is not counted as a rule"

# The grouped spelling, which is the reason the comma arm exists at all: two
# attributes in one `#[…]`. It has to keep working now that the comma is conditional,
# and nothing drove it before — deleting the arm left the suite green.
d="$work/attribute-group-with-testrule"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[\\PHPUnit\\Framework\\Attributes\\CoversNothing, TestRule]
    public function grouped(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because('Grouped.');
    }"
assert_rejects "$d" "a #[TestRule] grouped behind another attribute is analysed" \
    "inNamespace(Vendor\\Mod\\Model)"

# An ARRAY argument closes the group early unless `[` raises the depth. Deleting the
# `[` arm left the suite green, and combined with the grouped spelling above it is a
# fail-open: the walk resumes past the `TestRule` name and the rule is never seen.
d="$work/attribute-with-array-argument"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[\\PHPUnit\\Framework\\Attributes\\TestWith([1, 2]), TestRule]
    public function afterAnArray(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because('After an array.');
    }"
assert_rejects "$d" "an array argument does not close the attribute group early" \
    "inNamespace(Vendor\\Mod\\Model)"

# A body-less declaration ends on `;`. Without that arm the body scan runs past the
# declaration into the NEXT method and adopts its selector — the same fail-open shape
# as the malformed-with-helper case, one declaration form over. Deleting the arm left
# the suite green.
d="$work/attribute-on-a-bodyless-method"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "    #[TestRule]
    abstract public function declaredOnly(): Rule;

    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Model'))
            ->because('Live.');
    }"
assert_rejects "$d" "a body-less rule declaration does not adopt the next method's selector" \
    "could not identify a subject selector"

# The same distinction the other way: a TestRule name used as a VALUE is not a rule,
# and counting it produced a false red with a diagnostic pointing at the wrong file.
d="$work/testrule-as-an-argument"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[\\PHPUnit\\Framework\\Attributes\\UsesClass(TestRule::class)]
    public function notARule(): void
    {
    }

    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because('Live.');
    }"
assert_accepts "$d" "a TestRule name used as an attribute argument is not counted as a rule"

# --- REJECT: a rule that only LOOKS like one, inside a heredoc ---
# The text scan counted it, so an ArchitectureTest with zero real rules reported OK.
# Tokens see one string, so the emptiness guard fires instead.
d="$work/heredoc-rule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "    public function notARule(): string
    {
        return <<<'CODE'
    #[TestRule]
    public function looksReal(): Rule
    {
        return PHPat::rule()->classes(Selector::inNamespace('Vendor\\Mod'));
    }
CODE;
    }"
assert_rejects "$d" "a rule inside a heredoc is not counted as one" \
    "no #[TestRule] or test*-named public rule methods found"
# --- REJECT: a class named only inside a heredoc is not in the inventory ---
# The inventory used to be a line-anchored regex over comment-stripped text, which
# cannot tell a declaration from a string that looks like one. A `class Node` inside
# a heredoc registered a class that does not exist, so a vacuous subject naming it was
# certified live. Tokens answer this by construction; without them the case ACCEPTS.
d="$work/heredoc-class"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
mkdir -p "$d/src/Model"
cat > "$d/src/Model/template.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\Mod\Model;

return <<<'CODE'
final class Node
{
}
CODE;
PHP
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_rejects "$d" "a class named inside a heredoc is not inventoried" "matches no class"

# --- ACCEPT: two declarations in one file are both inventoried ---
# The inventory took the FIRST match per file, so a second class was invisible and any
# subject naming it was reported vacuous. Both subjects here live in one file.
d="$work/two-classes-one-file"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
cat > "$d/src/Pair.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Vendor\Mod;

final class Other
{
}

final class Configuration
{
}
PHP
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_accepts "$d" "a second declaration in the same file is inventoried"

# --- REJECT: a src/ file past the size cap says so ---
# The arm existed and could not report: it appends to $violations inside the inventory
# loop, and a later `$violations = []` discarded every one of those entries. Measured
# before the fix — this fixture printed `OK` and exited 0, with the oversized file
# silently absent from the inventory. Revert the declaration's position and it does
# again.
d="$work/src-past-the-size-cap"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
mkdir -p "$d/src/Model"
php -r '
    file_put_contents($argv[1], "<?php\n\ndeclare(strict_types=1);\n\nnamespace Vendor\\Mod\\Model;\n\n// " . str_repeat("x", 262145) . "\nfinal class Node\n{\n}\n");
' "$d/src/Model/Node.php"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
assert_rejects "$d" "a src/ file past the size cap is reported, not silently skipped" \
    "is larger than the 262144 bytes this gate reads"

# The companion in the OTHER direction: the file above put the oversized file behind
# the inNamespace() arm (Node.php, under Model), leaving classname()'s guard exercised
# only by the chmod-based src-unreadable case below — which is skipped under root.
# Root is not a corner case here: it is this image's default user. Same shape, the
# oversized file IS the classname() target this time.
# NAMED d2, not reassigned into $d: the block below this one is a PRE-EXISTING
# must-not-carry written against the FIRST fixture (the inNamespace-arm one, still
# named $d). Round 17 found that inserting a bare `d=` reassignment here silently
# repointed that pre-existing block at this new fixture instead — the exact class of
# gap this pair exists to close, reintroduced for the sibling arm by the fix itself.
d2="$work/src-past-the-size-cap-classname-target"
write_class "$d2" "Model/Node.php" "Vendor\Mod\Model" "final class" "Node"
mkdir -p "$d2/src"
php -r '
    file_put_contents($argv[1], "<?php

declare(strict_types=1);

namespace Vendor\Mod;

// " . str_repeat("x", 262145) . "
final class Configuration
{
}
");
' "$d2/src/Configuration.php"
write_archtest "$d2" "$MODEL_RULE

$CONFIG_RULE"
assert_rejects "$d2" "an oversized classname() target is reported, not silently skipped"     "is larger than the 262144 bytes this gate reads"
out="$(php "$GATE" "$d2" 2>&1)" || true
if grep -qF -- 'matches no class' <<<"$out"; then
    harness_fail "an oversized classname() target made the gate fabricate a vacuous-rule verdict"
fi

# The must-not-carry half, against the FIRST fixture ($d, the inNamespace-arm one
# declared above) — $inventoryIncomplete has two sites, this one and the unreadable
# arm elsewhere, and only the unreadable one had this check before. Measured:
# dropping this site's flag alone left the suite green while the gate went on to
# print "matches no class" for a class this very file defines, one screen up.
out="$(php "$GATE" "$d" 2>&1)" || true
if grep -qF -- 'matches no class' <<<"$out"; then
    harness_fail "an oversized src/ file made the gate fabricate a vacuous-rule verdict"
fi

# The safeReportValue() half. Oversize, not unreadable — the one a pull request can
# actually produce: git carries no mode bits, so a chmod 000 fixture cannot ship in a
# PR, but a >256 KB file is one \`git add\`. Measured: removing safeReportValue() from
# just this report site survived the suite before this case existed.
d="$work/src-oversize-poisoned-name"
write_class "$d" "Configuration.php" "Vendor\Mod" "final class" "Configuration"
mkdir -p "$d/src"
poisoned="$d/src/$(printf 'a\n::error title=x::forged.php')"
php -r 'file_put_contents($argv[1], "<?php\n// " . str_repeat("x", 262145));' "$poisoned"
write_archtest "$d" "$CONFIG_RULE"
assert_report_is_inert "$d" 'an oversized src/ filename carrying control characters' \
    'a?::error'

# --- REJECT (exit 2): an ArchitectureTest past the size cap ---
# The gate's only exit-1 path that carries no violation list, and nothing drove it.
d="$work/archtest-past-the-size-cap"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
php -r '
    file_put_contents($argv[1], "<?php\n\n// " . str_repeat("x", 262145) . "\n");
' "$d/tests/Architecture/ArchitectureTest.php"
assert_usage_error "$d" "an ArchitectureTest past the size cap is reported as oversized" \
    "is larger than the 262144 bytes this gate reads"

# --- The unreadable half of both read arms ---
# There was no chmod fixture in this file at all, so only the oversize half of each
# arm was driven. That is what let two defects ship: the raw E_WARNING and the
# fabricated liveness verdict below. Skipped under root, where mode 000 denies
# nothing and every case here would read as a false regression; CI runs non-root.
if [ "$(id -u)" -eq 0 ]; then
    printf 'skip (running as root: mode 000 does not deny read): the unreadable-source cases\n'
else
    # An unreadable src/ file leaves the inventory SHORT, and the liveness arms can
    # then only answer "not found". Before the guard this fixture reported the read
    # failure AND `modelIsALeaf: subject inNamespace(Vendor\Mod\Model) matches no
    # class — a vacuous rule` for a class that plainly exists. The must-not-carry is
    # the point of the case; the must-carry only proves it reported at all.
    d="$work/src-unreadable"
    write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
    write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
    write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE"
    # BOTH targets, so both liveness arms are driven: inNamespace(Model) and
    # classname(Configuration) each lose their class to the same read failure.
    chmod 000 "$d/src/Model/Node.php" "$d/src/Configuration.php"
    assert_rejects "$d" "an unreadable src/ file reports as unreadable, not as a vacuous rule" \
        "cannot be read, so its classes are not in the inventory"

    # $GATE is a PHP FILE, so it needs the interpreter — every harness helper spells
    # it `php "$gate"`. Executing it directly fails silently, $out stays empty and the
    # grep below then asserts nothing; measured, that is exactly what this line did
    # before the prefix was added.
    out="$(php "$GATE" "$d" 2>&1)" || true
    if grep -qF -- 'matches no class' <<<"$out"; then
        harness_fail "an unreadable src/ file made the gate fabricate a vacuous-rule verdict"
    fi

    # A filename is the widest byte domain either shipped gate reports, and a pull
    # request chooses it. Measured: with safeReportValue() removed from this one site
    # the suite stayed green, because nothing drove it.
    #
    # The directory name is deliberately two characters: safeReportValue() truncates
    # at 64 bytes from the FRONT, and a path carries its identifying part at the end,
    # so a long work dir would eat the poison and leave the must-carry asserting the
    # tmp prefix. The must-carry is correspondingly short — `a?::error` is the whole
    # proof, since the `?` is the newline this site has to have translated.
    d="$work/pn"
    write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
    write_archtest "$d" "$CONFIG_RULE"
    poisoned="$d/src/$(printf 'a\n::error title=x::forged ##[error]legacy \033[2K\rcr.php')"
    printf '<?php\n' > "$poisoned"
    chmod 000 "$poisoned"
    assert_report_is_inert "$d" 'a src/ filename carrying control characters cannot forge a command' \
        'a?::error'

    # The ArchitectureTest itself, the exit-2 half. Distinct from the oversize case
    # above, and it must say which of the two happened.
    d="$work/archtest-unreadable"
    write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
    mkdir -p "$d/tests/Architecture"
    printf '<?php\n' > "$d/tests/Architecture/ArchitectureTest.php"
    chmod 000 "$d/tests/Architecture/ArchitectureTest.php"
    assert_usage_error "$d" "an unreadable ArchitectureTest reports as unreadable, not as oversized" \
        "cannot be read"
fi

# --- REJECT: an ArchitectureTest with no #[TestRule] method at all ---
d="$work/no-testrule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$NON_RULE_METHOD"
assert_rejects "$d" "no #[TestRule] or test*-named methods" "no #[TestRule] or test*-named public rule methods found"

# --- REJECT (exit 2): an ArchitectureTest present but no src/ directory ---
d="$work/no-src"
write_archtest "$d" "$MODEL_RULE"
assert_usage_error "$d" "ArchitectureTest but no src/" "no src/ directory"

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

# A PHP identifier may legally carry bytes above 0x7F. With `\w+` and no `/u` the
# head pattern stopped at the first such byte, so a #[TestRule] method named this
# way was skipped in silence — in a gate whose docblock says it fails CLOSED. All
# three widened patterns could be reverted and the suite stayed green until these
# two cases existed.
d="$work/rule-name-non-ascii"
write_class "$d" 'Model/Node.php' 'Vendor\Mod\Model' 'final class' Node
write_archtest "$d" "$(printf '    #[TestRule]\n    public function pr\xc3\xbcfeSchichten(): Rule\n    {\n        return PHPat::rule()\n            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))\n            ->shouldNot()->dependOn()\n            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))\n            ->because(%s);\n    }\n' "'\\Nope'" "'\\Model\\Node'" "'Injected.'")"
assert_rejects "$d" "a #[TestRule] method named with a non-ASCII identifier is analysed, not skipped" "matches no class"

# The bounding twin. The subject search stops at the NEXT `function` declaration;
# with `\w+` a helper whose name carries such a byte is not a bound, so the search
# leaks into it and adopts its selector — the case flips from reject to accept.
d="$work/helper-name-non-ascii"
write_class "$d" 'Model/Node.php' 'Vendor\Mod\Model' 'final class' Node
write_archtest "$d" "$(printf '    #[TestRule]\n    public function malformed(): Rule\n    {\n        return PHPat::rule()\n    }\n\n    private function h\xc3\xa4lfer(): Rule\n    {\n        return PHPat::rule()\n            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))\n            ->shouldNot()->dependOn()\n            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))\n            ->because(%s);\n    }\n' "'\\Model\\Node'" "'\\Model\\Node'" "'Helper.'")"
assert_rejects "$d" "a malformed rule does not adopt a non-ASCII-named helper's selector" "could not identify a subject selector"


# The report-shape control for THIS binary. Its subject expression is read out of
# the consumer's ArchitectureTest and interpolated into the report, on the same
# trust boundary as the sibling gate — and here the source is a PHP file rather
# than XML, so ESC is expressible and the ANSI half of the threat model applies
# too. Asserts the properties GitHub Actions and a terminal key on, not the
# absence of the payload text: once the bytes cannot start a line, the text is
# inert, and demanding its absence would also pass on a gate that stopped
# reporting the subject at all.
d="$work/report-injection"
write_class "$d" 'Model/Person.php' 'Vendor\Mod\Model' class Person
mkdir -p "$d/tests/Architecture"

# Built with printf rather than a second interpreter: the buildbox ships PHP, not
# python3. A raw ESC and raw newlines inside a PHP single-quoted string are legal,
# and the gate's own `[^)]*` / `[^\']+` captures do not exclude either.
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf '    #[TestRule]\n    public function injected(): Rule\n    {\n'
    printf '        return PHPat::rule()\n'
    printf "            ->classes(Selector::classname('Vendor\\Mod\\Nope\033[2K\n"
    printf '::error title=Architecture::no vacuous rules found\n'
    printf '::add-mask::secret\n'
    printf "check-phpat-subjects: OK'))\n"
    printf '            ->shouldNot()->dependOn()\n'
    printf "            ->classes(Selector::classname('Vendor\\Mod\\Model\\Person'))\n"
    printf "            ->because('Injected subject.');\n"
    printf '    }\n}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"

assert_report_is_inert "$d" 'a classname subject carrying control characters' \
    'Nope?[2K?::error title=Architecture'

# Length is the ONLY property the wrap adds at these sites — both captures admit
# identifier bytes only, so the C0 scrub and the prefix break are no-ops on them.
# Without a case that exceeds the cap, removing every safeReportValue() around
# $ruleName and $selector leaves the whole suite green, which is how the wrap's own
# stated motivation went unpinned. The sibling gate's overlong-key case is the shape.
d="$work/report-length-rule-name"
write_class "$d" 'Model/Person.php' 'Vendor\Mod\Model' class Person
mkdir -p "$d/tests/Architecture"
long_name="$(printf 'z%.0s' $(seq 1 400))"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf '    #[TestRule]\n    public function %s(): Rule\n    {\n' "$long_name"
    printf '        return PHPat::rule()\n'
    printf "            ->classes(Selector::inNamespace('Vendor\\Mod\\Nope'))\n"
    printf '            ->shouldNot()->dependOn()\n'
    printf "            ->classes(Selector::classname('Vendor\\Mod\\Model\\Person'))\n"
    printf "            ->because('Long rule name.');\n"
    printf '    }\n}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"

assert_rejects "$d" "an overlong rule name is truncated with a marker" \
    "$(printf 'z%.0s' $(seq 1 64))…"

# The legacy `##[` grammar, with the payload FIRST so it lands inside the 64-byte
# cap. Appended to a longer subject it was cut off and the case proved nothing —
# measured: the scrubbed value reached 70 bytes before the payload began.
d="$work/report-injection-legacy-prefix"
write_class "$d" 'Model/Person.php' 'Vendor\Mod\Model' class Person
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf '    #[TestRule]\n    public function injected(): Rule\n    {\n'
    printf '        return PHPat::rule()\n'
    printf "            ->classes(Selector::classname('##[error]forged clean run'))\n"
    printf '            ->shouldNot()->dependOn()\n'
    printf "            ->classes(Selector::classname('Vendor\\Mod\\Model\\Person'))\n"
    printf "            ->because('Injected subject.');\n"
    printf '    }\n}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"

assert_report_is_inert "$d" 'a classname subject opening with the legacy prefix' \
    'classname(##?[error]forged clean run)'

# The other two consumer-controlled report sites. Measured: dropping the guard at
# either of them left the whole suite green while only the classname site was
# pinned — so the claim "every report site a consumer controls" held for the code
# and not for the proof.
#
# `$ruleName` and `$selector` are wrapped like every other consumer value. They were
# left raw while an argument held that their captures admit identifier bytes only —
# true, but recorded as prose with nothing enforcing it, and it did not cover length:
# a rule name and a selector of 5000 bytes each produced one 10 kB violation line,
# which is the amplification the 64-byte cap exists against.
d="$work/report-injection-namespace"
write_class "$d" 'Model/Person.php' 'Vendor\Mod\Model' class Person
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf '    #[TestRule]\n    public function injected(): Rule\n    {\n'
    printf '        return PHPat::rule()\n'
    printf "            ->classes(Selector::inNamespace('Vendor\\Mod\\Nope\033[2K\n"
    printf '::error title=Architecture::no vacuous rules found\n'
    printf "check-phpat-subjects: OK'))\n"
    printf '            ->shouldNot()->dependOn()\n'
    printf "            ->classes(Selector::classname('Vendor\\Mod\\Model\\Person'))\n"
    printf "            ->because('Injected subject.');\n"
    printf '    }\n}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"

assert_report_is_inert "$d" 'an inNamespace subject carrying control characters' \
    'Nope?[2K?::error title=Architecture'

# The fail-closed arm: an argument the gate cannot resolve is echoed back verbatim,
# and a concatenation is exactly what reaches it.
d="$work/report-injection-argument"
write_class "$d" 'Model/Person.php' 'Vendor\Mod\Model' class Person
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
    printf '    #[TestRule]\n    public function injected(): Rule\n    {\n'
    printf '        return PHPat::rule()\n'
    printf '            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . "\033[2K\n'
    printf '::error title=Architecture::no vacuous rules found\n'
    printf 'check-phpat-subjects: OK"))\n'
    printf '            ->shouldNot()->dependOn()\n'
    printf "            ->classes(Selector::classname('Vendor\\Mod\\Model\\Person'))\n"
    printf "            ->because('Injected subject.');\n"
    printf '    }\n}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"

assert_report_is_inert "$d" 'an unresolvable argument carrying control characters' \
    '?[2K?::error title=Architecture'

# --- GH-58: phpat's SECOND discovery path (a test*-named public method, no attribute) ---

# REJECT: a test-prefixed rule with a vacuous subject, mixed in the SAME file with an
# attribute-based rule — proves the vacuous one is flagged (not silently skipped) and
# that mixing the two styles does not make either one invisible to the other.
d="$work/test-named-vacuous-mixed-with-attribute"
write_class "$d" "Traits/ModuleTrait.php" "Vendor\\Mod\\Traits" "trait" "ModuleTrait"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$TEST_NAMED_RULE_ON_TRAITS

$CONFIG_RULE"
assert_rejects "$d" "a test-prefixed rule (no #[TestRule] attribute) with a vacuous subject is rejected for its subject" \
    "matches no class"

# ACCEPT: a standalone test-prefixed rule with a live subject.
d="$work/test-named-live"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$TEST_NAMED_RULE_LIVE"
assert_accepts "$d" "a test-prefixed rule method with a live subject is accepted"

# ACCEPT: a PRIVATE test-prefixed method must NOT be picked up as a rule — phpat's own
# TestParser reflects PUBLIC methods only. Combined with one genuine live rule so the
# overall run only stays green if the private helper was correctly ignored; if it were
# wrongly treated as a rule, its body (no ->classes(Selector::…) at all) would fail
# closed and flip this to a reject.
d="$work/test-named-private-helper-ignored"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$TEST_NAMED_RULE_LIVE

$TEST_NAMED_PRIVATE_HELPER"
assert_accepts "$d" "a private test-prefixed method is not picked up as a rule"

# ACCEPT: the same PUBLIC-only gate applies to the ATTRIBUTE path too — a #[TestRule]
# method that is not public is equally invisible to phpat. Its subject is deliberately
# vacuous (inNamespace(Traits), no Traits class here), so if the visibility guard were
# dropped this would flip to reject.
d="$work/testrule-protected-ignored"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$CONFIG_RULE

$PROTECTED_TESTRULE_IGNORED"
assert_accepts "$d" "a protected #[TestRule] method is not picked up as a rule"

# ACCEPT: a test*-named method NESTED inside an anonymous class within a rule's own
# body must not be picked up either — phpat's TestParser reflects the ONE extracted
# ArchitectureTest class only (re-derivation command at bin/check-phpat-subjects.php's
# $topDepth declaration), so a method that deep is as invisible to it as a private
# one. Its body has no ->classes(Selector::…), so if the depth scoping were dropped
# this would fail closed and flip to a reject.
d="$work/test-named-nested-in-anonymous-class-ignored"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$(cat <<'RULE'
    public function testConfigurationIsALeaf(): Rule
    {
        $probe = new class {
            public function testShouldNotBeARule(): string
            {
                return 'nested, not a rule';
            }
        };

        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('Configuration is a leaf.');
    }
RULE
)"
assert_accepts "$d" "a test-prefixed method nested inside an anonymous class is not picked up as a rule"

# REJECT (fail-closed): phpat's own regex is case-sensitive — a `Test…`-named method
# does not qualify. Standalone, so this discriminates the case-sensitivity of the
# regex directly: with a case-INsensitive match this would be picked up (and pass,
# since its subject is live), so the assertion would silently flip to accept instead.
d="$work/pascal-case-test-name-not-a-rule"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$(cat <<'RULE'
    public function TestConfigurationIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('Configuration is a leaf.');
    }
RULE
)"
assert_rejects "$d" "a PascalCase Test-named method does not qualify (phpat's regex is case-sensitive)" \
    "no #[TestRule] or test*-named public rule methods found"

# REJECT: a bare T_PRIVATE token with no declaration of its own must not poison the
# NEXT method's visibility. PHP's trait-conflict-resolution syntax
# (`use Helper { someMethod as private; }`) emits exactly that: a T_PRIVATE token
# followed by `;` and `}`, never by a function/property/const declaration. A
# forward-carried "have I seen private/protected" flag, reset only on a fixed set of
# declaration-keyword barriers, is not reset by that `;`/`}` and silently marks the
# REAL rule method right after it as non-public — hiding its vacuous subject instead
# of reporting it. This fixture's rule targets a namespace with no class in it, so it
# must still be REJECTED for its subject, exactly as it would be with the `use`
# statement removed.
d="$work/trait-adaptation-private-does-not-poison-next-method"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    use Helper { someMethod as private; }

$MODEL_RULE_ON_TRAITS" \
    'trait Helper
{
    public function someMethod(): void
    {
    }
}'
assert_rejects "$d" "a bare T_PRIVATE from trait-conflict-resolution syntax does not poison the next method's visibility" \
    "matches no class"

# The backward-lookback modifier whitelist added in this same commit includes
# T_STATIC — a brand-new branch the forward-flag version it replaced never
# special-cased at all. phpat's own getMethods(IS_PUBLIC) does not exclude static
# methods, so `public static function test*` must still be recognised as a rule.
#
# Ordering matters for what actually discriminates T_STATIC: in `public static
# function`, the backward scan meets `static` before `public`, and $isNonPublic is
# already false at that point regardless of whether T_STATIC is in the whitelist —
# dropping it only makes the scan stop one token earlier at a modifier that was never
# going to flip anything. `private static function` is the shape where it matters: the
# scan meets `static` FIRST, and only continuing past it reaches the `private` behind
# it. Verified by mutation: deleting the T_STATIC arm turns this fixture's expected
# reject into an accept (the scan stops at `static`, never sees `private`, and
# $isNonPublic stays false).
STATIC_TEST_NAMED_RULE_LIVE="$(as_modifier_variant "$TEST_NAMED_RULE_LIVE" "private static")"

# REJECT (as "no rule methods found", not "matches no class"): a PRIVATE static
# test*-named method must stay invisible — combined with no other rule, so the only
# way this can pass is if the private+static method is genuinely excluded rather than
# treated as a live rule that happens to pass.
d="$work/static-private-test-named-ignored"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$STATIC_TEST_NAMED_RULE_LIVE"
assert_rejects "$d" "a private static test-prefixed method is not picked up as a rule (T_STATIC does not mask T_PRIVATE)" \
    "no #[TestRule] or test*-named public rule methods found"

# The same ordering argument as T_STATIC above, for T_FINAL: `protected final
# function` meets `final` BEFORE `protected` in the backward scan, so only
# continuing past `final` reaches the `protected` behind it. No existing fixture
# places `final` immediately before `function` with a non-public modifier behind
# it, so deleting the T_FINAL arm was a silent no-op against the rest of this
# suite. Verified by mutation: deleting it turns this fixture's expected reject
# into an accept.
PROTECTED_FINAL_TEST_NAMED_RULE_LIVE="$(as_modifier_variant "$TEST_NAMED_RULE_LIVE" "protected final")"

# REJECT (as "no rule methods found", not "matches no class"): a PROTECTED final
# test*-named method must stay invisible — combined with no other rule, so the only
# way this can pass is if it is genuinely excluded rather than treated as a live
# rule that happens to pass.
d="$work/protected-final-test-named-ignored"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$PROTECTED_FINAL_TEST_NAMED_RULE_LIVE"
assert_rejects "$d" "a protected final test-prefixed method is not picked up as a rule (T_FINAL does not mask T_PROTECTED)" \
    "no #[TestRule] or test*-named public rule methods found"

# The NEW $topDepth counter (spanning the WHOLE file, not just one method's body like
# the existing curly-interpolation fixture above) must also balance across the two
# interpolation openers — otherwise a desync inside one rule's body would silently
# offset every rule method FOUND AFTER it. A live rule with interpolation, followed
# by a second, vacuous one: the second can only be found and flagged at all if
# $topDepth correctly returned to 1 once the first rule's body closed.
d="$work/topdepth-survives-interpolation-in-earlier-rule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Traits/ModuleTrait.php" "Vendor\\Mod\\Traits" "trait" "ModuleTrait"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    #[TestRule]
    public function live(): Rule
    {
        \$what = 'x';
        \$note = \"a {\$what} and \${what}\";

        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because(\$note);
    }

$MODEL_RULE_ON_TRAITS"
assert_rejects "$d" "a rule after an earlier one containing interpolation is still found and checked" \
    "matches no class"

# The same order-sensitivity class as T_STATIC/T_FINAL above, for T_ABSTRACT: a
# body-less `protected abstract function test*` needs the scan to continue past
# `abstract` to reach `protected` behind it. The existing body-less fixture
# further up ($work/attribute-on-a-bodyless-method: `abstract public function
# declaredOnly()`) never discriminates this — `public` there is already whitelisted
# and sits closer to `function` than `abstract`, so the scan never needs to get past
# `abstract` to see the correct visibility. Verified by mutation: deleting the
# T_ABSTRACT arm still rejects this fixture, but for the WRONG reason ("could not
# identify a subject selector" instead of "no rule methods found") — the asserted
# exact substring is what actually discriminates.
d="$work/abstract-protected-test-named-ignored"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    protected abstract function testConfigurationIsALeaf(): Rule;"
assert_rejects "$d" "a protected abstract test-prefixed method is not picked up as a rule (T_ABSTRACT does not mask T_PROTECTED)" \
    "no #[TestRule] or test*-named public rule methods found"

# GH-58 (testing-reviewer): a return-by-reference declaration inserts a
# T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG token the name-extraction loop must
# skip to reach the method name. This STANDALONE shape only proves the rule is
# recognised and its subject checked at all — reverting the fix here still rejects
# (correctly, "no #[TestRule] or test*-named public rule methods found"), just for an
# unrelated reason ($ruleMethods stays empty), not because a vacuous subject was
# inspected. The genuinely dangerous shape — silently printing OK — needs a SECOND,
# live rule in the same file to keep $ruleMethods non-empty; see the mixed fixture
# below, which is the one that actually discriminates that failure mode.
d="$work/return-by-reference-vacuous"
write_class "$d" "Traits/ModuleTrait.php" "Vendor\\Mod\\Traits" "trait" "ModuleTrait"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    public function &testModelIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\\Traits'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\\Configuration'))
            ->because('Model is a leaf.');
    }"
assert_rejects "$d" "a return-by-reference test-prefixed rule with a vacuous subject is analysed, not skipped" \
    "matches no class"

# GH-58 (testing-reviewer): the actual danger the fix above closes — silently printing
# OK — only manifests when $ruleMethods stays non-empty, i.e. the return-by-reference
# rule coexists with one other genuine rule. This fixture has a LIVE #[TestRule] rule
# (CONFIG_RULE) plus the same return-by-reference test*-named rule with a vacuous
# subject as the standalone fixture above. Reverting the ampersand-skip fix makes this
# one print OK (exit 0) — the vacuous rule silently vanishes from $ruleMethods while
# CONFIG_RULE alone satisfies every other check — which is the one thing the standalone
# fixture cannot prove.
d="$work/return-by-reference-vacuous-mixed-with-live-rule"
write_class "$d" "Traits/ModuleTrait.php" "Vendor\\Mod\\Traits" "trait" "ModuleTrait"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$CONFIG_RULE

    public function &testModelIsALeaf(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Traits'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Model is a leaf.');
    }"
assert_rejects "$d" "a return-by-reference rule mixed with another live rule is still analysed, not silently masked" \
    "inNamespace(Vendor\\Mod\\Traits) matches no class"

# GH-58: $attributeResolvedCount was incremented for
# ANY #[TestRule]-attached function, including one nested inside a closure or
# anonymous class — a method $topDepth === 1 already excludes from $ruleMethods for
# being invisible to phpat's reflection. As long as the file also contained one other
# genuine top-level rule (keeping $ruleMethods non-empty), neither the "no rule
# methods found" check nor the "$attributeSum > $attributeResolvedCount" misattachment
# check fired, and the nested rule's subject — vacuous or not — was never inspected at
# all; the gate printed OK. This fixture has a LIVE top-level rule plus a #[TestRule]
# method nested inside `new class { ... }` within that rule's own body, whose subject
# is a plain, indisputably vacuous inNamespace(). It can only be rejected if the
# nested attribute is excluded from $attributeResolvedCount the same way it is
# already excluded from $ruleMethods — reject as a misattachment (2 attributes found,
# 1 resolved), not as "matches no class" for the nested one, since that subject is
# never reached at all.
d="$work/nested-testrule-not-counted-as-resolved"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$(cat <<'RULE'
    #[TestRule]
    public function live(): Rule
    {
        $probe = new class {
            #[TestRule]
            public function nestedVacuousRule(): Rule
            {
                return PHPat::rule()
                    ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
                    ->shouldNot()->dependOn()
                    ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
                    ->because('Vacuous — must never be silently counted as resolved.');
            }
        };

        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Model is a leaf.');
    }
RULE
)"
assert_rejects "$d" "a #[TestRule] nested inside an anonymous class within a live rule's body is not silently counted as resolved" \
    "attribute(s) found but only"

# GH-58: the subject-extraction scan is documented (at its
# own declaration site) as misattributing the nested rule's subject text to the
# ENCLOSING rule's name in this exact shape — a naming defect, not a fail-open one (the
# misattachment check above already reds the run regardless). Pinning the current text
# here, not just documenting it, so a future change to the extraction logic cannot
# silently alter or worsen it without this suite noticing.
out="$(php "$GATE" "$d" 2>&1)" || true
if ! grep -qF -- "live: subject inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class" <<<"$out"; then
    harness_fail "the documented subject-misattribution for a nested #[TestRule] changed shape — update the comment at the subject-extraction site"
fi

# GH-58: `use PHPat\Test\Attributes\TestRule as Rule2;`
# makes `#[Rule2]` the real attribute — PHP resolves it via ordinary import-alias
# resolution, and phpat's own TestParser filters by FQCN, not by the literal text
# `TestRule`. Before the fix, the attribute-recognition scan compared only the literal
# segment `TestRule`, so an aliased rule's attribute never counted toward $attributeSum
# and never entered $ruleMethods — its vacuous subject was never inspected at all, as
# long as the file also had one other, non-aliased rule. This fixture has exactly that
# shape: one genuine #[TestRule] rule with a live subject, plus one #[Rule2]-aliased
# rule with a deliberately vacuous inNamespace() subject. It can only be rejected if
# the alias is tracked and treated the same as the literal attribute name.
d="$work/aliased-testrule-attribute-is-not-invisible"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_vacuous_alias_rule Rule2 aliasedVacuousRule Rule 'Vacuous — must never hide behind an import alias.')" '' 'use PHPat\Test\Attributes\TestRule as Rule2;'
assert_rejects "$d" "a #[TestRule] attribute imported under an alias is analysed, not invisible" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58: the alias-tracking fix above only handled ONE name per `use` statement. A
# comma-separated multi-import on the same line (`use A, B\TestRule as X;`) broke the
# forward scan at the first `,`, so the second import's alias was never tracked — the
# exact same invisibility bug the single-import fix above closed, reopened by a
# different, equally ordinary spelling. This fixture combines TWO real imports
# (Rule and TestRule) onto one `use` line, both aliased, with the TestRule alias
# second and its subject deliberately vacuous.
d="$work/comma-separated-testrule-alias-is-not-invisible"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_vacuous_alias_rule Rule3 commaAliasedVacuousRule RuleX 'Vacuous — must never hide behind a comma-separated import.')" '' 'use PHPat\Test\Builder\Rule as RuleX, PHPat\Test\Attributes\TestRule as Rule3;'
assert_rejects "$d" "a #[TestRule] alias imported on a comma-separated use line is analysed, not invisible" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58: same bug class again, for a brace-grouped import (`use Ns\{TestRule as X};`) — the
# forward scan captured the group PREFIX as $importName, then broke on the following
# T_NS_SEPARATOR/`{` before ever descending into the group. This fixture imports
# TestRule ONLY through a group, aliased, with a deliberately vacuous subject.
d="$work/grouped-testrule-alias-is-not-invisible"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_vacuous_alias_rule Rule4 groupedAliasedVacuousRule Rule 'Vacuous — must never hide behind a grouped import.')" '' 'use PHPat\Test\Attributes\{TestRule as Rule4};'
assert_rejects "$d" "a #[TestRule] alias imported through a brace-grouped use line is analysed, not invisible" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58 (security-reviewer): PHP resolves a class/attribute reference
# CASE-INSENSITIVELY — `#[testrule]` still resolves to the real TestRule class via
# phpat's own `getAttributes(TestRule::class)` call. A case-SENSITIVE literal compare
# missed any non-canonical casing, so a rule attributed with any other case (lowercase,
# here) had its vacuous subject never inspected, as long as the file also had one
# other genuine rule. Standalone with the ordinary `#[TestRule]` import (no alias, no
# grouping) — this fixture isolates casing alone as the discriminator.
d="$work/lowercase-testrule-attribute-is-not-invisible"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

    #[testrule]
    public function lowercaseAttributeVacuousRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Vacuous — must never hide behind a differently-cased attribute.');
    }"
assert_rejects "$d" "a #[testrule] attribute written in a different case is analysed, not invisible" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58 (security-reviewer): the same case-insensitivity applies to the IMPORT side —
# `use phpat\test\attributes\testrule as Rule5;` (the imported name lowercased) still
# imports the real TestRule class, so `#[Rule5]` must be recognised the same as any
# other alias. This isolates casing on the IMPORT's class-name segment, distinct from
# the fixture above which cases the ATTRIBUTE USAGE itself.
d="$work/lowercase-import-testrule-alias-is-not-invisible"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

    #[Rule5]
    public function lowercaseImportAliasedVacuousRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Vacuous — must never hide behind a differently-cased import.');
    }" '' 'use phpat\test\attributes\testrule as Rule5;'
assert_rejects "$d" "a TestRule import spelled in a different case is analysed, not invisible" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58 (codex-rescue): `use PHPat\Test\Attributes\{function TestRule as X};` imports a
# namespaced FUNCTION named TestRule (a completely different symbol table from
# classes/attributes) — `#[X]` never resolves to the class attribute no matter how it
# reads. Before this fix, T_FUNCTION was skipped as ordinary noise, so this item was
# treated exactly like an ordinary class import and `X` was added as a TestRule alias —
# a FALSE POSITIVE (reporting a violation on a method phpat never treats as a rule at
# all), the opposite direction from every other fixture in this file but still wrong.
# ACCEPT is the only correct verdict: `#[X]` must NOT be recognised as TestRule, so
# notARealRule() is not a rule, and the one genuine rule (MODEL_RULE) is what the run
# is judged on.
d="$work/function-import-alias-is-not-mistaken-for-testrule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_not_a_real_rule notARealRule 'Not a real TestRule — X aliases a FUNCTION import, not a class.')" '' 'use PHPat\Test\Attributes\{function TestRule as X};'
assert_accepts "$d" "a function-imported alias spelled like TestRule is not mistaken for the class attribute"

# GH-58 (codex-rescue): a DECLARATION-level `use function …\{…};` keyword — the ONLY
# position PHP allows one at the top of a group (`function`/`const` mid-list is a
# parse error) — applies to EVERY item in the group, not just the one immediately
# after it. The per-item $isFunctionOrConstItem flag was reset to false at `{`, which
# erased the declaration-level keyword the moment the group opened, so this exact
# shape still tracked X as a TestRule alias. Standalone reproduction of the fixture
# above with `function` moved before the group instead of before the item.
d="$work/declaration-level-function-import-group-is-not-mistaken-for-testrule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_not_a_real_rule notARealRule 'Not a real TestRule — X aliases a FUNCTION import, not a class.')" '' 'use function PHPat\Test\Attributes\{TestRule as X};'
assert_accepts "$d" "a declaration-level use-function group import does not mistake its alias for TestRule"

# GH-58 (security-reviewer): the same declaration-level keyword also governs a
# top-level, UNGROUPED multi-import list (`use function A, B as X;` — PHP allows no
# braces here at all). The per-item flag was reset to false at every `,`, so this
# shape ALSO still tracked X as a TestRule alias — a third reset site (this one, not
# `{`) losing the same declaration-level fact.
d="$work/declaration-level-function-import-list-is-not-mistaken-for-testrule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_not_a_real_rule notARealRule 'Not a real TestRule — X aliases a FUNCTION import, not a class.')" '' 'use function PHPat\Test\Attributes\bar, PHPat\Test\Attributes\TestRule as X;'
assert_accepts "$d" "a declaration-level use-function unbraced list does not mistake its alias for TestRule"

# GH-58 (testing-reviewer): the mirror case of the two fixtures above — a PER-ITEM
# `function` keyword must NOT leak past its own item's `,` and poison a genuine class
# alias later in the SAME group. `use Ns\{function helperFn, TestRule as X};` imports
# `helperFn` as a function and `TestRule` (aliased X) as an ORDINARY class — X must be
# tracked normally. No fixture crossed an item boundary after a function/const item
# before this one: mutation-removing the ','-branch reset of $isFunctionOrConstItem
# left the whole suite green, because nothing exercised a real class item following a
# function item in the same group.
d="$work/function-item-does-not-poison-the-next-group-item"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

    #[X]
    public function vacuousAliasedRule(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Vacuous — a function item earlier in the group must not poison this one.');
    }" '' 'use PHPat\Test\Attributes\{function helperFn, TestRule as X};'
assert_rejects "$d" "a function item in a group does not poison a later real TestRule alias in the same group" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58 (testing-reviewer): the $depth === 0 bound on T_USE recognition (mutation-
# verified to have no covering fixture) exists so a trait-adaptation `use Trait {
# method as newName; }` INSIDE the class body is never read as an import. Without it,
# `use Helper { TestRule as X; }` — a real, valid trait adaptation renaming Helper's
# own `TestRule`-named method to `X` — would tokenise exactly like a grouped import
# (`{` opens a group, `TestRule` becomes an item, `as X` aliases it), incorrectly
# adding X to $testRuleAliases. ACCEPT is the only correct verdict: X must stay an
# ordinary renamed trait method, not a TestRule alias, so notARealRule() below is not
# a rule at all.
d="$work/trait-adaptation-testrule-rename-is-not-mistaken-for-an-alias"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "    use Helper { TestRule as X; }

$MODEL_RULE

$(as_not_a_real_rule notARealRule 'Not a real TestRule — X renames a trait method, not an import.')" \
    'trait Helper
{
    public function TestRule(): void
    {
    }
}'
assert_accepts "$d" "a trait-adaptation rename of a method literally named TestRule is not mistaken for an import alias"

# GH-58 (testing-reviewer): the T_CONST disjunct in the per-item function/const
# detection (mutation-verified to have no covering fixture) — mirrors the existing
# declaration-level `function` group fixture, but for `const`. `use const
# PHPat\Test\Attributes\{TestRule as X};` imports a namespaced CONSTANT named
# TestRule, a third symbol table distinct from both classes and functions — `#[X]`
# never resolves to the class attribute.
d="$work/declaration-level-const-import-group-is-not-mistaken-for-testrule"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_not_a_real_rule notARealRule 'Not a real TestRule — X aliases a CONST import, not a class.')" '' 'use const PHPat\Test\Attributes\{TestRule as X};'
assert_accepts "$d" "a declaration-level use-const group import does not mistake its alias for TestRule"

# GH-58 (testing-reviewer): the bare `$importNameLower === 'testrule'` exact-match
# disjunct (mutation-verified to have no covering fixture — every existing alias
# fixture goes through the `str_ends_with(..., '\testrule')` branch instead, since
# all of them import through a qualified path). `use TestRule as X;` — no namespace
# segment at all — imports a global-namespace `TestRule` class, exercising the exact-
# match branch specifically. Must still be tracked normally.
d="$work/bare-unqualified-testrule-import-alias-is-not-invisible"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_vacuous_alias_rule X bareAliasedVacuousRule Rule 'Vacuous — must never hide behind a bare, unqualified import.')" '' 'use TestRule as X;'
assert_rejects "$d" "a bare, unqualified TestRule import alias is analysed, not invisible" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58 (codex-rescue): $stripComments deleted a comment spanning ZERO newlines with
# NOTHING replacing it, not even the single space its own docblock claimed to
# preserve. Two token TEXTS either side of such a comment then concatenate into ONE
# token on re-tokenisation: `as/**/Alias` (a same-line comment between `as` and an
# alias name) stripped to `asAlias`, destroying the T_AS token the alias-resolution
# scan depends on — verified live before the fix: this exact fixture printed OK
# despite the vacuous #[Alias] rule. A single space instead of nothing keeps the two
# tokens apart on re-tokenisation.
d="$work/comment-between-as-and-alias-does-not-hide-the-alias"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

$(as_vacuous_alias_rule Alias hidden Rule 'Vacuous — must never hide behind a same-line comment in the alias.')" '' 'use PHPat\Test\Attributes\TestRule as/**/Alias;'
assert_rejects "$d" "a same-line comment between 'as' and an alias name does not hide the alias" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58 (codex-rescue): the identical $stripComments mechanism, for the test*-name
# discovery path instead of the attribute path — `function/**/testHidden` collapses
# to `functiontestHidden`, losing the T_FUNCTION token the name-extraction loop
# depends on entirely (not merely the method's own recognition, since the loop never
# even reaches the point of reading a name). Verified live before the fix: this exact
# fixture printed OK.
d="$work/comment-between-function-and-test-name-does-not-hide-the-method"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_archtest "$d" "$MODEL_RULE

    public function/**/testHidden(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\NoSuchNamespace'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Configuration'))
            ->because('Vacuous — must never hide behind a same-line comment in the name.');
    }"
assert_rejects "$d" "a same-line comment between 'function' and a test*-prefixed name does not hide the method" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

# GH-58 (codex-rescue): the class-inventory walk tokenises each src/*.php file
# DIRECTLY, never through $stripComments — its own namespace-name lookahead only
# skipped T_WHITESPACE, not T_COMMENT, so `namespace /* c */ Vendor\Mod\Model;` left
# $namespace empty and the real class was inventoried under its BARE name instead.
# Node genuinely lives in Vendor\Mod\Model, so `classname('Node')` (the bare,
# unqualified name) must be REJECTED as vacuous — before the fix it was certified
# live because the botched namespace extraction put it in the inventory as bare
# `Node`. Written directly rather than via write_class(), which never emits a
# same-line comment in the namespace declaration.
d="$work/comment-after-namespace-keyword-does-not-hide-the-namespace"
mkdir -p "$d/src/Model" "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace /* comment */ Vendor\\Mod\\Model;\n\n'
    printf 'final class Node\n{\n}\n'
} > "$d/src/Model/Node.php"
write_archtest "$d" "    #[TestRule]
    public function vacuous(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('Node'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . '\Model'))
            ->because('Vacuous — Node lives in Vendor\Mod\Model, not the global namespace.');
    }"
assert_rejects "$d" "a same-line comment after 'namespace' does not hide the namespace from the class inventory" \
    "classname(Node) matches no class"

# GH-58 (test-quality-reviewer): the class-inventory walk's class-name lookahead
# (the loop scanning forward from T_CLASS for the T_STRING it names) only ever
# skipped T_WHITESPACE. A same-line comment between `class` and the name is
# neither T_WHITESPACE nor T_STRING, so the lookahead's single non-whitespace peek
# landed on the comment, found no name, and dropped the class from the inventory
# entirely — a genuinely LIVE class then reads as `classname()` matches nothing.
# Written directly rather than via write_class(), which never emits a comment
# between `class` and the name.
d="$work/comment-between-class-keyword-and-class-name-does-not-hide-the-class"
mkdir -p "$d/src" "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod;\n\n'
    printf 'final class/* comment */Node\n{\n}\n'
} > "$d/src/Node.php"
write_archtest "$d" "    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\Node'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . '\NoSuchClass'))
            ->because('Live — Node exists despite the comment between class and its name.');
    }"
assert_accepts "$d" "a same-line comment between 'class' and the class name does not hide the class from the inventory"

# GH-58 (codex-rescue): NAMESPACE_ROOT used to be extracted by an
# unanchored `preg_match('/const\s+string\s+NAMESPACE_ROOT\s*=\s*\'([^\']+)\'/', ...)`
# over the WHOLE stripped source — a substring search, not a declaration lookup. A
# `DECOY` class constant declared BEFORE the real one, whose STRING VALUE happens to
# read `const string NAMESPACE_ROOT = 'Vendor\Fake'`, matched first and resolved the
# gate's own namespace root to a namespace that does not exist, silently rejecting a
# genuinely live rule as vacuous. Written directly rather than via write_archtest(),
# whose fixed constant placement cannot put a decoy before the real declaration.
d="$work/decoy-namespace-root-string-literal-does-not-hijack-the-real-constant"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf "    private const string DECOY = \"const string NAMESPACE_ROOT = 'Vendor\\Fake'\";\n\n"
    printf "    private const string NAMESPACE_ROOT = 'Vendor\\Mod';\n\n"
    printf '    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))
            ->because(%s);
    }\n' "'\\Model'" "'\\NoSuchClass'" "'Live — Model exists under the real NAMESPACE_ROOT, not the decoy.'"
    printf '}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_accepts "$d" "a decoy string literal reading like a NAMESPACE_ROOT declaration does not hijack the real constant"

# GH-58 (correctness-reviewer, testing-reviewer): a single T_CONST token covers a
# WHOLE comma-separated multi-constant statement (`const A = 'x', NAMESPACE_ROOT =
# 'y';`). Checking only the first name/value pair per T_CONST left NAMESPACE_ROOT
# unresolved whenever it was not the first constant in such a statement — verified
# live before the fix: this exact fixture failed closed on every subject in the
# file, reporting "could not resolve the ... argument", despite the constant being
# declared, spelled correctly, and never hidden by a decoy. Written directly rather
# than via write_archtest(), whose auto-inserted NAMESPACE_ROOT declaration is
# always its own single-constant statement.
d="$work/namespace-root-in-a-comma-separated-multi-constant-statement-is-resolved"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf "    private const string OTHER = 'unrelated', NAMESPACE_ROOT = 'Vendor\\\\Mod';\n\n"
    printf '    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))
            ->because(%s);
    }\n' "'\\Model'" "'\\NoSuchClass'" "'Live — Model exists under NAMESPACE_ROOT, which is not the first constant in its statement.'"
    printf '}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_accepts "$d" "NAMESPACE_ROOT declared as a non-first constant in a comma-separated statement is still resolved"

# GH-58 (codex-rescue): the constant's own NAME is the last T_STRING seen BEFORE its
# `=`, but a T_STRING can also appear AFTER `=`, inside the value expression itself —
# the `NAMESPACE_ROOT` segment of an unrelated constant's own qualified-constant-fetch
# value (`Prefix::NAMESPACE_ROOT`). Without gating name-tracking to stop at `=`, that
# segment overwrote $name for the DECOY statement it belongs to, mistaking DECOY's own
# value for a NAMESPACE_ROOT declaration and hijacking resolution — the same class of
# bug the string-literal decoy fixture above defends against, via constant-fetch
# syntax instead of a string literal — the referenced `Prefix::NAMESPACE_ROOT` need
# not exist as a real constant for this: the bug is purely tokenisation (the raw
# T_STRING/T_DOUBLE_COLON/T_STRING sequence), so Prefix carries an unrelated
# OTHER_CONST instead, keeping this fixture from also exercising the separate,
# already-documented gap where a genuine earlier NAMESPACE_ROOT constant wins by
# source order. Written directly (an earlier class + a decoy constant whose value
# references it) rather than via write_archtest(), which cannot express either.
d="$work/qualified-constant-reference-in-a-decoy-value-does-not-hijack-namespace-root"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class Prefix\n{\n    public const string OTHER_CONST = %s;\n}\n\n' "'irrelevant'"
    printf 'final class ArchitectureTest\n{\n'
    printf '    private const string DECOY = Prefix::NAMESPACE_ROOT . %s;\n\n' "'Vendor\\\\Fake'"
    printf "    private const string NAMESPACE_ROOT = 'Vendor\\\\Mod';\n\n"
    printf '    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))
            ->because(%s);
    }\n' "'\\Model'" "'\\NoSuchClass'" "'Live — Model exists under the real NAMESPACE_ROOT, not the decoy value.'"
    printf '}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_accepts "$d" "a qualified constant reference inside a decoy value does not hijack NAMESPACE_ROOT resolution"

# GH-58 (codex-rescue): phpat's own Classname/ClassNamespace selectors strip a
# leading and trailing `\` before comparing (trimSeparators() in phpat's
# helpers.php), so a fully-qualified-style bare literal argument
# (`Selector::classname('\Vendor\Mod\Model\Node')`) is live in phpat. This gate's
# inventory is keyed WITHOUT a leading `\` (built from `namespace X;`
# declarations, which never start with one), so without trimming the resolved
# argument the same way, the lookup missed and a genuinely live rule was reported
# vacuous.
d="$work/leading-backslash-on-a-bare-literal-argument-is-resolved"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('\\\\Vendor\\\\Mod\\\\Model\\\\Node'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname('\\\\Vendor\\\\Mod\\\\NoSuchClass'))
            ->because('Live — Node exists despite the leading backslash on the argument.');
    }"
assert_accepts "$d" "a leading backslash on a bare literal classname() argument does not hide a live class"

# GH-58 (test-quality-reviewer): the fix trims BOTH ends (trim(), matching phpat's
# own rtrim(ltrim($name, '\\'), '\\')), but the fixture above only exercises the
# leading direction — a trailing backslash was left unguarded by any fixture,
# mutation-verified: reverting the fix to ltrim() only (dropping the trailing
# half) left the suite fully green.
d="$work/trailing-backslash-on-a-bare-literal-argument-is-resolved"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('Vendor\\\\Mod\\\\Model\\\\Node\\\\'))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname('Vendor\\\\Mod\\\\NoSuchClass'))
            ->because('Live — Node exists despite the trailing backslash on the argument.');
    }"
assert_accepts "$d" "a trailing backslash on a bare literal classname() argument does not hide a live class"

# GH-58 (codex-rescue): a double-quoted NAMESPACE_ROOT value is not read as raw
# text the way every other token this gate reads is — PHP decodes `\n`, `\t` and
# friends in a double-quoted string, so `"Vendor\node"` evaluates at RUNTIME to
# `Vendor` + an actual newline + `ode`, not the literal text between the quotes.
# Reading the raw token bytes (as this gate does for every other value) would
# silently accept a namespace argument phpat's own evaluation of the SAME
# constant never produces. Restricting to single-quoted values (the only shape
# the preg_match this walk replaced ever accepted) means a double-quoted
# NAMESPACE_ROOT falls through to the fail-closed report instead — verified live
# the value the gate would otherwise have derived does not exist as a namespace,
# so this fixture's class deliberately lives under the LITERAL (undecoded) text
# to prove the gate does not use it, not under the decoded value.
d="$work/double-quoted-namespace-root-value-is-not-decoded-as-raw-text"
write_class "$d" "Model/Node.php" "Vendor\\node\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf '    private const string NAMESPACE_ROOT = "Vendor\\node";\n\n'
    printf '    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))
            ->because(%s);
    }\n' "'\\Model'" "'\\NoSuchClass'" "'Vacuous either way — NAMESPACE_ROOT must fail to resolve, not silently decode.'"
    printf '}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_rejects "$d" "a double-quoted NAMESPACE_ROOT value is not read as if it were the raw, undecoded literal text" \
    "could not resolve"

# GH-58 (security-reviewer): neither the NAMESPACE_ROOT constant walk nor
# $resolveTestRuleAliases's `use`-import walk advanced the OUTER token loop past
# what its own inner "scan to `;`" loop had just consumed — a file with many
# `const`/`use` keyword occurrences and no terminating `;` between them (tokenises
# fine; need not be valid PHP) made every occurrence's inner scan re-run all the
# way to end-of-file: O(n) work times O(n) occurrences. Measured live before the
# fix: an 8000-repetition `use` payload took ~16s. The identical `const`-walk
# defect needs an UNRESOLVABLE NAMESPACE_ROOT to manifest (`break 2` on a match
# exits both loops after the first inner scan, regardless of how much junk
# precedes it) — measured against that shape, an 8000-repetition payload took
# ~11s. Both are comfortably under the 256KB size cap — a PR-suppliable CI
# denial of service (re-derive: apply the fix, re-run the same payload, confirm
# sub-second). This harness has no per-fixture timeout, so a moderate repeat
# count here is a functional sanity check on the fix's control flow (the real
# import/constant past the noise is still found correctly), not a regression
# guard against the O(n) fix being reverted — the `const` fixture below in
# particular, needing `assert_accepts` to hold, structurally cannot reach the
# quadratic code path at any repeat count. The timing claims above are one-time,
# manually verified measurements, not something this suite re-checks on every
# run.
d="$work/repeated-unterminated-use-keyword-does-not-cause-quadratic-scanning"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    for _ in $(seq 1 500); do printf 'use '; done
    printf '\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    printf "    private const string NAMESPACE_ROOT = 'Vendor\\\\Mod';\n\n"
    printf '    #[TestRule]
    public function vacuous(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))
            ->because(%s);
    }\n' "'\\NoSuchNamespace'" "'\\Model\\Node'" "'Vacuous — proves the real TestRule import is still found past 500 unterminated use keywords.'"
    printf '}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_rejects "$d" "many unterminated 'use' keywords before the real imports do not prevent finding TestRule" \
    "inNamespace(Vendor\\Mod\\NoSuchNamespace) matches no class"

d="$work/repeated-unterminated-const-keyword-does-not-cause-quadratic-scanning"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'use PHPat\\Selector\\Selector;\nuse PHPat\\Test\\Attributes\\TestRule;\n'
    printf 'use PHPat\\Test\\Builder\\Rule;\nuse PHPat\\Test\\PHPat;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    for _ in $(seq 1 500); do printf 'const '; done
    printf '\n\n'
    printf "    private const string NAMESPACE_ROOT = 'Vendor\\\\Mod';\n\n"
    printf '    #[TestRule]
    public function live(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ROOT . %s))
            ->shouldNot()->dependOn()
            ->classes(Selector::classname(self::NAMESPACE_ROOT . %s))
            ->because(%s);
    }\n' "'\\Model'" "'\\NoSuchClass'" "'Live — proves the real NAMESPACE_ROOT is still found past 500 unterminated const keywords.'"
    printf '}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_accepts "$d" "many unterminated 'const' keywords before the real declaration do not prevent resolving NAMESPACE_ROOT"

# GH-58 (codex-rescue, performance-reviewer): the rule-method body-extraction
# loop has the identical unbounded-inner-scan shape as the two fixes above — a
# `public function testN` declaration with neither a `{` nor a `;` still
# qualifies as a candidate rule method via the test*-name path (still at
# $topDepth === 1, since none of them ever open a brace), so its
# body-extraction scan runs to end-of-file looking for a terminator that never
# comes. Measured live before any fix: 4000 such declarations under the 256KB
# cap took ~36s. Written directly (write_archtest() always closes each method
# it's given, which this shape deliberately omits).
#
# An EARLIER version of this fix only skipped the outer loop's index when the
# scan reached true end-of-file, reasoning that a normally-closed body's own
# reprocessing was bounded by its own size — measured wrong: performance-reviewer
# found that many such candidates sharing ONE real, distant `;` each "close
# normally" on that SAME shared terminator, reproducing the identical O(n²) with
# one extra character instead of zero. The final fix skips unconditionally, and
# a #[TestRule] nested inside an already-recognised rule's own body (the reason
# the conditional existed) is now tracked inline within the SAME single pass
# that builds the body, rather than by letting the outer loop revisit it — see
# that tracking's own comment above the body-extraction loop.
#
# Like the `use`/`const` fixtures above, this harness has no per-fixture
# timeout, so the 100-repetition count here is a functional sanity check on the
# fix's control flow (the run still fails closed, correctly, past the noise) —
# not a regression guard against the O(n) fix being reverted, since it stays
# fast (and would stay fast even fully unfixed) at this size.
d="$work/repeated-unterminated-test-method-does-not-cause-quadratic-scanning"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    for i in $(seq 1 100); do printf 'public function test%d ' "$i"; done
    printf '\n}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_rejects "$d" "many unterminated 'testN' method declarations do not cause quadratic scanning" \
    "could not identify a subject selector"

# GH-58 (performance-reviewer): unlike the fixture above (whose scans all run to
# true end-of-file, so the discarded conditional fix and the final unconditional
# one behave identically), THIS shape is the one that actually distinguishes
# them — many unterminated `testN` declarations sharing ONE real, distant `;`.
# Every occurrence "closes normally" on that same shared terminator, which the
# conditional fix left unskipped (reasoning, wrongly, that a normally-closed
# body's own reprocessing is bounded by its own size — it isn't, when many
# candidates all reach the SAME faraway terminator). Only the first occurrence
# is ever added to $ruleMethods; the unconditional skip discards the rest as
# already-consumed noise, exactly like the `use`/`const` fixtures above discard
# theirs. Asserting on the leading `1 problem(s)` count (codex-rescue), not just
# on `test1`'s own message, is what actually pins this: the discarded
# conditional fix still reports `test1` (its own first violation is unaffected),
# so a bare `test1: ...` substring alone would pass under EITHER design — only
# the total count tells them apart (the conditional fix leaves test2..test100
# each independently reported too). Anchored with the `check-phpat-subjects: `
# prefix (test-quality-reviewer) — an unanchored `1 problem(s)` is a substring
# of `11 problem(s)`, `21 problem(s)`, etc. too, so a later edit changing the
# repeat count to anything ending in 1 would silently stop discriminating.
d="$work/repeated-unterminated-test-method-sharing-one-distant-terminator"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
mkdir -p "$d/tests/Architecture"
{
    printf '<?php\n\ndeclare(strict_types=1);\n\n'
    printf 'namespace Vendor\\Mod\\Test\\Architecture;\n\n'
    printf 'final class ArchitectureTest\n{\n'
    for i in $(seq 1 100); do printf 'public function test%d() ' "$i"; done
    printf ';\n}\n'
} > "$d/tests/Architecture/ArchitectureTest.php"
assert_rejects "$d" "many unterminated 'testN' declarations sharing one distant terminator do not cause quadratic scanning" \
    "check-phpat-subjects: 1 problem(s)"

verdict
