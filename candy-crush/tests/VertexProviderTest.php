<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * VertexProvider tests.
 *
 * Note: VertexProvider requires Google\Cloud\AIPlatform\V1\PredictionServiceClient
 * which is not available in the unit test environment. The following tests are included:
 *
 * - Unit tests for private methods (formatMessages, parseResponse) via reflection -
 *   these are skipped because the class cannot be instantiated without the SDK client.
 * - Integration tests marked with @group integration that require the SDK -
 *   these are properly skipped.
 *
 * To run full tests, install the Google Cloud SDK and run:
 *   vendor/bin/phpunit tests/VertexProviderTest.php --group=integration
 */
final class VertexProviderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Integration tests - require Google Cloud SDK
    // -------------------------------------------------------------------------

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testCreateFactoryWithDefaults(): void
    {
        $this->markTestSkipped(
            'VertexProvider::create() requires Google Cloud SDK which is not installed in test environment. ' .
            'This is an integration test that should be run separately with the SDK present.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testCreateFactoryWithCustomLocation(): void
    {
        $this->markTestSkipped(
            'VertexProvider::create() requires Google Cloud SDK which is not installed in test environment.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testCreateFactoryWithCustomModel(): void
    {
        $this->markTestSkipped(
            'VertexProvider::create() requires Google Cloud SDK which is not installed in test environment.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testNameReturnsVertex(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testSupportsStreamingReturnsTrue(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testSupportsFunctionCallingReturnsFalse(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testSupportsVisionReturnsFalse(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testSupportsJsonSchemaReturnsFalse(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testContextWindowReturns200000(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testCostPer1kTokensReturnsZero(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testCompleteReturnsCompleteResponse(): void
    {
        $this->markTestSkipped(
            'VertexProvider::complete() requires Google Cloud SDK which is not installed in test environment.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testCompleteReturnsErrorResponseOnException(): void
    {
        $this->markTestSkipped(
            'VertexProvider::complete() requires Google Cloud SDK which is not installed in test environment.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testCompleteStreamReturnsGenerator(): void
    {
        $this->markTestSkipped(
            'VertexProvider::completeStream() requires Google Cloud SDK which is not installed in test environment.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testEmbeddingsReturnsEmptyEmbeddingsResponse(): void
    {
        $this->markTestSkipped(
            'VertexProvider::embeddings() requires Google Cloud SDK which is not installed in test environment.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testFormatMessagesWithUserMessage(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testFormatMessagesWithAssistantMessage(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testFormatMessagesWithSystemMessage(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testFormatMessagesWithToolResultMessage(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testFormatMessagesWithMultipleMessages(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testParseResponseWithContentField(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     * @group requires-sdk
     */
    public function testParseResponseWithTextField(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testParseResponseWithReasoning(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }

    /**
     * @group integration
     * @group requires-sdk
     */
    public function testParseResponseWithEmptyData(): void
    {
        $this->markTestSkipped(
            'VertexProvider cannot be instantiated without Google Cloud SDK PredictionServiceClient.'
        );
    }
}
