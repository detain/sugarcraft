<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\ProviderStreamException;
use SugarCraft\Crush\Providers\TransientFailure;

/**
 * Audit 15a A2: the single recogniser for an SSE error frame (a failure the
 * server reports inside a 200 stream) and the verdict
 * {@see TransientFailure::isTransient()} reads off it.
 */
final class ProviderStreamExceptionTest extends TestCase
{
    public function testTheNestedOpenAiShapeIsRecognised(): void
    {
        $e = ProviderStreamException::fromErrorEvent(
            ['error' => ['message' => 'input is longer than the context length', 'code' => 400]],
            'SGLANG request failed: ',
        );

        $this->assertNotNull($e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('SGLANG request failed: input is longer than the context length', $e->getMessage());
        $this->assertSame(400, $e->serverCode);
        $this->assertFalse($e->transient);
    }

    public function testTheVllmTopLevelShapeIsRecognised(): void
    {
        $e = ProviderStreamException::fromErrorEvent(
            ['object' => 'error', 'message' => 'engine overloaded', 'type' => 'ServiceUnavailable', 'code' => 503],
        );

        $this->assertNotNull($e);
        $this->assertSame('engine overloaded', $e->getMessage());
        $this->assertSame(503, $e->serverCode);
        $this->assertTrue($e->transient);
    }

    public function testABareStringErrorIsRecognised(): void
    {
        $e = ProviderStreamException::fromErrorEvent(['error' => 'upstream aborted', 'code' => '502']);

        $this->assertNotNull($e);
        $this->assertSame('upstream aborted', $e->getMessage());
        $this->assertSame(502, $e->serverCode);
        $this->assertTrue($e->transient);
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonErrorFrames(): iterable
    {
        yield '[DONE] decodes to null' => [null];
        yield 'scalar' => ['data'];
        yield 'content delta' => [['object' => 'chat.completion.chunk', 'choices' => [['delta' => ['content' => 'Hi']]]]];
        yield 'finish frame' => [['choices' => [['delta' => [], 'finish_reason' => 'stop']]]];
        yield 'usage frame' => [['choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2]]];
        yield 'null error key' => [['error' => null, 'choices' => [['delta' => ['content' => 'x']]]]];
    }

    #[DataProvider('nonErrorFrames')]
    public function testOrdinaryFramesAreNotErrors(mixed $frame): void
    {
        $this->assertNull(ProviderStreamException::fromErrorEvent($frame));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function codeVerdicts(): iterable
    {
        yield '500' => [500, true];
        yield '503' => [503, true];
        yield '429' => [429, true];
        yield '408' => [408, true];
        yield '"500" as string' => ['500', true];
        yield '400 context overflow' => [400, false];
        yield '401' => [401, false];
        yield '413' => [413, false];
        yield 'no code' => [null, false];
        yield 'OpenAI string code' => ['context_length_exceeded', false];
        yield 'zero' => [0, false];
    }

    #[DataProvider('codeVerdicts')]
    public function testTransientFailureReadsTheVerdictFromTheServerCode(mixed $code, bool $transient): void
    {
        $e = ProviderStreamException::fromErrorEvent(['error' => ['message' => 'boom', 'code' => $code]]);

        $this->assertNotNull($e);
        $this->assertSame($transient, $e->transient);
        $this->assertSame($transient, TransientFailure::isTransient($e));
        $this->assertSame(
            $transient,
            TransientFailure::isTransient(new \RuntimeException('wrapped', 0, $e)),
            'the getPrevious() walk must reach the verdict too',
        );
    }

    public function testAMissingMessageFallsBackToAStatedOne(): void
    {
        $e = ProviderStreamException::fromErrorEvent(['error' => ['code' => 500]], 'P: ');

        $this->assertNotNull($e);
        $this->assertSame('P: ' . ProviderStreamException::FALLBACK_MESSAGE, $e->getMessage());
    }

    public function testARunawayMessageIsClipped(): void
    {
        $e = ProviderStreamException::fromErrorEvent(['error' => ['message' => str_repeat('x', 5000)]]);

        $this->assertNotNull($e);
        $this->assertSame(
            str_repeat('x', ProviderStreamException::MESSAGE_DISPLAY_LIMIT) . ' [truncated]',
            $e->getMessage(),
        );
    }
}
