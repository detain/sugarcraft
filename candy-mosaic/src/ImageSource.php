<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use React\Http\Browser;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\Color;
use SugarCraft\Flip\Decoder as FlipDecoder;
use SugarCraft\Flip\Frame as FlipFrame;

use function React\Promise\reject;

/**
 * A decoded image ready for rendering. Stores raw bytes, detected
 * format, and pixel dimensions. Immutable.
 */
final class ImageSource
{
    /**
     * Default decompression-bomb ceiling: reject images whose declared
     * pixel count (width × height) exceeds this before GD allocates the
     * full buffer. 50 megapixels comfortably covers legitimate posters/
     * screenshots while blocking a 64000×64000 "bomb" that would demand
     * ~12 GiB of truecolor pixel memory. Override per-call via the
     * factory `$maxPixels` argument or {@see self::withMaxPixels()}.
     */
    public const MAX_PIXELS = 50_000_000;

    /**
     * Hard ceiling on the byte size of a file handed to
     * {@see self::fromAnimatedFile()} before any of it is read into memory. The
     * pixel budget cannot bound a decompression/parse bomb on its own because the
     * bytes must be resident before their geometry is inspectable; this caps the
     * read itself. 64 MB is far above any sane terminal-scale animation.
     */
    public const MAX_BYTES = 67_108_864;

    /**
     * candy-flip's internal cell-grid ceiling (its `Decoder::MAX_CELLS`). Exposed
     * here so {@see self::fromAnimatedFile()} can refuse an over-large GIF with a
     * clear mosaic-owned message rather than surfacing flip's exception mid-decode.
     */
    private const FLIP_MAX_CELLS = 100_000;

    /**
     * @param string $bytes    Raw image bytes (PNG/JPEG/GIF)
     * @param string $format   MIME type: 'image/png', 'image/jpeg', 'image/gif'
     * @param int    $width    Pixel width
     * @param int    $height   Pixel height
     * @param int    $maxPixels  Decompression-bomb ceiling carried forward
     *                           into crop()/resize() re-decodes; <= 0 disables.
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
        public readonly int $maxPixels = self::MAX_PIXELS,
    ) {}

    /**
     * Reject an image whose declared pixel count exceeds the ceiling.
     *
     * Called with dimensions read from the header (getimagesize) BEFORE any
     * `imagecreatefrom*`, so a decompression bomb is refused before GD ever
     * allocates the pixel buffer. A ceiling of <= 0 disables the check.
     *
     * @throws \InvalidArgumentException  if width × height exceeds $maxPixels
     */
    private static function guardPixelCount(int $width, int $height, int $maxPixels): void
    {
        if ($maxPixels > 0 && $width > 0 && $height > 0 && $width * $height > $maxPixels) {
            throw new \InvalidArgumentException(
                Lang::t('image_source.too_large', [
                    'width'  => $width,
                    'height' => $height,
                    'max'    => $maxPixels,
                ]),
            );
        }
    }

    /**
     * Return a copy with a different decompression-bomb pixel ceiling.
     *
     * Re-validates the already-decoded dimensions against the new ceiling so
     * lowering it below the current image size fails fast, and threads the
     * ceiling into subsequent crop()/resize() re-decodes.
     *
     * @throws \InvalidArgumentException  if the current dimensions exceed $maxPixels
     */
    public function withMaxPixels(int $maxPixels): self
    {
        self::guardPixelCount($this->width, $this->height, $maxPixels);

        return new self($this->bytes, $this->format, $this->width, $this->height, $maxPixels);
    }

    /**
     * Load from a file on disk.
     *
     * @throws \InvalidArgumentException  if the file does not exist or is not a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromFile(string $path, int $maxPixels = self::MAX_PIXELS): self
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(Lang::t('image_source.file_not_found', ['path' => $path]));
        }

        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('image_source.no_gd'));
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \InvalidArgumentException(Lang::t('image_source.cannot_read', ['path' => $path]));
        }

        $info = @getimagesize($path);
        if ($info === false) {
            throw new \InvalidArgumentException(Lang::t('image_source.unsupported_format', ['path' => $path]));
        }

        // Decompression-bomb guard: getimagesize() reads only the header, so
        // reject an oversized image here before imagecreatefrom* allocates
        // the full truecolor pixel buffer.
        self::guardPixelCount((int) $info[0], (int) $info[1], $maxPixels);

        $format = match ($info['mime']) {
            'image/png'  => 'image/png',
            'image/jpeg' => 'image/jpeg',
            'image/gif'  => 'image/gif',
            default      => throw new \InvalidArgumentException(
                Lang::t('image_source.unsupported_mime', ['mime' => $info['mime']])
            ),
        };

        // Read dimensions from GD so palette PNGs are already converted.
        $img = match ($format) {
            'image/png'  => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/gif'  => imagecreatefromgif($path),
        };

        if ($img === false) {
            throw new \RuntimeException(Lang::t('image_source.gd_load_failed', ['path' => $path]));
        }

        // Palette PNG → truecolor so PixelGrid always sees 24-bit pixels.
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $width  = imagesx($img);
        $height = imagesy($img);
        imagedestroy($img);

        return new self($bytes, $format, $width, $height, $maxPixels);
    }

    /**
     * Load from raw bytes in memory.
     *
     * @throws \InvalidArgumentException  if the bytes are not a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    /**
     * Load from raw bytes in memory.
     *
     * Detects image format from magic bytes and uses GD to validate and
     * read dimensions — avoids the temp-file overhead of the previous
     * implementation which wrote bytes to disk and re-read via fromFile().
     *
     * @throws \InvalidArgumentException  if the bytes are not a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromString(string $bytes, int $maxPixels = self::MAX_PIXELS): self
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('image_source.no_gd'));
        }

        $format = self::detectImageFormat($bytes);

        // Decompression-bomb guard: read dimensions from the header (no full
        // decode) and reject oversized images BEFORE imagecreatefromstring
        // allocates the pixel buffer. For inputs getimagesizefromstring cannot
        // size, fall through and let GD validate/reject via the decode below.
        $info = @getimagesizefromstring($bytes);
        if ($info !== false) {
            self::guardPixelCount((int) $info[0], (int) $info[1], $maxPixels);
        }

        // Validate the bytes are a supported image and read dimensions.
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new \InvalidArgumentException(
                Lang::t('image_source.gd_load_failed', ['path' => '[memory-bytes]']),
            );
        }

        // Palette PNGs need truecolor conversion so PixelGrid always sees
        // 24-bit pixels (matching fromFile() behavior).
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }

        $width  = imagesx($img);
        $height = imagesy($img);
        imagedestroy($img);

        return new self($bytes, $format, $width, $height, $maxPixels);
    }

    /**
     * Detect image MIME type from magic bytes.
     *
     * Mirrors the format detection in fromFile() so fromString() produces
     * identical results for the same image bytes without hitting disk.
     */
    private static function detectImageFormat(string $bytes): string
    {
        if (strlen($bytes) >= 8 && $bytes[0] === "\x89" && $bytes[1] === 'P'
            && $bytes[2] === 'N' && $bytes[3] === 'G'
        ) {
            return 'image/png';
        }
        if (strlen($bytes) >= 3 && $bytes[0] === "\xFF" && $bytes[1] === "\xD8"
            && $bytes[2] === "\xFF"
        ) {
            return 'image/jpeg';
        }
        if (strlen($bytes) >= 6
            && (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'))
        ) {
            return 'image/gif';
        }
        // WebP: "RIFF" + 4 bytes + "WEBP"
        if (strlen($bytes) >= 12 && $bytes[0] === 'R' && $bytes[1] === 'I'
            && $bytes[2] === 'F' && $bytes[3] === 'F' && $bytes[8] === 'W'
            && $bytes[9] === 'E' && $bytes[10] === 'B' && $bytes[11] === 'P'
        ) {
            return 'image/webp';
        }

        // Fall back to GD's auto-detection; it will throw if unsupported.
        // This handles formats GD supports but we haven't explicitly listed.
        return 'image/png'; // dummy — fromString will validate via imagecreatefromstring
    }

    /**
     * Load from an existing GD image resource.
     *
     * @param \GdImage $resource  Truecolor GD image (palette images are
     *                            automatically converted)
     * @param string   $format    MIME type hint: 'image/png', 'image/jpeg',
     *                            or 'image/gif'. Required because GD cannot
     *                            re-detect format from a resource.
     */
    public static function fromGd(\GdImage $resource, string $format, int $maxPixels = self::MAX_PIXELS): self
    {
        if (!imageistruecolor($resource)) {
            imagepalettetotruecolor($resource);
        }

        $width  = imagesx($resource);
        $height = imagesy($resource);
        self::guardPixelCount($width, $height, $maxPixels);

        // Some GD builds write to output buffer and return bool|int rather
        // than returning the encoded bytes as a string.  Use a temp file to
        // guarantee we get the binary payload regardless of the GD variant.
        $tmp = fopen('php://temp', 'w+b');

        try {
            $ok = match ($format) {
                'image/png'  => imagepng($resource, $tmp, 9),
                'image/jpeg' => imagejpeg($resource, $tmp, 100),
                'image/gif'  => imagegif($resource, $tmp),
                default      => throw new \InvalidArgumentException(
                    Lang::t('image_source.unsupported_mime', ['mime' => $format])
                ),
            };

            rewind($tmp);
            $bytes = stream_get_contents($tmp);
        } finally {
            fclose($tmp);
        }

        return new self($bytes, $format, $width, $height, $maxPixels);
    }

    /**
     * Ingest a raw RGB24/RGBA scanline buffer with no container format.
     *
     * Decoders that already hold decoded pixels (a video frame, a generator
     * canvas) can hand them straight over instead of paying a container
     * round-trip (`imagepng()` → `fromString()`) per frame. The buffer is
     * rastered into a truecolor GD image in a single O(n) pass and encoded
     * once through {@see self::fromGd()}, so the resulting ImageSource is a
     * PNG container identical to what that round-trip would have produced —
     * minus the intermediate encode.
     *
     * `$hasAlpha` selects RGBA (4 bytes/pixel, 255 = opaque); without it the
     * buffer is RGB24 (3 bytes/pixel). Length must match exactly — a short
     * or long buffer is a caller bug and fails loud.
     *
     * @param string $bytes     Raw scanline buffer, row-major, w×h×(3|4) bytes.
     * @param int    $width     Pixel width  (> 0).
     * @param int    $height    Pixel height (> 0).
     * @param bool   $hasAlpha  True for RGBA32, false for RGB24.
     * @param int    $maxPixels Pixel ceiling (same semantics as the other
     *                          ingress factories); a trusted decoder with a
     *                          known-small frame grid may lower it.
     * @throws \RuntimeException          if ext-gd is not available
     * @throws \InvalidArgumentException  if dimensions are non-positive, the
     *                                    buffer length doesn't match w×h×(3|4),
     *                                    or the pixel count exceeds $maxPixels
     */
    public static function fromRgb(string $bytes, int $width, int $height, bool $hasAlpha = false, int $maxPixels = self::MAX_PIXELS): self
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('image_source.no_gd'));
        }

        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException(Lang::t('image_source.rgb_bad_dimensions', [
                'width'  => $width,
                'height' => $height,
            ]));
        }

        $channels = $hasAlpha ? 4 : 3;
        $expected = $width * $height * $channels;
        if (strlen($bytes) !== $expected) {
            throw new \InvalidArgumentException(Lang::t('image_source.rgb_size_mismatch', [
                'expected' => $expected,
                'actual'   => strlen($bytes),
            ]));
        }

        // Decompression-bomb parity with the container paths: a huge declared
        // canvas is refused before GD allocates the pixel buffer.
        self::guardPixelCount($width, $height, $maxPixels);

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw new \RuntimeException(Lang::t('image_source.gd_alloc_failed'));
        }

        if ($hasAlpha) {
            // Write pixels verbatim (no blending against the black default) and
            // carry the alpha byte through to the PNG encode in fromGd().
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $r = ord($bytes[$offset]);
                $g = ord($bytes[$offset + 1]);
                $b = ord($bytes[$offset + 2]);
                // Truecolor images accept the packed 0xRRGGBB int directly —
                // no palette allocation, no per-pixel array.
                $color = ($r << 16) | ($g << 8) | $b;
                if ($hasAlpha) {
                    // Byte alpha is 0..255 opaque-at-255; GD wants 0..127
                    // opaque-at-0. Integer scale keeps the pass allocation-free.
                    $a = intdiv((255 - ord($bytes[$offset + 3])) * 127, 255);
                    $color = imagecolorallocatealpha($img, $r, $g, $b, $a);
                }
                imagesetpixel($img, $x, $y, $color);
                $offset += $channels;
            }
        }

        try {
            return self::fromGd($img, 'image/png', $maxPixels);
        } finally {
            imagedestroy($img);
        }
    }

    /**
     * Decode an animated file into an {@see Animation} of frames + per-frame delays.
     *
     * GD exposes no frame API — `imagecreatefromgif()` returns only the first
     * frame and an APNG decodes as its base image — so animation is decoded by
     * hand through the two container walks this library owns a dependency on:
     *
     *   • GIF  → candy-flip's pure-PHP LZW decoder ({@see \SugarCraft\Flip\Decoder}),
     *     requested at the GIF's own pixel dimensions so a frame is 1:1 with the
     *     source (no resample loss) and flip's disposal compositing already baked
     *     in. flip speaks centiseconds; this converts to the milliseconds that
     *     {@see Animation} stores.
     *   • APNG → {@see ApngDecoder}, a pure-PHP `fcTL`/`fdAT` walk with the
     *     dispose-op state machine, returning fully composited RGBA frames.
     *
      * A plain (non-animated) PNG is accepted as the degenerate one-frame
      * animation with a zero delay. A single-frame GIF returns one frame carrying
      * its own GCE delay. JPEG/WebP and every other container fail fast — this
      * entry point decodes only GIF and APNG animation (still PNG is the one
      * non-animated convenience).
      *
      * Budgets, all enforced before the expensive work:
      *   • {@see self::MAX_BYTES} bounds the file read itself (a pixel budget
      *     cannot, because bytes are resident before geometry is inspectable).
      *   • MAX_PIXELS is enforced per frame AND in aggregate (frames × total
      *     pixels) so an animation that is small per-frame but huge across frames
      *     — the classic decompression bomb — is refused before it exhausts memory.
      *   • The APNG path reconciles its `acTL` attestation against the frames the
      *     stream actually carries, and the GIF path reconciles a structural
      *     descriptor count against the decoder's output, so neither can be fooled
      *     by an under-declared frame count nor a mis-synchronised sibling walk.
     *
     * Detection is about SOURCE decoding only: protocol/terminal selection
     * (kitty → iterm2 → sixel → chafa → halfblock) is unchanged and every frame
     * renders through the normal {@see Renderer\Renderer} pipeline.
     *
     * @param string $path      Filesystem path to a GIF or APNG (or still PNG).
     * @param int    $maxPixels  Per-frame AND aggregate pixel ceiling; ≤ 0 disables.
     * @throws \InvalidArgumentException  if the file is missing/unreadable, the
     *                                    format is not GIF/APNG/still-PNG, the
     *                                    stream is corrupt, or a declared pixel
     *                                    count exceeds the ceiling
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromAnimatedFile(string $path, int $maxPixels = self::MAX_PIXELS): Animation
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(Lang::t('image_source.file_not_found', ['path' => $path]));
        }
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('image_source.no_gd'));
        }
        // Bound the read BEFORE slurping: a pixel budget cannot stop a bomb whose
        // bytes must be resident before its geometry is even inspectable.
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException(Lang::t('image_source.file_too_large', [
                'size' => $size,
                'max'  => self::MAX_BYTES,
            ]));
        }
        $bytes = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($bytes === false) {
            throw new \InvalidArgumentException(Lang::t('image_source.cannot_read', ['path' => $path]));
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException(Lang::t('image_source.file_too_large', [
                'size' => $size !== false ? $size : strlen($bytes),
                'max'  => self::MAX_BYTES,
            ]));
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return self::animatedFramesFromGif($path, $bytes, $maxPixels);
        }

        if (ApngDecoder::isAnimatedPng($bytes)) {
            return self::animatedFramesFromApng($bytes, $maxPixels);
        }

        // A still PNG is a valid one-frame animation — feed the normal ingress.
        if (strlen($bytes) >= 8 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return new Animation([self::fromFile($path, $maxPixels)], [0]);
        }

        throw new \InvalidArgumentException(Lang::t('animation.unsupported_format', [
            'format' => self::detectImageFormat($bytes),
        ]));
    }

    /**
     * Decode a GIF via candy-flip into a validated {@see Animation}.
     *
     * @throws \InvalidArgumentException  on an unreadable header or an over-ceiling
     *                                    per-frame/aggregate pixel count
     */
    private static function animatedFramesFromGif(string $path, string $bytes, int $maxPixels): Animation
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new \InvalidArgumentException(Lang::t('image_source.unsupported_format', ['path' => $path]));
        }
        $w = (int) $info[0];
        $h = (int) $info[1];

        // Per-frame ceiling on the logical-screen size, before flip allocates.
        self::guardPixelCount($w, $h, $maxPixels);

        // candy-flip renders one cell per pixel and caps its cell grid at 100k, so
        // a GIF above ~316×316 is unloadable by it. Fail loud with a mosaic-owned,
        // translated reason instead of leaking flip's untranslated RuntimeException.
        if ($w * $h > self::FLIP_MAX_CELLS) {
            throw new \InvalidArgumentException(Lang::t('animation.gif_too_large_for_flip', [
                'width' => $w,
                'height' => $h,
                'max'   => self::FLIP_MAX_CELLS,
            ]));
        }

        // Cheap, structural frame count from the container bytes — no LZW decode.
        // This drives the AGGREGATE budget BEFORE flip materialises every frame
        // (a 20 KiB file claiming hundreds of frames must fail fast, not after
        // paying for the whole decode), and doubles as the reconciliation oracle
        // that catches the sibling decoder's frame-desync on real multi-frame GIFs.
        $declared = self::countGifFrames($bytes);
        if ($maxPixels > 0 && $w > 0 && $h > 0 && $w * $h * $declared > $maxPixels) {
            throw new \InvalidArgumentException(Lang::t('animation.too_many_pixels', [
                'frames' => $declared,
                'width'  => $w,
                'height' => $h,
                'total'  => $w * $h * $declared,
                'max'    => $maxPixels,
            ]));
        }

        $flipFrames = self::decodeGifFramesSafely($path, $w, $h);

        // Fail loud rather than ship a silently short/shuffled animation: if flip
        // disagrees with the container's own descriptor count (its header walk is
        // known to mis-skip image data on many real GIFs), refuse.
        if ($declared !== count($flipFrames)) {
            throw new \InvalidArgumentException(Lang::t('animation.gif_frame_count_mismatch', [
                'expected' => $declared,
                'got'      => count($flipFrames),
            ]));
        }

        $frames  = [];
        $delays  = [];
        foreach ($flipFrames as $frame) {
            $frames[] = self::gifFrameToImageSource($frame, $w, $h, $maxPixels);
            // flip carries the GCE delay in centiseconds; Animation speaks ms.
            $delays[] = $frame->delay * 10;
        }

        return new Animation($frames, $delays);
    }

    /**
     * Count a GIF's image-descriptor blocks by walking the container structure
     * (extension/data sub-blocks) WITHOUT decoding LZW. A correct walk must skip
     * the mandatory LZW minimum-code-size byte before following the image-data
     * sub-blocks — the exact byte that the sibling header walk gets wrong.
     *
     * @throws \InvalidArgumentException on a structurally corrupt container
     */
    private static function countGifFrames(string $bytes): int
    {
        $len = strlen($bytes);
        $i = 13; // past header (6) + logical screen descriptor (7)
        $gct = ord($bytes[10]);
        if (($gct & 0x80) !== 0) {
            $i += 3 * (2 << ($gct & 0x07)); // global colour table
        }

        $frames = 0;
        while ($i < $len) {
            $block = ord($bytes[$i]);
            if ($block === 0x3B) {
                break; // trailer
            }
            if ($block === 0x21) {
                // Extension: label byte, then length-prefixed sub-blocks to a 0 terminator.
                $i += 2;
                $i = self::skipGifSubBlocks($bytes, $i, $len);
                continue;
            }
            if ($block === 0x2C) {
                // Image descriptor: 4 shorts + packed (9 bytes) at i+1.
                if ($i + 10 > $len) {
                    throw new \InvalidArgumentException(Lang::t('image_source.unsupported_format', ['path' => 'GIF']));
                }
                $packed = ord($bytes[$i + 9]);
                $j = $i + 10;
                if (($packed & 0x80) !== 0) {
                    $j += 3 * (2 << ($packed & 0x07)); // local colour table
                }
                ++$j; // LZW minimum code size — the byte flip fails to skip
                $j = self::skipGifSubBlocks($bytes, $j, $len);
                $i = $j;
                if (++$frames > Animation::MAX_FRAMES) {
                    throw new \InvalidArgumentException(Lang::t('animation.too_many_frames', [
                        'max'   => Animation::MAX_FRAMES,
                        'count' => $frames,
                    ]));
                }
                continue;
            }
            // Unknown block type — cannot trust the rest of the stream.
            throw new \InvalidArgumentException(Lang::t('image_source.unsupported_format', ['path' => 'GIF']));
        }

        return $frames;
    }

    /**
     * Advance past a run of length-prefixed GIF sub-blocks terminated by a 0 byte.
     */
    private static function skipGifSubBlocks(string $bytes, int $i, int $len): int
    {
        while ($i < $len) {
            $sub = ord($bytes[$i]);
            ++$i;
            if ($sub === 0) {
                return $i;
            }
            $i += $sub;
        }

        return $i;
    }

    /**
     * Drive candy-flip's decoder under a temporary error handler so a corrupt GIF
     * (which trips GD/`imagecreatefromstring` warnings inside the sibling) and any
     * foreign exception surface as one clean, translated fail-fast rather than
     * warning text sprayed across a terminal or an untranslated RuntimeException.
     *
     * @return list<FlipFrame>
     */
    private static function decodeGifFramesSafely(string $path, int $w, int $h): array
    {
        $handler = static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        };
        set_error_handler($handler);
        try {
            $frames = FlipDecoder::decode($path, $w, $h);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(Lang::t('animation.gif_decode_failed', [
                'reason' => $e->getMessage(),
            ]));
        } finally {
            restore_error_handler();
        }

        return $frames;
    }

    /**
     * Convert one candy-flip frame (a cell grid of RGB triples or null) to an
     * RGBA PNG-backed {@see ImageSource}. Null cells become fully transparent.
     */
    private static function gifFrameToImageSource(FlipFrame $frame, int $w, int $h, int $maxPixels): self
    {
        $cells = $frame->cells;
        $bytes = '';
        for ($cy = 0; $cy < $h; $cy++) {
            $row = $cells[$cy] ?? [];
            for ($cx = 0; $cx < $w; $cx++) {
                $cell = $row[$cx] ?? null;
                if ($cell === null) {
                    $bytes .= "\x00\x00\x00\x00";
                } else {
                    $bytes .= chr($cell[0]) . chr($cell[1]) . chr($cell[2]) . "\xff";
                }
            }
        }

        return self::fromRgb($bytes, $w, $h, true, $maxPixels);
    }

    /**
     * Decode an APNG via {@see ApngDecoder} into a validated {@see Animation}.
     */
    private static function animatedFramesFromApng(string $bytes, int $maxPixels): Animation
    {
        // ApngDecoder::decode enforces per-frame AND aggregate MAX_PIXELS from
        // the cheap headers before inflating any pixel data.
        $decoded = ApngDecoder::decode($bytes, $maxPixels);

        $frames = [];
        $delays = [];
        foreach ($decoded as $frame) {
            $frames[] = self::fromRgb($frame['rgba'], $frame['width'], $frame['height'], true, $maxPixels);
            $delays[] = $frame['delayMs'];
        }

        return new Animation($frames, $delays);
    }

    /**
     * Load from a remote URL synchronously.
     *
     * Fetches the bytes with PHP stream wrappers (`file_get_contents`), so
     * any scheme PHP supports works — `http`, `https`, `file`, `data`.
     * Redirects are followed. This blocks the calling thread; for the
     * event loop use {@see ImageSource::fromUrlAsync()} instead.
     *
     * Security (scheme): the initial scheme is validated against
     * $allowedSchemes, and every redirect hop is re-validated against the SAME
     * list (redirects are followed manually with `max_redirects: 0`), so a 3xx
     * to `file://`, `gopher://`, or another disallowed scheme cannot smuggle
     * past the allow-list.
     *
     * Security (host / SSRF): for http(s) each hop's host — the initial URL
     * AND every redirect target — is resolved to its A/AAAA addresses and
     * rejected BEFORE the fetch if ANY resolved IP is private, loopback,
     * link-local, or reserved (RFC-1918, `127.0.0.0/8`, `169.254.0.0/16` incl.
     * the `169.254.169.254` cloud-metadata IP, `::1`, `fc00::/7`, `fe80::/10`,
     * CGNAT `100.64.0.0/10`, …). Resolving ALL addresses defeats DNS-rebinding
     * where a public name points at a metadata/internal IP. Deployments that
     * legitimately need a specific internal host pass it in $allowedHosts to
     * bypass the deny-list for that host only.
     *
     * @param string $url     Absolute URL (http/https/file/data).
     * @param array<string,string>|list<string> $headers  Optional request
     *               headers, either associative ('Authorization' => 'Bearer x')
     *               or pre-formatted lines ('Authorization: Bearer x').
     * @param array<string>|null $allowedSchemes  Enforced on the initial URL
     *               AND on every redirect target; null opts out of validation.
     * @param int $maxPixels  Decompression-bomb ceiling; see {@see self::MAX_PIXELS}.
     * @param array<string>|null $allowedHosts  Hosts permitted to bypass the
     *               private/reserved-IP deny-list (opt-in seam for trusted
     *               internal endpoints), matched case-insensitively on every
     *               hop. null (default) allow-lists nothing, so every private
     *               host is blocked.
     * @throws \InvalidArgumentException  if the URL cannot be fetched, a header
     *                                    contains CR/LF, a redirect targets a
     *                                    disallowed scheme, the host resolves to
     *                                    a private/reserved IP, or the payload is
     *                                    not a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromUrl(
        string $url,
        ?array $headers = null,
        ?array $allowedSchemes = ['http', 'https'],
        int $maxPixels = self::MAX_PIXELS,
        ?array $allowedHosts = null,
    ): self {
        self::validateUrlScheme($url, $allowedSchemes);
        return self::fromString(
            self::fetchUrlSync($url, $headers, $allowedSchemes, $allowedHosts),
            $maxPixels,
        );
    }

    /**
     * @param array<string>|null $allowedSchemes  null skips validation (opt-in to insecure)
     * @throws \InvalidArgumentException  if scheme is not in the allowed list
     */
    private static function validateUrlScheme(string $url, ?array $allowedSchemes): void
    {
        if ($allowedSchemes === null) {
            return;
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === false || $scheme === null) {
            throw new \InvalidArgumentException(
                Lang::t('image_source.url_invalid_scheme', ['scheme' => 'unknown', 'allowed' => implode(', ', $allowedSchemes)])
            );
        }
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new \InvalidArgumentException(
                Lang::t('image_source.url_invalid_scheme', ['scheme' => $scheme, 'allowed' => implode(', ', $allowedSchemes)])
            );
        }
    }

    /**
     * Cloud/link-local metadata endpoints that must never be reachable via a
     * fetched URL — an SSRF here leaks IAM credentials. Rejected explicitly in
     * addition to the range checks, since some PHP builds classify these
     * inconsistently under FILTER_FLAG_NO_RES_RANGE.
     */
    private const METADATA_IPS = ['169.254.169.254', 'fd00:ec2::254'];

    /**
     * Test-only override for host → IP resolution.
     *
     * Lets the SSRF / DNS-rebinding guards be exercised deterministically
     * without real DNS. Production code leaves this null and resolves via
     * dns_get_record()/gethostbyname(). Receives the bracket-stripped,
     * lower-cased host and returns a list of IP strings.
     *
     * @var (callable(string): list<string>)|null
     * @internal
     */
    private static $hostResolver = null;

    /**
     * @internal Test seam ONLY — override host resolution so SSRF guards can be
     *           verified without touching the network. Pass null to restore
     *           real DNS resolution. Never call from production code.
     */
    public static function overrideHostResolver(?callable $resolver): void
    {
        self::$hostResolver = $resolver;
    }

    /**
     * Reject a URL whose host resolves to a private, loopback, link-local, or
     * reserved IP (SSRF / cloud-metadata defense).
     *
     * Only http(s) URLs carry a remote host worth guarding; other schemes
     * (file://, data://) are governed by the scheme allow-list. The host is
     * resolved to ALL of its A/AAAA addresses and rejected if ANY is
     * non-public — this defeats DNS-rebinding where a public name resolves to
     * `169.254.169.254` or an RFC-1918 address. A host named in $allowedHosts
     * bypasses the check: the opt-in seam for deployments that must reach a
     * specific internal host.
     *
     * @param array<string>|null $allowedHosts  Hosts permitted to bypass the
     *              private-IP deny-list; matched case-insensitively against the
     *              URL host. null allow-lists nothing.
     * @throws \InvalidArgumentException  if the host resolves to a blocked
     *              address or cannot be resolved at all
     */
    private static function guardHostNotPrivate(string $url, ?array $allowedHosts): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return;
        }
        $scheme = strtolower($scheme);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            // http(s) with no host is malformed; let the fetch surface it.
            return;
        }

        $host = self::normalizeHost($host);

        if ($allowedHosts !== null) {
            foreach ($allowedHosts as $allowed) {
                if (strcasecmp($host, self::normalizeHost($allowed)) === 0) {
                    return;
                }
            }
        }

        $ips = self::resolveHost($host);
        if ($ips === []) {
            // Fail closed: a host we cannot resolve might rebind to an internal
            // address between this check and the fetch.
            throw new \InvalidArgumentException(
                Lang::t('image_source.url_host_unresolved', ['host' => $host]),
            );
        }

        foreach ($ips as $ip) {
            if (self::isPrivateOrReservedIp($ip)) {
                throw new \InvalidArgumentException(
                    Lang::t('image_source.url_host_blocked', ['host' => $host, 'ip' => $ip]),
                );
            }
        }
    }

    /** Strip IPv6 brackets and lower-case a host for comparison/resolution. */
    private static function normalizeHost(string $host): string
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return strtolower($host);
    }

    /**
     * Resolve a host to its list of IP addresses (both IPv4 and IPv6).
     *
     * A literal IP resolves to itself. A hostname is looked up via
     * dns_get_record() for A and AAAA records, falling back to gethostbyname()
     * for IPv4. The {@see self::$hostResolver} test seam, when set,
     * short-circuits the DNS calls.
     *
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$hostResolver !== null) {
            return array_values(array_unique((self::$hostResolver)($host)));
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (isset($rec['ip'])) {
                    $ips[] = $rec['ip'];
                } elseif (isset($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $v4 = gethostbyname($host); // returns the input unchanged on failure
            if ($v4 !== $host && filter_var($v4, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $v4;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Whether an IP is private, loopback, link-local, or otherwise reserved
     * (i.e. NOT a routable public address) and therefore an SSRF risk.
     *
     * Combines explicit range checks (so the security-critical ranges are
     * covered regardless of PHP-version quirks in FILTER_FLAG_NO_RES_RANGE,
     * and CGNAT `100.64.0.0/10` which that flag misses) with a final
     * filter_var() catch-all. IPv4-mapped IPv6 (`::ffff:a.b.c.d`) is unwrapped
     * and re-checked so it cannot smuggle a private IPv4 past the guard.
     */
    private static function isPrivateOrReservedIp(string $ip): bool
    {
        if (in_array($ip, self::METADATA_IPS, true)) {
            return true;
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true; // unparseable → treat as unsafe
        }

        if (strlen($packed) === 4) {
            $b = array_values(unpack('C4', $packed));

            // 0.0.0.0/8, 10/8, loopback 127/8, link-local 169.254/16,
            // 172.16/12, 192.168/16, CGNAT 100.64/10, reserved 240/4+broadcast.
            $blockedV4 = ($b[0] === 0)
                || ($b[0] === 10)
                || ($b[0] === 127)
                || ($b[0] === 169 && $b[1] === 254)
                || ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31)
                || ($b[0] === 192 && $b[1] === 168)
                || ($b[0] === 100 && $b[1] >= 64 && $b[1] <= 127)
                || ($b[0] >= 240);
            if ($blockedV4) {
                return true;
            }
        }

        if (strlen($packed) === 16) {
            // :: unspecified and ::1 loopback.
            if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\1") {
                return true;
            }

            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);
            // fc00::/7 unique-local (top 7 bits 1111110) and fe80::/10
            // link-local (top 10 bits 1111111010).
            if (($b0 & 0xFE) === 0xFC || ($b0 === 0xFE && ($b1 & 0xC0) === 0x80)) {
                return true;
            }

            // IPv4-mapped IPv6 (::ffff:a.b.c.d): unwrap and re-check.
            if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xFF\xFF")) {
                $v4 = inet_ntop(substr($packed, 12));
                if (is_string($v4) && self::isPrivateOrReservedIp($v4)) {
                    return true;
                }
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * Load from a remote URL asynchronously on the ReactPHP event loop.
     *
     * Resolves with a decoded {@see ImageSource}; rejects on transport error,
     * a non-2xx response, or a payload that is not a supported image. The GD
     * decode runs in the success callback, so the returned image is ready to
     * render immediately.
     *
     * Requires `react/http` (a suggested dependency). When no $browser is
     * supplied and the package is not installed, the returned promise rejects
     * with a clear instruction rather than fataling.
     *
     * Security (host / SSRF): mirrors the synchronous {@see self::fromUrl()}.
     * `React\Http\Browser` follows 3xx redirects INTERNALLY by default, which
     * would chase a `Location` into `169.254.169.254` (cloud metadata) or an
     * RFC-1918 host WITHOUT re-checking it — the async SSRF hole left open by
     * the sync-only guard. The Browser's built-in follower is therefore
     * disabled here and redirects are followed by hand, running
     * {@see self::guardHostNotPrivate()} on the initial URL AND every redirect
     * hop (each host resolved to ALL of its addresses, defeating DNS-rebinding)
     * before the request goes out. A host in $allowedHosts bypasses the
     * deny-list, matching the sync path's opt-in seam.
     *
     * @param string $url     Absolute http(s) URL.
     * @param array<string,string> $headers  Optional request headers. Must be
     *               associative ('Authorization' => 'Bearer x') — unlike the
     *               synchronous {@see ImageSource::fromUrl()}, the async path
     *               forwards them straight to Browser::get().
     * @param Browser|null $browser  Optional pre-configured ReactPHP Browser
     *               (e.g. with a shared connector/timeout); one is created on
     *               the default loop when omitted. Its redirect handling is
     *               overridden regardless, so per-hop host-guarding holds.
     * @param array<string>|null $allowedHosts  Hosts permitted to bypass the
     *               private/reserved-IP deny-list, matched case-insensitively
     *               on the initial URL AND every redirect hop. null (default)
     *               allow-lists nothing, so every private host is blocked.
     * @return PromiseInterface<self>
     */
    public static function fromUrlAsync(
        string $url,
        ?array $headers = null,
        ?Browser $browser = null,
        ?array $allowedSchemes = ['http', 'https'],
        int $maxPixels = self::MAX_PIXELS,
        ?array $allowedHosts = null,
    ): PromiseInterface {
        // Validate the initial scheme synchronously (unchanged contract); the
        // recursive fetch re-validates the scheme AND host-guards every hop.
        self::validateUrlScheme($url, $allowedSchemes);

        if ($browser === null) {
            if (!class_exists(Browser::class)) {
                return reject(new \RuntimeException(Lang::t('image_source.url_http_missing')));
            }
            $browser = new Browser();
        }

        // Disable the Browser's built-in redirect follower: it would chase a
        // 3xx Location internally, bypassing guardHostNotPrivate() on the
        // redirect target (the async SSRF hole). We follow by hand below,
        // host-guarding each hop before it is fetched.
        $browser = $browser->withFollowRedirects(false);

        return self::fetchUrlAsyncGuarded(
            $browser,
            $url,
            $headers,
            $allowedSchemes,
            $allowedHosts,
            $maxPixels,
            0,
        );
    }

    /**
     * Recursively fetch $url on the event loop, host-guarding every hop and
     * following redirects by hand — the async mirror of {@see self::fetchUrlSync()}.
     *
     * The caller has disabled the Browser's internal redirect follower, so a
     * 3xx resolves here with its `Location` intact: each hop re-validates the
     * scheme, runs the private/reserved-IP deny-list on the resolved host, and
     * only then follows — up to {@see self::MAX_REDIRECTS} hops. A pre-flight
     * guard failure rejects the returned promise rather than throwing.
     *
     * @param array<string,string>|null $headers
     * @param array<string>|null $allowedSchemes  Re-validated on every hop.
     * @param array<string>|null $allowedHosts    Bypass the deny-list for these
     *                                    hosts; re-checked on every hop.
     * @return PromiseInterface<self>
     */
    private static function fetchUrlAsyncGuarded(
        Browser $browser,
        string $url,
        ?array $headers,
        ?array $allowedSchemes,
        ?array $allowedHosts,
        int $maxPixels,
        int $hop,
    ): PromiseInterface {
        // SSRF / cloud-metadata guard BEFORE the request goes out — on the
        // initial URL AND every redirect target — so an http://169.254.169.254/
        // hop is refused, not requested. Fail closed as a rejected promise.
        try {
            self::validateUrlScheme($url, $allowedSchemes);
            self::guardHostNotPrivate($url, $allowedHosts);
        } catch (\Throwable $e) {
            return reject($e);
        }

        return $browser->get($url, $headers ?? [])->then(
            static function ($response) use ($browser, $url, $headers, $allowedSchemes, $allowedHosts, $maxPixels, $hop): mixed {
                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    if ($hop >= self::MAX_REDIRECTS) {
                        throw new \InvalidArgumentException(
                            Lang::t('image_source.too_many_redirects', ['url' => $url]),
                        );
                    }
                    $location = $response->getHeaderLine('Location');
                    if ($location === '') {
                        throw new \InvalidArgumentException(
                            Lang::t('image_source.redirect_no_location', ['url' => $url]),
                        );
                    }
                    $next = self::resolveRedirectUrl($url, $location);

                    // Recurse — the next hop re-guards scheme + host before it
                    // is fetched. Returning a promise chains it into this one.
                    return self::fetchUrlAsyncGuarded(
                        $browser,
                        $next,
                        $headers,
                        $allowedSchemes,
                        $allowedHosts,
                        $maxPixels,
                        $hop + 1,
                    );
                }

                // The default Browser rejects 4xx/5xx itself, but a caller may
                // inject one with withRejectErrorResponse(false); guard the
                // status here so the "rejects on non-2xx" contract always holds
                // rather than feeding an error page to the GD decoder.
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(
                        Lang::t('image_source.url_bad_status', ['status' => $status]),
                    );
                }

                return self::fromString((string) $response->getBody(), $maxPixels);
            },
        );
    }

    /** Maximum redirect hops followed by {@see self::fetchUrlSync()}. */
    private const MAX_REDIRECTS = 5;

    /**
     * Fetch raw bytes from a URL synchronously via PHP stream wrappers.
     *
     * SSRF hardening: redirects are followed MANUALLY (`max_redirects: 0`) so
     * each hop's scheme is re-validated against $allowedSchemes. The native
     * `follow_location` would honour a 3xx into `file://`/`gopher://` without
     * re-checking, letting an attacker-controlled redirect escape the caller's
     * allow-list; following by hand closes that hole.
     *
     * @param array<string,string>|list<string>|null $headers
     * @param array<string>|null $allowedSchemes  Re-validated on every hop.
     * @param array<string>|null $allowedHosts    Bypass the private-IP deny-list
     *                                    for these hosts; re-checked on every hop.
     * @throws \InvalidArgumentException  if the fetch fails, a redirect targets
     *                                    a disallowed scheme, the host resolves
     *                                    to a private/reserved IP, the redirect
     *                                    chain is too long, or the response is
     *                                    empty
     */
    private static function fetchUrlSync(
        string $url,
        ?array $headers,
        ?array $allowedSchemes = null,
        ?array $allowedHosts = null,
    ): string {
        $headerLines = ($headers !== null && $headers !== []) ? self::formatHeaders($headers) : null;
        $current     = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            // Re-validate the scheme on every hop — the initial URL and each
            // redirect target — so a 3xx cannot smuggle a disallowed scheme in.
            self::validateUrlScheme($current, $allowedSchemes);

            // SSRF / cloud-metadata guard: resolve the host and reject any hop
            // whose host maps to a private, loopback, link-local, or reserved
            // address — on the initial URL AND every redirect target — unless
            // it is explicitly allow-listed. Runs BEFORE file_get_contents(),
            // so a 3xx to http://169.254.169.254/ is refused, not requested.
            self::guardHostNotPrivate($current, $allowedHosts);

            $http = [
                'method'          => 'GET',
                'timeout'         => 30,
                'follow_location' => 0,
                'max_redirects'   => 0,
                // Read 3xx/4xx bodies+headers instead of returning false, so we
                // can inspect the status line and follow redirects ourselves.
                'ignore_errors'   => true,
            ];
            if ($headerLines !== null) {
                $http['header'] = $headerLines;
            }

            $context = stream_context_create(['http' => $http]);

            error_clear_last();
            // Reset before the call: the HTTP wrapper overwrites this magic
            // local for http(s), but a non-HTTP hop (file://, data://) leaves
            // it untouched — clearing it stops a prior hop's status/headers
            // from leaking into this one.
            $http_response_header = null;
            $bytes = @file_get_contents($current, false, $context);
            // $http_response_header is populated in local scope by the HTTP
            // wrapper; stays null for non-HTTP schemes (file://, data://).
            $responseHeaders = $http_response_header;

            if ($bytes === false) {
                $reason = error_get_last()['message'] ?? null;
                throw new \InvalidArgumentException(
                    Lang::t('image_source.url_fetch_failed', ['url' => $current])
                    . ($reason !== null ? ' (' . $reason . ')' : ''),
                );
            }

            $status = $responseHeaders !== null ? self::parseHttpStatus($responseHeaders) : null;

            // Non-HTTP scheme (or a wrapper that reports no status): the bytes
            // ARE the payload.
            if ($status === null) {
                if ($bytes === '') {
                    throw new \InvalidArgumentException(
                        Lang::t('image_source.url_fetch_failed', ['url' => $current]),
                    );
                }

                return $bytes;
            }

            if ($status >= 300 && $status < 400) {
                $location = self::headerValue((array) $responseHeaders, 'Location');
                if ($location === null || $location === '') {
                    throw new \InvalidArgumentException(
                        Lang::t('image_source.redirect_no_location', ['url' => $current]),
                    );
                }
                $current = self::resolveRedirectUrl($current, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new \InvalidArgumentException(
                    Lang::t('image_source.url_bad_status', ['status' => $status]),
                );
            }

            if ($bytes === '') {
                throw new \InvalidArgumentException(
                    Lang::t('image_source.url_fetch_failed', ['url' => $current]),
                );
            }

            return $bytes;
        }

        throw new \InvalidArgumentException(
            Lang::t('image_source.too_many_redirects', ['url' => $url]),
        );
    }

    /**
     * Extract the numeric HTTP status from a raw response-header list.
     *
     * @param list<string> $headers  The $http_response_header value.
     * @return int|null  The last status line's code, or null if none present.
     */
    private static function parseHttpStatus(array $headers): ?int
    {
        $status = null;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }

    /**
     * Case-insensitively read the last value of a header from a raw list.
     *
     * @param list<string> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name) . ':';
        $value  = null;
        foreach ($headers as $line) {
            if (str_starts_with(strtolower($line), $needle)) {
                $value = trim(substr($line, strlen($needle)));
            }
        }

        return $value;
    }

    /**
     * Resolve a (possibly relative) redirect Location against the base URL.
     *
     * Handles absolute URLs, scheme-relative (`//host/…`), absolute paths
     * (`/…`), and document-relative paths so the resolved target can be
     * scheme-re-validated before the next hop.
     */
    private static function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);

        // Absolute URL carrying its own scheme.
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $origin = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        // Scheme-relative: //host/path
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        // Absolute path.
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        // Document-relative path.
        $path = $parts['path'] ?? '/';
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '/' : substr($path, 0, $slash + 1);

        return $origin . $dir . $location;
    }

    /**
     * Normalise headers into the `Name: value` line list a stream context wants.
     *
     * Accepts an associative map ('Authorization' => 'Bearer x') or an already
     * formatted list ('Authorization: Bearer x'); both round-trip correctly.
     *
     * @param array<string,string>|list<string> $headers
     * @return list<string>
     * @throws \InvalidArgumentException  if a header contains CR or LF (request
     *                                    splitting / header injection)
     */
    private static function formatHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $line = is_int($name) ? (string) $value : $name . ': ' . $value;
            if (preg_match('/[\r\n]/', $line) === 1) {
                throw new \InvalidArgumentException(Lang::t('image_source.header_crlf'));
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Aspect ratio as a float (width / height).
     */
    public function aspectRatio(): float
    {
        return $this->height === 0 ? 1.0 : $this->width / $this->height;
    }

    /**
     * Return a new ImageSource cropped to the given pixel region.
     * The crop region must be fully within the source image bounds.
     *
     * @param int $x  Left offset in pixels
     * @param int $y  Top offset in pixels
     * @param int $w  Crop width in pixels
     * @param int $h  Crop height in pixels
     * @throws \InvalidArgumentException  if crop region is outside image bounds
     */
    public function crop(int $x, int $y, int $w, int $h): self
    {
        if ($x < 0 || $y < 0 || $w <= 0 || $h <= 0
            || $x + $w > $this->width || $y + $h > $this->height
        ) {
            throw new \InvalidArgumentException(
                "Crop region [$x,$y {$w}×{$h}] is outside image bounds "
                . "{$this->width}×{$this->height}"
            );
        }

        $src = imagecreatefromstring($this->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('image_source.gd_load_failed_from_string'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        $cropped = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        imagedestroy($src);
        if ($cropped === false) {
            throw new \RuntimeException(Lang::t('image_source.crop_failed'));
        }

        try {
            return $this->fromGd($cropped, $this->format, $this->maxPixels);
        } finally {
            imagedestroy($cropped);
        }
    }

    /**
     * Return a new ImageSource resized to the given pixel dimensions
     * using bicubic (high-quality) resampling.
     *
     * @param int $w  Target width in pixels (must be > 0)
     * @param int $h  Target height in pixels (must be > 0)
     * @throws \InvalidArgumentException  if dimensions are not positive
     */
    public function resize(int $w, int $h): self
    {
        if ($w <= 0 || $h <= 0) {
            throw new \InvalidArgumentException("Resize dimensions must be positive, got {$w}×{$h}");
        }

        $src = imagecreatefromstring($this->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('image_source.gd_load_failed_from_string'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        $dst = imagecreatetruecolor($w, $h);
        if ($dst === false) {
            imagedestroy($src);
            throw new \RuntimeException(Lang::t('image_source.gd_create_failed'));
        }

        // Preserve alpha channel for PNG.
        imagesavealpha($dst, true);
        imagealphablending($dst, false);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $this->width, $this->height);
        imagedestroy($src);

        try {
            return $this->fromGd($dst, $this->format, $this->maxPixels);
        } finally {
            imagedestroy($dst);
        }
    }
}
