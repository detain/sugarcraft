<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Runtime;

/**
 * C-CTX-4: the live-date row on `<turn-context>`.
 *
 * `<env>` freezes `Current date:` at capture, and the frozen bytes are the
 * cached prefix — correcting them mid-session is forbidden. The answer under
 * test is the tail row carrying today's date ONLY while the two disagree:
 * same-day steps must render no row (an unconditional line would re-state
 * what the model already read and widen every row), a session that runs past
 * midnight must say the true date exactly once per row, and a malformed
 * input must fail quiet like every other part of this row.
 *
 * The date pins avoid the midnight race the same way the rest of this
 * house does it: the FROZEN side is injected (a past date, or today's noon),
 * and where the AMBIENT side is asserted the expectation is the
 * before/after bracket rather than one literal — the test cannot flake in
 * the seconds a real session could span a date change.
 */
final class TurnContextDateTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/turn_context_date_test_' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDir($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }

    public function testTheSameDateCarriesNoRow(): void
    {
        $block = TurnContextBlock::new()->withCurrentDate('2026-10-28', '2026-10-28');

        $this->assertSame('', $block->currentDate());
        // Nothing else on the block either — a block with nothing to say is
        // no row at all, so the empty case cannot smuggle a bare fence.
        $this->assertSame('', $block->render());
        $this->assertNull($block->message());
    }

    public function testARolledDateRendersOneRowLine(): void
    {
        $block = TurnContextBlock::new()->withCurrentDate('2026-10-29', '2026-10-28');

        $this->assertSame('Current date: 2026-10-29', $block->currentDate());
        $this->assertSame(
            TurnContextBlock::FENCE . "\n" . TurnContextBlock::PREAMBLE . "\n\nCurrent date: 2026-10-29\n</turn-context>",
            $block->render(),
        );
        $this->assertNotNull($block->message());
    }

    public function testAMalformedTodayFailsQuiet(): void
    {
        // A caller passing a non-date is a bug in the caller, not a fact to
        // relay: no line, no exception, no half-rendered claim. The guard is
        // on the SHAPE `YYYY-MM-DD` — the only producer in the codebase is
        // DateTimeImmutable::format(), which cannot emit an impossible
        // calendar day, so a shape-correct date is never second-guessed.
        foreach (['', 'tomorrow', '2026-10-2', '29-10-2026', '2026/10/29'] as $today) {
            $block = TurnContextBlock::new()->withCurrentDate($today, '2026-10-28');
            $this->assertSame('', $block->currentDate(), "input {$today} must render nothing");
            $this->assertSame('', $block->render());
        }
    }

    public function testClearingHappensByRederivingFromTheCurrentEnvDate(): void
    {
        // The setter is value-derived, not sticky: re-passing today equal to
        // the frozen date clears the row (the Runtime re-calls with the live
        // pair every step, and midnight passing back "heals" by agreement).
        $block = TurnContextBlock::new()->withCurrentDate('2026-10-29', '2026-10-28');
        $this->assertNotSame('', $block->currentDate());

        $cleared = $block->withCurrentDate('2026-10-29', '2026-10-29');
        $this->assertSame('', $cleared->currentDate());
        // The original block is untouched (readonly value-object law).
        $this->assertSame('Current date: 2026-10-29', $block->currentDate());
    }

    public function testADateRowCountsAsAChangeAgainstTheSameHistoryRow(): void
    {
        $overnight = TurnContextBlock::new()->withCurrentDate('2026-10-29', '2026-10-28');

        // The midnight step's row is a change against a history that has none.
        $this->assertTrue($overnight->changedSince([]));

        // Sent once, the identical row is not a change again.
        $history = [$overnight->message()];
        $this->assertFalse($overnight->changedSince($history));

        // A same-day block has nothing to say and never re-sends over it.
        $this->assertFalse(TurnContextBlock::new()->withCurrentDate('2026-10-29', '2026-10-29')->changedSince($history));
    }

    // ─── the production wiring ──────────────────────────────────────

    /**
     * A session whose `<env>` froze a date in the past: the tail row must
     * say the ambient date once, and `<env>`'s frozen bytes must not move.
     */
    public function testRuntimeSendsTodayOnTheTailRowWhenEnvIsStale(): void
    {
        $before = (new DateTimeImmutable())->format('Y-m-d');
        [$row, $prompt, $after] = $this->deliverWithFrozenEnvDate(new DateTimeImmutable('2000-01-01 12:00:00'));

        $this->assertSame(1, preg_match('/Current date: (\d{4}-\d{2}-\d{2})/', $row, $at), $row);
        $this->assertContains($at[1], [$before, $after], 'the row must carry the ambient date, bracketed around the call');
        $this->assertStringContainsString('Current date: 2000-01-01', $prompt);
        $this->assertStringNotContainsString('Current date: ' . $at[1], $prompt);
    }

    /**
     * The same-day case: `<env>` already renders today's date, so the tail
     * row carries none — an unconditional line would re-send those bytes on
     * every step and grow the "changed" state for no information.
     */
    public function testRuntimeSendsNoDateRowWhileEnvStillAgrees(): void
    {
        [$row, $prompt] = $this->deliverWithFrozenEnvDate(new DateTimeImmutable((new DateTimeImmutable())->format('Y-m-d') . ' 12:00:00'));

        $this->assertStringNotContainsString('Current date:', $row);
        $this->assertStringContainsString('Current date: ' . (new DateTimeImmutable())->format('Y-m-d'), $prompt);
    }

    /**
     * Build the real Runtime with an INJECTED EnvironmentBlock pinned at
     * $frozen (the owner holds the session snapshot, so the injected block
     * skips the memo and settings per the environmentSnapshot() law), and
     * return [turn-context row, system prompt, ambient date after the call].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function deliverWithFrozenEnvDate(DateTimeImmutable $frozen): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), new EnvironmentBlock($this->tempDir, 'test-model', $frozen));
        $app = App::new($provider, 'test-model')->withRoot($this->tempDir);

        $method = new \ReflectionMethod($runtime, 'buildSystemPrompt');
        $method->setAccessible(true);

        return [
            $runtime->turnContext($app)->render(),
            (string) $method->invoke($runtime, $app),
            (new DateTimeImmutable())->format('Y-m-d'),
        ];
    }
}
