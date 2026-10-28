<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Chat\Handlers\HandlesRunControl;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\MarksPromptCache;
use SugarCraft\Code\Providers\ProviderInterface;

/**
 * C-CTX-5.c: the `cacheTtl` idle fold — the one timed to the provider's
 * prompt-cache lifetime rather than the hour-long offer window.
 *
 * The reason is money the user never sees: a conversation's cached prefix
 * goes cold while the session sits idle, and the next prompt would then
 * prefill the WHOLE conversation uncached. Folding just short of that expiry
 * spends one summarisation call to spare the far larger re-prefill bill.
 *
 * The TTL table, the margin and the floor all live in
 * {@see IdleCompactionPolicy} — the one place both hosts (the TUI's 60 s tick
 * and the served host's single alarm) must not disagree about them — and this
 * file is the table pin. The mode parsing (legacy bools keep reading, the
 * bool staying a shadow of the mode string) is pinned here too, because a
 * half-moved pair would let one host fold while the other only offers.
 */
final class CacheTtlIdleFoldTest extends TestCase
{
    /** A fixed instant so every boundary below is deterministic. */
    private const NOW = 1_800_000_000;

    private static function idleFor(int $seconds): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . (self::NOW - $seconds));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function configFrom(array $config): CompactorConfig
    {
        return CompactorConfig::fromSettings($config);
    }

    /**
     * A Backend double that is NOT an EngineBackend: the policy cannot see a
     * provider at all, which is the "caches server-side without marks" shape —
     * the implicit 5-minute prefix cache.
     */
    private function opaqueBackend(): Backend
    {
        return $this->createMock(Backend::class);
    }

    private function engineWithMarks(bool $marks): EngineBackend
    {
        $provider = new MarkingProviderStub();
        $provider->marks = $marks;

        return EngineBackend::new($provider, 'some-model');
    }

    public function testCacheTtlTable(): void
    {
        // `true` = the provider marks breakpoints: 1 h. A provider that does
        // not answer at all caches server-side without marks: 5 min for most.
        // An answer of `false` = nothing is cached for this model: no clock,
        // no fold.
        $this->assertSame(3600, IdleCompactionPolicy::CACHE_TTL_MARKED_SECONDS);
        $this->assertSame(300, IdleCompactionPolicy::CACHE_TTL_IMPLICIT_SECONDS);
        $this->assertSame(60, IdleCompactionPolicy::CACHE_TTL_MARGIN_SECONDS);

        $this->assertSame(3600, IdleCompactionPolicy::cacheTtlSeconds(true));
        $this->assertSame(300, IdleCompactionPolicy::cacheTtlSeconds(null));
        $this->assertNull(IdleCompactionPolicy::cacheTtlSeconds(false));
    }

    public function testSecondsOfReadsTheProvidersAnswer(): void
    {
        $this->assertSame(300, IdleCompactionPolicy::cacheTtlSecondsOf($this->opaqueBackend()));
        $this->assertNull(IdleCompactionPolicy::cacheTtlSecondsOf(null));

        $this->assertSame(3600, IdleCompactionPolicy::cacheTtlSecondsOf($this->engineWithMarks(true)));
        $this->assertNull(IdleCompactionPolicy::cacheTtlSecondsOf($this->engineWithMarks(false)));
    }

    public function testMarkedClockBoundary(): void
    {
        $engine = $this->engineWithMarks(true);
        $fireAt = IdleCompactionPolicy::CACHE_TTL_MARKED_SECONDS - IdleCompactionPolicy::CACHE_TTL_MARGIN_SECONDS;

        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $engine, 100_000, 100_000, self::idleFor($fireAt), self::NOW,
        ), 'exactly at the fold point is not past it — strict > like the hour clock');
        $this->assertTrue(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $engine, 100_000, 100_000, self::idleFor($fireAt + 1), self::NOW,
        ));
    }

    public function testImplicitClockBoundary(): void
    {
        $opaque = $this->opaqueBackend();
        $fireAt = IdleCompactionPolicy::CACHE_TTL_IMPLICIT_SECONDS - IdleCompactionPolicy::CACHE_TTL_MARGIN_SECONDS;

        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $opaque, 100_000, 100_000, self::idleFor($fireAt), self::NOW,
        ));
        $this->assertTrue(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $opaque, 100_000, 100_000, self::idleFor($fireAt + 1), self::NOW,
        ));
    }

    public function testUncachedProviderNeverFolds(): void
    {
        // The provider says nothing is cached for this model — folding would
        // spend a call to protect a cache that does not exist. Ten hours of
        // idle still must not fold.
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $this->engineWithMarks(false), 150_000, 100_000, self::idleFor(36_000), self::NOW,
        ));
    }

    public function testFloorIsBelowTheAutoTierAndStaysThere(): void
    {
        // 60% of the window, comfortably below the automatic tier's 85% — a
        // history at the tier already compacts there, so the cache fold exists
        // for the middle band, not the top one.
        $this->assertSame(60, IdleCompactionPolicy::CACHE_TTL_FLOOR_PERCENT);
        $this->assertLessThan(85, IdleCompactionPolicy::CACHE_TTL_FLOOR_PERCENT);

        $opaque = $this->opaqueBackend();
        $idle = self::idleFor(3000);

        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $opaque, 59_999, 100_000, $idle, self::NOW,
        ), 'one token under the floor does not fold');
        $this->assertTrue(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $opaque, 60_000, 100_000, $idle, self::NOW,
        ), 'the floor is where it starts counting, like the tier thresholds');
    }

    public function testUnknownShapesNeverFold(): void
    {
        $opaque = $this->opaqueBackend();

        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $opaque, 100_000, 0, self::idleFor(3000), self::NOW,
        ), 'an unresolvable window disables the check rather than firing on everything');
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $opaque, 100_000, -1, self::idleFor(3000), self::NOW,
        ));
        $this->assertFalse(IdleCompactionPolicy::shouldAutoCompactAtCacheTtl(
            $opaque, 100_000, 100_000, null, self::NOW,
        ), 'null idleness is unknown, which is never grounds — the hour clock\'s rule');
    }

    public function testLegacyBoolsKeepReading(): void
    {
        // The bool the setting was keeps reading: true is hour, false is off.
        $hour = self::configFrom([CompactorConfig::SETTING_IDLE_AUTO => true]);
        $this->assertTrue($hour->idleAuto, 'the bool stays the shadow the watch gate reads');
        $this->assertSame(CompactorConfig::IDLE_AUTO_HOUR, $hour->idleAutoMode);

        $off = self::configFrom([CompactorConfig::SETTING_IDLE_AUTO => false]);
        $this->assertFalse($off->idleAuto);
        $this->assertSame(CompactorConfig::IDLE_AUTO_OFF, $off->idleAutoMode);
        $this->assertEquals(CompactorConfig::new(), $off);
    }

    public function testModeStringsParseCaseInsensitivelyAndKeepTheirCanonicalForm(): void
    {
        $ttl = self::configFrom([CompactorConfig::SETTING_IDLE_AUTO => 'cacheTtl']);
        $this->assertSame(CompactorConfig::IDLE_AUTO_CACHE_TTL, $ttl->idleAutoMode);
        $this->assertTrue($ttl->idleAuto, 'any mode but off keeps the watch alive');

        $shouted = self::configFrom([CompactorConfig::SETTING_IDLE_AUTO => 'CacheTTL']);
        $this->assertSame(CompactorConfig::IDLE_AUTO_CACHE_TTL, $shouted->idleAutoMode);

        $padded = self::configFrom([CompactorConfig::SETTING_IDLE_AUTO => ' hour ']);
        $this->assertSame(CompactorConfig::IDLE_AUTO_HOUR, $padded->idleAutoMode);

        $explicitOff = self::configFrom([CompactorConfig::SETTING_IDLE_AUTO => 'off']);
        $this->assertSame(CompactorConfig::IDLE_AUTO_OFF, $explicitOff->idleAutoMode);
        $this->assertFalse($explicitOff->idleAuto);
    }

    public function testAmbiguousStringsAreIgnoredLikeTheBoolWas(): void
    {
        // `"true"` and `1` still must not buy the fold by accident — the
        // reason the original key refused coercions. They simply are not one
        // of the three mode words, so the safe default stands.
        foreach (['true', '1', 1, 0.0, [], null] as $junk) {
            $config = self::configFrom([CompactorConfig::SETTING_IDLE_AUTO => $junk]);
            $this->assertSame(CompactorConfig::IDLE_AUTO_OFF, $config->idleAutoMode, var_export($junk, true));
            $this->assertFalse($config->idleAuto);
        }
    }

    public function testTheWritersKeepThePairConsistent(): void
    {
        $config = CompactorConfig::new();

        $on = $config->withIdleAuto(true);
        $this->assertTrue($on->idleAuto);
        $this->assertSame(CompactorConfig::IDLE_AUTO_HOUR, $on->idleAutoMode);

        $off = $on->withIdleAuto(false);
        $this->assertFalse($off->idleAuto);
        $this->assertSame(CompactorConfig::IDLE_AUTO_OFF, $off->idleAutoMode);

        $ttl = $config->withIdleAutoMode(CompactorConfig::IDLE_AUTO_CACHE_TTL);
        $this->assertSame(CompactorConfig::IDLE_AUTO_CACHE_TTL, $ttl->idleAutoMode);
        $this->assertTrue($ttl->idleAuto);

        $back = $ttl->withIdleAuto(false);
        $this->assertFalse($back->idleAuto);
        $this->assertSame(CompactorConfig::IDLE_AUTO_OFF, $back->idleAutoMode);
    }

    public function testAHandBuiltHalfPairThrowsAndAPairBuilds(): void
    {
        // The writers keep both faces; a hand-built `new(idleAuto: true)`
        // without its mode is a half-pair, and because the properties are
        // readonly the constructor cannot re-pair it — it refuses loudly
        // instead, so no caller can carry a config where the watch gate and
        // the mode reader disagree.
        $hour = new CompactorConfig(idleAuto: true, idleAutoMode: CompactorConfig::IDLE_AUTO_HOUR);
        $this->assertTrue($hour->idleAuto);
        $this->assertSame(CompactorConfig::IDLE_AUTO_HOUR, $hour->idleAutoMode);

        $this->expectException(\InvalidArgumentException::class);
        new CompactorConfig(idleAuto: true);
    }

    public function testAnUnknownModeThrowsLikeEveryOtherModeKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CompactorConfig::new()->withIdleAutoMode('sometimes');
    }

    /**
     * The cache tick duplicates the hour tick's transient-silence list —
     * deliberate (a fold must never interrupt a keystroke or a permission
     * prompt on either path), and pinned structurally so a future edit to one
     * copy cannot silently leave the other answering "quiet" to a session
     * with a dialog open.
     */
    public function testTheCacheTickSilencesExactlyWhatTheHourTickSilences(): void
    {
        $hour = self::silenceBlock(Chat::class, 'handleIdleCompactTick');
        $cache = self::silenceBlock(HandlesRunControl::class, 'handleCacheTtlIdleCompactTick');

        $this->assertNotSame('', $hour, 'the hour tick lost the shape this pin keys on');
        $this->assertSame($hour, $cache);
    }

    private static function silenceBlock(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = \array_slice(
            \file($reflection->getFileName()),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        );

        $matched = \preg_match('/\$this->inFlight.*?historySearch !== null/s', \implode('', $lines), $found);

        return $matched === 1 ? (string) \preg_replace('/\s+/', ' ', $found[0]) : '';
    }
}

/**
 * A provider that answers the marks question. Test-local by design: nothing
 * in `src/` should depend on a stub, and the PSR-4 one-type-per-file rule
 * governs `src/`, not fixtures declared beside the test that uses them.
 */
class MarkingProviderStub implements ProviderInterface, MarksPromptCache
{
    public bool $marks = false;

    public function name(): string
    {
        return 'stub';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsDocuments(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return 100_000;
    }

    public function costPer1kTokens(string $model, string $direction): ?float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        throw new \LogicException('never called');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        throw new \LogicException('never called');
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        throw new \LogicException('never called');
    }

    public function marksPromptCache(string $model): bool
    {
        return $this->marks;
    }
}
