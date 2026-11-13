<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media;

use InvalidArgumentException;
use RuntimeException;
use SugarCraft\Image\PngMetadata;

/**
 * PNG container codec for the A1111 infotext round-trip — no SD server, no
 * library, pure byte work over the chunk framing of ISO/IEC 15948.
 *
 * S-N02 (R11): the chunk-framing engine now lives in sugar-image
 * {@see \SugarCraft\Image\PngMetadata} (which owns the golden corpus and every
 * law below); this class is the app-side adapter keeping the call-site
 * vocabulary plus the one GD-specific method the library does not carry
 * ({@see encodeGd()}). The laws below are documented where they are enforced.
 *
 * Plan W1.7 (plan_crush_media.md): "stamp/read the `parameters` tEXt chunk
 * ourselves and encode gd→PNG bytes". Payload text itself is produced and
 * consumed by {@see Infotext} — this class only moves opaque strings through
 * the container (W1.6 is the payload origin, so the dependency direction is
 * callers doing Infotext::emit → Png::writeText and Png::readText →
 * Infotext::parse; Png never calls Infotext).
 *
 * A1111 keyword law (§3.5 container facts, modules/images.py:565,624): the
 * generation parameters ride keyword `parameters` as a raw tEXt string
 * (default `pnginfo_section_name='parameters'`); the bare prompt additionally
 * rides keyword `prompt` as an iTXt chunk on modern saves. Both keywords are
 * read from either chunk type, so stamps from either A1111 era round-trip.
 *
 * Encoding choice (plan W1.7 step 2, verbatim): "tEXt (latin1) when
 * `mb_check_encoding($text,'ASCII')`… precisely: A1111 writes tEXt with the
 * raw str; non-latin1 bytes → iTXt(compressed, language tag '')". Read
 * operationally as the three-arm rule in {@see strategyFor()}: ASCII → tEXt;
 * non-ASCII UTF-8 → compressed iTXt; raw 8-bit non-UTF-8 (a legacy latin-1
 * dump) → tEXt verbatim, since iTXt text must legally be UTF-8 and A1111's
 * own writes are raw str. Interop edge disclosed: some legacy readers only
 * scan tEXt and will miss an iTXt stamp — unavoidable the moment the text is
 * non-ASCII UTF-8, because tEXt cannot legally carry those bytes as UTF-8;
 * {@see writeText()} reports the strategy chosen.
 *
 * CRC law (plan W1.7 step 3, verbatim): "CRC = `crc32($type.$data)` (common
 * trap: type INCLUDED — pin test with a hand-computed expected chunk byte)".
 * PNG spec §5.3: the CRC covers the chunk type and data fields, not the
 * length field. A no-type CRC would compile fine and break every reader —
 * PngTest pins externally-computed (python zlib) chunk bytes.
 *
 * Priority law: when one keyword appears in BOTH a tEXt and an iTXt chunk,
 * the iTXt value wins (PNG spec §4.2.3.1 note); within a chunk type the
 * first occurrence wins.
 *
 * Failure shapes (plan W1.7 step 1, verbatim): "Chunk framing strict:
 * length(4)+type(4)+data+crc(4); stop at IEND; refuse non-PNG, refuse total >
 * 64MiB, refuse truncated tail (Early Exit guards; reader returns null on
 * damage, never throws — mirrors the 'decode failures cost one transcript
 * line' resilience law, crush_media §8.6-4)". The writer keeps its refusals
 * loud: an InvalidArgumentException names exactly what was rejected.
 */
final class Png
{
    /** Size ceiling both directions — see {@see PngMetadata::MAX_BYTES}. */
    public const MAX_BYTES = PngMetadata::MAX_BYTES;

    /** Per-chunk text ceiling — see {@see PngMetadata::MAX_TEXT_BYTES}. */
    public const MAX_TEXT_BYTES = PngMetadata::MAX_TEXT_BYTES;

    /** A1111 default `pnginfo_section_name` (§3.5): the full parameters block. */
    public const KEYWORD_PARAMETERS = PngMetadata::KEYWORD_PARAMETERS;

    /** Modern-A1111 bare-prompt companion chunk keyword. */
    public const KEYWORD_PROMPT = PngMetadata::KEYWORD_PROMPT;

    /** Strategy reported by {@see writeText()}: ASCII text rides a plain tEXt chunk. */
    public const STRATEGY_TEXT = PngMetadata::STRATEGY_TEXT;

    /** Strategy reported by {@see writeText()}: non-ASCII text rides a compressed iTXt chunk. */
    public const STRATEGY_ITXT = PngMetadata::STRATEGY_ITXT;

    private function __construct()
    {
    }

    /** Magic sniff: eight fixed signature bytes, nothing more. */
    public static function isPng(string $bytes): bool
    {
        return PngMetadata::isPng($bytes);
    }

    /** Text under `$keyword`, or null for any unfavourable shape; never throws. */
    public static function readText(string $pngBytes, string $keyword = self::KEYWORD_PARAMETERS): ?string
    {
        return PngMetadata::readText($pngBytes, $keyword);
    }

    /**
     * Replace-in-place / insert-before-IEND of one text keyword.
     *
     * @param string|null $strategy out: which chunk type carried the text
     *
     * @throws InvalidArgumentException on non-PNG, damage, bad keyword, oversize
     */
    public static function writeText(string $pngBytes, string $keyword, string $text, ?string &$strategy = null): string
    {
        return PngMetadata::writeText($pngBytes, $keyword, $text, $strategy);
    }

    /**
     * Stamp the A1111 infotext (parameters chunk, plus an existing prompt chunk).
     *
     * @throws InvalidArgumentException with the same shapes as {@see writeText()}
     */
    public static function setInfotext(string $pngBytes, string $text): string
    {
        return PngMetadata::setInfotext($pngBytes, $text);
    }

    /**
     * Encode a GD image to PNG bytes (plan W1.7 step 4). Requires ext-gd; the
     * produced bytes obey the same 64 MiB ceiling as every other output. Stays
     * app-side: the library codec is pure byte work and carries no GD seam.
     *
     * @throws RuntimeException         if imagepng() fails or emits nothing
     * @throws InvalidArgumentException if the encoded bytes exceed 64 MiB
     */
    public static function encodeGd(\GdImage $image): string
    {
        ob_start();
        $written = imagepng($image);
        $bytes = ob_get_clean();
        if ($written !== true || !is_string($bytes) || $bytes === '') {
            throw new RuntimeException('imagepng() failed to encode the GD image.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Encoded PNG exceeds the 64 MiB ceiling.');
        }

        return $bytes;
    }

    /** Strategy law: ASCII → tEXt; UTF-8 → compressed iTXt; raw 8-bit → tEXt. */
    public static function strategyFor(string $text): string
    {
        return PngMetadata::strategyFor($text);
    }
}
