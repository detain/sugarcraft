<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media\Sd;

/**
 * Minimal typed HTTP response carried across the SdTransport seam (plan W1.2).
 *
 * Only what the media layer actually branches on: status class, content type,
 * raw bytes. Headers are deliberately absent — every current decision
 * (capability probes, sdapi bodies, future progress polls) is made from those
 * three; if a lane needs a header it widens this shape, not the interface.
 */
final readonly class SdTransportResult
{
    private function __construct(
        public int $status,
        public string $contentType,
        public string $body,
    ) {
    }

    public static function new(int $status, string $body, string $contentType = ''): self
    {
        return new self(status: $status, contentType: $contentType, body: $body);
    }

    public function is2xx(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Media type only — parameters ('; charset=…') are stripped before the test. */
    public function isJson(): bool
    {
        $mediaType = strtolower(trim(explode(';', $this->contentType)[0]));

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /**
     * Associative decode, null when the body is not a JSON object/array —
     * callers decide whether a non-JSON 200 is absence (fail-open probes) or a
     * protocol error (generation responses).
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public function decodedJson(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
