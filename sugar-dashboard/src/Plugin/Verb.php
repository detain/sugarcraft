<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plugin;

/**
 * The plugin wire vocabulary (sugar-dashboard-103).
 *
 * One line-delimited JSON exchange per request, four verbs total — the
 * handshake (init), the state seam (update), the render seam (view), and
 * the honest failure reply (error). Before this enum every leg compared
 * raw string literals, so a typo (`'rendar'`) compiled, decoded as an
 * unknown verb, and could only be caught at the wire. Decoders still
 * keep the raw string (foreign plugins may extend the vocabulary);
 * {@see \SugarCraft\Dashboard\Plugin\Request::verb()} parses it to a
 * case at the boundary, and unknown-but-string types answer with a
 * visible protocol error instead of a silent panel disable.
 *
 * The label used when a JSON object omits "type" is
 * {@see self::WIRE_MISSING} — deliberately not a verb, deliberately the
 * one spelling (the three old seats said 'unknown', 'unknown' and
 * 'Unknown request type').
 */
enum Verb: string
{
    /** Wire label for a payload that carried no "type" at all. */
    public const WIRE_MISSING = 'unknown';

    case Init = 'init';
    case Update = 'update';
    case View = 'view';
    case Error = 'error';
}
