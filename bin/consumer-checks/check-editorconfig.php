<?php

/**
 * This file is part of the package magicsunday/coding-standard.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * The .editorconfig (optional) contract check, extracted out of
 * bin/check-consumer-config.php (GH-48) once that file crossed 1000 lines. A
 * shared include, not an entry point — see bin/consumer-checks/helpers.php's
 * own docblock for the boundary this file follows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/coding-standard/
 */

/**
 * Asserts the 4-space house indent + the Makefile tab override on .editorconfig.
 *
 * @param list<string> $violations The accumulated report, appended to in place.
 * @param string       $repoRoot   The consumer repository root to inspect.
 *
 * @return void
 */
function checkEditorconfig(array &$violations, string $repoRoot): void
{
    $editorconfigFile = $repoRoot . '/.editorconfig';

    if (!is_file($editorconfigFile)) {
        return;
    }

    $contents = readBounded($violations, $editorconfigFile, '.editorconfig');

    if ($contents === null) {
        // Reported as oversize by readBounded(); the arms below would run on a
        // truncated read and name causes the file does not have.
        return;
    }

    if ($contents === false) {
        fail($violations, '.editorconfig', 'exists but cannot be read.');

        return;
    }

    // Editors honour a BOM'd .editorconfig — editorconfig-core-js reads one
    // and returns its settings, because JavaScript's `\s` matches U+FEFF.
    // PHP's trim() does not, so without this the key parses as
    // "\u{FEFF}root" and a file every editor obeys is reported as drift.
    $contents = stripBom($contents);

    // EditorConfig is section-scoped INI: `root` is a preamble key valid only
    // BEFORE the first `[section]`, and each key belongs to the section it sits
    // under. A per-line whole-file regex accepts drift (a `root` moved into a
    // section, `indent_style` set only in a narrow `[*.md]` while `[*]` uses tabs,
    // the Makefile override deleted), so parse the file into a preamble map plus a
    // per-section key map and assert each value in the section it must hold in.
    /** @var array<string, string> $preamble */
    $preamble = [];
    /** @var array<string, array<string, string>> $sections */
    $sections = [];
    $current  = null;

    // `\r\n|[\r\n]`, not `\R`. In 8-bit non-UTF mode PCRE2 expands `\R` to
    // an atomic group over CRLF, LF, VT, FF, CR and 0x85. THREE of those six are
    // wrong here: EditorConfig defines exactly CRLF,
    // LF and CR, so VT and FF split a line that is not one — and `\x85` is a UTF-8
    // CONTINUATION byte (`ą` is C4 85, `Ņ` is C5 85), so such a character in a
    // comment split mid-character and the tail fragment was re-parsed as a config
    // line.
    //
    // Measured 2026-08-15, GNU grep 3.8, on the byte that matters rather than on
    // the easy one — and the locale is the point, because PHP without `/u` runs
    // the 8-bit mode:
    //
    //     printf 'a\x85b' | LC_ALL=C grep -cP 'a\Rb'   -> 1
    //     printf 'a\x85b' |           grep -cP 'a\Rb'   -> 0   (UTF-8 locale)
    //
    // Not fixed with `/u`: `preg_split` then returns false on a file carrying
    // invalid UTF-8, the `?: []` collapses that to zero lines, and every
    // assertion below fires — three false violations on a file every editor
    // reads. The two sibling blocks normalise with str_replace for the same
    // reason.
    foreach (preg_split('/\r\n|[\r\n]/', $contents) ?: [] as $line) {
        $trimmed = trim($line);

        if (($trimmed === '') || ($trimmed[0] === '#') || ($trimmed[0] === ';')) {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $trimmed, $matches) === 1) {
            $current            = $matches[1];
            $sections[$current] = $sections[$current] ?? [];

            continue;
        }

        // `explode`, not `/^([^=]+?)\s*=\s*(.*)$/`. That pattern was quadratic on
        // consumer-controlled bytes, which is the same trust boundary
        // bin/support/safe-report-value.php describes — and unlike the JSONC
        // scan it had no size cap at all. `[^=]+?` is lazy and its class
        // INCLUDES whitespace, so it and the following `\s*` overlap: on a line
        // whose first `=` sits behind a whitespace run, the engine expands one
        // byte at a time and `\s*` re-consumes and backtracks the whole run at
        // every position. Θ(W²), and `pcre.backtrack_limit` never fires —
        // `preg_match` returns 1 with `No error` at every size below.
        //
        // Measured end-to-end through this binary on php 8.5, `.editorconfig`
        // holding one line of `a` + W spaces + `x=y`:
        //
        //      64 KiB     3.04 s        ->  0.10 s
        //     256 KiB    34.56 s        ->  0.11 s
        //       1 MiB   380.85 s        ->  0.10 s   (flat)
        //
        // A size cap would bound this to ~10 s; splitting at the first `=`
        // removes the shape instead, and is shorter than the regex it replaces.
        $pair = explode('=', $trimmed, 2);

        if ((count($pair) === 2) && (trim($pair[0]) !== '')) {
            // The charlist is spelled out because PHP's default omits `\x0C`
            // while PCRE's `\s` includes it — so the regex this replaces DID
            // strip a form feed around a key or value and `trim()` alone would
            // not. Measured over a 17-line corpus of real and hostile lines,
            // that is the one shape where the two disagree, and stripping it is
            // the answer the EditorConfig spec gives ("trim whitespace around
            // the key and the value").
            //
            // mb_ with an explicit encoding, not the byte-wise pair: this
            // package's own phpstan/disallowed-calls.neon bans strtolower()
            // for consumers, and a gate that ships a ban has no business
            // being the exception to it. EditorConfig keys are ASCII by
            // grammar, so the two agree on every real input — which is why it
            // survived here unnoticed, not a reason to keep it.
            $key   = mb_strtolower(trim($pair[0], " \t\n\r\0\x0B\x0C"), 'UTF-8');
            $value = mb_strtolower(trim($pair[1], " \t\n\r\0\x0B\x0C"), 'UTF-8');

            if ($current === null) {
                $preamble[$key] = $value;
            } else {
                $sections[$current][$key] = $value;
            }
        }
    }

    if (($preamble['root'] ?? null) !== 'true') {
        fail($violations, '.editorconfig', 'must set `root = true` in the preamble (before any section).');
    }

    $global = $sections['*'] ?? null;

    if ($global === null) {
        fail($violations, '.editorconfig', 'must define a global `[*]` section.');
    } else {
        if (($global['indent_style'] ?? null) !== 'space') {
            fail($violations, '.editorconfig', 'the `[*]` section must set `indent_style = space`.');
        }

        if (($global['indent_size'] ?? null) !== '4') {
            fail($violations, '.editorconfig', 'the `[*]` section must set `indent_size = 4`.');
        }
    }

    // Makefiles keep hard tabs; the canonical override is `[{Makefile,*.mk}]`. The
    // glob is case-sensitive, so the section name must match exactly — a lowercase
    // `{makefile,*.mk}` would not match the real `Makefile` and silently apply no
    // tab rule, so it is NOT accepted as an equivalent.
    $makefile = $sections['{Makefile,*.mk}'] ?? null;

    if (($makefile === null) || (($makefile['indent_style'] ?? null) !== 'tab')) {
        fail($violations, '.editorconfig', 'must keep the `[{Makefile,*.mk}]` section with `indent_style = tab`.');
    }
}
