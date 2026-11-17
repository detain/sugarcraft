<?php

declare(strict_types=1);

namespace SugarCraft\AnsiParser;

/**
 * One classified slice of a byte stream, as emitted by {@see AnsiTokenizer::parse()}.
 *
 * Field semantics by `$type`:
 *
 * - `TEXT`    — `$data` is one or more bytes that are not C0 controls or ESC.
 * - `CONTROL` — `$data` is a single C0 control byte (or DEL, 0x7F).
 * - `ESC`     — short two-byte sequence; `$data` is the byte after ESC.
 * - `CSI`     — `ESC [ … final`. `$intermediate` carries the leading private
 *               marker (`?`, `>`, `=`, `<`) plus any trailing intermediate
 *               bytes; `$params` is the raw parameter substring; `$final` is
 *               the single final byte.
 * - `OSC`, `DCS`, `APC`, `SOS`, `PM` — `$data` is the body between the opener
 *               and its terminator. `$terminator` is the exact byte run that
 *               closed it (`"\x1b\\"` for ST, `"\x07"` for BEL), which a
 *               byte-offset consumer needs to measure the span consumed.
 *
 * The shape and type constants match the tokens sugar-core's `Util\Token`
 * emits, so a consumer moving onto this class sees the same fields.
 *
 * Tokens are immutable.
 */
final class Token
{
    public const TEXT    = 'text';
    public const CONTROL = 'control';
    public const ESC     = 'esc';
    public const CSI     = 'csi';
    public const OSC     = 'osc';
    public const DCS     = 'dcs';
    public const APC     = 'apc';
    public const SOS     = 'sos';
    public const PM      = 'pm';

    public function __construct(
        public readonly string $type,
        public readonly string $data = '',
        public readonly string $intermediate = '',
        public readonly string $params = '',
        public readonly string $final = '',
        public readonly string $terminator = '',
    ) {
    }

    /**
     * Parse `$params` as a semicolon-separated list of integers (CSI
     * convention). An empty slot reads as 0.
     *
     * @return list<int>
     */
    public function paramInts(): array
    {
        if ($this->params === '') {
            return [];
        }
        $out = [];
        foreach (explode(';', $this->params) as $p) {
            $out[] = $p === '' ? 0 : (int) $p;
        }
        return $out;
    }
}
