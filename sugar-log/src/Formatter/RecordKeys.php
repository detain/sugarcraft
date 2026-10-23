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
 * is namespaced by prepending `ctx_` until it is free. The mapping is
 * deterministic and disclosed in the README so downstream consumers can rely
 * on `ctx_<key>` appearing whenever the raw name is reserved.
 */
final class RecordKeys
{
    /**
     * Envelope names the machine formatters own. Reserved unconditionally —
     * even when a given run does not emit one (e.g. `time` with
     * reportTimestamp disabled) — so the context-to-record mapping never
     * depends on formatter flags and decoders see a stable shape.
     *
     * @var list<string>
     */
    public const RESERVED = ['level', 'msg', 'time', 'caller', 'prefix'];

    /** The namespace prefix prepended once per collision. */
    public const PREFIX = 'ctx_';

    /**
     * Return a collision-free key for the record, registering it in $used.
     *
     * @param array<string,bool> $used keys already claimed by this record
     */
    public static function claim(string $key, array &$used): string
    {
        $candidate = $key;
        while (\in_array($candidate, self::RESERVED, true) || isset($used[$candidate])) {
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
