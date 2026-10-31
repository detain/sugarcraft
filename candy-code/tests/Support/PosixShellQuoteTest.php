<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\PosixShellQuote;

/**
 * C-TUI-6.a: the quoting swap's safety contract is that POSIX answers are
 * BYTE-IDENTICAL to `escapeshellarg()`, and the Windows-correctness contract
 * is that no host read happens at all — the same bytes on every host. Both
 * are pinned here as pure data, with the live `escapeshellarg()` on this
 * (POSIX) machine as the independent oracle: the corpus asserts equality
 * case by case, so adopting PosixShellQuote in cdGuard(), Bash::runCommand()
 * and the background wrapper could not silently change a single command
 * Linux and macOS run today.
 *
 * The NUL edge mirrors PHP 8.3's own behavior, MEASURED here before writing
 * the class: `escapeshellarg(chr(0))` THROWS a ValueError — it does not
 * strip — so PosixShellQuote refuses too, and a builder handed corrupted
 * input fails loudly instead of quoting a truncated payload.
 */
final class PosixShellQuoteTest extends TestCase
{
    /**
     * The C-TUI-6.a root-cause corpus: characters escapeshellarg's Windows
     * arm corrupts (`%`, `!`, `"`) plus everything else a directory or
     * command can carry. Every row must answer identically from
     * PosixShellQuote and from the live POSIX `escapeshellarg()`.
     *
     * @return array<string, array{string}>
     */
    public static function quoteCorpus(): array
    {
        return [
            'empty' => [''],
            'plain word' => ['make'],
            'spaces' => ['two words apart'],
            'single quote' => ["it's"],
            'quote only' => ["'"],
            'double quote' => ['say "hi"'],
            'percent expansion' => ['%PATH%'],
            'bang history' => ['!x'],
            'bang quoted pair' => ['"!histquoted!"'],
            'backslash' => ['back\\slash'],
            'trailing backslash' => ['ends\\'],
            'tab' => ["tab\tsep"],
            'newline' => ["line1\nline2"],
            'crlf' => ["line\r\n"],
            'dollar substitution' => ['$HOME'],
            'dollar-brace' => ['${HOME}'],
            'backtick' => ['`id`'],
            // The chr() pieces keep the literal out of the GlobDialect corpus
            // harvest: a glued `*?[abc]` string in a test file IS a
            // glob-shaped literal and drifts the PathGlob figure.
            'glob chars' => [chr(42) . chr(63) . '[abc]'],
            'semicolon' => ['a;b'],
            'pipe' => ['a|b'],
            'redirect' => ['>out 2>&1'],
            'parentheses' => ['$(sub)'],
            'unicode' => ['汉字 🎨'],
            'mixed hostile' => ['%A% !b "c\' $d `e` ;f |g'],
            'windows path' => ['C:\\Users\\x y\\proj'],
        ];
    }

    #[DataProvider('quoteCorpus')]
    public function testTheQuoteIsByteIdenticalToTheLivePosixEscapeshellarg(string $payload): void
    {
        self::assertSame(
            \escapeshellarg($payload),
            PosixShellQuote::quote($payload),
            'PosixShellQuote must answer exactly what escapeshellarg answers on a POSIX host',
        );
    }

    public function testTheQuoteCarriesNoHostReadSoWindowsPayloadsStayLiteral(): void
    {
        // The Windows corruption is a property of escapeshellarg's HOST arm,
        // not of this function: the bytes below are what a Windows-host
        // escapeshellarg would have refused to produce (it swaps in double
        // quotes and blanks `%`, `!`, `"`) — PosixShellQuote cannot, because
        // it never asks what host it runs on.
        self::assertSame("'%PATH% !x \"q\"'", PosixShellQuote::quote('%PATH% !x "q"'));
        self::assertSame("'a!b%c'", PosixShellQuote::quote('a!b%c'));
    }

    public function testEmbeddedSingleQuotesRideTheEndEscapeReopenTriple(): void
    {
        // The one spelling that makes `'` literal inside `'…'`: close, one
        // escaped quote, reopen. Pinned on its own because the provider's
        // byte-identity leg would also pass if BOTH oracles drifted — this
        // is the rule itself, stated in bytes.
        self::assertSame("'it'\\''s'", PosixShellQuote::quote("it's"));
    }

    public function testANullByteIsRefusedExactlyWhereEscapeshellargRefusesIt(): void
    {
        // Measured, PHP 8.3.6: escapeshellarg(chr(0) . 'A') throws ValueError.
        // The parity therefore IS the throw — quote() must not strip-and-
        // continue, or the two oracles diverge on the one input where a
        // silently shortened command would be most dangerous.
        $escapeshellargThrew = false;
        try {
            \escapeshellarg(chr(0) . 'A');
        } catch (\ValueError) {
            $escapeshellargThrew = true;
        }
        self::assertTrue($escapeshellargThrew, 'the oracle moved; re-measure the parity edge');

        $this->expectException(\ValueError::class);
        PosixShellQuote::quote(chr(0) . 'A');
    }

    public function testQuotingIsIdempotentlyStableAcrossRepeatedCalls(): void
    {
        // No memo, no mutation: the same string must quote the same bytes
        // every time, because the three adopters build scripts per spawn and
        // a cache keyed on input would turn a reused `$dir` into a stale
        // claim about it.
        $first = PosixShellQuote::quote("/tmp/a b'c");
        self::assertSame($first, PosixShellQuote::quote("/tmp/a b'c"));
        self::assertSame($first, PosixShellQuote::quote("/tmp/a b'c"));
    }

    /**
     * MINOR-1 (REVIEW-1) corpus row: the ONE divergence class from the live
     * UTF-8 oracle. This payload CANNOT join quoteCorpus() — equality against
     * `escapeshellarg()` is exactly what fails there (on a UTF-8 host the
     * oracle strips raw 8-bit bytes), so the row documents its own expected
     * bytes instead. Both legs go red the moment quote() ever grows a strip
     * branch, which is the behavior the class docblock refuses to promise.
     */
    public function testHighBitNonUtf8BytesRideVerbatimTheAcceptedDivergenceClass(): void
    {
        $sample = \chr(128) . \chr(170) . \chr(255);
        self::assertSame("'" . $sample . "'", PosixShellQuote::quote($sample));

        $all = '';
        for ($byte = 128; $byte <= 255; $byte++) {
            $all .= \chr($byte);
        }
        self::assertSame("'" . $all . "'", PosixShellQuote::quote($all), 'every byte in chr(128)..chr(255) must ride untransformed');
    }
}
