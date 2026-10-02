<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Audit A21 (b): Gemini 2.5 thinks by default, and its thought tokens spend
 * from the same `maxOutputTokens` budget as the answer. Vertex used to send
 * the Anthropic arm's REQUIRED 4096 default to Gemini too, so a hard prompt
 * could end `MAX_TOKENS` with little or no text. Now Gemini gets no ceiling
 * unless one is asked for, and an operator-set `thinkingBudget` rides
 * `generationConfig.thinkingConfig`.
 *
 * Wire shape only, through the provider's predictor/streamer seams - no live
 * Gemini call (this repo holds no GCP credentials).
 */
final class VertexGeminiOutputBudgetTest extends TestCase
{
    private const GEMINI_MODEL = 'gemini-2.5-pro';
    private const ANTHROPIC_MODEL = 'claude-sonnet-4-6@20250929';

    public function testAGeminiRequestWithNoCeilingSendsNoMaxOutputTokens(): void
    {
        $body = $this->sentBody(self::GEMINI_MODEL, null, new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        self::assertArrayNotHasKey('maxOutputTokens', $body['generationConfig']);
        self::assertArrayNotHasKey('thinkingConfig', $body['generationConfig']);
    }

    /** An explicit ceiling (the `maxOutputTokens` setting) still reaches the wire. */
    public function testAnExplicitCeilingIsStillSent(): void
    {
        $body = $this->sentBody(self::GEMINI_MODEL, null, new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
            maxTokens: 32_768,
        ));

        self::assertSame(32_768, $body['generationConfig']['maxOutputTokens']);
    }

    /**
     * @dataProvider budgets
     */
    public function testAConfiguredThinkingBudgetRidesGenerationConfig(int $budget): void
    {
        $body = $this->sentBody(self::GEMINI_MODEL, $budget, new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        self::assertSame(['thinkingBudget' => $budget], $body['generationConfig']['thinkingConfig']);
    }

    /** @return array<string, array{int}> */
    public static function budgets(): array
    {
        return ['dynamic' => [-1], 'off' => [0], 'capped' => [8192]];
    }

    /** The streaming path builds the same body. */
    public function testTheStreamingGeminiBodyCarriesTheBudgetAndNoDefaultCeiling(): void
    {
        $captured = null;
        $provider = VertexProvider::create(
            projectId: 'p',
            model: self::GEMINI_MODEL,
            predictor: fn (): array => [],
            streamer: function (string $endpoint, string $method, array $body) use (&$captured): \Generator {
                $captured = $body;

                yield from [];
            },
            thinkingBudget: 1024,
        );

        foreach ($provider->completeStream(new CompleteRequest(
            model: self::GEMINI_MODEL,
            messages: [new UserMessage('Hi')],
        )) as $unused) {
            // drain
        }

        self::assertIsArray($captured);
        self::assertSame(['thinkingBudget' => 1024], $captured['generationConfig']['thinkingConfig']);
        self::assertArrayNotHasKey('maxOutputTokens', $captured['generationConfig']);
    }

    /**
     * The Anthropic arm is untouched: `max_tokens` is REQUIRED there, so its
     * 4096 default stays, and a Gemini thinking budget is not an Anthropic
     * field.
     */
    public function testTheAnthropicArmKeepsItsRequiredDefaultAndIgnoresTheBudget(): void
    {
        $body = $this->sentBody(self::ANTHROPIC_MODEL, 2048, new CompleteRequest(
            model: self::ANTHROPIC_MODEL,
            messages: [new UserMessage('Hi')],
        ));

        self::assertSame(4096, $body['max_tokens']);
        self::assertArrayNotHasKey('thinkingConfig', $body);
        self::assertArrayNotHasKey('thinking', $body);
    }

    public function testABudgetBelowMinusOneIsRefusedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('thinkingBudget');

        VertexProvider::create(projectId: 'p', model: self::GEMINI_MODEL, predictor: fn (): array => [], thinkingBudget: -2);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(string $model, ?int $thinkingBudget, CompleteRequest $request): array
    {
        $captured = null;
        $provider = VertexProvider::create(
            projectId: 'p',
            model: $model,
            predictor: function (string $endpoint, string $method, array $body) use (&$captured): array {
                $captured = $body;

                return [];
            },
            thinkingBudget: $thinkingBudget,
        );

        $provider->complete($request);

        self::assertIsArray($captured);

        return $captured;
    }
}
