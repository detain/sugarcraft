<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Windows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\Windows\CommandLine;

/**
 * {@see CommandLine} — the MSVCRT quoting rules, from both ends.
 *
 * ## The corpus, and what each shape is there to catch
 *
 * `escape()` is written forward from the quoting rules; `toArgv()` is written
 * from the parser's documented behaviour. A round-trip corpus through the two is
 * therefore evidence about the CONTRACT and not about my own consistency — with
 * one exception, listed in {@see explicitWindowsShapes()}: the rows where the
 * expected command line is spelled out by hand, because a symmetric pair of bugs
 * would otherwise round-trip happily. `a\` before a quote is exactly that case.
 *
 *  - the empty argument: must come back as `""`, or it disappears.
 *    {@see testEmptyArgumentDoesNotShiftTheArgumentsAfterIt()} pins the
 *    count-and-position consequence, which is the bug's real shape: an argument
 *    that vanishes shifts every later one into a different parameter.
 *  - a trailing backslash: doubled inside quotes, literal outside them.
 *  - an embedded quote: escaped with a backslash AND preceded by a doubled run.
 *  - whitespace of each kind the parser splits on.
 *  - a path that needs nothing: passed through untouched, so that
 *    `C:\Program Files\...`-style backslashes are not silently rewritten.
 *
 * ## Why the NUL refusal is tested as a refusal
 *
 * There is no correct rendering of an argument containing a NUL for this API, so
 * anything other than throwing is a bug. Both directions are pinned: an argument
 * containing one, and a command line containing one.
 */
final class CommandLineTest extends TestCase
{
    public function testPlainArgumentsAreJoinedByOneSpaceAndPassedThrough(): void
    {
        self::assertSame(
            'php -l file.php',
            CommandLine::fromArgv(['php', '-l', 'file.php']),
        );
    }

    public function testBackslashesInAnUnquotedArgumentAreLiteral(): void
    {
        // The tempting port escapes here and produces C:\\Program, which the
        // parser hands back as C:\Program only by luck — and as something else
        // the moment a quote shows up in the same argument.
        $argument = 'C:\Users\me\proj';

        self::assertSame($argument, CommandLine::escape($argument));
        self::assertSame([$argument], CommandLine::toArgv($argument));
    }

    public function testEmptyArgumentBecomesAnExplicitQuotedEmpty(): void
    {
        self::assertSame('""', CommandLine::escape(''));
        self::assertSame('php "" -l', CommandLine::fromArgv(['php', '', '-l']));
        self::assertSame(['php', '', '-l'], CommandLine::toArgv('php "" -l'));
    }

    public function testEmptyArgumentDoesNotShiftTheArgumentsAfterIt(): void
    {
        $argv = ['php', '', 'a', ''];
        $parsed = CommandLine::toArgv(CommandLine::fromArgv($argv));

        self::assertCount(4, $parsed);
        self::assertSame($argv, $parsed);
    }

    public function testWhitespaceInsideAnArgumentIsQuotedAndRoundTrips(): void
    {
        foreach (['a b', 'leading space', "tab\there", "new\nline", 'a ' . '  ' . 'b'] as $argument) {
            self::assertSame(
                [$argument],
                CommandLine::toArgv(CommandLine::escape($argument)),
                'whitespace shape ' . json_encode($argument) . ' did not round-trip',
            );
        }
    }

    public function testArgumentOfOnlyWhitespaceIsQuotedNotEmpty(): void
    {
        // Same disappearing-argument hazard as the empty string, found by the
        // parser rather than by the writer: ' ' unquoted is a separator, and a
        // renderer that tests "is the argument non-empty" instead of "does it need
        // quoting" emits exactly that.
        self::assertSame('" "', CommandLine::escape(' '));
        self::assertSame([' '], CommandLine::toArgv(CommandLine::escape(' ')));
        self::assertSame(['  '], CommandLine::toArgv(CommandLine::escape('  ')));
        self::assertSame([''], CommandLine::toArgv(CommandLine::escape('')));
    }

    /**
     * Shapes whose expected command line is written by hand, not derived.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function explicitWindowsShapes(): array
    {
        return [
            // A run of one before a quote becomes two, and the quote is escaped.
            'quote' => ['a"b', '"a\\"b"'],
            'backslash then quote' => ['a\\"b', '"a\\\\\\"b"'],
            // A trailing backslash inside a quoted argument is doubled, so it
            // stops being an escape for the closing quote.
            'trailing backslash' => ['a b\\', '"a b\\\\"'],
            // An argument that is ONLY backslashes still needs the doubling once
            // it is quoted for any other reason.
            // A run of backslashes that does NOT precede a quote stays literal —
            // doubling everything is the other classic port bug.
            'space then backslashes' => ['a \\b', '"a \\b"'],
            'embedded quote at end' => ['say "hi"', '"say \\"hi\\""'],
            // cmd.exe metacharacters pass through UNQUOTED, by design: the CRT
            // parser that CreateProcessW feeds gives them no meaning outside a
            // quoted run, and quoting anything that does not need it would put
            // literal quotes into programs that parse their own argv. This row
            // pins the inertness both directions — escape() leaves them, and
            // toArgv() reads them back as one token. The caveat that makes
            // "inert" true lives in the class docblock: it holds only because
            // nothing here runs the line through cmd.exe.
            'shell metacharacters are inert data' => ['a|b^c&d%e', 'a|b^c&d%e'],
        ];
    }

    /**
     * @param non-empty-string $argument
     * @param non-empty-string $expectedLine
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('explicitWindowsShapes')]
    public function testQuotingRulesMatchTheDocumentedSpelling(string $argument, string $expectedLine): void
    {
        self::assertSame($expectedLine, CommandLine::escape($argument));
        self::assertSame([$argument], CommandLine::toArgv($expectedLine));
    }

    public function testRoundTripCorpus(): void
    {
        $corpus = [
            ['php', '-r', 'echo 1;'],
            ['php', '-r', "echo 'a b';"],
            ['sh', '-c', 'exit 42'],
            ['cmd', '/c', 'dir /b "C:\\Program Files"'],
            ['', 'x', '', 'y'],
            ['a"b"c', 'd\\', 'e=f', 'g h'],
            ['C:\\path with space\\', 'trail' . '\\', "nl\nx", 'ta' . "\t" . 'b'],
            ['"', '\\', '\\\\', '\\"'],
            ['x' . "\v" . 'y', 'z' . "\r" . 'w'],
        ];

        foreach ($corpus as $argv) {
            $parsed = CommandLine::toArgv(CommandLine::fromArgv($argv));

            self::assertSame(
                $argv,
                $parsed,
                'corpus row ' . json_encode($argv) . ' round-tripped as ' . json_encode($parsed),
            );
        }
    }

    public function testNulByteInAnArgumentIsRefused(): void
    {
        try {
            CommandLine::escape("safe\0evil");
            self::fail('a NUL byte was accepted into a command line');
        } catch (PtyException $e) {
            self::assertStringContainsString('NUL', $e->getMessage());
        }
    }

    public function testNulByteInACommandLineIsRefused(): void
    {
        try {
            CommandLine::toArgv("php\0-l");
            self::fail('a NUL byte was accepted in a command line to parse');
        } catch (PtyException $e) {
            self::assertStringContainsString('NUL', $e->getMessage());
        }
    }

    public function testAQuotedArgumentAbutsTheNextSeparator(): void
    {
        // The parser's rule that a closing quote followed by a space ENDS the
        // token — getting this wrong merges two arguments into one, which is a
        // different argument count rather than a wrong string.
        self::assertSame(['a b', 'c'], CommandLine::toArgv('"a b" c'));
        self::assertSame(['a b c'], CommandLine::toArgv('"a b c"'));
    }

    public function testAdjacentQuotedRunsMergeIntoOneArgument(): void
    {
        // `"a""b"` is ONE argument `ab` in this grammar, not two arguments. A
        // parser that ends a token at every closing quote produces the wrong argv
        // for any real Windows command line built by another tool.
        self::assertSame(['ab'], CommandLine::toArgv('"a""b"'));
        // and the other polarity: a space BETWEEN two quoted runs is still a
        // separator, so this is two arguments, not one.
        self::assertSame(['a', 'b'], CommandLine::toArgv('"a" "b"'));
    }

    public function testOddBackslashRunBeforeQuoteLeaksALiteralQuote(): void
    {
        // Three backslashes + quote = one literal backslash AND one literal
        // quote, and the delimiter state does NOT change. escape() is the oracle
        // for the spelling: this is what escape('a\"b') produces.
        self::assertSame(['a\\"b'], CommandLine::toArgv('"a\\\\\\"b"'));
        self::assertSame(['a\\\\b'], CommandLine::toArgv('"a\\\\b"'));
    }

    public function testNonStringArgumentIsRefused(): void
    {
        try {
            CommandLine::fromArgv(['php', 1]);
            self::fail('a non-string argument was accepted');
        } catch (PtyException $e) {
            self::assertStringContainsString('must be a string', $e->getMessage());
        }
    }

    public function testEmptyVectorRendersAsAnEmptyLine(): void
    {
        self::assertSame('', CommandLine::fromArgv([]));
        self::assertSame([], CommandLine::toArgv(''));
        self::assertSame([], CommandLine::toArgv('   '));
    }
}
