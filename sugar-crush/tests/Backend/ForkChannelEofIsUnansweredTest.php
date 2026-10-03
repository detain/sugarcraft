<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\ChildChannel;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 1.C-1: the child has NO deadline of its own on a question — the
 * parent owns the policy — so the parent going away is the only thing that can
 * end the wait, and it must end it as a refusal, never as a grant
 * (Appendix O §5.1: "the parent's death shows as EOF, which is a refusal").
 *
 * Driven on a real socketpair in one process: the "parent" end is closed right
 * after the `ask` frame is written, which is exactly what the child sees when
 * the turn's parent tears down or dies.
 *
 * The resolution is `cancelled` — the {@see \SugarCraft\Crush\Permissions\DenialKind::Unanswered}
 * half, distinct from a reject. The approver contract is still `bool` until
 * 1.C-2 widens it, so through {@see ChildChannel::approver()} it reaches the
 * engine as a plain refusal; the distinction is carried by the resolution for
 * that step to read.
 */
final class ForkChannelEofIsUnansweredTest extends TestCase
{
    /** @var list<resource> */
    private array $open = [];

    protected function tearDown(): void
    {
        foreach ($this->open as $socket) {
            if (\is_resource($socket)) {
                \fclose($socket);
            }
        }
        parent::tearDown();
    }

    public function testTheParentHangingUpMidQuestionSettlesItUnanswered(): void
    {
        [$parent, $child] = $this->pair();
        $writes = 0;
        $channel = ChildChannel::new(
            $child,
            static function (array $frame) use ($child, $parent, &$writes): void {
                $body = \serialize($frame);
                \fwrite($child, \pack('N', \strlen($body)) . $body);
                $writes++;
                \fclose($parent);
            },
            self::drain(),
            'default',
        );

        $resolution = $channel->ask(new ToolCall('call_1', 'Edit', []), self::gateAsk());

        self::assertSame(1, $writes, 'the question was never put');
        self::assertTrue($resolution->cancelled, 'EOF must settle the question as unanswered, not as a decision');
        self::assertNull($resolution->reply);
        self::assertFalse($resolution->permits());
        self::assertSame(ChildChannel::PARENT_GONE, $resolution->note);
        self::assertTrue($channel->isClosed());

        // Every later question settles the same way WITHOUT writing to the
        // dead stream (a write there would end the child mid-turn).
        $again = $channel->ask(new ToolCall('call_2', 'Edit', ['x' => 1]), self::gateAsk());
        self::assertTrue($again->cancelled);
        self::assertSame(1, $writes);
    }

    public function testThroughTheApproverAnUnansweredQuestionIsARefusal(): void
    {
        [$parent, $child] = $this->pair();
        $channel = ChildChannel::new(
            $child,
            static function (array $frame) use ($parent): void {
                \fclose($parent);
            },
            self::drain(),
        );

        self::assertFalse(($channel->approver())(new ToolCall('call_1', 'Edit', []), self::gateAsk()));
    }

    public function testACorruptReplyStreamIsAlsoUnanswered(): void
    {
        [$parent, $child] = $this->pair();
        $channel = ChildChannel::new(
            $child,
            static function (array $frame) use ($parent): void {
                // A header that claims an impossible length.
                \fwrite($parent, "\xFF\xFF\xFF\xFFgarbage");
            },
            self::drain(),
        );

        $resolution = $channel->ask(new ToolCall('call_1', 'Edit', []), self::gateAsk());

        self::assertTrue($resolution->cancelled);
        self::assertFalse($resolution->permits());
        self::assertNotSame(PermissionReply::Once, $resolution->reply);
    }

    public static function gateAsk(): HookResult
    {
        return (new HookResult(HookResult::ASK, 'Edit needs approval'))->withAskedBy([PermissionGateHook::NAME]);
    }

    /**
     * EngineBackend's own frame decoder — the channel is built with it in
     * production, so the test uses the same one rather than a lookalike.
     *
     * @return \Closure(string, bool): list<array<string, mixed>>
     */
    public static function drain(): \Closure
    {
        return (new \ReflectionMethod(EngineBackend::class, 'drainFrames'))->getClosure(null);
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function pair(): array
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair, 'fixture: no socketpair on this host');
        \array_push($this->open, ...$pair);

        return $pair;
    }
}
