<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\ToolCall;

/**
 * S-T03: a Bash command past the 64 KB length door is not parsed or classified
 * (it cost ~2.5 s at 1 MB); it asks, naming the size. The rm -rf floor stays.
 */
final class PermissionGateLengthDoorTest extends TestCase
{
    private static function bash(string $command): ToolCall
    {
        return new ToolCall('Bash', ['command' => $command]);
    }

    private static function gate(PermissionMode $mode): PermissionGate
    {
        return (new PermissionGate($mode, [], new \SugarCraft\Code\Permissions\SafetyClassifier()))->withReadOnlyAutoAllow(true);
    }

    /** @return iterable<string, array{PermissionMode}> */
    public static function askingModes(): iterable
    {
        yield 'default' => [PermissionMode::Default];
        yield 'accept-edits' => [PermissionMode::AcceptEdits];
        yield 'auto' => [PermissionMode::Auto];
    }

    /** @dataProvider askingModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('askingModes')]
    public function testMegabyteEchoAsksNamingSizeQuickly(PermissionMode $mode): void
    {
        $gate = self::gate($mode);
        $gate->evaluate(self::bash('echo warm'), '/tmp');
        $command = 'echo ' . str_repeat('a', 1_000_000);

        $t = microtime(true);
        $decision = $gate->evaluate(self::bash($command), '/tmp');
        $ms = (microtime(true) - $t) * 1000;

        $this->assertSame(PermissionDecision::Ask, $decision);
        $this->assertLessThan(50.0, $ms);
        $this->assertStringContainsString(' bytes', (string) $gate->lastAutoReason());
        $this->assertStringContainsString((string) strlen($command), (string) $gate->lastAutoReason());
    }

    public function testPlanStillDeniesOversizedCommandQuickly(): void
    {
        $gate = self::gate(PermissionMode::Plan);
        $t = microtime(true);
        $decision = $gate->evaluate(self::bash('echo ' . str_repeat('a', 1_000_000)), '/tmp');

        $this->assertSame(PermissionDecision::Deny, $decision);
        $this->assertLessThan(50.0, (microtime(true) - $t) * 1000);
    }

    public function testBelowCeilingVerdictUnchanged(): void
    {
        $gate = self::gate(PermissionMode::Default);

        $this->assertSame(PermissionDecision::Allow, $gate->evaluate(self::bash('echo ' . str_repeat('a', 60_000)), '/tmp'));
        $this->assertNull($gate->lastAutoReason());
    }

    /** @dataProvider floorSpellings */
    #[\PHPUnit\Framework\Attributes\DataProvider('floorSpellings')]
    public function testRmRfFloorSurvivesPastTheCeiling(string $head): void
    {
        $pad = ' ; echo ' . str_repeat('a', 100_000);

        foreach ([PermissionMode::Default, PermissionMode::BypassPermissions] as $mode) {
            $this->assertSame(
                PermissionDecision::Deny,
                self::gate($mode)->evaluate(self::bash($head . $pad), '/tmp'),
                "$head under {$mode->name}",
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function floorSpellings(): iterable
    {
        yield 'bare' => ['rm -rf /'];
        yield 'quote-split' => ["r''m -rf /"];
        yield 'backslash' => ['r\\m -rf /'];
        yield 'absolute' => ['/bin/rm -rf ~'];
    }
}
