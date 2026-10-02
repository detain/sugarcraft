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

    /**
     * Quote removal makes `'{-delete,}'` and `{-delete,}` the same word, and
     * only the unquoted one is brace-expanded into a `-delete` flag — so the
     * expandability of each word is recorded beside it (audit F-P2).
     */
    public function testExpandableFlagsEachWordBashMayRewrite(): void
    {
        $parsed = ShellWords::parse(
            "find . {-delete,} '{-delete,}' *.php '*.php' \$X \"\$X\" '\$X' \$'\\x41' a`b` \$(c) <(d) ~",
        );

        $this->assertSame(
            [[false, false, true, false, true, false, true, true, false, false, true, true, true, false]],
            $parsed->expandable,
        );
    }

    public function testExpandableStaysParallelToCommandsAcrossRedirectsAndOperators(): void
    {
        $parsed = ShellWords::parse('ls *.c 2>/dev/null | wc -l; > f');

        $this->assertSame([['ls', '*.c'], ['wc', '-l'], []], $parsed->commands);
        $this->assertSame([[false, true], [false, false], []], $parsed->expandable);
    }

    /**
     * `${…}` and `$[…]` can evaluate arithmetic and prompt strings, both of
     * which run a substitution hidden in a variable's value; they are recorded
     * so a fail-closed caller can refuse them. A `$(` nested inside one runs
     * as surely as a bare one and must be recorded as a substitution — it used
     * to be skipped along with the braces.
     */
    public function testParameterExpansionsAreRecordedWithTheirNestedSubstitutions(): void
    {
        $plain = ShellWords::parse('echo ${HOME} "$[1+1]"');
        $this->assertSame(['${', '$['], $plain->parameterExpansions);
        $this->assertFalse($plain->hasSubstitution());

        $nested = ShellWords::parse('echo ${x:-$(rm x)} "${y:-`rm y`}"');
        $this->assertSame(['$(', '`'], $nested->substitutions);
    }

    /**
     * bash removes `\<newline>` before it reads what follows a `$`, so a
     * continuation cannot hide an expansion or a substitution from the parse.
     */
    public function testLineContinuationAfterDollarDoesNotHideAnExpansion(): void
    {
        $this->assertSame(['${'], ShellWords::parse("echo \$\\\n{x:=1}")->parameterExpansions);
        $this->assertSame(['$('], ShellWords::parse("echo \"\$\\\n(rm x)\"")->substitutions);
        $this->assertSame([['echo', '${x}']], ShellWords::parse("echo \$\\\n{x}")->commands);
    }

    public function testDequotedRejoinsOneCommandPerLine(): void
    {
        $this->assertSame(
            "rm -rf x\nfind . -delete",
            ShellWords::parse("rm '-rf' x 2>/dev/null; find . \"-delete\"")->dequoted(),
        );
    }

    /**
     * The raw source of each simple command, split on UNQUOTED operators only
     * and kept parallel to {@see ShellWords::$commands} — including across a
     * redirection-only command and a skipped here-doc body.
     */
    public function testSourcesKeepEachCommandsRawTextParallelToItsWords(): void
    {
        $parsed = ShellWords::parse("git commit -m \"a; b\" 2>/dev/null && echo `id` | > f\ncat <<EOF\nbody; rm x\nEOF\nls");

        $this->assertSame(
            ['git commit -m "a; b" 2>/dev/null', 'echo `id`', '> f', 'cat <<EOF', 'ls'],
            $parsed->sources,
        );
        $this->assertCount(\count($parsed->commands), $parsed->sources);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function redirectionInertness(): iterable
    {
        yield 'stderr to /dev/null' => ['ls 2>/dev/null', true];
        yield 'fd duplication' => ['ls 2>&1', true];
        yield 'fd close' => ['ls 2>&-', true];
        yield 'input from a file' => ['wc -l < f', true];
        yield 'here-string' => ['cat <<< hi', true];
        yield 'output to a file' => ['ls > f', false];
        yield 'append to a file' => ['ls >> f', false];
        yield 'both streams to a file' => ['ls &> f', false];
        yield 'read-write open' => ['ls <> f', false];
        yield 'network input' => ['cat < /dev/tcp/example.com/80', false];
        yield 'here-doc body is never examined' => ["cat <<EOF\nx\nEOF", false];
    }

    #[DataProvider('redirectionInertness')]
    public function testIsInertRedirectionJudgesOperatorAndTarget(string $line, bool $inert): void
    {
        $redirections = ShellWords::parse($line)->redirections;
        $this->assertCount(1, $redirections);
        $this->assertSame($inert, ShellWords::isInertRedirection($redirections[0]));
    }
}
