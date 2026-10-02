<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\ToolResultsMsg;

/**
 * Audit F-H1, the dormant Chat tool path ({@see Chat::applyPostToolUse()}):
 * a PostToolUse block was a silent no-op there too — only `additionalContext`
 * was read, so a secret scanner that exited 2 on `AKIA…` let the key through
 * to the model and its reason went nowhere. The engine path was fixed in
 * `19f25de74` (Runtime::settle()); this pins the mirror, text for text.
 */
final class ChatPostToolUseBlockTest extends TestCase
{
    private const SECRET = 'AKIAIOSFODNN7EXAMPLE';

    /** The audit's scenario with a real ScriptHook that exits 2 when it sees the key. */
    public function testAScriptHookBlockWithholdsTheOutputAndSurfacesItsReason(): void
    {
        $result = $this->runOne(
            static fn(): string => 'aws_access_key_id = ' . self::SECRET,
            new ScriptHook(
                'secret-scan',
                HookEvent::PostToolUse,
                '.*',
                'printf %s "$CRUSH_TOOL_OUTPUT" | grep -q AKIA && { echo "AWS key in output" >&2; exit 2; }; exit 0',
                'scan for secrets',
            ),
        );

        $this->assertStringNotContainsString(self::SECRET, $result->result . (string) $result->error);
        $this->assertNull($result->error, 'the call succeeded; the withheld text lands where its output would have');
        $this->assertSame(
            '[output withheld by PostToolUse hook: AWS key in output] The call ran; its output is not shown.',
            $result->result,
        );
        $this->assertNull($result->denial, 'nothing refused the CALL; it ran');
    }

    /** A blocking verdict's own note is dropped (it was written while reading the refused output). */
    public function testTheBlockingHooksOwnNoteIsDropped(): void
    {
        $result = $this->runOne(
            static fn(): string => 'secret ' . self::SECRET,
            $this->postHook('scan', static fn(): HookResult => HookResult::deny('secret found', 'I saw ' . self::SECRET)),
        );

        $this->assertStringNotContainsString(self::SECRET, $result->result);
        $this->assertStringStartsWith('[output withheld by PostToolUse hook: secret found]', $result->result);
    }

    /** An empty reason still reads as a sentence. */
    public function testAnEmptyReasonSaysSo(): void
    {
        $result = $this->runOne(
            static fn(): string => 'x',
            $this->postHook('scan', static fn(): HookResult => HookResult::deny('  ')),
        );

        $this->assertSame(
            '[output withheld by PostToolUse hook: no reason given] The call ran; its output is not shown.',
            $result->result,
        );
    }

    /**
     * A failed call's output lives in the error slot: the hook is shown that
     * text (Runtime hands it ToolResult::content(), the error there), and the
     * withheld text stays in the error slot so the call still reads as failed.
     */
    public function testAFailedCallsErrorOutputIsScannedAndWithheldToo(): void
    {
        $seen = [];
        $result = $this->runOne(
            static function (): string {
                throw new \RuntimeException('connect failed with key ' . self::SECRET);
            },
            $this->postHook('scan', static function (HookContext $c) use (&$seen): HookResult {
                $seen[] = $c->toolOutput;

                return str_contains($c->toolOutput, 'AKIA') ? HookResult::deny('key in stderr') : HookResult::allow();
            }),
        );

        $this->assertCount(1, $seen);
        $this->assertStringContainsString(self::SECRET, $seen[0], 'the hook must see the error text to judge it');
        $this->assertSame('', $result->result);
        $this->assertSame(
            '[output withheld by PostToolUse hook: key in stderr] The call ran; its output is not shown.',
            $result->error,
        );
    }

    /** An ASK nobody can answer after the fact fails closed, like Runtime. */
    public function testAnAskFailsClosed(): void
    {
        $result = $this->runOne(
            static fn(): string => 'out',
            $this->postHook('scan', static fn(): HookResult => HookResult::ask('show this?')),
        );

        $this->assertStringStartsWith('[output withheld by PostToolUse hook: show this?]', $result->result);
    }

    /** The control: a permitting hook's note is still appended, output intact. */
    public function testAPermittingHookStillAppendsItsNote(): void
    {
        $result = $this->runOne(
            static fn(): string => 'out',
            $this->postHook('note', static fn(): HookResult => HookResult::allow('', 'checked')),
        );

        $this->assertSame("out\n\nchecked", $result->result);
    }

    /** A hook that throws is reported to the model, as Runtime::settle() does, not thrown through the loop. */
    public function testAThrowingHookIsAnAnnotation(): void
    {
        $result = $this->runOne(
            static fn(): string => 'out',
            $this->postHook('broken', static function (): HookResult {
                throw new \LogicException('scanner crashed');
            }),
        );

        $this->assertSame("out\n\n[PostToolUse hook failed: LogicException: scanner crashed]", $result->result);
    }

    // ---- fixtures ----------------------------------------------------------

    private function runOne(\Closure $tool, HookInterface $hook): ToolResult
    {
        $hooks = new HookManager(new HookRegistry());
        $hooks->register($hook);

        $chat = (new Chat())->registerTool('probe', $tool)->withHooks($hooks);
        [$running, $cmd] = $chat->update(new AssistantMsg(
            Message::assistant('running')->withToolCalls([new ToolCall('probe', [], 'call_1')]),
        ));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $loop = \React\EventLoop\Loop::get();
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved, $loop): void {
            $resolved = $msg;
            $loop->stop();
        });
        if ($resolved === null) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertInstanceOf(ToolResultsMsg::class, $resolved, 'the tool batch did not complete');
        $this->assertCount(1, $resolved->results);

        return $resolved->results[0];
    }

    private function postHook(string $name, \Closure $decide): HookInterface
    {
        return new class ($name, $decide) implements HookInterface {
            public function __construct(private readonly string $hookName, private readonly \Closure $decide) {}
            public function name(): string { return $this->hookName; }
            public function event(): HookEvent { return HookEvent::PostToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult { return ($this->decide)($context); }
        };
    }
}
