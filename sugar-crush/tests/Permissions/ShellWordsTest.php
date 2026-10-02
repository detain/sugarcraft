<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\ShellWords;

/**
 * @see ShellWords
 *
 * The tokeniser behind the quote-aware rm-rf breaker and ConfirmRemoveHook
 * (audit F-P1). Its contract is "the argv bash would build, before expansion":
 * every case below is the word list bash itself produces for that line.
 */
final class ShellWordsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<list<string>>}>
     */
    public static function wordCases(): iterable
    {
        yield 'single-quoted flag' => ["rm '-rf' ~", [['rm', '-rf', '~']]];
        yield 'double-quoted flag' => ['rm "-rf" /', [['rm', '-rf', '/']]];
        yield 'quotes glued into one word' => ["rm -'r'\"f\" x", [['rm', '-rf', 'x']]];
        yield 'escaped command word' => ['\\rm -rf x', [['rm', '-rf', 'x']]];
        yield 'escaped space joins words' => ['touch a\\ b', [['touch', 'a b']]];
        yield 'ANSI-C quoting is decoded' => ["rm \$'\\x2drf' /", [['rm', '-rf', '/']]];
        yield 'locale quoting is a double quote' => ['echo $"a b"', [['echo', 'a b']]];
        yield 'double quotes keep a literal backslash' => ['echo "a\\zb"', [['echo', 'a\\zb']]];
        yield 'double quotes honour their four escapes' => ['echo "\\$\\"\\\\"', [['echo', '$"\\']]];
        yield 'no expansion of variables or tilde' => ['rm -rf "$HOME" ${HOME} ~', [['rm', '-rf', '$HOME', '${HOME}', '~']]];
        yield 'whitespace runs and tabs' => ["a \t  b", [['a', 'b']]];
        yield 'line continuation' => ["rm -rf \\\n/", [['rm', '-rf', '/']]];
        yield 'assignment with quoted space' => ['X="a b" cmd', [['X=a b', 'cmd']]];
        yield 'comment dropped' => ['echo hi # rm -rf /', [['echo', 'hi']]];
        yield 'hash inside a word is not a comment' => ['echo a#b', [['echo', 'a#b']]];
        yield 'empty line' => ['   ', []];
    }

    /**
     * @param list<list<string>> $expected
     */
    #[DataProvider('wordCases')]
    public function testWordsAfterQuoteRemoval(string $line, array $expected): void
    {
        $parsed = ShellWords::parse($line);

        $this->assertSame($expected, $parsed->commands);
        $this->assertTrue($parsed->complete);
    }

    public function testEveryControlOperatorSplitsCommands(): void
    {
        $parsed = ShellWords::parse("a && b || c | d; e & f |& g\nh\ri ;; (j) ");

        $this->assertSame(
            [['a'], ['b'], ['c'], ['d'], ['e'], ['f'], ['g'], ['h'], ['i'], ['j']],
            $parsed->commands,
        );
        $this->assertSame(['&&', '||', '|', ';', '&', '|&', "\n", "\r", ';;', '(', ')'], $parsed->operators);
    }

    public function testQuotedOperatorsDoNotSplit(): void
    {
        $parsed = ShellWords::parse("echo 'a; b' \"c && d\" e\\|f");

        $this->assertSame([['echo', 'a; b', 'c && d', 'e|f']], $parsed->commands);
        $this->assertSame([], $parsed->operators);
    }

    /**
     * Redirections leave the word list (`2>/dev/null` is not an operand) and are
     * reported with fd, operator and target whatever the spacing — the shapes a
     * plan-mode write check has to see (audit F-P2 lists `>f`, `2> f`, `>|`).
     *
     * @return iterable<string, array{string, list<string>, list<array{command: int, fd: ?string, op: string, target: ?string}>}>
     */
    public static function redirectionCases(): iterable
    {
        yield 'spaced' => ['echo x > f', ['echo', 'x'], [['command' => 0, 'fd' => null, 'op' => '>', 'target' => 'f']]];
        yield 'glued both sides' => ['echo x>f', ['echo', 'x'], [['command' => 0, 'fd' => null, 'op' => '>', 'target' => 'f']]];
        yield 'fd prefix' => ['echo x 2> f', ['echo', 'x'], [['command' => 0, 'fd' => '2', 'op' => '>', 'target' => 'f']]];
        yield 'clobber' => ['cat a >| f', ['cat', 'a'], [['command' => 0, 'fd' => null, 'op' => '>|', 'target' => 'f']]];
        yield 'append' => ['echo x >>f', ['echo', 'x'], [['command' => 0, 'fd' => null, 'op' => '>>', 'target' => 'f']]];
        yield 'dup' => ['cmd 2>&1', ['cmd'], [['command' => 0, 'fd' => '2', 'op' => '>&', 'target' => '1']]];
        yield 'both streams' => ['cmd &> f', ['cmd'], [['command' => 0, 'fd' => null, 'op' => '&>', 'target' => 'f']]];
        yield 'input' => ['wc -l <in', ['wc', '-l'], [['command' => 0, 'fd' => null, 'op' => '<', 'target' => 'in']]];
        yield 'here-string' => ['cat <<< "a b"', ['cat'], [['command' => 0, 'fd' => null, 'op' => '<<<', 'target' => 'a b']]];
        yield 'quoted digit is a word, not an fd' => ["echo '2'>f", ['echo', '2'], [['command' => 0, 'fd' => null, 'op' => '>', 'target' => 'f']]];
        yield 'quoted target' => ['echo x > "a b"', ['echo', 'x'], [['command' => 0, 'fd' => null, 'op' => '>', 'target' => 'a b']]];
        yield 'rm operand after redirect' => ['rm -rf / 2>/dev/null', ['rm', '-rf', '/'], [['command' => 0, 'fd' => '2', 'op' => '>', 'target' => '/dev/null']]];
    }

    /**
     * @param list<string> $words
     * @param list<array{command: int, fd: ?string, op: string, target: ?string}> $redirections
     */
    #[DataProvider('redirectionCases')]
    public function testRedirectionsAreReportedAndRemovedFromWords(string $line, array $words, array $redirections): void
    {
        $parsed = ShellWords::parse($line);

        $this->assertSame([$words], $parsed->commands);
        $this->assertSame($redirections, $parsed->redirections);
        $this->assertTrue($parsed->hasRedirection());
        $this->assertTrue($parsed->complete);
    }

    public function testRedirectionIsKeyedToItsCommand(): void
    {
        $parsed = ShellWords::parse('ls; echo x >f');

        $this->assertSame(1, $parsed->redirections[0]['command']);
        $this->assertSame([['ls'], ['echo', 'x']], $parsed->commands);
    }

    public function testHeredocBodyIsSkippedNotParsedAsCommands(): void
    {
        $parsed = ShellWords::parse("cat <<'EOF' >out\nrm -rf /\nEOF\nls");

        $this->assertSame([['cat'], ['ls']], $parsed->commands);
        $this->assertSame('EOF', $parsed->redirections[0]['target']);
        $this->assertTrue($parsed->complete);
    }

    public function testTabStrippingHeredocDelimiter(): void
    {
        $parsed = ShellWords::parse("cat <<-END\n\tbody\n\tEND\npwd");

        $this->assertSame([['cat'], ['pwd']], $parsed->commands);
        $this->assertTrue($parsed->complete);
    }

    /**
     * Substitutions are reported (a fail-closed allow check must refuse them)
     * and their raw text stays inside the word they sit in — no splitting on
     * the operators inside them.
     */
    public function testSubstitutionsAreReportedAndKeptRaw(): void
    {
        $parsed = ShellWords::parse('echo $(rm -rf /; x) `id` <(ls) >(cat) "$(a "b c")" ${X:-a b}');

        $this->assertSame(['$(', '`', '<(', '>(', '$('], $parsed->substitutions);
        $this->assertTrue($parsed->hasSubstitution());
        $this->assertSame(
            [['echo', '$(rm -rf /; x)', '`id`', '<(ls)', '>(cat)', '$(a "b c")', '${X:-a b}']],
            $parsed->commands,
        );
        $this->assertSame([], $parsed->operators);
        $this->assertTrue($parsed->complete);
    }

    public function testPlainLineHasNoSubstitutionOrRedirection(): void
    {
        $parsed = ShellWords::parse("git log --oneline -n '5'");

        $this->assertFalse($parsed->hasSubstitution());
        $this->assertFalse($parsed->hasRedirection());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function incompleteCases(): iterable
    {
        yield 'unterminated single quote' => ["rm '-rf /"];
        yield 'unterminated double quote' => ['rm "-rf /'];
        yield 'unterminated ANSI-C quote' => ["rm \$'-rf /"];
        yield 'trailing backslash' => ['rm -rf /\\'];
        yield 'unclosed command substitution' => ['echo $(ls'];
        yield 'unclosed backtick' => ['echo `ls'];
        yield 'unclosed parameter expansion' => ['echo ${HOME'];
        yield 'redirection without target' => ['echo >'];
        yield 'redirection target is an operator' => ['echo > ; ls'];
        yield 'heredoc without body' => ['cat <<EOF'];
        yield 'heredoc without delimiter line' => ["cat <<EOF\nbody"];
    }

    #[DataProvider('incompleteCases')]
    public function testUnparseableLinesAreFlaggedIncomplete(string $line): void
    {
        $this->assertFalse(ShellWords::parse($line)->complete);
    }

    public function testIncompleteLineStillYieldsBestEffortWords(): void
    {
        $this->assertSame([['rm', '-rf /']], ShellWords::parse("rm '-rf /")->commands);
    }

    public function testDequotedRejoinsOneCommandPerLine(): void
    {
        $this->assertSame(
            "rm -rf x\nfind . -delete",
            ShellWords::parse("rm '-rf' x 2>/dev/null; find . \"-delete\"")->dequoted(),
        );
    }
}
