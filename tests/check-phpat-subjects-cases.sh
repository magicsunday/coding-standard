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

# --- ACCEPT: isAbstract subject WITH a real abstract class present ---
d="$work/abstract-present"
write_class "$d" "AbstractNode.php" "Vendor\\Mod" "abstract class" "AbstractNode"
write_class "$d" "Configuration.php" "Vendor\\Mod" "final class" "Configuration"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
write_archtest "$d" "$MODEL_RULE

$CONFIG_RULE

$ABSTRACT_RULE"
assert_accepts "$d" "isAbstract subject with an abstract class present"

# --- ACCEPT: no ArchitectureTest at all → nothing to check ---
d="$work/no-archtest"
write_class "$d" "Model/Node.php" "Vendor\\Mod\\Model" "final class" "Node"
assert_accepts "$d" "no ArchitectureTest present"

if [ "$fails" -ne 0 ]; then
    printf '\n%d case(s) failed.\n' "$fails"
    exit 1
fi

printf '\nAll cases passed.\n'
