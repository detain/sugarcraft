<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Providers\OpenAIProvider;

/**
 * Audit A13: OpenAIProvider::contextWindow() answered 8,192 for models it can
 * PRICE (gpt-4o-mini at 128k, gpt-4.1 / gpt-4.1-mini at ~1M), so every
 * context tier Chat computes — the reminder, auto-compaction and blocking
 * refusal — fired after a handful of messages. Its `default => 8_192` was a
 * guess the ProviderInterface contract forbids: unknown is 0, and
 * ContextWindow::resolve() turns that into the one named fallback.
 */
final class OpenAIProviderContextWindowTest extends TestCase
{
    /**
     * Legacy models whose real windows ARE small. They get exact pins rather
     * than the >= 128k floor so a future "everything is 128k" shortcut reds.
     */
    private const LEGACY_SMALL_WINDOWS = [
        'gpt-4' => 8_192,
        'gpt-3.5-turbo' => 16_385,
    ];

    /**
     * Every model the provider can price, read off the private table itself:
     * a row added to PRICE_TABLE without a window joins this provider
     * automatically and reds, which is exactly how A13 slipped in (three rows
     * priced, none sized).
     *
     * @return iterable<string, array{string}>
     */
    public static function pricedModels(): iterable
    {
        $table = (new \ReflectionClassConstant(OpenAIProvider::class, 'PRICE_TABLE'))->getValue();
        self::assertIsArray($table);

        foreach (array_keys($table) as $model) {
            yield $model => [(string) $model];
        }
    }

    #[DataProvider('pricedModels')]
    public function testEveryPricedModelReportsARealWindow(string $model): void
    {
        $provider = new OpenAIProvider($this->createMock(ClientContract::class), $model);

        if (array_key_exists($model, self::LEGACY_SMALL_WINDOWS)) {
            $this->assertSame(self::LEGACY_SMALL_WINDOWS[$model], $provider->contextWindow());

            return;
        }

        $this->assertGreaterThanOrEqual(
            128_000,
            $provider->contextWindow(),
            "{$model} is priced, so it must be sized: every current OpenAI chat model has at least a 128k window",
        );
    }

    public function testExactWindowsForTheModelsTheAuditNamed(): void
    {
        $client = $this->createMock(ClientContract::class);

        $this->assertSame(128_000, (new OpenAIProvider($client, 'gpt-4o-mini'))->contextWindow());
        $this->assertSame(1_047_576, (new OpenAIProvider($client, 'gpt-4.1'))->contextWindow());
        $this->assertSame(1_047_576, (new OpenAIProvider($client, 'gpt-4.1-mini'))->contextWindow());
    }

    public function testUnknownModelReportsZeroSoTheNamedFallbackApplies(): void
    {
        $provider = new OpenAIProvider($this->createMock(ClientContract::class), 'some-future-model');

        // 0 = "unknown" per ProviderInterface::contextWindow(); a guessed
        // number would silently become every context tier's denominator.
        $this->assertSame(0, $provider->contextWindow());
        $this->assertSame(ContextWindow::FALLBACK_TOKENS, ContextWindow::resolve($provider->contextWindow()));
    }
}
