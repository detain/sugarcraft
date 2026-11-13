<?php

declare(strict_types=1);

namespace SugarCraft\Log\Formatter;

/**
 * Record-key policy shared by the machine-readable formatters.
 *
 * The envelope names {@see self::RESERVED} carry structural meaning for log
 * decoders (level, message, timestamp, caller, prefix). Context keys are
 * attacker-influenceable — via a pass-through `$context` from an API request
 * or tool arguments — so a context entry named `level` could CLOBBER the
 * envelope and forge a benign-looking record (`level=INFO` hiding a real
 * `level=ERROR`, or replacing `time` entirely).
 *
 * Policy: reserved names are never overwritten. A context key that collides
 * with a reserved name — or with an already-accepted key in the same record —
 * is namespaced by prepending `ctx_` until it is free. The empty-string key is
 * namespaced too: it collides with nothing, but it still names nothing, and a
 * decoder must never be handed a bare `=` token or a `""` member. The mapping is
 * deterministic and disclosed in the README so downstream consumers can rely
 * on `ctx_<key>` appearing whenever the raw name is reserved.
 */
final class RecordKeys
{
    /**
     * Envelope names the machine formatters own. Reserved unconditionally —
     * even when a given run does not emit one (e.g. `time` with
     * reportTimestamp disabled, or `_encode_error` outside the defensive
     * encode path) — so the context-to-record mapping never depends on
     * formatter flags or on which branch produced the line, and decoders see
     * a stable shape. `_encode_error` joined the list because JsonFormatter's
     * defensive fallback writes it: without reservation a hostile context
     * entry could pre-empt it and mask a real encode failure.
     *
     * @var list<string>
     */
    public const RESERVED = ['level', 'msg', 'time', 'caller', 'prefix', '_encode_error'];

    /** The namespace prefix prepended once per collision. */
    public const PREFIX = 'ctx_';

    /**
     * Return a collision-free key for the record, registering it in $used.
     *
     * @param array<string,bool> $used keys already claimed by this record
     */
    public static function claim(string $key, array &$used): string
    {
        // The empty key names nothing: left bare it would emit an unaddressable
        // `=value` token in logfmt and a `""` member in JSON, so it is claimed
        // like any other clash — first pass lands on `ctx_` via the loop below.
        $candidate = $key;
        while ($candidate === '' || \in_array($candidate, self::RESERVED, true) || isset($used[$candidate])) {
            $candidate = self::PREFIX . $candidate;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * True when $key names an envelope field that context must not overwrite.
     */
    public static function isReserved(string $key): bool
    {
        return \in_array($key, self::RESERVED, true);
    }
}
