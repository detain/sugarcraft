<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use React\Promise\PromiseInterface;
use SugarCraft\Plugins\Bridge\PlatformFace;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A platform face that records what the router asks of it. A chunk whose
 * zero-based delivery index is in $failAt is rejected.
 */
final class FakeFace implements PlatformFace
{
    /** @var list<string> chunks delivered, in arrival order */
    public array $delivered = [];

    /** @var list<array{ref:array<string,mixed>, askId:string, nonce:string}> */
    public array $approvals = [];

    /** @var list<array{askId:string, line:string}> */
    public array $retired = [];

    public int $typingCalls = 0;

    /** @var int[] zero-based attempt indexes that reject */
    public array $failAt = [];

    private int $attempts = 0;

    public function __construct(
        private readonly string $platform = 'fake',
        private readonly int $limit = 10,
        private readonly int $lines = 100,
    ) {
    }

    public function name(): string
    {
        return $this->platform;
    }

    public function textChunkLimit(): int
    {
        return $this->limit;
    }

    public function maxLinesPerMessage(): int
    {
        return $this->lines;
    }

    public function deliver(array $ref, string $chunk): PromiseInterface
    {
        $index = $this->attempts++;
        if (\in_array($index, $this->failAt, true)) {
            return reject(new \RuntimeException("boom on {$index}"));
        }
        $this->delivered[] = $chunk;

        return resolve(null);
    }

    public function promptApproval(array $ref, string $sessionId, array $ask, string $nonce = ''): void
    {
        $this->approvals[] = ['ref' => $ref, 'askId' => (string) ($ask['askId'] ?? ''), 'nonce' => $nonce];
    }

    public function retireApproval(string $askId, string $line): void
    {
        $this->retired[] = ['askId' => $askId, 'line' => $line];
    }

    public function typing(array $ref): void
    {
        $this->typingCalls++;
    }
}
