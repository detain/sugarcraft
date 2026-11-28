<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * The on-disk shape a {@see LogReader} parses, one per writing formatter.
 *
 * String-backed so a config value parses with `LogFormat::from('json')`.
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
enum LogFormat: string
{
    /** One JSON object per line, as {@see Formatter\JsonFormatter} writes. */
    case Json = 'json';

    /** `key=value` pairs per line, as {@see Formatter\LogfmtFormatter} writes. */
    case Logfmt = 'logfmt';

    /**
     * Human-readable lines, as {@see Formatter\TextFormatter} writes (or any
     * plain-text log). Only the level is recovered; the rest stays the line.
     */
    case Text = 'text';
}
