<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Media;

use SugarCraft\Mosaic\ImageSource;

/**
 * One image admitted into a generation request (crush-media W4.1).
 *
 * The ingress boundary between "a file/bytes the user chose" and "a wire
 * `init_images` entry": validation happens HERE, once, and everything past
 * this point is a trusted immutable value — base64 of the EXACT source
 * bytes (no re-encode in v1, plan §W4.1 microstep 3), plus the geometry
 * read by candy-mosaic's {@see ImageSource} (its decompression-bomb pixel
 * ceiling applies; the byte ceiling is enforced here because
 * `ImageSource::fromFile()` reads before it validates).
 *
 * URLs are refused by ruling (crush_media SSRF ledger, plan W4.1): the
 * ingress accepts LOCAL paths or in-memory bytes only. A `data:` URI is
 * refused by the same door — the wire field carries raw base64, never a
 * URI, and accepting the scheme shape would train users to expect it.
 *
 * Fail-fast: every refusal is an \InvalidArgumentException naming what was
 * refused; callers land it as a form status, never a partial slot.
 */
final readonly class ImageIngress
{
    /**
     * The one refusal sentence for every remote-URL shape. Literal English
     * per the STORE_UNAVAILABLE precedent: it names a ruling, not a locale
     * string, and it must read identically in every log.
     */
    public const URL_REFUSAL = 'image sources must be local files — URLs and data: URIs are never fetched (crush-media W4.1)';

    /** Extensions the picker offers; mirrors ImageSource's accepted mimes (§2.6 v1 ceiling — webp is NOT one of them). */
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif'];

    private function __construct(
        /** Display path — the real file for {@see self::fromPath()}, an origin label for bytes. */
        public readonly string $path,
        /** The exact source bytes, sent as-is (no v1 re-encode). */
        public readonly string $bytes,
        /** Raw base64 of {@see self::$bytes} — the wire `init_images` entry, never a data: URI. */
        public readonly string $base64,
        public readonly int $width,
        public readonly int $height,
        /** Canonical mime from ImageSource (`image/png`, `image/jpeg`, `image/gif`). */
        public readonly string $mime,
        public readonly string $sha1,
    ) {
    }

    /**
     * Admit a local file.
     *
     * @throws \InvalidArgumentException on a URL/data-URI shape, a missing or
     *         unreadable file, an over-cap byte size, or any ImageSource
     *         refusal (unsupported format, decompression-bomb pixels)
     * @throws \RuntimeException when ext-gd is unavailable or a header-valid
     *         file's pixel data fails to decode (ImageSource gd_load_failed)
     */
    public static function fromPath(string $path, int $maxBytes = ImageSource::MAX_BYTES): self
    {
        $candidate = trim($path);
        if (self::looksRemote($candidate)) {
            throw new \InvalidArgumentException(self::URL_REFUSAL);
        }

        // The byte ceiling must land BEFORE the read: fromFile() slurps the
        // whole file with no cap of its own (MAX_BYTES only guards its
        // animated sibling).
        $size = @filesize($candidate);
        if ($size !== false && $size > $maxBytes) {
            throw new \InvalidArgumentException(
                'image file is ' . $size . ' bytes, over the ' . $maxBytes . '-byte ceiling',
            );
        }

        $source = ImageSource::fromFile($candidate);

        return self::hydrate($candidate, $source);
    }

    /**
     * Admit bytes already in memory (the clipboard paste of W4.2). The
     * origin string is a label only — it never touches the filesystem.
     *
     * @throws \InvalidArgumentException on an over-cap size or an unsupported/corrupt image
     * @throws \RuntimeException when ext-gd is unavailable
     */
    public static function fromBytes(string $bytes, string $origin, int $maxBytes = ImageSource::MAX_BYTES): self
    {
        if (\strlen($bytes) > $maxBytes) {
            throw new \InvalidArgumentException(
                'image bytes from ' . $origin . ' are ' . \strlen($bytes) . ' bytes, over the ' . $maxBytes . '-byte ceiling',
            );
        }

        $source = ImageSource::fromString($bytes);

        return self::hydrate($origin, $source);
    }

    /** Base name of the origin path — what the form's source rows show. */
    public function name(): string
    {
        return basename($this->path);
    }

    /** "WxH" — the geometry cell of a source row. */
    public function dimensions(): string
    {
        return $this->width . '×' . $this->height;
    }

    private static function hydrate(string $pathOrOrigin, ImageSource $source): self
    {
        return new self(
            $pathOrOrigin,
            $source->bytes,
            base64_encode($source->bytes),
            $source->width,
            $source->height,
            $source->format,
            sha1($source->bytes),
        );
    }

    /**
     * Any `[scheme]://` shape plus the `data:` prefix. Deliberately wider
     * than the schemes we would fear: the door is "not a local path", not
     * "not http".
     */
    private static function looksRemote(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        return str_starts_with(strtolower($candidate), 'data:')
            || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) === 1;
    }
}
