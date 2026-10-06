<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\Settings\SessionSettings;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\ReadOnlyCommands;
use SugarCraft\Crush\Permissions\SedCommand;
use SugarCraft\Crush\Permissions\ShellCompound;
use SugarCraft\Crush\Permissions\ShellWords;
use SugarCraft\Crush\ToolCall;

/**
 * User decision 2026-10-11: `sed` with a script that only reads, and `for` /
 * `while read` loops (and an `if` inside one) whose every command is
 * read-only, run without asking under `default` / `accept-edits` and are
 * allowed under `plan` — the shapes most of the user's 24 still-prompting
 * logged asks took.
 *
 * Fail closed: `sed -i`, `-f`, `w`/`W`/`e` and `s///w`/`s///e` anywhere, a
 * script the parser cannot read, a loop over a command substitution, a body
 * with any command that is not read-only, a redirection to a file, a
 * variable that is not the loop's, a re-bound loop name, and an unknown loop
 * value that could reach a checked command as an option all still ask.
 */
final class ReadOnlySedAndLoopsTest extends TestCase
{
    use ReadOnlyGateTrait;

    private string $base = '';

    private string $root = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/rosl-' . bin2hex(random_bytes(4));
        mkdir($base . '/project/sugar-crush', 0o700, true);
        $this->base = (string) realpath($base);
        $this->root = $this->base . '/project';
        SessionSettings::reset();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        @rmdir($this->root . '/sugar-crush');
        @rmdir($this->root);
        @rmdir($this->base);
    }

    private function decide(PermissionGate $gate, string $command): PermissionDecision
    {
        return $gate->evaluate(new ToolCall('Bash', ['command' => str_replace('{root}', $this->root, $command)]), $this->root);
    }

    /** @return array<string, array{string}> */
    public static function readOnlyLines(): array
    {
        return [
            'sed prints a range' => ["sed -n '1,20p' f"],
            'sed substitutes into a pipe' => ["sed 's/a/b/' f | sort"],
            'sed with two -e scripts' => ["sed -e 's/^use //' -e 's/;.*//' f"],
            'sed with another delimiter' => ["sed 's|vendor/sugarcraft/||' f"],
            'sed with a block, = and an address range' => ["sed -n '/start/,/end/{p;=}' f"],
            'sed on the last line' => ["sed -n '\$p' f"],
            'sed with step addresses and numbered flags' => ["sed '1~2d;s/x/y/g3' f"],
            'sed reading a file in' => ["sed '/x/r other.txt' f"],
            'sed appending text with a semicolon in it' => ["sed '1a x; w out' f"],
            'sed -E -n --posix' => ["sed -E -n --posix 's/(a)/\\1/p' f"],
            'a for loop over a glob' => ['for f in src/*.php; do wc -l "$f"; done'],
            'a while read loop fed by a pipe' => ['grep -l x src/* | while read -r f; do head -3 "$f"; done'],
            'a while read loop fed by a file' => ['while IFS= read -r line; do echo "$line"; done < composer.json'],
            'a for loop over literal words, newline-separated' => ["for d in a b\ndo\n  ls \"\$d\"\ndone"],
            'nested loops' => ["for f in a b; do\n  for g in c d; do echo \"\$f\$g\"; done\ndone"],
            'an if inside a loop' => ['for f in src/*.php; do if grep -q x "$f"; then echo "$f"; fi; done'],
            'sed per file of a glob' => ['for f in src/*.php; do sed -n 1p "$f"; done'],
            'sed after -- on read input' => ['while read -r f; do sed -n 1p -- "$f"; done < list'],
            'a known value as the command' => ['for c in cat wc; do $c f; done'],
            'a known value inside a sed script' => ['for n in 3 5; do sed -n "${n}p" f; done'],
            'a loop piped on' => ['for f in a b; do echo "$f"; done | sort -u'],
            'a plain ${name} in a loop body' => ['for ns in Core Kit; do grep -rl "SugarCraft\\\\${ns}" src; done'],
        ];
    }

    #[DataProvider('readOnlyLines')]
    public function testAReadOnlySedOrLoopRunsWithoutAsking(string $command): void
    {
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(), $command), $command);
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(PermissionMode::AcceptEdits), $command), $command);
        self::assertSame(PermissionDecision::Allow, $this->decide(new PermissionGate(PermissionMode::Plan), $command), $command);
    }

    /** @return array<string, array{string}> */
    public static function notReadOnly(): array
    {
        return [
            'sed -i' => ['sed -i s/a/b/ f'],
            'sed -i clustered' => ['sed -ni p f'],
            'sed --in-place' => ['sed --in-place p f'],
            'sed --in, a long-option prefix' => ['sed --in p f'],
            'sed -i after the script' => ['sed p -i f'],
            'sed -f a script file' => ['sed -f s.sed f'],
            'sed w' => ["sed 'w out' f"],
            'sed W' => ["sed -n '1W out' f"],
            'sed s///w' => ["sed 's/x/y/w out' f"],
            'sed s///gw' => ["sed 's/x/y/gw out' f"],
            'sed s///e' => ["sed 's/x/y/e' f"],
            'sed e command' => ["sed -n 'p;e ls' f"],
            'sed e in a second -e' => ["sed -e p -e 'e id' f"],
            'sed w inside a block' => ["sed -n '/x/{w out\n}' f"],
            'sed with a bracket GNU and BSD split differently' => ["sed 's/[/]/w x/' f"],
            'sed -l, whose value BSD does not take' => ['sed -l 5 p f'],
            'sed with an unknown command' => ["sed 'k' f"],
            'sed with no script' => ['sed'],
            'sed script from a variable' => ['sed "$s" f'],
            'a loop over a command substitution' => ['for f in $(ls); do cat "$f"; done'],
            'a loop body with rm' => ['for f in a; do rm "$f"; done'],
            'a loop body writing a file' => ['for f in a; do cat "$f" > out; done'],
            'a loop redirected to a file' => ['for f in a; do echo x; done > out'],
            'python -c stays an interpreter' => ['python3 -c "print(1)"'],
            'an unquoted unknown value into sed' => ['for f in *; do sed -n 1p $f; done'],
            'read input into a checked command' => ['while read f; do sed -n 1p "$f"; done < l'],
            'read input as a sed script' => ['while read f; do sed "$f" x; done < l'],
            'a variable that is not the loop\'s' => ['for f in a; do echo $HOME; done'],
            'a positional parameter' => ['for f in a; do echo "$1"; done'],
            'a re-bound loop name' => ['for f in a; do for f in b; do echo; done; done'],
            'the inner value leaking out' => ['for x in p; do for x in w; do echo; done; sed -n "$x" f; done'],
            'a value that is a sed write' => ['for f in "w out"; do sed "$f" x; done'],
            'an empty loop list' => ['for f in; do cat "$f"; done'],
            'read input into find' => ['while read -r f; do find "$f"; done < l'],
            'case' => ['case x in a) ls;; esac'],
            'until' => ['until false; do ls; done'],
            'while with another condition' => ['while true; do ls; done'],
            'do on the header line' => ['for f in a b do echo; done'],
            'a quoted keyword' => ['"for" f in a; do ls; done'],
            'an arithmetic for' => ['for ((i=0; i<3; i++)); do ls; done'],
            'a ${…} that evaluates' => ['for f in a; do echo ${f:=x}; done'],
            'a known value that is a sed write' => ['for s in w; do sed "${s} out" f; done'],
        ];
    }

    #[DataProvider('notReadOnly')]
    public function testAnythingElseStillAsks(string $command): void
    {
        self::assertSame(PermissionDecision::Ask, $this->decide($this->gate(), $command), $command);
        self::assertSame(PermissionDecision::Deny, $this->decide(new PermissionGate(PermissionMode::Plan), $command), $command);
    }

    /**
     * What plan allows and the auto-allow does not, for a loop as for any
     * line: plan withholds writes, not where a `cd` goes or how long a job
     * runs.
     */
    public function testACdInALoopOrABackgroundLoopAsksButPlanAllowsIt(): void
    {
        foreach (['for f in sugar-crush; do cd "$f"; done', 'for f in a; do ls; done &'] as $command) {
            self::assertSame(PermissionDecision::Ask, $this->decide($this->gate(), $command), $command);
            self::assertSame(PermissionDecision::Allow, $this->decide(new PermissionGate(PermissionMode::Plan), $command), $command);
        }
    }

    /**
     * The user's own logged asks (2026-10-11 replay of `session.db`) that
     * were sed pipelines and literal-list loops, verbatim but for the root:
     * each prompted before, each runs unasked now.
     *
     * @return array<string, array{string}>
     */
    public static function theUsersLoggedAsks(): array
    {
        return [
            'use statements through sed -e twice' => [<<<'EOT'
                cd {root}/sugar-crush && grep -rh '^use SugarCraft' src tests bin 2>/dev/null | sed -e 's/^use //' -e 's/;.*//' | cut -d'\' -f1,2 | sort | uniq -c | sort -rn
                EOT],
            'a namespace loop' => [<<<'EOT'
                cd {root}/sugar-crush && for ns in Core Sprinkles Mouse Layout Mosaic Forms Fuzzy Pty Mcp Shine Veil Diff Focus Toast Kit; do echo "### $ns"; grep -rl "SugarCraft\\\\$ns" src bin 2>/dev/null | head -12 | sed 's/^/   /'; done
                EOT],
            'a namespace loop with ${ns} and fold' => [<<<'EOT'
                cd {root}/sugar-crush && for ns in Core Sprinkles Mouse Forms Fuzzy Layout Mcp Mosaic Veil Shine Diff Focus Pty Toast; do echo "### $ns"; grep -rhoE "^use SugarCraft\\\\${ns}\\\\[A-Za-z0-9_\\\\]+" src/ bin/ 2>/dev/null | sed "s/^use SugarCraft\\\\${ns}\\\\//" | sort -u | tr '\n' ' ' | fold -w 300; echo; done
                EOT],
            'vendor listing through sed' => [<<<'EOT'
                cd {root}/sugar-crush && ls -d vendor/sugarcraft/* 2>/dev/null | sed 's|vendor/sugarcraft/||' ; echo "=== direct use statements in src ==="; grep -rhoP '^use SugarCraft\\\\[A-Za-z]+' src/ | sed 's/^use SugarCraft..//' | sort | uniq -c | sort -rn
                EOT],
            'use counts through sed' => [<<<'EOT'
                cd {root}/sugar-crush && grep -rho 'use SugarCraft\\[A-Za-z0-9_]*' src/ bin/ 2>/dev/null | sed 's/use SugarCraft.//' | sort | uniq -c | sort -rn
                EOT],
        ];
    }

    #[DataProvider('theUsersLoggedAsks')]
    public function testTheUsersLoggedSedAndLoopAsksNoLongerAsk(string $command): void
    {
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(), $command));
    }

    public function testADenyOrAskRuleStillSeesTheCommandsOfALoopBody(): void
    {
        $gate = $this->gate(PermissionMode::Default, [
            new PermissionRule('Bash(wc *)', PermissionAction::Deny),
            new PermissionRule('Bash(head *)', PermissionAction::Ask),
        ]);

        self::assertSame(PermissionDecision::Deny, $this->decide($gate, 'for f in a b; do wc -l "$f"; done'));
        self::assertSame(PermissionDecision::Ask, $this->decide($gate, 'for f in a; do if true; then head -3 "$f"; fi; done'));
        self::assertSame(PermissionDecision::Allow, $this->decide($gate, 'for f in a; do cat "$f"; done'));
    }

    public function testAProtectedFileInALoopStillAsksAndTheHookRefusesIt(): void
    {
        $command = 'for f in .env config.php; do cat "$f"; done';
        self::assertTrue(ProtectFilesHook::namesProtectedFile($command));
        self::assertFalse(ReadOnlyCommands::autoAllows($command, $this->root));
        self::assertSame(PermissionDecision::Ask, $this->decide($this->gate(), $command));
    }

    public function testTheSedParserReadsWhatItAllows(): void
    {
        foreach (['p', '1,5p', '/a/,+3d', '0,/re/s//x/', '$!N;P;D', ':a;N;$!ba;s/\n/ /g', 'y/abc/xyz/', '\%x%p', '/x/I{s/a/b/2;p}', "1i\\\nhead", 'l 40', 'q5', 'F;='] as $script) {
            self::assertTrue(SedCommand::scriptIsReadOnly($script), $script);
        }
        foreach (['w f', 'W f', 'e', 'e ls', 's/a/b/w f', 's/a/b/e', 's/a/b/ w f', 'v', 'L', '{p', 'p}', 'pp', 's/a/b', 'y/ab/c', ':', '1:a', '/[/]/p', 's/a/b/;w f'] as $script) {
            self::assertFalse(SedCommand::scriptIsReadOnly($script), $script);
        }
        self::assertFalse(SedCommand::isReadOnly(['-n', ReadOnlyCommands::UNKNOWN, 'f']), 'a script from an unknown loop value');
        self::assertTrue(SedCommand::isReadOnly(['-n', '1p', ReadOnlyCommands::UNKNOWN]), 'an unknown file operand is still only read');
    }

    public function testTheStructureIsReadOffTheTokens(): void
    {
        $items = ShellCompound::parse(ShellWords::parse("ls | while read -r f; do\n  if grep -q x \"\$f\"; then echo \"\$f\"; fi\ndone < list && pwd"));
        self::assertNotNull($items);
        self::assertSame(['simple', 'while', 'simple'], array_column($items, 'kind'));
        self::assertSame('|', $items[0]['terminator']);
        self::assertSame(['f'], $items[1]['vars']);
        self::assertSame('&&', $items[1]['terminator']);
        self::assertSame('if', $items[1]['body'][0]['kind']);
        self::assertSame(['grep', 'echo'], array_map(
            static fn (array $node): string => $node['words'][0],
            array_slice(ShellCompound::simpleCommands($items), 1, 2),
        ));
        self::assertSame('grep -q x "$f"', ShellCompound::simpleCommands($items)[1]['source'], 'the keyword is not part of the command');

        $flat = ShellCompound::parse(ShellWords::parse('git status && ls; pwd'));
        self::assertNotNull($flat);
        self::assertFalse(ShellCompound::hasCompound($flat));

        foreach (['for f in a; do ls', 'done', 'if ls; then pwd', 'for f in a; do; ls; done', 'while read f; ls; done', '(for f in a; do ls; done)'] as $broken) {
            self::assertNull(ShellCompound::parse(ShellWords::parse($broken)), $broken);
        }
    }

    public function testTheTokeniserRecordsTerminatorsAndLiveDollars(): void
    {
        $parsed = ShellWords::parse("a \"\$x\" '\$y' \${z}w |\n\nb;");
        self::assertSame([['a', '$x', '$y', '${z}w'], ['b']], $parsed->commands);
        self::assertSame(['|', ';'], $parsed->terminators, 'a blank line ends no command');
        self::assertSame([[], [[0, true]], [], [[0, false]]], $parsed->dollars[0], "only the live \$ are recorded; '\$y' is text");
        self::assertSame(['{z}'], $parsed->parameterExpansionBodies);
    }
}
