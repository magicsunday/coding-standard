<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\CodingStandard\Test;

use PHPUnit\Framework\AssertionFailedError;
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
use function is_file;
use function preg_match;
use function preg_match_all;
use function token_get_all;

use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ELLIPSIS;
use const T_STRING;
use const T_WHITESPACE;

/**
 * A structural, grep-shaped regression guard against a PHPUnit assertion (or
 * a self::fail()) whose own call — subject/actual argument OR a hand-written
 * custom message — carries a raw
 * `$result->output`/`->getOutput()`/`->getErrorOutput()` access, run against
 * PR-editable content (this repository's own biome/base.json,
 * tsconfig/base.json, package.json, templates/jscpd.json, or subprocess
 * output produced against them). The string-containment, regex, assertSame()
 * and assertEquals() assertions leak the raw subject/actual operand on a
 * failure, but through TWO DIFFERENT PHPUnit mechanisms, and wrapping only a
 * custom $message in self::scrubbedForDiagnostic() suppresses neither: the
 * containment and regex ones embed the raw operand straight into
 * getMessage(); assertSame()/assertEquals() on two STRING operands instead
 * attach a SebastianBergmann\Comparator\ComparisonFailure that only
 * PHPUnit's own CLI/text printer renders, never getMessage() — EXCEPT a
 * TYPE-MISMATCHED comparison (e.g. one operand `null`), which reaches
 * getMessage() by a third path instead. Both dated observations, their
 * re-derivation commands, and that exception live in
 * tests/CheckJsConfigsTest.php's own assertMessageDoesNotForgeWorkflowCommand()
 * and readmeToolVersionLockstepFailsWithoutForgingAWorkflowCommand()
 * docblocks respectively, not repeated here. Every other assertion leaks
 * through its message alone, which reaches getMessage() verbatim.
 *
 * Which calls are guarded is a PATTERN, not a list (#164,
 * self::GUARDED_CALL_PATTERN): every `assert*()` call — PHPUnit's own
 * (assertTrue(), assertCount(), …) and every suite-local helper alike —
 * plus self::fail(). A hand-kept list only ever covered the names someone
 * thought of; `assertTrue($ok, $process->getErrorOutput())` leaks as surely
 * as assertSame() does. The whole argument list is scanned, so even
 * `assertNotSame('', $result->output, 'msg')`, which cannot leak, is flagged;
 * keep the report out of such a call, or hoist an integer-valued use like
 * `substr_count($result->output, …)` into a local first.
 *
 * A sanctioned wrap (self::SAFE_WRAP_CALLS) is stripped BY ARGUMENT (#164):
 * only the one argument it scrubs is removed, and its other arguments —
 * diagnosticMessage()'s label, messageOrDefault()'s verbatim message,
 * messageWithOutput()'s message and default — stay in the scan, since each
 * is composed into the result unscrubbed.
 *
 * Labels are still not followed through data flow: a raw report that reaches
 * a label through a local variable or a callable (CheckDisallowedCallsTest
 * passes `$failureMessage($function)`, where `$function` is a name its
 * extractor restricts to `[a-z0-9_]+`) is invisible.
 *
 * This is a BEST-EFFORT static grep-shaped guard, not a real PHP parser.
 * Detection walks the token_get_all() TOKEN ARRAY directly, by a token
 * INDEX rather than a byte offset (self::significantTokens()/
 * self::openParenIndexAfter()/self::matchingCloseParenIndex()/
 * self::stripSafeWraps() below), instead of flattening the
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
 * - IDENTIFIER-BOUNDARY-SAFE: a guarded call (self::GUARDED_CALL_PATTERN,
 *   anchored at both ends) and a self::SAFE_WRAP_CALLS name
 *   (self::stripSafeWraps()) each only match a T_STRING token whose text EXACTLY equals the wanted
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
 *   below, with the position of the argument it scrubs, or this guard would
 *   false-positive on it.
 * - self::RAW_OUTPUT_PATTERN also flags a regex-capture variable
 *   (`$matches[`) and an array-key access shaped like subprocess output
 *   (`['stdout']`/`['stderr']`, SINGLE-QUOTED only — a double-quoted
 *   `["stdout"]`/`["stderr"]` is not matched, an accepted, honest limitation
 *   confirmed to have no live instance in any of self::guardedFiles() today),
 *   on top of the direct `->output`/`->getOutput()`/`->getErrorOutput()`
 *   accessors, applied to self::tokensToText()'s reconstruction of a call's
 *   own argument tokens (with every sanctioned wrap's scrubbed argument
 *   already removed) —
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
 *   tests/CheckJsConfigsManifestTest.php tests/CheckCheckedExceptionsTest.php
 *   tests/CheckDisallowedCallsTest.php tests/Support/ScrubbedDiagnostics.php`
 *   (the `--` is required: without it,
 *   a pattern starting with `-` is parsed as an option, not the search
 *   text): no current call site in any of
 *   self::guardedFiles() uses this syntax. If this construct is ever
 *   intentionally introduced into a guarded file, the guard would need a
 *   targeted extension at that point — not before.
 * - self::GUARDED_CALL_PATTERN/self::SAFE_WRAP_CALLS matching requires an exact
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
 *   Confirmed via `grep -noE '[A-Za-z0-9_]+::(assert[A-Z][A-Za-z0-9_]*|fail)\('
 *   tests/GateTestCase.php tests/CheckJsConfigsTest.php
 *   tests/CheckJsConfigsManifestTest.php tests/CheckCheckedExceptionsTest.php
 *   tests/CheckDisallowedCallsTest.php tests/Support/ScrubbedDiagnostics.php`:
 *   every hit is either a docblock mention or a call spelled with the bare
 *   `self::` prefix, so no call site in self::guardedFiles() uses another
 *   spelling.
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
     * What makes a call a guarded one: any `assert*()` call — PHPUnit's own
     * and every suite-local helper alike — plus `fail()`. A pattern rather
     * than a closed list of names (#164): a list only ever covers the names
     * someone thought of, and `assertTrue($ok, $result->output)` leaks through
     * its message exactly as `assertSame()` does. A declaration of such a
     * helper is not a call and is skipped (self::isGuardedCallAt()).
     */
    private const GUARDED_CALL_PATTERN = '/\A(?:assert[A-Z]\w*|fail)\z/';

    /**
     * The sanctioned wraps, each mapped to the zero-based position of the ONE
     * argument it scrubs. Only that argument is stripped; every other
     * argument (diagnosticMessage()'s label, messageOrDefault()'s and
     * messageWithOutput()'s message and default) is composed into the
     * result verbatim and therefore stays in the scan (#164). A wrap called
     * with named or spread arguments cannot be mapped by position, so it is
     * not stripped at all — the fail-closed direction.
     */
    private const SAFE_WRAP_CALLS = [
        'scrubbedForDiagnostic' => 0,
        'diagnosticMessage'     => 1,
        'messageOrDefault'      => 2,
        'messageWithOutput'     => 2,
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
     * fixed lived in the first three files returned below. The two PHPStan
     * gate suites are listed because they assert on a raw PHPStan report, and
     * tests/Support/ScrubbedDiagnostics.php because it holds the containment
     * assertions. A new file added to this suite that repeats
     * the same biomeCi()/runTsc()-against-PR-editable-config shape, or asserts
     * on a subprocess report, would need adding here too; this guard only
     * reads what it is told to.
     *
     * tests/CheckConsumerConfigTest.php is deliberately absent: what it must
     * keep out of a failure message is the CONTENT of a repository file held
     * in a plain local variable, a shape RAW_OUTPUT_PATTERN cannot see, so
     * listing it would prove nothing. Its one live instance is pinned by
     * aFailedCanonFlagCheckDoesNotEmbedTheCanonContentInItsMessage() instead.
     *
     * tests/Support/GateProcessTest.php (several plain
     * `self::assertStringContainsString('hello', $result->output)`-style
     * calls, e.g. in runCapturesStdout()) and
     * tests/GateTestCaseTest.php's own
     * theMessageCompositionHelpersComposeAsDocumented() (several assertSame()
     * calls against a hand-authored literal carrying `::error::`) share the
     * shape this guard scans for, yet are deliberately left
     * out of the list below: both fixtures are author-controlled literals a
     * PR can never influence, not PR-editable content, so routing them
     * through the scrub helpers would be unnecessary churn rather than
     * closing a real gap.
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
            "{$root}/tests/CheckCheckedExceptionsTest.php",
            "{$root}/tests/CheckDisallowedCallsTest.php",
            "{$root}/tests/CheckReleaseTagLockstepTest.php",
            "{$root}/tests/CheckPhpCsFixerTest.php",
            "{$root}/tests/Support/ScrubbedDiagnostics.php",
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
     * Splits a call's argument-token span at its top-level commas. A comma
     * nested inside `(...)`, `[...]` or `{...}` (a nested call, an array
     * literal, a match arm, an interpolation) belongs to that inner
     * construct, so each of those opens and closes a depth level — counted
     * only on bare punctuation tokens (plus the array-shaped `{`-openers of an
     * interpolation, whose closing `}` is bare), for the reason
     * self::isRawParenToken() gives. An empty trailing argument (a trailing
     * comma) is dropped.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens A call's own argument-token span.
     *
     * @return list<list<string|array{0: int, 1: string, 2: int}>> One token list per argument, in order.
     */
    private static function splitTopLevelArguments(array $tokens): array
    {
        $arguments = [];
        $current   = [];
        $depth     = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (($token[0] === T_CURLY_OPEN) || ($token[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                    ++$depth;
                }
            } elseif (in_array($token, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                --$depth;
            } elseif (($token === ',') && ($depth === 0)) {
                $arguments[] = $current;
                $current     = [];

                continue;
            }

            $current[] = $token;
        }

        if (self::nextNonWhitespaceIndex($current, 0) !== null) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * Whether every argument is a plain positional one — no `...` spread and
     * no `name:` label — so self::SAFE_WRAP_CALLS' positions apply to it.
     *
     * @param list<list<string|array{0: int, 1: string, 2: int}>> $arguments The output of self::splitTopLevelArguments().
     *
     * @return bool True when every argument is positional.
     */
    private static function isPositionalArgumentList(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            $first = self::nextNonWhitespaceIndex($argument, 0);

            if ($first === null) {
                continue;
            }

            if (is_array($argument[$first]) && ($argument[$first][0] === T_ELLIPSIS)) {
                return false;
            }

            if (is_array($argument[$first]) && ($argument[$first][0] === T_STRING)) {
                $next = self::nextNonWhitespaceIndex($argument, $first + 1);

                if (($next !== null) && ($argument[$next] === ':')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Removes the scrubbed argument of every self::SAFE_WRAP_CALLS call from
     * $tokens and keeps everything else, so whatever self::tokensToText()
     * reconstructs afterwards carries only the text NOT already covered by a
     * scrub — a wrap's unscrubbed arguments included (#164). A matched wrap
     * call is replaced by its remaining arguments, each stripped the same way
     * in turn (a wrap nested inside a label is still honoured) and followed by
     * a `,`, so two of them never fuse into one token text. The wrap name
     * must equal a T_STRING token's own text EXACTLY, never as a substring:
     * PHP's own tokenizer already emits a whole identifier as one token, so
     * `xscrubbedForDiagnostic` cannot be mistaken for `scrubbedForDiagnostic`
     * the way a byte-level needle search could. A wrap whose argument list is
     * not plainly positional (self::isPositionalArgumentList()) is left in
     * place whole, its arguments scanned like any other text.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens A call's own argument-list token span.
     *
     * @return list<string|array{0: int, 1: string, 2: int}> The input $tokens with every scrubbed wrap argument removed.
     */
    private static function stripSafeWraps(array $tokens): array
    {
        $result = [];
        $count  = count($tokens);
        $i      = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if (is_array($token) && ($token[0] === T_STRING) && isset(self::SAFE_WRAP_CALLS[$token[1]])) {
                $callArguments = self::callArgumentTokensAt($tokens, $i);

                if ($callArguments !== null) {
                    $arguments = self::splitTopLevelArguments($callArguments[0]);

                    if (self::isPositionalArgumentList($arguments)) {
                        foreach ($arguments as $position => $argument) {
                            if ($position === self::SAFE_WRAP_CALLS[$token[1]]) {
                                continue;
                            }

                            $result = [...$result, ...self::stripSafeWraps($argument), ','];
                        }

                        $i = $callArguments[1] + 1;

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
     * Whether the token at $index is a T_STRING naming a guarded call
     * (self::GUARDED_CALL_PATTERN). A helper's own declaration matches too,
     * harmlessly: a parameter list never carries a self::RAW_OUTPUT_PATTERN
     * shape, so it cannot produce a finding.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens The output of self::significantTokens().
     * @param int                                           $index  The index to test.
     *
     * @return bool True when $index names a guarded call.
     */
    private static function isGuardedCallAt(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        return is_array($token) && ($token[0] === T_STRING) && (preg_match(self::GUARDED_CALL_PATTERN, $token[1]) === 1);
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
     * Scans $path for every guarded call (self::GUARDED_CALL_PATTERN) and,
     * for each one, strips every self::SAFE_WRAP_CALLS wrap's scrubbed
     * argument from its own argument list — the message argument included, since a hand-written
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
        if (!is_file($path)) {
            self::fail("The file to scan does not exist, so the guard would find nothing: {$path}");
        }

        $tokens   = self::significantTokens((string) file_get_contents($path));
        $count    = count($tokens);
        $findings = [];

        $i = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if (!self::isGuardedCallAt($tokens, $i)) {
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

            if (is_array($token) && (preg_match(self::RAW_OUTPUT_PATTERN, $strippedText) === 1)) {
                $findings[] = "{$path}:{$token[2]}: {$token[1]}(" . self::tokensToText($argumentTokens) . ')';
            }

            $i = $closeParenIndex + 1;
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
     * guarded call found in $phpSource — the finding string
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
     * @param string $phpSource PHP source containing exactly one guarded call.
     *
     * @return string The stripped argument text self::RAW_OUTPUT_PATTERN is actually matched against.
     */
    private static function strippedArgumentTextFor(string $phpSource): string
    {
        $tokens = self::significantTokens($phpSource);
        $count  = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!self::isGuardedCallAt($tokens, $i)) {
                continue;
            }

            $callArguments = self::callArgumentTokensAt($tokens, $i);

            if ($callArguments === null) {
                continue;
            }

            return self::tokensToText(self::stripSafeWraps($callArguments[0]));
        }

        self::fail('No guarded call was found in the given fixture source.');
    }

    /**
     * The regression guard itself: none of the files self::guardedFiles()
     * lists may make a guarded call (self::GUARDED_CALL_PATTERN) with a raw,
     * unscrubbed subprocess-output accessor anywhere in its own argument list
     * outside a wrap's scrubbed argument. A future call site that
     * reintroduces the shape (rather than routing the report through
     * self::scrubbedForDiagnostic()/diagnosticMessage()/messageWithOutput())
     * fails this test instead of shipping silently.
     */
    #[Test]
    public function noRiskyAssertionCarriesUnscrubbedSubprocessOutput(): void
    {
        $findings = [];

        self::assertContains(
            self::root() . '/tests/Support/ScrubbedDiagnostics.php',
            self::guardedFiles(),
            'The file that holds the containment assertions is no longer guarded.',
        );

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
     * One row per call shape the guard must flag: a raw report interpolated
     * into that call's message is found. The rows deliberately reach past the
     * names the guard used to list by hand (#164) — assertTrue(), assertCount(),
     * a suite-local assert*() helper and fail() — since a pattern that
     * silently narrowed back to a closed list would turn exactly those rows
     * red.
     *
     * @param string $call A call name the guard must flag.
     */
    #[Test]
    #[DataProvider('guardedCallProvider')]
    public function detectsARawOutputInterpolatedIntoEveryGuardedCall(string $call): void
    {
        $findings = $this->findingsFor(
            'poisoned-per-name-fixture.php',
            "<?php\nself::{$call}(0, \$x, \"boom\\n{\$result->output}\");\n",
        );

        self::assertCount(1, $findings, "The guard did not flag a raw report interpolated into a {$call}() call.");
    }

    /**
     * @return array<string, array{0: string}> Call names the guard must flag, keyed by themselves.
     */
    public static function guardedCallProvider(): array
    {
        return [
            'assertStringContainsString'          => ['assertStringContainsString'],
            'assertStringNotContainsString'       => ['assertStringNotContainsString'],
            'assertMatchesRegularExpression'      => ['assertMatchesRegularExpression'],
            'assertDoesNotMatchRegularExpression' => ['assertDoesNotMatchRegularExpression'],
            'assertSame'                          => ['assertSame'],
            'assertEquals'                        => ['assertEquals'],
            'assertNotSame'                       => ['assertNotSame'],
            'assertOutputContains'                => ['assertOutputContains'],
            'assertOutputDoesNotContain'          => ['assertOutputDoesNotContain'],
            'assertTrue'                          => ['assertTrue'],
            'assertFalse'                         => ['assertFalse'],
            'assertCount'                         => ['assertCount'],
            'assertNotEmpty'                      => ['assertNotEmpty'],
            'assertGateRejects'                   => ['assertGateRejects'],
            'fail'                                => ['fail'],
        ];
    }

    /**
     * A name that merely resembles a guarded one is not one: `assertion()`
     * has no capital after `assert`, `failed()` is not `fail()`. Without this,
     * a pattern widened to any identifier would pass every row above while
     * flagging ordinary helpers.
     */
    #[Test]
    public function doesNotFlagACallThatOnlyResemblesAGuardedName(): void
    {
        $findings = $this->findingsFor(
            'resembling-name-fixture.php',
            <<<'PHP'
            <?php
            self::assertion($result->output);
            self::failed($result->output);
            self::fails($result->output);
            PHP,
        );

        self::assertSame([], $findings, 'The guard flagged a call whose name only resembles a guarded one.');
    }

    /**
     * A guarded file that does not exist would otherwise be read as an empty
     * source and yield no findings, so a rename or move would silently switch
     * the guard off for that file (a failed read is only a warning, which
     * does not fail this run).
     */
    #[Test]
    public function refusesToScanAFileThatDoesNotExist(): void
    {
        self::assertThrows(
            static fn (): array => self::findUnscrubbedRawOutputAssertions(self::root() . '/tests/DoesNotExist.php'),
            AssertionFailedError::class,
            'The scanner accepted a path that is not a file.',
        );
    }

    /**
     * The guard's control for the sanctioned wrap itself: a call
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
     * The guard's control for the fourth sanctioned wrap:
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
     * messageOrDefault() returns its non-empty $message VERBATIM, so only its
     * third argument is scrubbed; a raw report in the first one must be found.
     * This was an accepted gap while a matched wrap's whole span was stripped
     * (#164) — a wrap is now stripped by argument, per self::SAFE_WRAP_CALLS.
     */
    #[Test]
    public function detectsARawOutputInMessageOrDefaultsUnscrubbedMessageArgument(): void
    {
        $findings = $this->findingsFor(
            'message-or-default-first-argument-leak-fixture.php',
            <<<'PHP'
            <?php
            self::assertSame(0, $x, self::messageOrDefault('prefix: ' . $result->output, 'default', $result->output));
            PHP,
        );

        self::assertCount(1, $findings, "The guard did not flag a raw report in messageOrDefault()'s unscrubbed first argument.");
    }

    /**
     * The label half of #164: diagnosticMessage() composes its label into the
     * message verbatim, so a raw report there leaks as surely as an unwrapped
     * one, and messageWithOutput()'s message and default do the same. One row
     * per unscrubbed argument position.
     *
     * @return array<string, array{0: string}>
     */
    public static function unscrubbedWrapArgumentProvider(): array
    {
        return [
            'diagnosticMessage label'   => ['self::diagnosticMessage("boom {$result->output}", $result->output)'],
            'messageWithOutput message' => ['self::messageWithOutput($result->output, \'d\', $result->output)'],
            'messageWithOutput default' => ['self::messageWithOutput(\'\', $result->output, $result->output)'],
            'messageOrDefault default'  => ['self::messageOrDefault(\'\', $result->output, $result->output)'],
        ];
    }

    /**
     * Flags a raw report in an argument a sanctioned wrap does not scrub.
     *
     * @param string $message The message expression, a wrap carrying a raw report in an unscrubbed argument.
     */
    #[Test]
    #[DataProvider('unscrubbedWrapArgumentProvider')]
    public function detectsARawOutputInAnUnscrubbedWrapArgument(string $message): void
    {
        $findings = $this->findingsFor('unscrubbed-wrap-argument-fixture.php', "<?php\nself::assertTrue(\$ok, {$message});\n");

        self::assertCount(1, $findings, 'The guard did not flag a raw report in an argument the wrap does not scrub.');
    }

    /**
     * A wrap's scrubbed argument must still be found at its own position when
     * an earlier argument carries a comma of its own — inside a nested call,
     * an array literal, a match arm, or an interpolation — and a wrap nested
     * inside a label is honoured in turn. A split that miscounted any of
     * those would shift the report into a kept position and flag it.
     *
     * @return array<string, array{0: string}>
     */
    public static function scrubbedWrapArgumentProvider(): array
    {
        return [
            'nested call'  => ["self::diagnosticMessage(implode(', ', \$parts), \$result->output)"],
            'array'        => ["self::diagnosticMessage(['a', 'b'][\$k], \$result->output)"],
            'match'        => ["self::diagnosticMessage(match (\$k) { 1 => 'a', default => 'b' }, \$result->output)"],
            'interpolated' => ['self::diagnosticMessage("{$a[$k]}, {$b}", $result->output)'],
            'nested wrap'  => ['self::diagnosticMessage(self::scrubbedForDiagnostic($result->output), $result->output)'],
        ];
    }

    /**
     * Does not flag a report that sits only in a wrap's scrubbed argument.
     *
     * @param string $message The message expression, a wrap whose report sits in its scrubbed argument alone.
     */
    #[Test]
    #[DataProvider('scrubbedWrapArgumentProvider')]
    public function doesNotFlagAReportInAWrapsScrubbedArgument(string $message): void
    {
        $findings = $this->findingsFor('scrubbed-wrap-argument-fixture.php', "<?php\nself::assertTrue(\$ok, {$message});\n");

        self::assertSame([], $findings, 'The guard flagged a report that only a wrap\'s scrubbed argument carries.');
    }

    /**
     * A wrap called with named or spread arguments cannot be mapped by
     * position, so none of it is stripped: the fail-closed direction. A
     * position-mapped strip here would drop `label:` — the unscrubbed one.
     */
    #[Test]
    public function doesNotStripAWrapCalledWithNamedOrSpreadArguments(): void
    {
        $findings = $this->findingsFor(
            'named-wrap-arguments-fixture.php',
            <<<'PHP'
            <?php
            self::assertTrue($ok, self::diagnosticMessage(output: 'fixed', label: $result->output));
            self::assertTrue($ok, self::scrubbedForDiagnostic(...[$result->output]));
            PHP,
        );

        self::assertCount(2, $findings, 'The guard stripped a wrap it could not map by position.');
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
     * sanctioned wrap, so its scrubbed argument swallows the trailing report
     * and RAW_OUTPUT_PATTERN never sees the real leak.
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
     * self::stripSafeWraps()'s own identifier-boundary
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
        // strips ONLY the wrap's scrubbed third argument, or mis-balances on the nowdoc's `)` and keeps
        // that argument too. Count the raw accesses left in the STRIPPED text instead: exactly one, the
        // trailing unwrapped one. The nowdoc body itself is the wrap's unscrubbed default, so it stays.
        $strippedText = self::strippedArgumentTextFor($phpSource);

        self::assertStringContainsString('Looks good', $strippedText, 'The wrap\'s unscrubbed nowdoc default was stripped.');
        self::assertSame(
            1,
            preg_match_all(self::RAW_OUTPUT_PATTERN, $strippedText),
            "The stripped argument text does not carry exactly the one trailing unwrapped \$result->output — actual stripped text: {$strippedText}",
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
     * a guarded or SAFE_WRAP_CALLS call's own `(`), or inside a bracket
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
