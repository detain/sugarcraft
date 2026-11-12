<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * C6 (wf-impl): the `key:=<json>` spelling of a run-context pair, shared by
 * `/workflow run` and the headless `workflow run --param` (httpie's `:=`).
 *
 * A plain `key=value` pair is always a STRING, so a stage reading a list —
 * `from: {context: files}` — could only be driven from the Workflow tool or
 * PHP. `files:='["src/A.php","src/B.php"]'` now carries the decoded JSON
 * value (list, object, number, boolean or null) instead. Malformed JSON is
 * refused naming the key, never passed through as the raw string.
 */
final class RunContextValue
{
    /** The operator that marks a pair's value as JSON. */
    public const JSON_OPERATOR = ':=';

    /**
     * Split one `key=value` / `key:=json` token at its first `=`.
     *
     * @return array{0: string, 1: string, 2: bool}|null [key, raw value, isJson];
     *         null when the token has no `=` at all
     */
    public static function split(string $pair): ?array
    {
        $at = strpos($pair, '=');
        if ($at === false) {
            return null;
        }

        if ($at > 0 && $pair[$at - 1] === ':') {
            return [substr($pair, 0, $at - 1), substr($pair, $at + 1), true];
        }

        return [substr($pair, 0, $at), substr($pair, $at + 1), false];
    }

    /**
     * The decoded value of a `key:=json` pair.
     *
     * @throws \InvalidArgumentException naming the key, when $json does not parse.
     */
    public static function decode(string $key, string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf(
                '`%s:=` needs a JSON value (e.g. %s:=\'["a","b"]\'), got `%s` (%s)',
                $key,
                $key,
                $json,
                $e->getMessage(),
            ), 0, $e);
        }
    }
}
