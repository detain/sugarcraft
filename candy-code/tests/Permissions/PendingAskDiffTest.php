<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\ChildChannel;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Permissions\AskDiffPreview;
use SugarCraft\Code\Tools\ToolCall;

/**
 * C-SEC-2.b: the two gates the diff passes through. {@see PendingAsk::describe()}
 * is the server attaching it — present only when the confined preview exists;
 * {@see PendingAsk::fromFrame()} is a peer handing one over — every shape
 * violation (empty, oversize, not a string) drops the value rather than
 * serving it, and an absent key is the ordinary case, not an error.
 */
final class PendingAskDiffTest extends TestCase
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

    public function testADescribedGateAskCarriesTheConfinedPreview(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));

        $frame = PendingAsk::describe(self::editCall(), self::gateAsking(), 'default', $root);

        self::assertArrayHasKey('diff', $frame);
        $diff = $frame['diff'];
        self::assertIsString($diff);
        self::assertStringContainsString('@@ -3,', $diff, 'the frame carries the tool diff, never a snippet echo');
        self::assertSame(AskDiffPreview::for('Edit', self::editCall()->arguments(), $root), $diff);
    }

    public function testADescribedAskWithoutARootOrWithNothingToPreviewCarriesNoDiffKey(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));

        self::assertArrayNotHasKey('diff', PendingAsk::describe(self::editCall(), self::gateAsking(), 'default', null));

        $bash = new ToolCall('c2', 'Bash', ['command' => 'ls']);
        self::assertArrayNotHasKey('diff', PendingAsk::describe($bash, self::gateAsking('Bash wants to run ls'), 'default', $root));
    }

    public function testAFrameRoundTripsItsDiff(): void
    {
        $root = $this->scratchDir();
        file_put_contents($root . '/f.txt', self::nineLines('target', 6));
        $frame = PendingAsk::describe(self::editCall(), self::gateAsking(), 'default', $root);

        $ask = PendingAsk::fromFrame($frame, static function (): void {
        });

        self::assertNotNull($ask);
        self::assertSame($frame['diff'], $ask->diff);
    }

    public function testAHostileDiffOnAFrameIsDroppedNotServed(): void
    {
        $settle = static function (): void {
        };

        $empty = PendingAsk::fromFrame(self::editFrame(['diff' => '']), $settle);
        self::assertNull($empty?->diff, 'an empty string is not a diff');

        $oversize = PendingAsk::fromFrame(self::editFrame(['diff' => str_repeat('x', AskDiffPreview::MAX_DIFF_BYTES + 1)]), $settle);
        self::assertNull($oversize?->diff, 'a peer cannot push past the wire bound through this door');

        $exact = PendingAsk::fromFrame(self::editFrame(['diff' => str_repeat('x', AskDiffPreview::MAX_DIFF_BYTES)]), $settle);
        self::assertNotNull($exact?->diff, 'the bound itself is admissible');

        $notAString = PendingAsk::fromFrame(self::editFrame(['diff' => 42]), $settle);
        self::assertNull($notAString?->diff);
    }

    public function testAFrameWithoutTheKeyIsTheOrdinaryHandle(): void
    {
        $ask = PendingAsk::fromFrame(self::editFrame([]), static function (): void {
        });

        self::assertNotNull($ask);
        self::assertNull($ask->diff, 'absent means nothing to preview, not a rejected value');
    }

    /**
     * A known-good Edit ask frame — the shape {@see PendingAsk::describe()}
     * writes for the arguments above — for the arms that test the reader
     * without routing through the writer.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function editFrame(array $overrides): array
    {
        $arguments = ['file_path' => 'f.txt', 'old_string' => "target\n", 'new_string' => "TARGET\n"];

        return [
            'kind' => ChildChannel::ASK,
            'askId' => PendingAsk::askId('c1', 'Edit', $arguments),
            'toolCallId' => 'c1',
            'tool' => 'Edit',
            'arguments' => $arguments,
            'reason' => 'Edit needs approval',
            'source' => 'gate',
            'mode' => 'default',
            'suggestions' => ['once', 'always', 'reject'],
            'alwaysScope' => ['tool' => 'Edit'],
        ] + $overrides;
    }

    private static function editCall(): ToolCall
    {
        return new ToolCall('c1', 'Edit', ['file_path' => 'f.txt', 'old_string' => "target\n", 'new_string' => "TARGET\n"]);
    }

    private static function gateAsking(string $reason = 'Edit needs approval'): HookResult
    {
        return (new HookResult(HookResult::ASK, $reason))->withAskedBy([PermissionGateHook::NAME]);
    }

    /** A nine-line file with $word on line $at (and a second pair when given). */
    private static function nineLines(string $word, int $at, ?string $second = null, ?int $secondAt = null): string
    {
        $lines = ['l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'l8', 'l9'];
        $lines[$at - 1] = $word;
        if ($second !== null && $secondAt !== null) {
            $lines[$secondAt - 1] = $second;
        }

        return implode("\n", $lines) . "\n";
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
