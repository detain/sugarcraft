<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Cli\HeadlessPermissionPrompt;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Tools\ToolCall;

/**
 * ISS-040: bypass answers GATE asks (owner ruling 2026-10-06) but a hook
 * rostered through {@see HeadlessPermissionPrompt::requireUnattendedAnswer()}
 * keeps its exit-3 promise: with no tty it takes the existing fail-closed
 * refusal, at a tty it gets the real question.
 */
final class HeadlessPermissionPromptUnattendedAskTest extends TestCase
{
    private ToolCall $call;

    protected function setUp(): void
    {
        HeadlessPermissionPrompt::forgetUnattendedAnswers();
        $this->call = new ToolCall('t1', 'Bash', ['command' => 'make deploy']);
    }

    protected function tearDown(): void
    {
        HeadlessPermissionPrompt::forgetUnattendedAnswers();
    }

    public function testAnUnrosteredAskStillTakesTheBypassAllow(): void
    {
        $err = fopen('php://memory', 'r+b');
        $prompt = new HeadlessPermissionPrompt(PermissionMode::BypassPermissions, null, $err);

        self::assertTrue($prompt($this->call, $this->askFrom('confirm-deploy')));
        self::assertSame('', $this->drain($err), 'the blanket grant writes nothing');
    }

    public function testARosteredAskWithNoTerminalRefusesInsteadOfAllowing(): void
    {
        HeadlessPermissionPrompt::requireUnattendedAnswer('confirm-deploy');
        $err = fopen('php://memory', 'r+b');
        $prompt = new HeadlessPermissionPrompt(PermissionMode::BypassPermissions, null, $err);

        self::assertFalse($prompt($this->call, $this->askFrom('confirm-deploy')));
        $written = $this->drain($err);
        self::assertStringContainsString('deploy', $written);
        self::assertStringNotContainsString('Approved by bypass-permissions mode', $written);
    }

    public function testARosteredAskAtATerminalPutsTheRealQuestion(): void
    {
        HeadlessPermissionPrompt::requireUnattendedAnswer('confirm-deploy');
        $in = fopen('php://memory', 'r+b');
        fwrite($in, "n\n");
        rewind($in);
        $err = fopen('php://memory', 'r+b');
        $prompt = new HeadlessPermissionPrompt(
            PermissionMode::BypassPermissions,
            $in,
            $err,
            interactive: true,
        );

        self::assertFalse($prompt($this->call, $this->askFrom('confirm-deploy')));
        self::assertStringContainsString('confirm-deploy.sh', $this->drain($err));
    }

    public function testEveryAskedByNameIsConsultedNotJustTheFirst(): void
    {
        HeadlessPermissionPrompt::requireUnattendedAnswer('late-guard');

        $asked = (new HookResult(HookResult::ASK, 'q', null, ''))
            ->withAskedBy(['early-guard', 'late-guard']);
        self::assertTrue(HeadlessPermissionPrompt::demandsUnattendedAnswer($asked));

        $unasked = (new HookResult(HookResult::ASK, 'q', null, ''))
            ->withAskedBy(['early-guard']);
        self::assertFalse(HeadlessPermissionPrompt::demandsUnattendedAnswer($unasked));
    }

    private function askFrom(string $name): HookResult
    {
        return (new HookResult(HookResult::ASK, 'confirm-deploy.sh wants to run make deploy', null, ''))
            ->withAskedBy([$name]);
    }

    /** @param resource $stream */
    private function drain($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
