<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host\ClientTools;

/**
 * One client-registered tool descriptor, validated at the door.
 *
 * A client (an editor, a browser bridge, anything holding the session's
 * `Write` scope) announces a set of these; from the next turn each one is
 * offered to the model as a `client__<name>` proxy
 * ({@see ClientToolProxy}). The proxy never re-reads a wire payload — by the
 * time anything downstream sees a spec, every field already satisfies the
 * shape this class enforces in its constructor, so a directly-built spec and
 * a wire-parsed one cannot disagree. The validation lives in the type, not in
 * whichever caller remembered to check.
 *
 * ## The bounds, and why each exists
 *
 * - `name` matches {@see NAME_PATTERN}: lowercase ASCII, leading letter, at
 *   most 48 characters. Provider-side tool-name grammars are strict about
 *   underscores and hostile about everything else; `browser_context` is the
 *   shape a real announcement carries, `Browser-Context` is a wire fatal
 *   three hops from its cause.
 * - `description` is 1..1024 CHARACTERS (`mb_strlen`, not bytes — the model
 *   reads characters and a multilingual client sends them). One description
 *   per proxy is what the model sees in its tool list; the cap bounds the
 *   prompt cost of a registration, not the client's creativity.
 * - `inputSchema` must declare `type: "object"` and encode to at most
 *   {@see MAX_SCHEMA_BYTES}. Providers reject non-object top-level schemas
 *   outright, and one bloated announcement would bill every later turn of
 *   the session for the same JSON.
 * - `timeoutSeconds` is 1..600. This is the SAME per-operation ceiling
 *   `user__*` scripts declared in C-TOOL-6.e and for the same reason: it
 *   bounds ONE client's answer, chosen by the registration itself. It is not
 *   a blanket request timeout — the standing E646 law (no whole-LLM-call
 *   deadline) is untouched by it, because nothing here puts a timer on a
 *   provider exchange; the timer belongs to one tool call on one person's
 *   browser.
 *
 * Unknown top-level keys are tolerated by {@see fromDecoded()} (a future
 * field must not brick an old build's parser), but every KNOWN key must be
 * exactly its declared type — no coercion, no trimming.
 */
final class ClientToolSpec
{
    /** The tool's own name, before the `client__` proxy prefix is added. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/';

    /** Longest accepted description, counted in characters. */
    public const MAX_DESCRIPTION_CHARS = 1024;

    /** Longest accepted input schema, counted as its JSON encoding in bytes. */
    public const MAX_SCHEMA_BYTES = 16384;

    public const MIN_TIMEOUT_SECONDS = 1;

    public const MAX_TIMEOUT_SECONDS = 600;

    /** An announcement that omits the timeout gets a comfortable two minutes. */
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        /** @var array<string, mixed> */
        public readonly array $inputSchema,
        public readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('bad_name');
        }

        if ($description === '' || mb_strlen($description) > self::MAX_DESCRIPTION_CHARS) {
            throw new \InvalidArgumentException('bad_description');
        }

        if (($inputSchema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException('bad_schema');
        }

        // The size bound is on the WIRE shape the model pays for, which is
        // the encoding, not the count of PHP array entries.
        $encoded = json_encode($inputSchema);
        if ($encoded === false || strlen($encoded) > self::MAX_SCHEMA_BYTES) {
            throw new \InvalidArgumentException('bad_schema');
        }

        if ($timeoutSeconds < self::MIN_TIMEOUT_SECONDS || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException('bad_timeout');
        }
    }

    /**
     * The whole announced shape, as a verdict: null when it IS a tool
     * descriptor, otherwise the one-word class of what made it not one.
     *
     * Mirrors {@see \SugarCraft\Code\Tools\UserTools\UserToolSpec::refusal()}:
     * the wire parser (C-TOOL-6.i) answers a bad announcement with the verdict
     * word, never with an exception's stack trace, and {@see fromDecoded()}
     * is only ever reached after this answers null.
     *
     * @param array<array-key, mixed> $decoded the announcement, already decoded
     */
    public static function refusal(array $decoded): ?string
    {
        if ($decoded === []) {
            return 'bad_shape';
        }

        $name = $decoded['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return 'bad_name';
        }

        $description = $decoded['description'] ?? null;
        if (!is_string($description) || $description === '' || mb_strlen($description) > self::MAX_DESCRIPTION_CHARS) {
            return 'bad_description';
        }

        $schema = $decoded['inputSchema'] ?? null;
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object') {
            return 'bad_schema';
        }

        $encoded = json_encode($schema);
        if ($encoded === false || strlen($encoded) > self::MAX_SCHEMA_BYTES) {
            return 'bad_schema';
        }

        $timeout = $decoded['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS;
        if (!is_int($timeout)
            || $timeout < self::MIN_TIMEOUT_SECONDS
            || $timeout > self::MAX_TIMEOUT_SECONDS
        ) {
            return 'bad_timeout';
        }

        return null;
    }

    /**
     * Build the validated spec from a shape {@see refusal()} answered null.
     * The constructor re-checks every bound; this method only narrows types.
     *
     * @param array<array-key, mixed> $decoded
     */
    public static function fromDecoded(array $decoded): self
    {
        /** @var string $name */
        $name = $decoded['name'];
        /** @var string $description */
        $description = $decoded['description'];
        /** @var array<string, mixed> $schema */
        $schema = $decoded['inputSchema'];
        /** @var int $timeout */
        $timeout = $decoded['timeoutSeconds'] ?? self::DEFAULT_TIMEOUT_SECONDS;

        return new self(
            name: $name,
            description: $description,
            inputSchema: $schema,
            timeoutSeconds: $timeout,
        );
    }
}
