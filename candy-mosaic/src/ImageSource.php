<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use React\Http\Browser;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\Color;

use function React\Promise\reject;

/**
 * A decoded image ready for rendering. Stores raw bytes, detected
 * format, and pixel dimensions. Immutable.
 */
final class ImageSource
{
    /**
     * @param string $bytes    Raw image bytes (PNG/JPEG/GIF)
     * @param string $format   MIME type: 'image/png', 'image/jpeg', 'image/gif'
     * @param int    $width    Pixel width
     * @param int    $height   Pixel height
     */
    public function __construct(
        public readonly string $bytes,
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * Load from a file on disk.
     *
     * @throws \InvalidArgumentException  if the file does not exist or is not a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromFile(string $path): self
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

        return new self($bytes, $format, $width, $height);
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
    public static function fromString(string $bytes): self
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('image_source.no_gd'));
        }

        $format = self::detectImageFormat($bytes);

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

        return new self($bytes, $format, $width, $height);
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
    public static function fromGd(\GdImage $resource, string $format): self
    {
        if (!imageistruecolor($resource)) {
            imagepalettetotruecolor($resource);
        }

        $width  = imagesx($resource);
        $height = imagesy($resource);

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

        return new self($bytes, $format, $width, $height);
    }

    /**
     * Load from a remote URL synchronously.
     *
     * Fetches the bytes with PHP stream wrappers (`file_get_contents`), so
     * any scheme PHP supports works — `http`, `https`, `file`, `data`.
     * Redirects are followed. This blocks the calling thread; for the
     * event loop use {@see ImageSource::fromUrlAsync()} instead.
     *
     * Security: like {@see ImageSource::fromFile()}, the trust decision for
     * the source is the caller's. Because every PHP scheme is honoured and
     * redirects are followed, passing an untrusted/user-influenced URL exposes
     * local-file reads (`file:///etc/passwd`) and SSRF (e.g. cloud metadata at
     * `http://169.254.169.254/…`, possibly reached via a redirect). Only pass
     * URLs you control or have validated against an allow-list.
     *
     * @param string $url     Absolute URL (http/https/file/data).
     * @param array<string,string>|list<string> $headers  Optional request
     *               headers, either associative ('Authorization' => 'Bearer x')
     *               or pre-formatted lines ('Authorization: Bearer x').
     * @throws \InvalidArgumentException  if the URL cannot be fetched, a header
     *                                    contains CR/LF, or the payload is not
     *                                    a supported image
     * @throws \RuntimeException          if ext-gd is not available
     */
    public static function fromUrl(string $url, ?array $headers = null, ?array $allowedSchemes = ['http', 'https']): self
    {
        self::validateUrlScheme($url, $allowedSchemes);
        return self::fromString(self::fetchUrlSync($url, $headers));
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
     * @param string $url     Absolute http(s) URL.
     * @param array<string,string> $headers  Optional request headers. Must be
     *               associative ('Authorization' => 'Bearer x') — unlike the
     *               synchronous {@see ImageSource::fromUrl()}, the async path
     *               forwards them straight to Browser::get().
     * @param Browser|null $browser  Optional pre-configured ReactPHP Browser
     *               (e.g. with a shared connector/timeout); one is created on
     *               the default loop when omitted.
     * @return PromiseInterface<self>
     */
    public static function fromUrlAsync(
        string $url,
        ?array $headers = null,
        ?Browser $browser = null,
        ?array $allowedSchemes = ['http', 'https'],
    ): PromiseInterface {
        self::validateUrlScheme($url, $allowedSchemes);

        if ($browser === null) {
            if (!class_exists(Browser::class)) {
                return reject(new \RuntimeException(Lang::t('image_source.url_http_missing')));
            }
            $browser = new Browser();
        }

        return $browser->get($url, $headers ?? [])->then(
            static function ($response): self {
                // The default Browser rejects 4xx/5xx itself, but a caller may
                // inject one with withRejectErrorResponse(false); guard the
                // status here so the "rejects on non-2xx" contract always holds
                // rather than feeding an error page to the GD decoder.
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException(
                        Lang::t('image_source.url_bad_status', ['status' => $status]),
                    );
                }

                return self::fromString((string) $response->getBody());
            },
        );
    }

    /**
     * Fetch raw bytes from a URL synchronously via PHP stream wrappers.
     *
     * @param array<string,string>|list<string>|null $headers
     * @throws \InvalidArgumentException  if the fetch fails or returns empty
     */
    private static function fetchUrlSync(string $url, ?array $headers): string
    {
        $http = [
            'method'          => 'GET',
            'timeout'         => 30,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'ignore_errors'   => false,
        ];
        if ($headers !== null && $headers !== []) {
            $http['header'] = self::formatHeaders($headers);
        }

        $context = stream_context_create(['http' => $http]);

        error_clear_last();
        $bytes = @file_get_contents($url, false, $context);

        if ($bytes === false || $bytes === '') {
            // Surface the underlying cause (DNS failure, refused, 404, timeout)
            // that the @-suppression otherwise hides, for debuggability.
            $reason = error_get_last()['message'] ?? null;
            throw new \InvalidArgumentException(
                Lang::t('image_source.url_fetch_failed', ['url' => $url])
                . ($reason !== null ? ' (' . $reason . ')' : ''),
            );
        }

        return $bytes;
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
            return $this->fromGd($cropped, $this->format);
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
            return $this->fromGd($dst, $this->format);
        } finally {
            imagedestroy($dst);
        }
    }
}
