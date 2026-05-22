<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * One classified slice from a byte stream emitted by {@see Parser::parse()}.
 *
 * The parser turns a raw byte buffer into a list of these tokens so
 * downstream consumers (key/mouse decoders, OSC reply handlers, the
 * renderer's diff layer) can branch on `$type` and read structured
 * fields instead of scanning bytes themselves.
 *
 * Field semantics by `$type`:
 *
 * - `Text`     — `$data` is one or more printable / whitespace bytes.
 * - `Control`  — `$data` is a single C0 control byte (`\r`, `\n`, `\t`,
 *                Backspace, Bell, etc.).
 * - `Esc`      — Short two-byte sequence. `$data` is the byte after ESC
 *                (e.g. `7` for DECSC, `8` for DECRC, `M` for reverse
 *                index).
 * - `Csi`      — `ESC [ … final`. `$intermediate` carries the leading
 *                private marker (`?`, `>`, `=`, `<`) plus any trailing
 *                intermediate bytes. `$params` is the raw parameter
 *                substring. `$final` is the single final byte
 *                (`A`-`Z`, `a`-`z`, `@`-`~`).
 * - `Osc`      — `$data` is the body between `ESC ]` and the
 *                terminator (ST / BEL).
 * - `Dcs`      — `$data` is the body between `ESC P` and ST.
 * - `Apc`      — `$data` is the body between `ESC _` and ST. CandyZone
 *                uses APC for its zero-width markers.
 * - `Sos`      — `$data` is the body between `ESC X` and ST.
 * - `Pm`       — `$data` is the body between `ESC ^` and ST.
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
    ) {
    }

    /**
     * Parse `$params` as a semicolon-separated list of integer params
     * (CSI convention). Empty entries become 0. Useful inside CSI
     * tokens whose final byte takes a numeric argument list.
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
