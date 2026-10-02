<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

final readonly class ToolCall
{
    /** Bytes of the raw payload quoted back in {@see argumentsErrorFor()}'s message. */
    private const ERROR_EXCERPT_LIMIT = 200;

    /**
     * Audit A23: the provider's verbatim `function.arguments` JSON, kept only
     * while it decodes EXACTLY to {@see $arguments} - see {@see rawArguments()}.
     */
    private ?string $rawArguments;

    /**
     * @param ?string $argumentsError audit A11: set when the provider received
     *                                an `arguments` payload it could not decode
     *                                into an argument map. The call then
     *                                carries `[]` arguments only so its shape
     *                                stays valid; {@see \SugarCraft\Crush\Runtime}
     *                                refuses to run it and answers the model
     *                                with this text instead, so the model
     *                                learns its JSON was broken rather than
     *                                reading a misleading "missing parameter"
     *                                error from a tool that ran with nothing.
     * @param ?string $rawArguments   audit A23: the wire `function.arguments`
     *                                string the model sent, when there was one.
     *                                Providers pass it unconditionally; it is
     *                                kept only if it is a JSON object that
     *                                decodes to exactly `$arguments`, so a
     *                                broken, scalar or list payload - or one a
     *                                caller paired with different arguments -
     *                                is dropped here and never replayed.
     */
    public function __construct(
        private string $id,
        private string $name,
        private array $arguments,
        private ?string $argumentsError = null,
        ?string $rawArguments = null,
    ) {
        $this->rawArguments = self::faithfulRaw($rawArguments, $arguments);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * Why this call's wire arguments could not be used, or null when they
     * decoded (including a genuine zero-argument call).
     *
     * `??` rather than a plain read: a call `serialize()`d before this
     * property existed (a suspended delegation's transcript, see
     * {@see \SugarCraft\Crush\Agents\SuspendedDelegations}) unserializes with
     * it uninitialized, and a typed read of that would throw.
     */
    public function argumentsError(): ?string
    {
        return $this->argumentsError ?? null;
    }

    /**
     * The same call, marked as carrying undecodable arguments (or cleared,
     * with null).
     */
    public function withArgumentsError(?string $argumentsError): self
    {
        return new self($this->id, $this->name, $this->arguments, $argumentsError, $this->rawArguments());
    }

    /**
     * The arguments exactly as the model sent them on the wire, or null when
     * there is no faithful wire form to replay (a recovered textual call, a
     * pre-decoded payload, a blank or broken one).
     *
     * Audit A23: replaying a call re-encoded from {@see arguments()} cannot be
     * faithful, because an associative `json_decode()` maps `{}` and `[]` to
     * the same empty PHP array - `{"opts":{}}` went back to the model as
     * `{"opts":[]}`, contradicting the tool's schema on every later step, and
     * models copy what they see themselves doing. The original string carries
     * no such ambiguity, so the history formatter
     * ({@see \SugarCraft\Crush\Providers\Concerns\ToolSchema::formatToolCalls()})
     * sends it verbatim and re-encodes only when this is null.
     *
     * Non-null implies it decodes to exactly {@see arguments()}: the
     * constructor checks, so a call rebuilt with rewritten arguments can
     * never replay text that says something else.
     *
     * `??` for the same reason as {@see argumentsError()}: a call serialized
     * before this property existed unserializes with it uninitialized.
     */
    public function rawArguments(): ?string
    {
        return $this->rawArguments ?? null;
    }

    /**
     * Classify a raw `function.arguments` wire value: null when it is usable
     * as an argument map, else the model-facing reason it is not.
     *
     * Usable: an already-decoded array (servers that pre-decode), an absent
     * or blank payload (how a genuine zero-argument call arrives), JSON
     * `null` (some servers' spelling of the same), and any JSON object or
     * array. Everything else - invalid JSON, or a JSON scalar - is a call the
     * model asked for with arguments that cannot reach the tool, and running
     * it with `[]` would answer a question the model never asked.
     *
     * Pure: no logging. Providers keep their own UI diagnostics; this only
     * decides what the call carries to Runtime.
     */
    public static function argumentsErrorFor(mixed $raw): ?string
    {
        if (is_array($raw) || $raw === null) {
            return null;
        }

        if (!is_string($raw)) {
            return sprintf('arguments were %s, not a JSON object', get_debug_type($raw));
        }

        if (trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return sprintf(
                'arguments were not valid JSON (%s): %s',
                json_last_error_msg(),
                self::excerpt($raw),
            );
        }

        if ($decoded === null || is_array($decoded)) {
            return null;
        }

        return sprintf(
            'arguments decoded to %s, not a JSON object: %s',
            get_debug_type($decoded),
            self::excerpt($raw),
        );
    }

    public static function fromArray(array $data): self
    {
        $error = $data['argumentsError'] ?? null;

        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            arguments: $data['arguments'] ?? [],
            argumentsError: is_string($error) ? $error : null,
            rawArguments: is_string($raw = $data['rawArguments'] ?? null) ? $raw : null,
        );
    }

    /**
     * `argumentsError` and `rawArguments` are written only when set, so a
     * call without them keeps the exact three-key shape every existing reader
     * was written against.
     */
    public function toArray(): array
    {
        $array = [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];

        if ($this->argumentsError() !== null) {
            $array['argumentsError'] = $this->argumentsError();
        }

        if ($this->rawArguments() !== null) {
            $array['rawArguments'] = $this->rawArguments();
        }

        return $array;
    }

    /**
     * `$raw` when it is a JSON object that decodes to exactly `$arguments`,
     * else null.
     *
     * Object-only because `function.arguments` is an object in the OpenAI
     * schema: a top-level list or scalar is re-encoded as an object by the
     * formatter rather than replayed. Strict `===` also catches key order and
     * int/float/string drift, so the replayed text and the arguments the tool
     * ran with cannot disagree.
     *
     * @param array<mixed> $arguments
     */
    private static function faithfulRaw(?string $raw, array $arguments): ?string
    {
        if ($raw === null || !str_starts_with(ltrim($raw, " \t\n\r"), '{')) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $decoded === $arguments ? $raw : null;
    }

    private static function excerpt(string $raw): string
    {
        if (strlen($raw) <= self::ERROR_EXCERPT_LIMIT) {
            return $raw;
        }

        // mb_strcut never splits a UTF-8 sequence, so the excerpt stays valid
        // text however the cut falls.
        return mb_strcut($raw, 0, self::ERROR_EXCERPT_LIMIT, 'UTF-8') . ' [...]';
    }
}
