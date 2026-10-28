<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * The elicitation/create wire shape, mapped to and from the ask-a-human
 * vocabulary (C-MCP-3).
 *
 * MCP's `elicitation/create` carries a `requestedSchema` of
 * `{"type":"object","properties":{...},"required":[...]}` — a FLAT set of
 * primitive-typed fields, each optionally enum-constrained (spec 2025-06-18
 * narrowing; this adapter speaks the 2024-11-05 protocol and accepts the
 * shape whenever a server sends it). This class turns that schema into a
 * question list a UI (or a test double, or an auto-approver) can render,
 * and turns the collected answers back into the protocol's
 * `{"action":"accept","content":{...}}` / `{"action":"decline"}` /
 * `{"action":"cancel"}` result — validating every answer against its
 * declared type before a byte of it is promised back to the server.
 *
 * Nothing here renders or asks: the approver closure is injected by the
 * caller ({@see McpServerRequests}), so the TUI, the headless refusal and
 * the tests all share one mapping/validation core. Strings the server
 * wrote (field messages) travel verbatim and are sanitised by whatever
 * displays them; no user-facing literals are minted here, so there are no
 * lang keys to add.
 */
final class McpElicitation
{
    /** Sentinel an approver returns to answer `{"action":"decline"}`. */
    public const DECLINE = '__mcp_decline__';

    /** Sentinel an approver returns to answer `{"action":"cancel"}`. */
    public const CANCEL = '__mcp_cancel__';

    /** Primitive JSON types elicitation fields may declare. */
    private const FIELD_TYPES = ['string', 'number', 'integer', 'boolean'];

    /**
     * Turn a requestedSchema into an ordered question list.
     *
     * Each entry is
     * `array{key: string, message: string, type: string,
     *        enum: ?list<string>, required: bool}`.
     * A `type: "string"` carrying a non-empty `enum` of strings becomes a
     * choice question (`enum` populated); every other field is a typed
     * free-input question.
     *
     * @return list<array{key: string, message: string, type: string, enum: ?list<string>, required: bool}>
     * @throws \InvalidArgumentException on any schema outside the flat
     *         primitive shape elicitation allows — a nested or untyped
     *         schema is refused loudly rather than rendered wrong.
     */
    public static function questions(mixed $requestedSchema): array
    {
        if (!is_array($requestedSchema) || ($requestedSchema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException('elicitation requestedSchema must be a JSON object of type "object"');
        }

        $properties = $requestedSchema['properties'] ?? null;
        if (!is_array($properties) || $properties === [] || array_keys($properties) === range(0, count($properties) - 1)) {
            throw new \InvalidArgumentException('elicitation requestedSchema needs a non-empty "properties" object');
        }

        $required = $requestedSchema['required'] ?? [];
        if (!is_array($required)) {
            throw new \InvalidArgumentException('elicitation requestedSchema "required" must be a list of field names');
        }

        $questions = [];

        foreach ($properties as $key => $field) {
            if (!is_string($key) || !is_array($field)) {
                throw new \InvalidArgumentException('elicitation property "' . (is_string($key) ? $key : '?') . '" must be a JSON object');
            }

            $type = $field['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::FIELD_TYPES, true)) {
                throw new \InvalidArgumentException('elicitation property "' . $key . '" declares unsupported type ' . get_debug_type($type));
            }

            $enum = null;
            if (array_key_exists('enum', $field)) {
                // The spec narrows enums to string fields; anything else
                // is a shape this client will not guess an answer for.
                if ($type !== 'string' || !is_array($field['enum']) || $field['enum'] === []) {
                    throw new \InvalidArgumentException('elicitation property "' . $key . '" carries an unusable enum');
                }

                foreach ($field['enum'] as $option) {
                    if (!is_string($option)) {
                        throw new \InvalidArgumentException('elicitation property "' . $key . '" enum must hold strings only');
                    }
                }

                $enum = array_values($field['enum']);
            }

            $message = $field['description'] ?? $field['title'] ?? null;
            if (!is_string($message) || $message === '') {
                $message = $key;
            }

            $questions[] = [
                'key' => $key,
                'message' => $message,
                'type' => $type,
                'enum' => $enum,
                'required' => in_array($key, $required, true),
            ];
        }

        return $questions;
    }

    /**
     * Run the collected answers (whatever the approver returned) back
     * through the question list and into the protocol result shape.
     *
     * @param list<array{key: string, message: string, type: string, enum: ?list<string>, required: bool}> $questions
     * @param (\Closure(list<array<string,mixed>>): mixed)|null $approver
     *        renders the questions and returns the answer map, one of the
     *        {@see DECLINE}/{@see CANCEL} sentinels, or null to cancel.
     *        Null approver means "nobody to ask" — a cancel, never a guess.
     * @return array<string,mixed> the `result` payload for the reply
     * @throws \InvalidArgumentException when an answer violates its
     *         declared type, names a field nobody asked for, or a required
     *         field is missing
     */
    public static function result(array $questions, ?\Closure $approver): array
    {
        if ($approver === null) {
            return ['action' => 'cancel'];
        }

        $answer = $approver($questions);

        if ($answer === null || $answer === self::CANCEL) {
            return ['action' => 'cancel'];
        }

        if ($answer === self::DECLINE) {
            return ['action' => 'decline'];
        }

        if (!is_array($answer)) {
            throw new \InvalidArgumentException('elicitation approver must return an answer map or a sentinel, ' . get_debug_type($answer) . ' given');
        }

        $content = [];

        foreach ($questions as $question) {
            $key = $question['key'];

            if (!array_key_exists($key, $answer)) {
                if ($question['required']) {
                    throw new \InvalidArgumentException('elicitation answer is missing required field "' . $key . '"');
                }

                continue;
            }

            $value = $answer[$key];

            if ($question['enum'] !== null && !in_array($value, $question['enum'], true)) {
                throw new \InvalidArgumentException('elicitation field "' . $key . '" answer is outside its enum');
            }

            self::assertFieldType($key, (string) $question['type'], $value);
            $content[$key] = $value;
        }

        // An answer naming a field nobody asked for is a bug in the
        // approver (or a server smuggling keys through a stale schema) —
        // refuse it rather than echo invented state back.
        foreach (array_keys($answer) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('elicitation answer keys must be field names');
            }

            $known = false;
            foreach ($questions as $question) {
                if ($question['key'] === $key) {
                    $known = true;
                    break;
                }
            }

            if (!$known) {
                throw new \InvalidArgumentException('elicitation answer names unknown field "' . $key . '"');
            }
        }

        return ['action' => 'accept', 'content' => $content];
    }

    /**
     * Whole round trip for a params map as it arrives on the wire.
     *
     * @param array<string,mixed> $params
     * @param (\Closure(list<array<string,mixed>>): mixed)|null $approver
     * @return array<string,mixed>
     * @throws \InvalidArgumentException on a malformed schema (the caller
     *         maps this to a JSON-RPC invalid-params error) or a violating
     *         answer (mapped to an internal error).
     */
    public static function respond(array $params, ?\Closure $approver): array
    {
        return self::result(self::questions($params['requestedSchema'] ?? null), $approver);
    }

    private static function assertFieldType(string $key, string $type, mixed $value): void
    {
        $ok = match ($type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            default => false,
        };

        if (!$ok) {
            throw new \InvalidArgumentException('elicitation field "' . $key . '" expects ' . $type . ', got ' . get_debug_type($value));
        }
    }
}
