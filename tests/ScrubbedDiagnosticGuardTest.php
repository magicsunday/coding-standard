<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_slice;
use function count;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function is_array;
use function preg_match;
use function token_get_all;

use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_STRING;
use const T_WHITESPACE;

/**
 * A structural, grep-shaped regression guard against a PHPUnit assertion
 * whose own call — subject/actual argument OR a hand-written custom
 * message — carries a raw
 * `$result->output`/`->getOutput()`/`->getErrorOutput()` access, run against
 * PR-editable content (this repository's own biome/base.json,
 * tsconfig/base.json, package.json, templates/jscpd.json, or subprocess
 * output produced against them). All six of self::RISKY_ASSERTIONS leak the
 * raw subject/actual operand on a failure, but through TWO DIFFERENT
 * PHPUnit mechanisms, and wrapping only a custom $message in
 * self::scrubbedForDiagnostic() suppresses neither: the first four embed
 * the raw operand straight into getMessage(); assertSame()/assertEquals()
 * on two STRING operands instead attach a
 * SebastianBergmann\Comparator\ComparisonFailure that only PHPUnit's own
 * CLI/text printer renders, never getMessage() — EXCEPT a
 * TYPE-MISMATCHED comparison (e.g. one operand `null`), which reaches
 * getMessage() by a third path instead. Both dated observations, their
 * re-derivation commands, and that exception live in
 * tests/CheckJsConfigsTest.php's own assertMessageDoesNotForgeWorkflowCommand()
 * and readmeToolVersionLockstepFailsWithoutForgingAWorkflowCommand()
 * docblocks respectively, not repeated here. Every real
 * assertSame()/assertEquals() call site self::RISKY_ASSERTIONS scans for in
 * this codebase compares same-typed (string) operands, so this guard's own
 * scope does not currently need to police that third path — but a future
 * `assertSame($stringOrNull, $poisonedString)`-shaped call would need the
 * same manual self::fail() treatment even though it builds no
 * ComparisonFailure.
 *
 * This is a BEST-EFFORT static grep-shaped guard, not a real PHP parser.
 * Detection walks the token_get_all() TOKEN ARRAY directly, by a token
 * INDEX rather than a byte offset (self::significantTokens()/
 * self::openParenIndexAfter()/self::matchingCloseParenIndex()/
 * self::stripBalancedCallsFromTokens() below), instead of flattening the
 * tokens into a reconstructed string plus a parallel byte-mask that a
 * caller has to keep manually realigned through every strip — this file's
 * earlier byte-mask-plus-strpos() approach twice produced a live-reproduced
 * bypass, which walking the token array directly closes structurally rather
 * than by adding a further special case:
 * - STRING/HEREDOC/NOWDOC-CONTENT-SAFE, INTERPOLATED OR NOT:
 *   self::matchingCloseParenIndex()/self::openParenIndexAfter() depth-count/
 *   identify a paren only via self::isRawParenToken() — see that method's
 *   own docblock for why an array-shaped fragment (including the one shape
 *   whose own text can legitimately equal a single `(`/`)` character) can
 *   never satisfy it. An earlier version of this guard checked
 *   paren-identity through self::tokenText() instead, which collapses
 *   array-ness away — that version mis-balanced on exactly the
 *   interpolated-fragment shape self::isRawParenToken() forecloses,
 *   live-reproduced in
 *   detectsARiskyAssertionWithRawOutputAfterAnInterpolatedStringWhoseSegmentIsALoneClosingParen()
 *   and
 *   detectsARiskyAssertionWhereASanctionedWrapsInterpolatedStringSegmentIsALoneOpeningParen()
 *   below; see doesNotMisbalanceOnAClosingParenEmbeddedInAWrapsOwnHeredocArgument()
 *   below for the closing-paren half of the non-interpolated (heredoc/nowdoc
 *   body) case and
 *   detectsARiskyAssertionWhoseWrapArgumentCarriesAnUnmatchedOpeningParen()
 *   for the opening-paren half of the same fix; all four are now
 *   structural consequences of token atomicity plus the raw-token identity
 *   check, rather than a maintained byte-mask. The earlier byte-mask masked string CONTENT correctly
 *   but never consulted the mask when first LOCATING a candidate call — a
 *   sanctioned wrap name appearing only as decoy TEXT inside a PRECEDING
 *   string literal (e.g. `'Use messageOrDefault(...) to build this: ' .
 *   $result->output`) was matched by a plain needle search as if it were a
 *   real call, then swallowed everything up to and including the real,
 *   unwrapped output that followed; see
 *   detectsARiskyAssertionDisguisedByADecoyWrapNameInAPrecedingStringLiteral()
 *   below. Locating a candidate here instead means finding a T_STRING
 *   token, which the tokenizer never emits for text inside a string
 *   literal in the first place, so the failure mode cannot recur.
 * - IDENTIFIER-BOUNDARY-SAFE: self::openParenIndexAfter() (used both to
 *   locate a self::RISKY_ASSERTIONS candidate and inside
 *   self::stripBalancedCallsFromTokens() for a self::SAFE_WRAP_CALLS name)
 *   only matches a T_STRING token whose text EXACTLY equals the wanted
 *   name — never a substring — because PHP's own tokenizer already emits a
 *   whole identifier as one token; a byte-level `strpos($text,
 *   "{$name}(")` needle search has no such boundary and would match
 *   `scrubbedForDiagnostic(` inside a longer identifier like
 *   `xscrubbedForDiagnostic(`. See
 *   doesNotConfuseAHelperNameThatMerelyEndsWithASanctionedWrapName() below.
 * - It recognises exactly four "sanctioned wrap" call names
 *   (scrubbedForDiagnostic/diagnosticMessage/messageOrDefault/messageWithOutput)
 *   by their bare, EXACT name, not by resolving `self::`/`GateTestCase::`/an
 *   inherited call to the same method — a differently-named future helper
 *   wrapping the identical scrub would need adding to self::SAFE_WRAP_CALLS
 *   below, or this guard would false-positive on it.
 * - self::fail() call sites (the manual `if (...) { self::fail(...) }`
 *   shape the rest of this suite uses instead of a risky assertion) are
 *   deliberately OUT OF SCOPE: self::fail() takes a single literal string
 *   with no re-export mechanism, so a raw value reaching it is a DIFFERENT,
 *   already-covered concern (the message itself must be built with the
 *   scrub wrap, which is a per-call-site fix, not a PHPUnit-mechanism leak
 *   this guard exists to catch).
 * - self::RAW_OUTPUT_PATTERN also flags a regex-capture variable
 *   (`$matches[`) and an array-key access shaped like subprocess output
 *   (`['stdout']`/`['stderr']`, SINGLE-QUOTED only — a double-quoted
 *   `["stdout"]`/`["stderr"]` is not matched, an accepted, honest limitation
 *   confirmed to have no live instance in any of self::guardedFiles() today),
 *   on top of the direct `->output`/`->getOutput()`/`->getErrorOutput()`
 *   accessors, applied to self::tokensToText()'s reconstruction of a call's
 *   own argument tokens (with every sanctioned wrap span already removed) —
 *   but it still matches by fixed literal shape, not real data-flow, so a
 *   raw value reaching a risky assertion through a differently-named
 *   variable or a deeper array/object path is still not detected.
 * - self::significantTokens() drops every T_COMMENT/T_DOC_COMMENT token
 *   from the sequence outright, so a comment or docblock merely quoting a
 *   risky-assertion call as illustrative prose is not flagged.
 * - self::RAW_OUTPUT_PATTERN does not match PHP's curly-brace dynamic
 *   property/method access syntax (`$result->{'output'}`,
 *   `$result->{'getOutput'}()`) — this is valid, semantically identical PHP
 *   that evades detection because the literal text `output`/`getOutput` is
 *   not immediately adjacent to `->` in the reconstructed argument text; it
 *   sits inside a separate string-literal token following a `{`. Unlike the
 *   whitespace/quote-style gaps documented above, this one has no
 *   independent backstop: re-derive via `php-cs-fixer describe
 *   object_operator_without_whitespace` (or any other CGL rule) that this
 *   repository's own `Symfony`/`PER-CS2x0` ruleset does NOT normalize
 *   curly-brace dynamic access away, so nothing upstream of this guard
 *   prevents the shape from being written. Confirmed via
 *   `grep -noF -- '->{' tests/GateTestCase.php tests/CheckJsConfigsTest.php
 *   tests/CheckJsConfigsManifestTest.php` (the `--` is required: without it,
 *   a pattern starting with `-` is parsed as an option, not the search
 *   text): no current call site in any of
 *   self::guardedFiles() uses this syntax. If this construct is ever
 *   intentionally introduced into a guarded file, the guard would need a
 *   targeted extension at that point — not before.
 * - self::RISKY_ASSERTIONS/self::SAFE_WRAP_CALLS matching requires an exact
 *   T_STRING token match on the call NAME itself, not on whatever precedes
 *   it — so a namespace-qualified STATIC call
 *   (`\PHPUnit\Framework\Assert::assertSame(...)`, or any other class-name
 *   qualifier before `::`) is still caught: `::` (T_DOUBLE_COLON) always
 *   breaks name-fusion, so the method name that follows it tokenizes as an
 *   ordinary, separate T_STRING no matter how the class name before it is
 *   spelled (plain T_STRING, T_NAME_QUALIFIED or T_NAME_FULLY_QUALIFIED).
 *   Confirmed via `php -r "var_dump(token_get_all('<?php
 *   \PHPUnit\Framework\Assert::assertSame(1,2);'));"`: the `assertSame`
 *   token is `[T_STRING, 'assertSame']`, identical in shape to the bare
 *   `self::assertSame(...)` form — see
 *   detectsARiskyAssertionCalledOnAFullyQualifiedClassName() below, which
 *   pins this. The only shape that genuinely evades detection is a
 *   namespaced FUNCTION call with no `::` at all (e.g. `Foo\assertSame(...)`),
 *   which collapses into a single opaque T_NAME_QUALIFIED token this guard
 *   never inspects — but no PHPUnit assertion is ever invoked that way
 *   (they are all static methods, always called via `::`), so this is a
 *   real but practically inapplicable gap for this guard's actual scope.
 *   Confirmed via `grep -noE '[A-Za-z0-9_]+::(assertSame|assertEquals|
 *   assertStringContainsString|assertStringNotContainsString|
 *   assertMatchesRegularExpression|assertDoesNotMatchRegularExpression)\('
 *   tests/GateTestCase.php tests/CheckJsConfigsTest.php
 *   tests/CheckJsConfigsManifestTest.php`: every real call site in
 *   self::guardedFiles() today is spelled with the bare `self::` prefix.
 * - self::stripBalancedCallsFromTokens() strips an ENTIRE self::SAFE_WRAP_CALLS
 *   call span as safe once the wrap NAME matches, with no notion that a wrap
 *   may scrub only SOME of its own arguments. messageOrDefault() is exactly
 *   that case: its non-empty-$message branch returns $message verbatim, with
 *   no scrub at all (unlike its $default/$output branch, which delegates to
 *   diagnosticMessage()) — a raw ->output value embedded in messageOrDefault()'s
 *   FIRST argument would be stripped as safe by this guard and never flagged.
 *   Pinned by doesNotFlagMessageOrDefaultsOwnUnscrubbedMessageArgument()
 *   below, whose assertion is deliberately the opposite of every sibling
 *   control: it proves the gap, not the guard's soundness, so it stays a
 *   re-derivable fact rather than a one-off manual claim. No current call
 *   site in self::guardedFiles() does this (every real messageOrDefault() call passes
 *   a developer-literal or empty string as $message), so nothing is missed
 *   today — but this guard's trust model, not just its pattern coverage, has
 *   a real gap here; a targeted fix would special-case messageOrDefault()'s
 *   argument index rather than trusting the wrap name alone.
 *
 * A determined future edit can still dodge this guard (e.g. reassigning
 * $result->output to a local variable first, then passing that variable) —
 * it catches the shape every real incident in this file's history actually
 * took, not every conceivable rephrasing of it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */
#[CoversNothing]
final class ScrubbedDiagnosticGuardTest extends GateTestCase
{
    /**
     * The PHPUnit assertion functions whose own subject/actual argument
     * leaks raw on a failure — via failureDescription() into getMessage()
     * for the first four, via a raw ComparisonFailure PHPUnit's CLI/text
     * printer renders (never getMessage(), for two string operands — see
     * this class's own docblock above for the type-mismatch exception) for
     * the last two; see this class's own docblock above for the distinction.
     */
    private const RISKY_ASSERTIONS = [
        'assertStringContainsString',
        'assertStringNotContainsString',
        'assertMatchesRegularExpression',
        'assertDoesNotMatchRegularExpression',
        'assertSame',
        'assertEquals',
    ];

    /**
     * The call names this guard accepts as already having scrubbed whatever
     * they wrap — see this class's own docblock for why this is a fixed,
     * unresolved name list rather than true call-graph resolution.
     */
    private const SAFE_WRAP_CALLS = [
        'scrubbedForDiagnostic',
        'diagnosticMessage',
        'messageOrDefault',
        'messageWithOutput',
    ];

    /**
     * The shapes a raw, unscrubbed value under test takes in this codebase:
     * a `$result->output` property access, one of the two Process accessor
     * methods (`->getOutput()`/`->getErrorOutput()`), a regex-capture
     * variable (`$matches[`), or an array-key access shaped like captured
     * subprocess output (`['stdout']`/`['stderr']`, the shape
     * runBuildToolsSeparated()'s own callers use in this file). Every `\s*`
     * around an operator/bracket here mirrors self::openParenIndexAfter()'s
     * own whitespace-skipping tolerance — without it, valid, compilable PHP
     * like `$result -> output` (spaces around `->`) or
     * `$result[ 'stdout' ]` (spaces inside brackets) would carry the exact
     * same leak yet go undetected by this regex alone, even though this
     * repository's own CGL step currently rejects that shape before this
     * guard is ever reached — re-derive which fixer does so, and from which
     * ruleset, via `php-cs-fixer describe object_operator_without_whitespace`/
     * `php-cs-fixer describe no_spaces_around_offset` (both report
     * "part of … Symfony", the ruleset php-cs-fixer/base.php's own
     * `setRules()` enables) rather than trusting this citation if that
     * ruleset ever changes.
     */
    private const RAW_OUTPUT_PATTERN = '/->\s*output\b|->\s*getOutput\s*\(|->\s*getErrorOutput\s*\(|\$matches\s*\[|\[\s*\'stdout\'\s*\]|\[\s*\'stderr\'\s*\]/';

    /**
     * Every failed accept/reject-pattern regression this file's history
     * fixed lived in exactly the files returned below. A new file added to
     * this suite that repeats the same biomeCi()/runTsc()-against-
     * PR-editable-config shape would need adding here too; this guard only
     * reads what it is told to.
     *
     * tests/Support/GateProcessTest.php's runCapturesStdout() (a plain
     * `self::assertStringContainsString('hello', $result->output)`) and
     * tests/GateTestCaseTest.php's own
     * theMessageCompositionHelpersComposeAsDocumented() (several assertSame()
     * calls against a hand-authored literal carrying `::error::`) share the
     * RISKY_ASSERTIONS shape this guard scans for, yet are deliberately left
     * out of the list below: both fixtures are author-controlled literals a
     * PR can never influence, not PR-editable content, so routing them
     * through the scrub helpers would be unnecessary churn rather than
     * closing a real gap.
     *
     * `tests/CheckCheckedExceptionsTest.php` and
     * `tests/CheckDisallowedCallsTest.php` are peer gate-suite classes
     * (AGENTS.md documents both) that DO carry the same unscrubbed-leak
     * shape today; they are deliberately NOT added below and NOT fixed as
     * part of this guard — that defect is tracked separately as #160.
     *
     * @return list<string>
     */
    private static function guardedFiles(): array
    {
        $root = self::root();

        return [
            "{$root}/tests/GateTestCase.php",
            "{$root}/tests/CheckJsConfigsTest.php",
            "{$root}/tests/CheckJsConfigsManifestTest.php",
        ];
    }

    /**
     * Tokenizes $source once via token_get_all(), then drops every
     * T_COMMENT/T_DOC_COMMENT token from the returned sequence entirely —
     * appending only the kept tokens onto a fresh array leaves it a plain,
     * contiguous-integer-indexed list every later helper can walk by index.
     * Without this, a comment or docblock merely quoting a risky-assertion
     * call as illustrative prose (this class's own docblock is exactly such
     * a case) would false-positive
     * self::findUnscrubbedRawOutputAssertions() below; dropping the token
     * outright (rather than blanking its text in a reconstructed string, the
     * byte-mask approach this file used previously) means a later step never
     * sees it at all.
     *
     * @param string $source The PHP source to tokenize.
     *
     * @return list<string|array{0: int, 1: string, 2: int}> The token list token_get_all() returns for $source, comments removed.
     */
    private static function significantTokens(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && (($token[0] === T_COMMENT) || ($token[0] === T_DOC_COMMENT))) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * A single token's own source text, regardless of whether token_get_all()
     * represented it as a plain one-character string (every structural
     * punctuation token, `(`/`)`/`,`/`;`/… included) or as the
     * `array{0: int, 1: string, 2: int}` shape it uses for everything else.
     *
     * This collapses the array/non-array distinction, so it must only be used
     * for TEXT RECONSTRUCTION (self::tokensToText()) — never to test whether a
     * token IS a genuine anonymous `(`/`)` punctuation token, which
     * self::isRawParenToken() below checks directly on the raw token instead;
     * see that method's own docblock for why an array-shaped fragment can
     * never satisfy that check even when its collapsed text here happens to
     * equal `(`/`)` — comparing THIS method's collapsed return value against
     * `'('`/`')'` would treat that fragment identically to a real bare
     * punctuation token, which is exactly the live-reproduced depth-miscount
     * self::isRawParenToken() exists to foreclose.
     *
     * @param string|array{0: int, 1: string, 2: int} $token One entry from self::significantTokens()'s output.
     *
     * @return string The token's own source text.
     */
    private static function tokenText(string|array $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /**
     * Whether $token is a genuine, anonymous single-character `$char`
     * punctuation token — checked on the RAW token, never through
     * self::tokenText()'s collapsed text. PHP's own token_get_all() returns
     * an anonymous single-character punctuation token (`(`, `)`, `,`, `;`, …)
     * as a bare PHP string, never as an array; every array-shaped token is
     * therefore excluded here by construction, regardless of what its text
     * looks like.
     *
     * This is the canonical explanation for why an array-shaped token can
     * never satisfy this check, referenced by name from every other site in
     * this class that relies on it: token_get_all() splits a double-quoted
     * string or non-nowdoc heredoc at every interpolation point, and the
     * literal-text fragment it emits between two such points becomes its own
     * ARRAY-shaped T_ENCAPSED_AND_WHITESPACE token — one whose own text CAN
     * legitimately equal a single `(`/`)` character when that is the only
     * literal text in the segment (e.g. the fragment right after `{$id}` in
     * `"count ({$id})"`, whose text is exactly `)`). A heredoc/nowdoc BODY
     * tokenizes the same way, as does every other multi-character token
     * (T_CONSTANT_ENCAPSED_STRING, T_STRING, a cast token like `(int)`, …).
     * `!is_array($token)` excludes all of these uniformly, regardless of what
     * their own text looks like — a `(`/`)` BYTE inside one is never itself a
     * separate `(`/`)` punctuation token to begin with, because PHP's own
     * tokenizer already carves such content into its own atomic, array-shaped
     * token.
     *
     * @param string|array{0: int, 1: string, 2: int} $token One entry from self::significantTokens()'s output.
     * @param string                                  $char  The single punctuation character to test for (`(` or `)`).
     *
     * @return bool True when $token is a real, bare `$char` punctuation token.
     */
    private static function isRawParenToken(string|array $token, string $char): bool
    {
        return !is_array($token) && ($token === $char);
    }

    /**
     * Finds the index of the next non-T_WHITESPACE token at or after
     * $fromIndex, so a caller can look past insignificant whitespace between
     * a call name and its opening `(` without also skipping past a
     * significant token.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens    The output of self::significantTokens().
     * @param int                                           $fromIndex The index to start scanning from (inclusive).
     *
     * @return int|null The index of the next non-whitespace token, or null if $tokens ends first.
     */
    private static function nextNonWhitespaceIndex(array $tokens, int $fromIndex): ?int
    {
        $count = count($tokens);

        for ($i = $fromIndex; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (is_array($token) && ($token[0] === T_WHITESPACE)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Given the index of a T_STRING token naming a call, finds the index of
     * that call's own opening `(` — but only if the very next significant
     * token actually IS a genuine, bare `(` punctuation token, checked via
     * self::isRawParenToken() (see that method's own docblock for why an
     * array-shaped fragment can never be mistaken for one); a bare identifier
     * with no call following it (or followed by something else entirely) is
     * not a call at all, so this returns null rather than a wrong index.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens    The output of self::significantTokens().
     * @param int                                           $nameIndex The index of the T_STRING token naming the candidate call.
     *
     * @return int|null The index of the matching `(` token, or null when $nameIndex is not actually a call.
     */
    private static function openParenIndexAfter(array $tokens, int $nameIndex): ?int
    {
        $index = self::nextNonWhitespaceIndex($tokens, $nameIndex + 1);

        if (($index === null) || !self::isRawParenToken($tokens[$index], '(')) {
            return null;
        }

        return $index;
    }

    /**
     * Finds the index of the closing `)` balancing the `(` at
     * $openParenIndex. Depth-counts only a token that self::isRawParenToken()
     * confirms is a genuine, bare `(`/`)` punctuation token — see that
     * method's own docblock for why an array-shaped fragment can never
     * satisfy that check, so depth-counting here is safe for interpolated
     * strings and heredoc/nowdoc bodies too, and can no longer mis-balance
     * the extent this returns the way a byte-level scan over reconstructed
     * text could.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens         The output of self::significantTokens().
     * @param int                                           $openParenIndex The index of the opening `(` token.
     *
     * @return int|null The index of the matching `)` token, or null if $tokens ends before depth returns to 0.
     */
    private static function matchingCloseParenIndex(array $tokens, int $openParenIndex): ?int
    {
        $depth = 1;
        $count = count($tokens);

        for ($i = $openParenIndex + 1; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (self::isRawParenToken($token, '(')) {
                ++$depth;
            } elseif (self::isRawParenToken($token, ')')) {
                --$depth;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Concatenates each token's own text back into a plain string, in order
     * — used only to reconstruct the text of an already-located token span
     * (a call's own argument list) for self::RAW_OUTPUT_PATTERN, never to
     * re-scan that reconstructed text for a nested call: every call/paren
     * lookup in this class walks the token array directly instead.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens A token span, e.g. from array_slice().
     *
     * @return string The span's own source text.
     */
    private static function tokensToText(array $tokens): string
    {
        $text = '';

        foreach ($tokens as $token) {
            $text .= self::tokenText($token);
        }

        return $text;
    }

    /**
     * Removes every balanced `$funcName(...)` call from $tokens — the call
     * name token through its matching closing `)` token, inclusive — leaving
     * every other token untouched and in order. $funcName must match a
     * T_STRING token's own text EXACTLY, never as a substring: PHP's own
     * tokenizer already emits a whole identifier as one token, so a
     * differently-named identifier that merely contains $funcName (e.g.
     * `xscrubbedForDiagnostic` containing `scrubbedForDiagnostic`) can no
     * longer be mistaken for it the way a byte-level `strpos($text,
     * "{$funcName}(")` needle search could — see this class's own docblock
     * for the incident this structurally forecloses.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens   The token span to strip $funcName(...) calls from.
     * @param string                                        $funcName The bare call name to strip (no `self::` prefix — see this class's own docblock).
     *
     * @return list<string|array{0: int, 1: string, 2: int}> The input $tokens with every balanced $funcName(...) call removed.
     */
    private static function stripBalancedCallsFromTokens(array $tokens, string $funcName): array
    {
        $result = [];
        $count  = count($tokens);
        $i      = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_array($token) && ($token[0] === T_STRING) && ($token[1] === $funcName)) {
                $openParenIndex = self::openParenIndexAfter($tokens, $i);

                if ($openParenIndex !== null) {
                    $closeParenIndex = self::matchingCloseParenIndex($tokens, $openParenIndex);

                    if ($closeParenIndex !== null) {
                        $i = $closeParenIndex + 1;

                        continue;
                    }
                }
            }

            $result[] = $token;
            ++$i;
        }

        return $result;
    }

    /**
     * Strips every self::SAFE_WRAP_CALLS name's balanced call out of
     * $tokens, one wrap name at a time, so whatever self::tokensToText()
     * reconstructs afterwards carries only the argument text NOT already
     * covered by a sanctioned scrub wrap.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens A call's own argument-list token span.
     *
     * @return list<string|array{0: int, 1: string, 2: int}> The input $tokens with every sanctioned wrap call removed.
     */
    private static function stripSafeWraps(array $tokens): array
    {
        foreach (self::SAFE_WRAP_CALLS as $wrap) {
            $tokens = self::stripBalancedCallsFromTokens($tokens, $wrap);
        }

        return $tokens;
    }

    /**
     * Given the index of a candidate call-name token, locates that call's own
     * argument-token span by chaining self::openParenIndexAfter() (find the
     * `(` immediately following the name) and self::matchingCloseParenIndex()
     * (find the balancing `)`) — the exact "locate a call, extract its own
     * argument tokens" sequence self::findUnscrubbedRawOutputAssertions() and
     * self::strippedArgumentTextFor() both need. Sharing it here means a
     * future fix to either chained lookup (this file's own history already
     * shows self::openParenIndexAfter() and self::matchingCloseParenIndex()
     * each independently fixing a live-reproduced bypass) only needs ONE
     * call site updated, not two kept in sync by hand.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens    The output of self::significantTokens().
     * @param int                                           $nameIndex The index of the token naming the candidate call.
     *
     * @return array{0: list<string|array{0: int, 1: string, 2: int}>, 1: int}|null A [argument tokens, closing `)` index] pair, or null when $nameIndex is not actually a call.
     */
    private static function callArgumentTokensAt(array $tokens, int $nameIndex): ?array
    {
        $openParenIndex = self::openParenIndexAfter($tokens, $nameIndex);

        if ($openParenIndex === null) {
            return null;
        }

        $closeParenIndex = self::matchingCloseParenIndex($tokens, $openParenIndex);

        if ($closeParenIndex === null) {
            return null;
        }

        $argumentTokens = array_slice($tokens, $openParenIndex + 1, $closeParenIndex - $openParenIndex - 1);

        return [$argumentTokens, $closeParenIndex];
    }

    /**
     * Scans $path for every call to one of self::RISKY_ASSERTIONS and, for
     * each one, strips every self::SAFE_WRAP_CALLS wrap from its own
     * argument list — the message argument included, since a hand-written
     * custom message embedding raw output unscrubbed is the SAME defect
     * class as a hand-rolled message that skips the scrub helper, not merely
     * the PHPUnit auto-export mechanism. Whatever self::tokensToText()
     * reconstructs from what remains is checked against
     * self::RAW_OUTPUT_PATTERN; a match is a finding. $path is tokenized
     * once via self::significantTokens() (comments already gone) and every
     * call/paren lookup below walks that same token array by index — no
     * byte offset, no reconstructed intermediate string, no parallel mask to
     * keep aligned.
     *
     * @param string $path Absolute path to the PHP source file to scan.
     *
     * @return list<string> One description per finding, empty when none.
     */
    private static function findUnscrubbedRawOutputAssertions(string $path): array
    {
        $tokens   = self::significantTokens((string) file_get_contents($path));
        $count    = count($tokens);
        $findings = [];

        foreach (self::RISKY_ASSERTIONS as $assertionName) {
            $i = 0;

            while ($i < $count) {
                $token = $tokens[$i];

                if (!is_array($token) || ($token[0] !== T_STRING) || ($token[1] !== $assertionName)) {
                    ++$i;

                    continue;
                }

                $callArguments = self::callArgumentTokensAt($tokens, $i);

                if ($callArguments === null) {
                    ++$i;

                    continue;
                }

                [$argumentTokens, $closeParenIndex] = $callArguments;
                $strippedText                       = self::tokensToText(self::stripSafeWraps($argumentTokens));

                if (preg_match(self::RAW_OUTPUT_PATTERN, $strippedText) === 1) {
                    $line       = $token[2];
                    $findings[] = "{$path}:{$line}: {$assertionName}(" . self::tokensToText($argumentTokens) . ')';
                }

                $i = $closeParenIndex + 1;
            }
        }

        return $findings;
    }

    /**
     * Writes $phpSource to $filename inside this test's fixture directory,
     * then scans it via self::findUnscrubbedRawOutputAssertions() — the
     * "write a fixture file, scan it" shape every self-test below (this
     * guard's own controls, proving it actually discriminates rather than
     * always returning the same result regardless of input) repeated
     * independently before this existed. Each caller keeps its own distinct
     * $filename/$phpSource/assertion; only this boilerplate collapses.
     *
     * @param string $filename  The fixture file's bare name, written under this test's own fixture directory.
     * @param string $phpSource The PHP source to write into it.
     *
     * @return list<string> One description per finding, empty when none.
     */
    private function findingsFor(string $filename, string $phpSource): array
    {
        $path = "{$this->fixture()->path()}/{$filename}";
        file_put_contents($path, $phpSource);

        return self::findUnscrubbedRawOutputAssertions($path);
    }

    /**
     * Exposes the STRIPPED argument text self::findUnscrubbedRawOutputAssertions()
     * matches self::RAW_OUTPUT_PATTERN against internally, for the FIRST
     * self::RISKY_ASSERTIONS call found in $phpSource — the finding string
     * that method itself returns is built from the UNSTRIPPED argument
     * tokens instead, so it cannot tell a correct strip (leaving only a
     * genuinely unwrapped trailing access) apart from a mis-parse that
     * happens to still match self::RAW_OUTPUT_PATTERN for the wrong reason
     * (e.g. because a sanctioned wrap's own already-scrubbed content was
     * never actually stripped). A test asserting on THIS return value can
     * make that distinction where a bare "some finding fired" check cannot —
     * see doesNotMisbalanceOnAClosingParenEmbeddedInAWrapsOwnHeredocArgument()
     * for the case this exists for.
     *
     * @param string $phpSource PHP source containing exactly one self::RISKY_ASSERTIONS call.
     *
     * @return string The stripped argument text self::RAW_OUTPUT_PATTERN is actually matched against.
     */
    private static function strippedArgumentTextFor(string $phpSource): string
    {
        $tokens = self::significantTokens($phpSource);
        $count  = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!is_array($token) || ($token[0] !== T_STRING) || !in_array($token[1], self::RISKY_ASSERTIONS, true)) {
                continue;
            }

            $callArguments = self::callArgumentTokensAt($tokens, $i);

            if ($callArguments === null) {
                continue;
            }

            return self::tokensToText(self::stripSafeWraps($callArguments[0]));
        }

        self::fail('No self::RISKY_ASSERTIONS call was found in the given fixture source.');
    }

    /**
     * The regression guard itself: none of the files self::guardedFiles()
     * lists may call one of self::RISKY_ASSERTIONS with a raw, unscrubbed
     * subprocess-output accessor anywhere in its own argument list. A future
     * call site that
     * reintroduces the shape (rather than following the
     * self::scrubbedForDiagnostic()/diagnosticMessage()/messageOrDefault()
     * pattern, or the manual `if (...) { self::fail(...) }` shape this
     * guard deliberately does not police — see this class's own docblock
     * for why) fails this test instead of shipping silently.
     */
    #[Test]
    public function noRiskyAssertionCarriesUnscrubbedSubprocessOutput(): void
    {
        $findings = [];

        foreach (self::guardedFiles() as $file) {
            $findings = [...$findings, ...self::findUnscrubbedRawOutputAssertions($file)];
        }

        self::assertSame(
            [],
            $findings,
            "A PHPUnit assertion's own argument list carries a raw, unscrubbed "
                . "subprocess-output accessor (see this class's own docblock for the "
                . "defect class this guards against):\n" . implode("\n", $findings),
        );
    }

    /**
     * The guard's own control: without it, an intentionally reintroduced raw
     * `assertSame(0, $result->exitCode, "…\n{$result->output}")`-shaped call
     * embedded in a throwaway fixture string (never written to a real file,
     * so the actual suite's own content is untouched) would go undetected —
     * proving self::findUnscrubbedRawOutputAssertions() actually discriminates
     * rather than always returning an empty list regardless of input.
     */
    #[Test]
    public function detectsAnIntentionallyReintroducedRawOutputAssertion(): void
    {
        $findings = $this->findingsFor(
            'poisoned-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, "boom\n{$result->output}");
            PHP,
        );

        self::assertNotEmpty($findings, 'The guard did not flag a deliberately unscrubbed assertSame() call — it is not exercising the check it claims to.');
    }

    /**
     * The guard's own second control, for the sanctioned wrap itself: a call
     * whose only `->output` access is inside a
     * self::scrubbedForDiagnostic()/diagnosticMessage()/messageOrDefault()
     * wrap must NOT be flagged — without this, a guard that flagged
     * anything merely mentioning `->output` (rather than an UNWRAPPED one)
     * would fail every legitimate call site in this suite.
     */
    #[Test]
    public function doesNotFlagAProperlyScrubbedAssertion(): void
    {
        $findings = $this->findingsFor(
            'clean-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, self::diagnosticMessage('label', $result->output));
            PHP,
        );

        self::assertSame([], $findings, 'The guard flagged a call whose only ->output access is inside a sanctioned scrub wrap.');
    }

    /**
     * The guard's own third control, for the fourth sanctioned wrap:
     * messageWithOutput() was added after self::SAFE_WRAP_CALLS was first
     * written and, like the other three wraps, must not be flagged when it
     * is the ONLY thing carrying a risky assertion's `->output` access —
     * without this, a future edit dropping 'messageWithOutput' back out of
     * self::SAFE_WRAP_CALLS would false-positive on every real call site
     * using it undetected by this suite.
     */
    #[Test]
    public function doesNotFlagAnAssertionScrubbedViaMessageWithOutput(): void
    {
        $findings = $this->findingsFor(
            'clean-message-with-output-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $result->exitCode, self::messageWithOutput('', 'label', $result->output));
            PHP,
        );

        self::assertSame([], $findings, 'The guard flagged a call whose only ->output access is inside self::messageWithOutput().');
    }

    /**
     * Pins a KNOWN, accepted gap in this class's own docblock (the
     * self::SAFE_WRAP_CALLS/messageOrDefault() trust-model paragraph): once
     * self::stripBalancedCallsFromTokens() matches the wrap NAME, it strips
     * the WHOLE call span, with no notion that messageOrDefault()'s
     * non-empty-$message branch never scrubs that argument. This assertion
     * is deliberately the OPPOSITE of every sibling control above — it
     * proves the gap exists, not that the guard is sound — so this class's
     * own docblock's "live-reproduced" claim stays a real, re-derivable fact
     * rather than an unfalsifiable one. If self::stripBalancedCallsFromTokens()
     * is ever made argument-aware for messageOrDefault(), $findings below
     * MUST start reporting this call site, and this test's own assertion
     * needs updating in lockstep with that docblock paragraph.
     */
    #[Test]
    public function doesNotFlagMessageOrDefaultsOwnUnscrubbedMessageArgument(): void
    {
        $findings = $this->findingsFor(
            'messageordefault-first-argument-leak.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $x, self::messageOrDefault('prefix: ' . $result->output, 'default', $result->output));
            PHP,
        );

        self::assertSame(
            [],
            $findings,
            "This is the accepted gap, not a regression: messageOrDefault()'s "
            . 'first argument is never scrubbed, and this guard cannot see that '
            . 'a sanctioned wrap only conditionally scrubs its own arguments.',
        );
    }

    /**
     * self::RAW_OUTPUT_PATTERN's `$matches[` alternative: a risky assertion
     * comparing a regex-capture variable directly (the exact shape
     * assertReadmeToolVersionMatchesDevDependenciesPin() carried before it
     * was converted to a manual mismatch check + self::fail()) must be
     * flagged, not just the `->output`/`->getOutput()`/`->getErrorOutput()`
     * accessors.
     */
    #[Test]
    public function detectsARiskyAssertionUsingARegexCaptureVariable(): void
    {
        $findings = $this->findingsFor(
            'poisoned-matches-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame($matches[1], $actual, 'boom');
            PHP,
        );

        self::assertNotEmpty($findings, 'The guard did not flag a risky assertion using a regex-capture variable ($matches[1]) as its raw operand.');
    }

    /**
     * self::RAW_OUTPUT_PATTERN's `['stdout']`/`['stderr']` alternative: a
     * risky assertion comparing an array-key access shaped like captured
     * subprocess output — the real shape runBuildToolsSeparated()'s own
     * callers use in tests/CheckJsConfigsTest.php — must be flagged too.
     */
    #[Test]
    public function detectsARiskyAssertionUsingAnArrayKeyAccess(): void
    {
        $findings = $this->findingsFor(
            'poisoned-array-key-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame('typescript@5.0.16', trim($result['stdout']));
            PHP,
        );

        self::assertNotEmpty($findings, "The guard did not flag a risky assertion using an array-key access (\$result['stdout']) as its raw operand.");
    }

    /**
     * self::significantTokens()'s own control: a docblock or comment merely
     * mentioning a risky-assertion call in prose (exactly the shape this
     * class's own docblock and several method docblocks in this suite use)
     * must NOT be flagged — without dropping every T_COMMENT/T_DOC_COMMENT
     * token first, this guard would false-positive on its own
     * documentation.
     */
    #[Test]
    public function doesNotFlagARiskyAssertionMentionedOnlyInAComment(): void
    {
        $findings = $this->findingsFor(
            'commented-mention-fixture.php',
            <<<'PHP'
            <?php

            /**
             * See self::assertSame($matches[1], $result['stdout'], $result->output) for
             * an illustrative example of the shape this guard rejects — never actually
             * called here.
             */
            // Also mentioned in a single-line comment: assertSame($result->output, $x);
            final class CommentedMentionFixture
            {
            }
            PHP,
        );

        self::assertSame([], $findings, 'The guard flagged a risky-assertion call that only appears inside a comment/docblock, never as real code.');
    }

    /**
     * self::matchingCloseParenIndex()'s own control for an unmatched opening
     * paren inside a sanctioned wrap's OWN string argument: without
     * tokenizing string literals, a `(` embedded in
     * self::scrubbedForDiagnostic()'s own argument text overruns that call's
     * true closing `)` and swallows the rest of the containing risky
     * assertion's argument list — including the trailing, genuinely
     * unwrapped `$result->output` below — as if it were already inside the
     * sanctioned wrap, so self::stripBalancedCallsFromTokens() strips the
     * whole span and RAW_OUTPUT_PATTERN never sees the real leak.
     * Live-reproduced against this guard before string literals became
     * atomic, opaque tokens; see this class's own docblock.
     */
    #[Test]
    public function detectsARiskyAssertionWhoseWrapArgumentCarriesAnUnmatchedOpeningParen(): void
    {
        $findings = $this->findingsFor(
            'unmatched-paren-in-wrap-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $x, self::scrubbedForDiagnostic("unbalanced ( paren") . $result->output);
            PHP,
        );

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion whose sanctioned-wrap argument carries an unmatched opening '
                . 'paren, even though a genuinely unwrapped $result->output follows it in the same argument list.',
        );
    }

    /**
     * Live-reproduced against this file's earlier byte-mask-plus-strpos()
     * mechanism, before the token-array rewrite (self::significantTokens()/
     * self::openParenIndexAfter()/self::matchingCloseParenIndex()): a sanctioned
     * wrap-call NAME appearing merely as decoy TEXT inside a PRECEDING
     * string literal (never a real call) was matched by a plain needle
     * search as if it were one, then the prior mechanism's balanced-call
     * extractor — invoked as though the matched position were a real
     * opening `(` when it was actually inside a masked string-literal span
     * — never returned to depth 0 within that same string and consumed
     * forward past it, swallowing the genuinely unwrapped `$result->output`
     * that follows along with it. Locating a candidate by T_STRING token
     * instead of a byte-level needle search forecloses this structurally:
     * the tokenizer never emits a T_STRING token for text inside a string
     * literal.
     */
    #[Test]
    public function detectsARiskyAssertionDisguisedByADecoyWrapNameInAPrecedingStringLiteral(): void
    {
        $findings = $this->findingsFor(
            'decoy-wrap-name-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $x, 'Use messageOrDefault(...) to build this: ' . $result->output);
            PHP,
        );

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion whose message argument merely MENTIONS a sanctioned wrap '
                . 'name as decoy text inside a preceding string literal, even though a genuinely unwrapped '
                . '$result->output follows it in the same argument list.',
        );
    }

    /**
     * self::stripBalancedCallsFromTokens()'s own identifier-boundary
     * control: a helper whose name merely ENDS WITH a sanctioned wrap name
     * (`xscrubbedForDiagnostic`, not the real `scrubbedForDiagnostic`) must
     * NOT be treated as the sanctioned wrap — proving the T_STRING match is
     * exact, not the substring match a byte-level `strpos($text,
     * "scrubbedForDiagnostic(")` needle search would have performed. No
     * such collision exists in this codebase today; this guards against one
     * a future helper could introduce.
     */
    #[Test]
    public function doesNotConfuseAHelperNameThatMerelyEndsWithASanctionedWrapName(): void
    {
        $findings = $this->findingsFor(
            'substring-identifier-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $x, self::xscrubbedForDiagnostic($result->output));
            PHP,
        );

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion whose raw $result->output is wrapped only by a helper whose '
                . 'name merely ENDS WITH a sanctioned wrap name (xscrubbedForDiagnostic), not the sanctioned '
                . 'scrubbedForDiagnostic() itself — the match must be exact, not a substring.',
        );
    }

    /**
     * The closing-paren half of self::matchingCloseParenIndex()'s
     * string-literal-safety, mirroring
     * detectsARiskyAssertionWhoseWrapArgumentCarriesAnUnmatchedOpeningParen()'s
     * already-covered opening-paren half — using a NOWDOC (`<<<'MSG'`) for
     * the wrap's own string argument, doubling as this class's own heredoc/
     * nowdoc-content regression case: verified via token_get_all() that a
     * nowdoc BODY tokenizes as T_ENCAPSED_AND_WHITESPACE — see
     * self::isRawParenToken()'s own docblock for why that array-shaped token
     * can never satisfy it — so its embedded CLOSING `)` (`Looks good :)`)
     * must not be mistaken for the wrap call's own closing paren either. The
     * wrap call's true extent — through the real `)` right after its own
     * `$result->output` argument — must still be correctly found and
     * stripped, leaving the trailing, genuinely unwrapped `. $result->output`
     * that follows it detected.
     */
    #[Test]
    public function doesNotMisbalanceOnAClosingParenEmbeddedInAWrapsOwnHeredocArgument(): void
    {
        $phpSource = <<<'PHP'
            <?php
            self::assertSame(0, $x, self::messageWithOutput($message, <<<'MSG'
            Looks good :)
            MSG, $result->output) . $result->output);
            PHP;

        $findings = $this->findingsFor('closing-paren-in-wrap-nowdoc-fixture.php', $phpSource);

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion whose sanctioned-wrap OWN nowdoc argument carries an '
                . 'embedded closing paren, even though a genuinely unwrapped $result->output follows the wrap '
                . 'call in the same argument list.',
        );

        // self::assertNotEmpty() alone is not discriminating: it passes whether the guard correctly
        // identifies ONLY the genuinely-unwrapped trailing $result->output, or incorrectly leaves the
        // wrap's own already-scrubbed nowdoc body in the reconstructed text too — either mis-parse still
        // produces "some non-empty finding". Assert on the STRIPPED text itself instead, so a mis-parse
        // that keeps the wrap's own body is caught even though it would still satisfy assertNotEmpty().
        $strippedText = self::strippedArgumentTextFor($phpSource);

        self::assertStringNotContainsString(
            'Looks good',
            $strippedText,
            'The stripped argument text still carries the sanctioned wrap\'s own nowdoc body — '
                . 'self::stripSafeWraps() failed to remove the whole messageWithOutput(...) call, not just '
                . 'happened to still match self::RAW_OUTPUT_PATTERN for an unrelated reason.',
        );
        self::assertSame(
            1,
            preg_match(self::RAW_OUTPUT_PATTERN, $strippedText),
            'The stripped argument text does not carry the genuinely unwrapped trailing $result->output at all — '
                . "actual stripped text: {$strippedText}",
        );
    }

    /**
     * Live-reproduced against the token-array rewrite before
     * self::isRawParenToken() existed: this call's message argument
     * interpolates a variable immediately followed by a lone `)`
     * character — the exact array-shaped T_ENCAPSED_AND_WHITESPACE
     * interpolation-boundary fragment shape self::isRawParenToken()'s own
     * docblock explains (using the same `"count ({$id})"`-shaped example).
     * Before that method existed, self::tokenText() collapsed the
     * fragment's array-ness away, so comparing its collapsed text against
     * `')'` treated it identically to a genuine bare punctuation token,
     * making self::matchingCloseParenIndex() report depth-0 (a "closed
     * call") right there — long before the assertion's TRUE closing `)`,
     * which comes after `. $result->output`. The extracted argument span
     * was truncated before ever reaching the trailing, genuinely unwrapped
     * `$result->output`, so this call previously produced NO finding.
     */
    #[Test]
    public function detectsARiskyAssertionWithRawOutputAfterAnInterpolatedStringWhoseSegmentIsALoneClosingParen(): void
    {
        $findings = $this->findingsFor(
            'interpolated-lone-close-paren-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $id, "unexpected count ({$id})" . $result->output);
            PHP,
        );

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion whose message argument interpolates a variable immediately '
                . 'followed by a lone `)` character (an array-shaped T_ENCAPSED_AND_WHITESPACE fragment, not a real '
                . 'closing paren token), even though a genuinely unwrapped $result->output follows it in the same '
                . 'argument list.',
        );
    }

    /**
     * The opening-paren counterpart of the previous test, this time the fake
     * punctuation token sits inside a SANCTIONED WRAP's own interpolated
     * string argument: `self::scrubbedForDiagnostic("prefix {$a}(")`
     * produces the same array-shaped T_ENCAPSED_AND_WHITESPACE fragment
     * shape self::isRawParenToken()'s own docblock explains, here with text
     * exactly `(` right after `{$a}`. Before self::isRawParenToken() existed,
     * self::tokenText() collapsed that fragment's array-ness away, inflating
     * the depth count for the whole outer span so it never returned to 0 —
     * self::matchingCloseParenIndex() returned null, and
     * self::findUnscrubbedRawOutputAssertions()'s own
     * `if ($closeParenIndex === null) { ++$i; continue; }` guard silently
     * skipped the ENTIRE call, producing NO finding despite the plainly
     * unwrapped trailing `$result->output`.
     */
    #[Test]
    public function detectsARiskyAssertionWhereASanctionedWrapsInterpolatedStringSegmentIsALoneOpeningParen(): void
    {
        $findings = $this->findingsFor(
            'interpolated-lone-open-paren-in-wrap-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $x, self::scrubbedForDiagnostic("prefix {$a}(") . $result->output);
            PHP,
        );

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion whose sanctioned-wrap own interpolated string argument '
                . 'contains a variable immediately followed by a lone `(` character (an array-shaped '
                . 'T_ENCAPSED_AND_WHITESPACE fragment, not a real opening paren token), even though a genuinely '
                . 'unwrapped $result->output follows the wrap call in the same argument list.',
        );
    }

    /**
     * Pins the corrected understanding this class's own docblock documents:
     * a namespace-qualified STATIC call is still caught, because `::`
     * (T_DOUBLE_COLON) always breaks name-fusion, so the method name after
     * it tokenizes as an ordinary, separate T_STRING regardless of how the
     * class name before it is spelled. Without this test, a future edit
     * narrowing self::findUnscrubbedRawOutputAssertions() to require a
     * specific token immediately before the T_STRING (e.g. only `self::`)
     * could silently stop matching this shape.
     */
    #[Test]
    public function detectsARiskyAssertionCalledOnAFullyQualifiedClassName(): void
    {
        $findings = $this->findingsFor(
            'fully-qualified-static-call-fixture.php',
            <<<'PHP'
            <?php
            \PHPUnit\Framework\Assert::assertSame(0, $result->exitCode, "boom\n{$result->output}");
            PHP,
        );

        self::assertNotEmpty(
            $findings,
            'The guard did not flag a risky assertion called on a fully-qualified class name '
                . '(\PHPUnit\Framework\Assert::assertSame(...)) — the method name after `::` still '
                . 'tokenizes as a plain T_STRING, so this shape must be caught exactly like the bare '
                . 'self:: form.',
        );
    }

    /**
     * self::RAW_OUTPUT_PATTERN's own whitespace-tolerance control: valid,
     * compilable PHP may put whitespace around the `->` operator
     * (`$result -> output`), between a method name and its own opening paren
     * (`getOutput ()` — the same kind of gap self::openParenIndexAfter()
     * already skips over via self::nextNonWhitespaceIndex() when it locates
     * a RISKY_ASSERTIONS/SAFE_WRAP_CALLS call's own `(`), or inside a bracket
     * pair (`$result[ 'stdout' ]`). Valid PHP allows all three, so the regex
     * must tolerate them too for each of its six alternatives, not rely on
     * this repository's own CGL step to keep such whitespace from ever
     * reaching this guard (see self::RAW_OUTPUT_PATTERN's own docblock for
     * which fixer that is today and how to re-derive it). A regression
     * narrowing or dropping the `\s*` from any one alternative could ship
     * silently, so one data-provider row per alternative closes that gap
     * without six near-identical test methods.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function whitespaceTolerantRawOutputShapes(): array
    {
        return [
            '->\s*output' => [
                'whitespace-around-object-operator-fixture.php',
                <<<'PHP'
                <?php
                self::assertSame(0, $x, "boom " . $result -> output);
                PHP,
            ],
            '->\s*getOutput\s*\(' => [
                'whitespace-getoutput-fixture.php',
                <<<'PHP'
                <?php
                self::assertSame('x', $x, $result -> getOutput ());
                PHP,
            ],
            '->\s*getErrorOutput\s*\(' => [
                'whitespace-geterroroutput-fixture.php',
                <<<'PHP'
                <?php
                self::assertSame('x', $x, $result -> getErrorOutput ());
                PHP,
            ],
            '\$matches\s*\[' => [
                'whitespace-matches-fixture.php',
                <<<'PHP'
                <?php
                self::assertSame($matches [1], $x, 'boom');
                PHP,
            ],
            "[\s*'stdout'\s*]" => [
                'whitespace-stdout-fixture.php',
                <<<'PHP'
                <?php
                self::assertSame('x', $x, $result[ 'stdout' ]);
                PHP,
            ],
            "[\s*'stderr'\s*]" => [
                'whitespace-stderr-fixture.php',
                <<<'PHP'
                <?php
                self::assertSame('x', $x, $result[ 'stderr' ]);
                PHP,
            ],
        ];
    }

    /**
     * Verifies each `\s*`-widened self::RAW_OUTPUT_PATTERN alternative — the
     * six self::whitespaceTolerantRawOutputShapes() rows this method is
     * driven by — is still detected.
     *
     * @param string $filename  The fixture file's bare name, written under this test's own fixture directory.
     * @param string $phpSource PHP source carrying the whitespace-varied raw-output shape under test.
     */
    #[Test]
    #[DataProvider('whitespaceTolerantRawOutputShapes')]
    public function detectsEachWhitespaceTolerantRawOutputPatternAlternative(string $filename, string $phpSource): void
    {
        $findings = $this->findingsFor($filename, $phpSource);

        self::assertNotEmpty(
            $findings,
            "The guard did not flag a whitespace-varied raw-output shape ({$filename}), even though "
                . 'self::RAW_OUTPUT_PATTERN was widened with \s* specifically to tolerate it.',
        );
    }
}
