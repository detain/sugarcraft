<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;

/**
 * A batch provider answering from a script, in call order, recording every
 * request it was sent. A script entry that is a {@see \Throwable} is THROWN
 * from that call, which is how a test interrupts a turn part-way; a
 * {@see \Closure} entry is called with the request and answers at call time
 * (e.g. to report which process it ran in). Constructed with `streams: true`
 * it is a STREAMING provider, and an entry may then be a list of chunks that
 * {@see completeStream()} yields one by one — how a test sends many tiny deltas. Past the end
 * of the script it repeats the last answer, so a run that loops longer than
 * expected fails on an assertion rather than on a crash.
 */
final class ScriptedProvider implements ProviderInterface
{
    /** @var list<CompleteRequest> */
    public array $requests = [];

    /**
     * @param list<CompleteResponse|\Throwable|\Closure(CompleteRequest): CompleteResponse|list<CompleteResponse>> $script
     */
    public function __construct(private array $script, private bool $streams = false)
    {
    }

    /**
     * Append more answers — for a test that drives a second run (a resume)
     * through the same provider after the first has used up its script.
     */
    public function then(CompleteResponse|\Throwable|\Closure ...$answers): void
    {
        array_push($this->script, ...$answers);
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function supportsStreaming(): bool
    {
        return $this->streams;
    }

    public function supportsFunctionCalling(): bool
    {
        return true;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return 100000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $answer = $this->next($request);
        if (\is_array($answer)) {
            return new CompleteResponse(content: implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $answer)));
        }

        return $answer;
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        $answer = $this->next($request);
        foreach (\is_array($answer) ? $answer : [$answer] as $chunk) {
            yield $chunk;
        }
    }

    /**
     * @return CompleteResponse|list<CompleteResponse>
     */
    private function next(CompleteRequest $request): CompleteResponse|array
    {
        $this->requests[] = $request;
        $index = min(\count($this->requests), \count($this->script)) - 1;
        $answer = $this->script[$index] ?? new CompleteResponse(content: '');

        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        if ($answer instanceof \Closure) {
            return $answer($request);
        }

        return $answer;
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}
