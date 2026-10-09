<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use InvalidArgumentException;
use RuntimeException;

/**
 * PNG container codec for the A1111 infotext round-trip — no SD server, no
 * library, pure byte work over the chunk framing of ISO/IEC 15948.
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
    /** Size ceiling both directions — sibling of candy-mosaic ImageSource::MAX_BYTES (64 MiB). */
    public const MAX_BYTES = 67_108_864;

    /** A1111 default `pnginfo_section_name` (§3.5): the full parameters block. */
    public const KEYWORD_PARAMETERS = 'parameters';

    /** Modern-A1111 bare-prompt companion chunk keyword, written as iTXt by upstream. */
    public const KEYWORD_PROMPT = 'prompt';

    /** Strategy reported by {@see writeText()}: ASCII text rides a plain tEXt chunk. */
    public const STRATEGY_TEXT = 'tEXt';

    /** Strategy reported by {@see writeText()}: non-ASCII text rides a compressed iTXt chunk. */
    public const STRATEGY_ITXT = 'iTXt';

    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const TEXT_CHUNKS = ['tEXt' => true, 'iTXt' => true];

    private function __construct()
    {
    }

    /** Magic sniff: eight fixed signature bytes, nothing more. */
    public static function isPng(string $bytes): bool
    {
        return str_starts_with($bytes, self::SIGNATURE);
    }

    /**
     * Extract the text stored under `$keyword`, or null for ANY unfavourable
     * shape: not a PNG, oversized, truncated tail, CRC-mismatched chunk,
     * malformed text chunk, compressed iTXt that will not inflate, or simply
     * absent keyword. Never throws (reader resilience law).
     */
    public static function readText(string $pngBytes, string $keyword = self::KEYWORD_PARAMETERS): ?string
    {
        $chunks = self::scan($pngBytes);
        if ($chunks === null) {
            return null;
        }
        $text = null;
        foreach ($chunks as $chunk) {
            if (!isset(self::TEXT_CHUNKS[$chunk['type']])) {
                continue;
            }
            $entry = self::decodeTextChunk($chunk['type'], $chunk['data']);
            if ($entry === null) {
                return null; // malformed text chunk = damaged container, fail closed
            }
            if ($entry['keyword'] !== $keyword) {
                continue;
            }
            if ($chunk['type'] === 'iTXt') {
                return $entry['text']; // iTXt priority over tEXt (PNG spec §4.2.3.1)
            }
            $text ??= $entry['text']; // first tEXt occurrence wins
        }

        return $text;
    }

    /**
     * Return the PNG with `$keyword` carrying `$text`: an existing tEXt/iTXt
     * chunk with that keyword is REPLACED in place — never appended as a
     * duplicate — and every other chunk keeps its bytes and ordering
     * untouched, including trailing bytes after IEND. No matching chunk → a
     * new one is inserted immediately before IEND.
     *
     * Strategy law (W1.7 step 2): ASCII text → tEXt (latin-1 raw, what A1111
     * itself writes); anything else → iTXt compressed with empty language and
     * translated-keyword fields. `$strategy` reports which was chosen.
     *
     * @throws InvalidArgumentException on non-PNG input, > 64 MiB input,
     *                                  damaged framing/CRC, an illegal
     *                                  keyword, NUL bytes in the text, or a
     *                                  produced file over the 64 MiB cap
     */
    public static function writeText(string $pngBytes, string $keyword, string $text, ?string &$strategy = null): string
    {
        $chunks = self::requireParseable($pngBytes, 'writeText');
        if (!self::isValidKeyword($keyword)) {
            throw new InvalidArgumentException('PNG text-chunk keyword must be 1-79 printable bytes with no NUL and no leading/trailing space.');
        }
        if (str_contains($text, "\x00")) {
            throw new InvalidArgumentException('PNG text-chunk payload may not contain NUL bytes.');
        }
        $strategy = self::strategyFor($text);
        $replacement = self::chunk($strategy, $keyword . "\x00" . ($strategy === self::STRATEGY_TEXT
            ? $text
            : chr(1) . chr(0) . "\x00\x00" . gzcompress($text, 9)));
        $rebuilt = self::SIGNATURE;
        $placed = false;
        foreach ($chunks as $chunk) {
            $isText = isset(self::TEXT_CHUNKS[$chunk['type']]);
            $entry = $isText ? self::decodeTextChunk($chunk['type'], $chunk['data']) : null;
            if ($isText && $entry === null) {
                throw new InvalidArgumentException("Png::writeText(): {$chunk['type']} chunk payload malformed at offset {$chunk['start']}.");
            }
            $isTarget = $isText && $entry['keyword'] === $keyword;
            if ($isTarget && $placed) {
                continue; // duplicate occurrence of the keyword → collapsed, never kept
            }
            if ($chunk['type'] === 'IEND' && !$placed) {
                $rebuilt .= $replacement; // absent keyword → insert before IEND
                $placed = true;
            }
            if ($isTarget) {
                $rebuilt .= $replacement; // in-place replacement
                $placed = true;
                continue;
            }
            $rebuilt .= substr($pngBytes, $chunk['start'], $chunk['end'] - $chunk['start']);
        }
        $rebuilt .= substr($pngBytes, $chunks[count($chunks) - 1]['end']); // preserve any post-IEND tail verbatim
        if (strlen($rebuilt) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Produced PNG would exceed the 64 MiB ceiling.');
        }

        return $rebuilt;
    }

    /**
     * Stamp the full infotext block: `$text` becomes the `parameters` chunk
     * (in place, per the replacement law) and an already-present `prompt`
     * chunk is re-synced to the first line of the text — the A1111 pair
     * law ("parameters" tEXt carries everything, "prompt" iTXt carries the
     * bare prompt). A file without a `prompt` chunk is not given one: the
     * minimal-diff / preservation law outranks minting upstream's newer
     * companion chunk on our stamps.
     *
     * @throws InvalidArgumentException with the same shapes as {@see writeText()}
     */
    public static function setInfotext(string $pngBytes, string $text): string
    {
        $stamped = self::writeText($pngBytes, self::KEYWORD_PARAMETERS, $text);
        if (self::readText($stamped, self::KEYWORD_PROMPT) !== null) {
            $prompt = strstr($text, "\n", true);
            $stamped = self::writeText($stamped, self::KEYWORD_PROMPT, $prompt === false ? $text : $prompt);
        }

        return $stamped;
    }

    /**
     * Encode a GD image to PNG bytes (plan W1.7 step 4: "encodeGd wraps
     * `imagepng($im)` output buffering; no resample/blend logic here —
     * mosaic's job"). Requires ext-gd; the produced bytes obey the same
     * 64 MiB ceiling as every other output.
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

    /**
     * Strategy law in one place (W1.7 step 2): ASCII rides tEXt; non-ASCII
     * that is legal UTF-8 rides compressed iTXt; raw 8-bit bytes that are not
     * valid UTF-8 (a legacy latin-1 dump) ride tEXt verbatim — exactly
     * "A1111 writes tEXt with the raw str", since iTXt text must be UTF-8.
     */
    public static function strategyFor(string $text): string
    {
        if (mb_check_encoding($text, 'ASCII')) {
            return self::STRATEGY_TEXT;
        }

        return mb_check_encoding($text, 'UTF-8') ? self::STRATEGY_ITXT : self::STRATEGY_TEXT;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Parse the chunk framing into trusted records
     * `{type,data,start,end}` or null for any damage — the Early Exit
     * guard stack of the reader: signature, size cap, IHDR-first, complete
     * frames, CRC over type+data, stop at IEND.
     *
     * @return list<array{type: string, data: string, start: int, end: int}>|null
     */
    private static function scan(string $pngBytes): ?array
    {
        if (strlen($pngBytes) > self::MAX_BYTES || !self::isPng($pngBytes)) {
            return null;
        }
        $offset = 8;
        $total = strlen($pngBytes);
        $chunks = [];
        while (true) {
            if ($total - $offset < 12) {
                return null; // truncated tail: not even a minimal frame
            }
            $length = unpack('N', substr($pngBytes, $offset, 4))[1];
            $end = $offset + 12 + $length;
            if ($end > $total) {
                return null; // truncated chunk
            }
            $type = substr($pngBytes, $offset + 4, 4);
            $data = substr($pngBytes, $offset + 8, $length);
            $crc = unpack('N', substr($pngBytes, $offset + 8 + $length, 4))[1];
            if ($crc !== (crc32($type . $data) & 0xFFFFFFFF)) {
                return null; // CRC covers type+data (PNG spec §5.3) — mismatch = damaged
            }
            if ($chunks === [] && $type !== 'IHDR') {
                return null;
            }
            $chunks[] = ['type' => $type, 'data' => $data, 'start' => $offset, 'end' => $end];
            if ($type === 'IEND') {
                return $chunks; // stop at IEND; any trailing bytes are the caller's to preserve
            }
            $offset = $end;
        }
    }

    /**
     * Writer-side gate: same walk, loud verdicts instead of null.
     *
     * @return list<array{type: string, data: string, start: int, end: int}>
     */
    private static function requireParseable(string $pngBytes, string $caller): array
    {
        if (!self::isPng($pngBytes)) {
            throw new InvalidArgumentException("Png::{$caller}(): input is not a PNG (bad 8-byte signature).");
        }
        if (strlen($pngBytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException("Png::{$caller}(): input exceeds the 64 MiB ceiling.");
        }
        $chunks = self::scan($pngBytes);
        if ($chunks === null) {
            throw new InvalidArgumentException("Png::{$caller}(): PNG chunk framing damaged (truncated tail, CRC mismatch, or missing IHDR/IEND).");
        }

        return $chunks;
    }

    /**
     * Decode one tEXt/iTXt chunk payload.
     *
     * tEXt: `keyword \0 text` (latin-1).
     * iTXt: `keyword \0 compFlag compMethod lang \0 translatedKeyword \0 text`
     * (UTF-8; compFlag 1 → zlib deflate, inflated here).
     *
     * @return array{keyword: string, text: string}|null null = malformed
     */
    private static function decodeTextChunk(string $type, string $data): ?array
    {
        $nul = strpos($data, "\x00");
        if ($nul === false) {
            return null;
        }
        $keyword = substr($data, 0, $nul);
        if ($type === 'tEXt') {
            $text = substr($data, $nul + 1);

            return str_contains($text, "\x00") ? null : ['keyword' => $keyword, 'text' => $text];
        }
        if (strlen($data) < $nul + 4) {
            return null;
        }
        $compFlag = ord($data[$nul + 1]);
        $compMethod = ord($data[$nul + 2]);
        if ($compFlag > 1 || $compMethod !== 0) {
            return null;
        }
        $langEnd = strpos($data, "\x00", $nul + 3);
        if ($langEnd === false) {
            return null;
        }
        $transEnd = strpos($data, "\x00", $langEnd + 1);
        if ($transEnd === false) {
            return null;
        }
        $text = substr($data, $transEnd + 1);
        if ($compFlag === 1) {
            $inflated = @gzuncompress($text);
            if ($inflated === false) {
                return null;
            }
            $text = $inflated;
        }

        return str_contains($text, "\x00") ? null : ['keyword' => $keyword, 'text' => $text];
    }

    /** Frame a payload as length+type+data+crc(type.data) — the PNG spec §5.3 law. */
    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xFFFFFFFF);
    }

    /**
     * PNG spec §4.2.3.1/§4.2.3.3 keyword shape: 1-79 printable Latin-1 bytes,
     * no NUL, no leading or trailing space.
     */
    private static function isValidKeyword(string $keyword): bool
    {
        return $keyword !== ''
            && strlen($keyword) <= 79
            && preg_match('/^[\x20-\x7E\xA1-\xFF]+$/', $keyword) === 1
            && !str_starts_with($keyword, ' ')
            && !str_ends_with($keyword, ' ');
    }
}
