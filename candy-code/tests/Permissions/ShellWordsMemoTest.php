<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ShellWords;
use SugarCraft\Code\Permissions\ShellWordsMemo;
use SugarCraft\Code\ToolCall;

/**
 * A command line is lexed ONCE per tool call (audit row 41), not once per
 * rule check. Before CC4-3 every argument-scoped `Bash(...)` rule the gate
 * tried called {@see ShellWords::parse()} on the command again, so the
 * gates below lexed their line once per rule; the observer on
 * {@see ShellWordsMemo} counts the lexes that really happen.
 */
final class ShellWordsMemoTest extends TestCase
{
    /** @var list<string> */
    private array $lexed = [];

    protected function setUp(): void
    {
        ShellWordsMemo::reset();
        $this->lexed = [];
        ShellWordsMemo::observe(function (string $line): void {
            $this->lexed[] = $line;
        });
    }

    protected function tearDown(): void
    {
        ShellWordsMemo::observe(null);
        ShellWordsMemo::reset();
    }

    private function lexesOf(string $line): int
    {
        return \count(array_keys($this->lexed, $line, true));
    }

    /** @return list<PermissionRule> */
    private static function shellRules(): array
    {
        return [
            new PermissionRule('Bash(rm *)', PermissionAction::Deny),
            new PermissionRule('Bash(curl *)', PermissionAction::Ask),
            new PermissionRule('Bash(* > /etc/*)', PermissionAction::Deny),
            new PermissionRule('Bash(sudo *)', PermissionAction::Deny),
            new PermissionRule('Bash(git add * && git commit *)', PermissionAction::Allow),
            new PermissionRule('Bash(git *)', PermissionAction::Allow),
        ];
    }

    public function testOneToolCallLexesItsCommandOnceAcrossEveryRule(): void
    {
        $command = 'git add "a b" && git commit -m "x; y"';
        $gate = new PermissionGate(PermissionMode::DontAsk, self::shellRules());

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Bash', ['command' => $command])));
        self::assertSame(1, $this->lexesOf($command), 'six shell rules were checked against one parse');
    }

    public function testANonMatchingLineIsStillLexedOnceAcrossEveryRule(): void
    {
        $command = "ls -la 'my dir' | wc -l; echo done";
        $gate = new PermissionGate(PermissionMode::BypassPermissions, self::shellRules());

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Bash', ['command' => $command])));
        self::assertSame(1, $this->lexesOf($command));
    }

    public function testTheModeEvaluatorSharesTheRulesParse(): void
    {
        $command = 'for f in a b; do wc -l "$f"; done';
        $gate = (new PermissionGate(PermissionMode::Default, self::shellRules()))->withReadOnlyAutoAllow(true);

        $gate->evaluate(new ToolCall('Bash', ['command' => $command]));
        self::assertSame(1, $this->lexesOf($command));
    }

    public function testAStructuredAllowPatternIsLexedOnceAcrossCalls(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk, self::shellRules());
        $gate->evaluate(new ToolCall('Bash', ['command' => 'git add a && git commit -m one']));
        $gate->evaluate(new ToolCall('Bash', ['command' => 'git add b && git commit -m two']));

        self::assertSame(1, $this->lexesOf('git add * && git commit *'));
        self::assertSame(1, $this->lexesOf('git add a && git commit -m one'));
        self::assertSame(1, $this->lexesOf('git add b && git commit -m two'));
    }

    public function testTheSameLineYieldsTheSameParse(): void
    {
        $first = ShellWords::parse("echo 'a;b' > /dev/null");

        self::assertSame($first, ShellWords::parse("echo 'a;b' > /dev/null"));
        self::assertNotSame($first, ShellWords::parse("echo 'a;b'  > /dev/null"), 'another spelling is another parse');
        self::assertSame(2, \count($this->lexed));
    }

    public function testTheCacheIsBoundedAndEvictsTheLeastRecentlyUsedLine(): void
    {
        ShellWords::parse('line 0');
        for ($i = 1; $i <= ShellWordsMemo::CAPACITY; ++$i) {
            ShellWords::parse('line ' . $i);
            // Keep line 0 hot, so line 1 is the oldest when the cap is hit.
            ShellWords::parse('line 0');
        }

        self::assertSame(ShellWordsMemo::CAPACITY, ShellWordsMemo::size());
        ShellWords::parse('line 0');
        ShellWords::parse('line 1');
        self::assertSame(1, $this->lexesOf('line 0'));
        self::assertSame(2, $this->lexesOf('line 1'), 'line 1 was evicted and lexed again');
    }

    public function testALineOverTheLengthDoorIsNeverKept(): void
    {
        $long = 'echo ' . str_repeat('a', ShellWordsMemo::MAX_LINE_BYTES);
        $parsed = ShellWords::parse($long);

        self::assertSame([['echo', str_repeat('a', ShellWordsMemo::MAX_LINE_BYTES)]], $parsed->commands);
        self::assertSame(0, ShellWordsMemo::size());
        ShellWords::parse($long);
        self::assertSame(2, $this->lexesOf($long));
    }

    /**
     * A 64 KiB line of short words parses to about 2 MiB of arrays; before
     * the byte budget, 32 of them stayed pinned at about 62 MiB.
     */
    public function testWordDenseLinesCannotPinMoreThanTheByteBudget(): void
    {
        $before = memory_get_usage();
        for ($i = 0; $i < ShellWordsMemo::CAPACITY; ++$i) {
            $line = substr("echo {$i}" . str_repeat(' a', ShellWordsMemo::MAX_LINE_BYTES), 0, ShellWordsMemo::MAX_LINE_BYTES - 16);
            ShellWords::parse($line);
        }
        $this->lexed = [];

        self::assertLessThanOrEqual(ShellWordsMemo::MAX_RETAINED_BYTES, ShellWordsMemo::retainedBytes());
        self::assertLessThan(ShellWordsMemo::MAX_RETAINED_BYTES + 1024 * 1024, memory_get_usage() - $before);
    }

    public function testTheByteBudgetEvictsTheLeastRecentlyUsedLine(): void
    {
        $lines = [];
        for ($i = 0; $i < ShellWordsMemo::CAPACITY; ++$i) {
            $lines[] = substr("echo {$i}" . str_repeat(' a', 8000), 0, 12000);
            ShellWords::parse($lines[$i]);
        }

        self::assertLessThan(ShellWordsMemo::CAPACITY, ShellWordsMemo::size(), 'the byte budget, not the count, evicted');
        self::assertLessThanOrEqual(ShellWordsMemo::MAX_RETAINED_BYTES, ShellWordsMemo::retainedBytes());
        ShellWords::parse($lines[\count($lines) - 1]);
        ShellWords::parse($lines[0]);
        self::assertSame(1, $this->lexesOf($lines[\count($lines) - 1]), 'the newest line is still kept');
        self::assertSame(2, $this->lexesOf($lines[0]), 'the oldest line was evicted and lexed again');
    }

    public function testResetForgetsEveryParse(): void
    {
        ShellWords::parse('ls');
        self::assertSame(1, ShellWordsMemo::size());

        ShellWordsMemo::reset();
        self::assertSame(0, ShellWordsMemo::size());
        self::assertSame(0, ShellWordsMemo::retainedBytes());
        ShellWords::parse('ls');
        self::assertSame(2, $this->lexesOf('ls'));
    }
}
