<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * RFC 6901 JSON pointer resolution over decoded workflow documents — the
 * one resolver the condition grammar ({@see StageCondition} `pointer:`)
 * reads typed rows with, written to the EXACT semantics of the engine's
 * `{{name.<pointer>}}` interpolation helper so both surfaces answer the
 * same leaf for the same spelling:
 *
 * - The pointer is split on `/` BEFORE unescaping, so `~1` protects an
 *   embedded slash; each reference token then unescapes `~1` → `/` first
 *   and `~0` → `~` second — reversed, `~01` would wrongly reach a key `/`.
 * - A leading `/` is the from-root spelling and addresses the same document
 *   as the bare form: `/a/0` and `a/0` are the same pointer. The empty
 *   pointer is the whole document; `/` is the member named "".
 * - Dots carry no meaning: `a.b` names ONE key with a dot in it.
 * - Array indices are member names like any other: `0` reaches a list's
 *   first item, a non-canonical index (`01`, `-`, `1.0`) reaches nothing.
 *
 * Unlike the interpolation helper (which only wants scalars to splice into
 * a prompt), resolution here returns ANY leaf — objects, lists, and JSON
 * null — and reports found/not-found separately, because "the member is
 * null" and "there is no member" are different answers to a routing
 * question.
 */
final class JsonPointer
{
    /**
     * Resolve $pointer against an already-decoded document (json_decode
     * with assoc=true, so objects are PHP arrays).
     *
     * @return array{found: bool, value: mixed} `found` false means the
     *         pointer reached nothing; `found` true with a null `value` is a
     *         JSON null leaf.
     */
    public static function resolve(mixed $document, string $pointer): array
    {
        $node = $document;
        foreach (self::tokens($pointer) as $name) {
            if (!is_array($node) || !array_key_exists($name, $node)) {
                return ['found' => false, 'value' => null];
            }
            $node = $node[$name];
        }

        return ['found' => true, 'value' => $node];
    }

    /**
     * Resolve $pointer against a JSON text, such as a row of the engine's
     * {@see WorkflowEngine::TYPED_RESULTS_CONTEXT_KEY} map. A text that is
     * not valid JSON reaches nothing — never a thrown error, because a run
     * context is data and the callers answer a miss fail-closed.
     *
     * @return array{found: bool, value: mixed}
     */
    public static function resolveJson(string $json, string $pointer): array
    {
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['found' => false, 'value' => null];
        }

        return self::resolve($document, $pointer);
    }

    /**
     * Whether every `~` in $pointer starts a legal escape (`~0` or `~1`).
     * Resolution itself tolerates a stray `~` (it simply names a member
     * with a tilde in it, as the interpolation helper always has); this is
     * the LOAD-time check a grammar key can refuse on, so a typo such as
     * `~2` is reported where it is written instead of quietly missing.
     */
    public static function isWellFormed(string $pointer): bool
    {
        return preg_match('/~(?![01])/', $pointer) !== 1;
    }

    /**
     * The unescaped reference tokens of $pointer, root first.
     *
     * @return list<string>
     */
    public static function tokens(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        $segments = explode('/', $pointer);
        if ($segments[0] === '') {
            array_shift($segments);
        }

        return array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            $segments,
        );
    }
}
