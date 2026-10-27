<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\TranscriptProjector;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\Schema\EventSchemas;
use SugarCraft\Code\Protocol\Schema\ProtocolSchema;
use SugarCraft\Code\Protocol\SessionFeed;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-SEC-2.b: the diff the server computed for a gate question reaches both
 * wire surfaces — the host transcript event and the session feed's pending-ask
 * summary — through the real frame round-trip, not a hand-built handle. The
 * schema declares the key optional, so the arms WITH and WITHOUT a preview
 * both validate.
 */
final class PermissionAskDiffEventTest extends TestCase
{
    /** @var list<string> */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            self::pruneScratch($dir);
        }
        $this->scratchDirs = [];
    }

    public function testAPreviewedAskReachesBothWireSurfacesWithItsDiff(): void
    {
        $root = $this->scratchDir();
        // 18 lines apart: the context windows cannot merge, so a one-hunk
        // (truncated) preview would show here as a count of 1.
        $lines = array_map(static fn (int $i): string => "l$i", range(1, 20));
        $lines[1] = 'alpha';
        $lines[17] = 'omega';
        file_put_contents($root . '/f.txt', implode("\n", $lines) . "\n");
        $settle = static function (): void {
        };

        $frame = PendingAsk::describe(self::batchedEditCall(), self::gateAsking(), 'default', $root);
        self::assertArrayHasKey('diff', $frame);
        $ask = PendingAsk::fromFrame($frame, $settle);
        self::assertNotNull($ask);

        $event = TranscriptProjector::new()->backendEvent(new PermissionAsked($ask), 's', 't');
        self::assertNotNull($event);
        self::assertSame(SessionEvent::PERMISSION_REQUESTED, $event->type);
        self::assertSame($frame['diff'], $event->data['diff'], 'the projector passes it through');
        self::assertGreaterThanOrEqual(2, substr_count((string) $frame['diff'], '@@ -'), 'a batched call previews every hunk');
        self::assertSame($frame['diff'], SessionFeed::askSummary($ask)['diff'], 'the feed summary carries the same text');

        $schema = EventSchemas::data(SessionEvent::PERMISSION_REQUESTED);
        self::assertNotNull($schema);
        self::assertSame([], ProtocolSchema::errors($event->data, $schema), 'the payload fits permission.requested');
        $server = EventSchemas::data(EventType::PERMISSION_ASKED);
        self::assertNotNull($server);
        self::assertSame([], ProtocolSchema::errors(['sessionId' => 's', ...$event->data], $server), 'and permission.asked');
    }

    public function testAnAskWithNothingToPreviewStaysGreenWithoutTheKey(): void
    {
        $root = $this->scratchDir();
        $settle = static function (): void {
        };

        $frame = PendingAsk::describe(new ToolCall('c2', 'Bash', ['command' => 'ls']), self::gateAsking('Bash wants to run ls'), 'default', $root);
        self::assertArrayNotHasKey('diff', $frame);
        $ask = PendingAsk::fromFrame($frame, $settle);
        self::assertNotNull($ask);

        $event = TranscriptProjector::new()->backendEvent(new PermissionAsked($ask), 's', 't');
        self::assertNotNull($event);
        self::assertArrayNotHasKey('diff', $event->data, 'no key, not an empty one');
        self::assertArrayNotHasKey('diff', SessionFeed::askSummary($ask));

        $schema = EventSchemas::data(SessionEvent::PERMISSION_REQUESTED);
        self::assertNotNull($schema);
        self::assertSame([], ProtocolSchema::errors($event->data, $schema));
        $server = EventSchemas::data(EventType::PERMISSION_ASKED);
        self::assertNotNull($server);
        self::assertSame([], ProtocolSchema::errors(['sessionId' => 's', ...$event->data], $server));
    }

    private static function batchedEditCall(): ToolCall
    {
        return new ToolCall('c1', 'Edit', [
            'file_path' => 'f.txt',
            'old_string' => "alpha\n",
            'new_string' => "ALPHA\n",
            'edits' => [['old_string' => "omega\n", 'new_string' => "OMEGA\n"]],
        ]);
    }

    private static function gateAsking(string $reason = 'Edit needs approval'): HookResult
    {
        return (new HookResult(HookResult::ASK, $reason))->withAskedBy([PermissionGateHook::NAME]);
    }

    private function scratchDir(): string
    {
        $dir = \sys_get_temp_dir() . '/cc-sec2b-' . uniqid('p', true);
        self::assertNotFalse(mkdir($dir, 0700, true));
        $this->scratchDirs[] = $dir;

        return $dir;
    }

    private static function pruneScratch(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $dir . '/' . $entry;
            if (is_dir($child)) {
                self::pruneScratch($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($dir);
    }
}
