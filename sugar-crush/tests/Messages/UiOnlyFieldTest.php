<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Messages;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Audit 15b-03: {@see Message::$uiOnly} marks a row the model must never see.
 * The flag is only as good as its survival - a wither that rebuilds the
 * message without it, or a resume that reads it back as false, puts the row
 * straight back on the wire - so every rebuild route is pinned here.
 */
final class UiOnlyFieldTest extends TestCase
{
    public function testTheFactoriesDefaultToAgentVisibleAndNoticeIsUiOnly(): void
    {
        $this->assertFalse(Message::user('u')->uiOnly);
        $this->assertFalse(Message::assistant('a')->uiOnly);
        $this->assertFalse(Message::system('s')->uiOnly);

        $notice = Message::notice('Queued (1 waiting)', 1_700_000_000);
        $this->assertTrue($notice->uiOnly);
        $this->assertSame(Role::System, $notice->role);
        $this->assertSame(1_700_000_000, $notice->createdAt);
    }

    public function testWithUiOnlySetsAndClearsTheFlagAndKeepsEverythingElse(): void
    {
        $message = Message::assistant('a', 5, 'thought');
        $flagged = $message->withUiOnly();

        $this->assertTrue($flagged->uiOnly);
        $this->assertFalse($message->uiOnly, 'immutable: the original is untouched');
        $this->assertSame([Role::Assistant, 'a', 5, 'thought'], [$flagged->role, $flagged->content, $flagged->createdAt, $flagged->reasoning]);
        $this->assertFalse($flagged->withUiOnly(false)->uiOnly);
    }

    public function testEveryWitherPreservesTheFlag(): void
    {
        $flagged = Message::assistant('a')->withUiOnly();

        $rebuilt = [
            'attachFile' => $flagged->attachFile('/tmp/x'),
            'attachImage' => $flagged->attachImage('/tmp/x.png'),
            'withToolCalls' => $flagged->withToolCalls([new ToolCall('bash', [], 'c1')]),
            'withToolResults' => $flagged->withToolResults([ToolResult::ok('bash', 'ok', 'c1')]),
            'withReasoning' => $flagged->withReasoning('r'),
            'withImage' => $flagged->withImage('bytes', 'kitty'),
            'withUsage' => $flagged->withUsage(Usage::reported(1, 0.0, 1, 0)),
            'withLengthStopped' => $flagged->withLengthStopped(true),
            'withStepsTruncated' => $flagged->withStepsTruncated(true),
        ];

        foreach ($rebuilt as $wither => $message) {
            $this->assertTrue($message->uiOnly, "{$wither}() dropped the flag");
        }
    }

    public function testTheFlagRoundTripsThroughThePersistedShape(): void
    {
        $row = Message::user('/help', 7)->withUiOnly()->jsonSerialize();

        $this->assertTrue($row['uiOnly']);
        $back = Message::fromArray(json_decode((string) json_encode($row), true));
        $this->assertTrue($back->uiOnly);
        $this->assertSame([Role::User, '/help', 7], [$back->role, $back->content, $back->createdAt]);
    }

    public function testAnAgentVisibleRowSerialisesExactlyAsBeforeTheFlag(): void
    {
        $row = Message::assistant('legacy')->jsonSerialize();

        $this->assertArrayNotHasKey('uiOnly', $row, 'pre-flag transcripts and new visible rows stay byte-identical');
        $this->assertFalse(Message::fromArray($row)->uiOnly, 'a row with no key is visible');
        $this->assertFalse(Message::fromArray(['role' => 'user', 'content' => 'x', 'uiOnly' => 'yes'])->uiOnly, 'only a real true counts');
    }

    public function testTheFlagNeverReachesTheWireShape(): void
    {
        $this->assertSame(['role' => 'system', 'content' => 'n'], Message::notice('n')->toWire());
    }

    public function testAgentVisibleDropsUiOnlyRowsAndReindexes(): void
    {
        $history = [
            3 => Message::notice('launch warning'),
            7 => Message::user('/help')->withUiOnly(),
            9 => Message::user('real'),
            11 => Message::assistant('answer'),
        ];

        $visible = Message::agentVisible($history);

        $this->assertSame([0, 1], array_keys($visible));
        $this->assertSame(['real', 'answer'], array_map(static fn(Message $m): string => $m->content, $visible));
        $this->assertSame([], Message::agentVisible([]));
    }
}
