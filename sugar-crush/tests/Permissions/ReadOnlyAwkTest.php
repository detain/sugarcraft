<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Config\Settings\SessionSettings;
use SugarCraft\Crush\Permissions\AwkCommand;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\ReadOnlyCommands;
use SugarCraft\Crush\ToolCall;

/**
 * User decision 2026-10-11, part 3: `awk` / `gawk` / `mawk` with a SIMPLE
 * INLINE program run unasked under `default` / `accept-edits` and are allowed
 * under `plan`. Strict, because awk is a language: anything that could write,
 * run a command, read from a command or file of its choosing, or load code is
 * refused, and so is every `>` the check cannot prove is a comparison.
 */
final class ReadOnlyAwkTest extends TestCase
{
    private string $base = '';

    private string $root = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/roawk-' . bin2hex(random_bytes(4));
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

    private function gate(PermissionMode $mode = PermissionMode::Default): PermissionGate
    {
        return (new PermissionGate($mode))->withReadOnlyAutoAllow(true);
    }

    /** @return array<string, array{string}> */
    public static function readOnlyLines(): array
    {
        return [
            'print a field, counted' => ["awk '{print \$1}' f | sort | uniq -c"],
            'a comparison in a pattern' => ["awk -F: '\$3 > 1000 {print \$1}' /etc/passwd"],
            'a comparison in parentheses' => ["awk '{ if (\$2 > 5) print \$1 }' f"],
            'a sum' => ["awk '{s+=\$2} END{print s+0}' f"],
            'a regex pattern' => ["awk '/foo/ {print}' f"],
            'strings and an || with no >' => ["awk '\$1 == \"a\" || \$1 == \"b\" {print \$2 \"-\" \$3}' f"],
            '-v and an attached -F' => ["awk -F, -v n=2 '{print \$n}' f.csv"],
            'gawk and mawk' => ["gawk 'NR==1' f && mawk 'length > 80' f"],
            'plain getline' => ["awk '{ getline line; print line }' f"],
            'in a loop' => ['for f in src/*.php; do awk \'END{print NR}\' "$f"; done'],
        ];
    }

    #[DataProvider('readOnlyLines')]
    public function testASimpleInlineProgramRunsWithoutAsking(string $command): void
    {
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(), $command), $command);
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(PermissionMode::AcceptEdits), $command), $command);
        self::assertSame(PermissionDecision::Allow, $this->decide(new PermissionGate(PermissionMode::Plan), $command), $command);
    }

    /** @return array<string, array{string}> */
    public static function notReadOnly(): array
    {
        return [
            'system()' => ["awk '{system(\"rm \" \$1)}' f"],
            'system with a space' => ["awk '{system (\"id\")}' f"],
            'print to a file' => ["awk '{print > \"out\"}' f"],
            'append to a file' => ["awk '{print >> \"out\"}' f"],
            'print to a file named by a field' => ["awk '{print > \$1}' f"],
            'printf redirected' => ["awk '{printf \"%s\", \$0 > \"/dev/stderr\"}' f"],
            'redirection after parentheses' => ["awk '{print (\$1) > (\$2)}' f"],
            'a > in a program with a string' => ["awk '\$1 > \"m\" {print}' f"],
            'a > in a program with a regex' => ["awk -F: '\$3 >= 1000 && \$7 !~ /nologin/ {print \$1}' /etc/passwd"],
            'pipe into a command' => ["awk '{print | \"sh\"}' f"],
            'command into getline' => ["awk 'BEGIN{\"date\" | getline d; print d}'"],
            'a coprocess' => ["awk 'BEGIN{print \"x\" |& \"cat\"}'"],
            'getline from a file' => ["awk '{getline line < \"/etc/shadow\"; print line}' f"],
            'close()' => ["awk '{close(\"f\")}' f"],
            'fflush()' => ["awk '{fflush()}' f"],
            '@load' => ["gawk '@load \"filefuncs\"; BEGIN{}'"],
            'an indirect call' => ["gawk 'BEGIN{f=\"sys\" \"tem\"; @f(\"id\")}'"],
            '-f program file' => ['awk -f prog.awk f'],
            '-i include' => ['gawk -i inplace \'{print}\' f'],
            '--exec' => ['gawk --exec prog.awk f'],
            'mawk -W exec' => ['mawk -W exec prog.awk f'],
            'gawk --profile writes a file' => ["gawk --profile '{print}' f"],
            'gawk -o pretty-prints to a file' => ["gawk -o '{print}' f"],
            'an option after the program' => ["awk '{print}' -f x f"],
            'no program' => ['awk'],
            'a program from a variable' => ['awk "$p" f'],
            'a program from a loop value' => ['while read -r p; do awk "$p" f; done < l'],
            'a bad -v' => ["awk -v 'a[1]=2' '{print}' f"],
        ];
    }

    #[DataProvider('notReadOnly')]
    public function testAnythingElseStillAsks(string $command): void
    {
        self::assertSame(PermissionDecision::Ask, $this->decide($this->gate(), $command), $command);
        self::assertSame(PermissionDecision::Deny, $this->decide(new PermissionGate(PermissionMode::Plan), $command), $command);
    }

    /**
     * The user's own logged asks (2026-10-11 replay of `session.db`) that
     * piped through awk, verbatim but for the root.
     *
     * @return array<string, array{string}>
     */
    public static function theUsersLoggedAsks(): array
    {
        return [
            'grep -P, sed, awk, sort, uniq' => [<<<'EOT'
                cd {root}/sugar-crush && grep -rhoP '^use SugarCraft\\\\[A-Za-z\\\\]+' src/ tests/ bin/ 2>/dev/null | sed 's/^use //' | awk -F'\\\\' '{print $1"\\"$2}' | sort | uniq -c | sort -rn
                EOT],
            'grep -E, sed, awk, sort, uniq' => [<<<'EOT'
                cd {root}/sugar-crush && grep -rhoE '^use (SugarCraft\\[A-Za-z0-9_\\]+)' src/ bin/ 2>/dev/null | sed 's/^use //' | awk -F'\\\\' '{print $1"\\"$2}' | sort | uniq -c | sort -rn
                EOT],
        ];
    }

    #[DataProvider('theUsersLoggedAsks')]
    public function testTheUsersLoggedAwkAsksNoLongerAsk(string $command): void
    {
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(), $command));
    }

    public function testTheProgramCheckDirectly(): void
    {
        self::assertTrue(AwkCommand::programIsReadOnly('$1 > 0 && $2 < 3 { n++ } END { print n }'));
        self::assertTrue(AwkCommand::programIsReadOnly('{ while ((getline line) > 0) n++ }'));
        self::assertFalse(AwkCommand::programIsReadOnly('{ print } > 0 {'), 'unbalanced braces');
        self::assertFalse(AwkCommand::programIsReadOnly('{ if (a) print > b }'));
        self::assertFalse(AwkCommand::programIsReadOnly('{ print "(" ; print > "f" }'), 'a paren in a string cannot launder a redirection');
        self::assertFalse(AwkCommand::programIsReadOnly(ReadOnlyCommands::UNKNOWN));
    }
}
