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

verdict
