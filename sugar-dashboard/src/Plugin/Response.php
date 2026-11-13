<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plugin;

/**
 * Response DTO for plugin communication.
 *
 * Represents a response from a plugin executable to the host.
 * Sent as line-delimited JSON over stdout.
 *
 * Mirrors the lattice JSON protocol.
 *
 * @readonly
 */
final class Response
{
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {}

    /**
     * Create an init response.
     *
     * @param string $name Module name
     * @param array{0:int,1:int} $minSize Minimum dimensions
     * @param int $interval Update interval in seconds (0 = no auto-update)
     */
    public static function init(string $name, array $minSize, int $interval = 0): self
    {
        return new self('init', [
            'name' => $name,
            'minSize' => $minSize,
            'interval' => $interval,
        ]);
    }

    /**
     * Create an update response.
     *
     * @param array<string, mixed> $state Updated module state
     */
    public static function update(array $state): self
    {
        return new self('update', ['state' => $state]);
    }

    /**
     * Create a view response.
     *
     * @param string $content Rendered content
     */
    public static function view(string $content): self
    {
        return new self('view', ['content' => $content]);
    }

    /**
     * Create an error response.
     *
     * @param string $message Error message
     */
    public static function error(string $message): self
    {
        return new self('error', ['message' => $message]);
    }

    /** Maximum number of raw bytes echoed back in a protocol-error message. */
    private const ERROR_PREVIEW_BYTES = 120;

    /**
     * Create from JSON string.
     *
     * Parses at the boundary: the decoded document must already carry a
     * string type and an array payload, or the line is refused with a
     * printable, clipped preview. Raw hostile bytes never reach the
     * message (exception texts travel into terminal chrome), and a
     * mistyped field is a protocol violation — not a PHP TypeError.
     *
     * @throws \InvalidArgumentException If the line is not a valid response object.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid plugin JSON: ' . self::preview($json));
        }

        $type = $data['type'] ?? 'unknown';
        if (!is_string($type)) {
            throw new \InvalidArgumentException(
                'Plugin response "type" must be a string, got ' . get_debug_type($type) . ': ' . self::preview($json)
            );
        }

        $payload = $data['data'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException(
                'Plugin response "data" must be an object, got ' . get_debug_type($payload) . ': ' . self::preview($json)
            );
        }

        return new self($type, $payload);
    }

    /**
     * Convert to JSON string.
     *
     * Invalid UTF-8 is substituted, not fatal: the error path at
     * PluginSdk::run()'s catch builds this JSON from an exception message
     * that may carry raw bytes (finding sugar-dashboard-209), and a throw
     * there would kill the child's reply channel — the one moment it most
     * owes the host an answer.
     *
     * @throws \JsonException If the payload cannot be encoded (inf/nan).
     */
    public function toJson(): string
    {
        return json_encode([
            'type' => $this->type,
            'data' => $this->data,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Printable, length-clipped rendering of an unparsable wire line.
     */
    private static function preview(string $json): string
    {
        $trimmed = trim($json);
        if (strlen($trimmed) > self::ERROR_PREVIEW_BYTES) {
            $trimmed = substr($trimmed, 0, self::ERROR_PREVIEW_BYTES) . '...';
        }

        // Byte-wise: every non-printable (control bytes, ESC sequences,
        // invalid UTF-8) becomes '?' so a refusal can never smuggle a
        // terminal escape into whatever surface prints it.
        return preg_replace('/[^\x20-\x7E]/', '?', $trimmed) ?? '';
    }
}
