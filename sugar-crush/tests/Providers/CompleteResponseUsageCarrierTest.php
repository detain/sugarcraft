<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Usage;

/**
 * The E17 carrier: CompleteResponse::$usage is where a provider's split usage
 * DOCUMENT starts its journey back to Chat — the decision the backlog entry
 * said was blocking, taken. These tests pin the carrier's contract at the
 * carrier itself; the fold sites (Runtime's two seams and the providers'
 * constructions) landed later and are pinned in RuntimeUsageFoldTest and
 * ProviderUsageCarriersTest — a contract nothing tests until the last
 * wire lands is how the $truncated field nearly drifted.
 */
final class CompleteResponseUsageCarrierTest extends TestCase
{
    public function testTheCarrierIsNullableAndTheOldProjectionIsUntouched(): void
    {
        // Every construction site in the repo today uses this shape; the
        // carrier must be additive with no behavior change behind it.
        $response = new CompleteResponse(content: 'hi', tokensUsed: 42, costUsd: 0.003);

        $this->assertNull($response->usage, 'null means "the split was not carried" — never a zero and never a fabricated document');
        $this->assertSame(42, $response->tokensUsed, 'the total projection stays authoritative for anything that only reads a total');
        $this->assertSame(0.003, $response->costUsd);
    }

    public function testPromptTokensResolvesNullThroughAMissingCarrier(): void
    {
        $response = new CompleteResponse(content: 'hi', tokensUsed: 42);

        $this->assertNull(
            $response->promptTokens(),
            'no carrier and an incomplete carrier answer the SAME null — a consumer must never re-derive the bucket identity to tell them apart',
        );
    }

    public function testPromptTokensResolvesThroughACompleteCarrier(): void
    {
        // The Anthropic-shaped identity: input counts only AFTER the last
        // cache breakpoint, so the prompt is cacheRead + cacheCreation + input.
        $usage = Usage::new(100, 0.01, inputTokens: 10, outputTokens: 30, cacheReadTokens: 50, cacheCreationTokens: 10);
        $response = new CompleteResponse(content: 'hi', tokensUsed: 100, usage: $usage);

        $this->assertSame(70, $response->promptTokens());
        $this->assertSame($usage, $response->usage, 'the carrier is the document as parsed, not a projection of it');
    }

    public function testAPartialCarrierStillAnswersNullForThePrompt(): void
    {
        // Usage::promptTokens() refuses to total across an unreported bucket;
        // the resolved accessor passes that refusal through unchanged rather
        // than substituting the total the caller asked to NOT compare.
        $usage = Usage::new(100, 0.01, inputTokens: 10, outputTokens: 30);
        $response = new CompleteResponse(content: 'hi', tokensUsed: 100, usage: $usage);

        $this->assertNull($response->promptTokens(), 'a document that said nothing about one cache bucket has no honest prompt figure — 10 is not 70 and not 0');
    }
}
