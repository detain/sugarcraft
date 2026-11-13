<?php

// codacy ignore tainted-filename
declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

/**
 * Loads TTF font faces from bundled or system directories.
 *
 * TTF files are loaded as file paths for use with imagettftext().
 * Also supports old-format BMP font files via imageloadfont().
 *
 * No upstream counterpart: charmbracelet/vhs screenshots a live ttyd with
 * Chromium + ffmpeg and ships no font-loading class of this shape — this
 * .tape→GIF raster pipeline is original to SugarCraft.
 */
final class FontLoader
{
    /** @var array<string> */
    private array $fontDirs;

    private ?string $lastResolvedPath = null;

    /**
     * phar:// → real-path extraction cache, keyed on the archive path.
     *
     * @var array<string, string>
     */
    private static array $materialized = [];

    /**
     * @param list<string> $fontDirs
     */
    public function __construct(array $fontDirs = [])
    {
        $bundled = __DIR__ . '/../../fonts/';
        $systemDirs = self::systemDirs();

        $allDirs = array_merge(
            [$bundled],
            $fontDirs,
            $systemDirs,
        );

        $this->fontDirs = array_values(array_unique($allDirs, SORT_STRING));
    }

    /**
     * Load a TTF font file path for use with imagettftext().
     *
     * @param string $family e.g. "JetBrainsMono" or "JetBrainsMono-Bold"
     * @param float $size pt size
     * @param string $style "regular"|"bold"|"italic"|"bolditalic"
     * @return string absolute path to the TTF file
     * @throws \RuntimeException if font not found
     */
    public function load(string $family, float $size, string $style = 'regular'): string
    {
        $fontFile = $this->resolve($family, $style);

        if ($fontFile === null) {
            throw new \RuntimeException("Font not found: {$family} ({$style})");
        }

        if (!is_readable($fontFile)) {
            throw new \RuntimeException("Font file not readable: {$fontFile}");
        }

        $this->lastResolvedPath = self::materializeForGd($fontFile);

        return $this->lastResolvedPath;
    }

    /**
     * Turn a phar font path into a real filesystem path.
     *
     * FreeType (imagettftext) reads only plain paths: a `phar://…/Fonts.ttf`
     * name passes is_file()/is_readable() yet comes back "Could not open font"
     * at render time, so a phar build would ship GIFs of empty background
     * tiles (finding candy-vcr-023, reproduced on-host: real-FS bytes return
     * the bbox array, the same bytes at phar:// return false). Extract the
     * bytes once per process to a stable temp file and hand every backend
     * the real path. Real paths pass through untouched.
     */
    public static function materializeForGd(string $path): string
    {
        if (!self::isWithinPhar($path)) {
            return $path;
        }

        $cached = self::$materialized[$path] ?? null;
        if ($cached !== null && is_file($cached)) {
            return $cached;
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Unable to read bundled font from archive: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $extension = $extension === 'otf' ? 'otf' : 'ttf';
        $dir = sys_get_temp_dir() . '/candy-vcr-fonts-' . getmypid();
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create font extraction directory: {$dir}");
        }
        // Atomic land: write a per-attempt temp then rename, so two processes
        // extracting the same archive path never expose a half-written TTF.
        $final = $dir . '/' . sha1($path) . '.' . $extension;
        $temp = $final . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temp, $bytes) === false) {
            @unlink($temp);
            throw new \RuntimeException("Unable to write extracted font: {$final}");
        }
        chmod($temp, 0o600);
        if (!@rename($temp, $final)) {
            @unlink($temp);
            if (!is_file($final)) {
                throw new \RuntimeException("Unable to place extracted font: {$final}");
            }
        }

        self::$materialized[$path] = $final;

        return $final;
    }

    /**
     * Is this path served by the phar stream wrapper?
     *
     * Two spellings reach GD the same way: the explicit `phar://…` form and
     * the implicit `/abs/app.phar/inner/file` form that a running archive's
     * own __DIR__ expands to (the form this defect actually shipped in).
     */
    private static function isWithinPhar(string $path): bool
    {
        if (str_starts_with($path, 'phar://')) {
            return true;
        }

        $probe = '';
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '') {
                continue;
            }
            $probe .= '/' . $segment;
            if (str_ends_with($segment, '.phar') && (is_file($probe) || is_file(ltrim($probe, '/')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a font to its absolute path without throwing.
     *
     * @param string $family e.g. "JetBrainsMono"
     * @param string $style "regular"|"bold"|"italic"|"bolditalic"
     * @return string|null absolute path or null if not found
     */
    public function resolve(string $family, string $style = 'regular'): ?string
    {
        $candidates = $this->buildCandidates($family, $style);

        foreach ($this->fontDirs as $dir) {
            foreach ($candidates as $name) {
                foreach (['.ttf', '.TTF', '.otf', '.OTF'] as $ext) {
                    $path = $dir . '/' . $name . $ext;
                    if (is_file($path)) {
                        $this->lastResolvedPath = $path;
                        return $path;
                    }
                }

                $familyDir = $dir . '/' . $name;
                if (is_dir($familyDir)) {
                    // scandir, not glob: glob() reads no stream wrapper, and under a
                    // phar build (tools/build-phar.php) a family subdirectory can live
                    // INSIDE the phar:// archive, where a glob listing comes back empty
                    // and the bundled fonts would silently vanish from the shipped
                    // artifact. scandir + the .ttf filter below reproduces the glob set
                    // exactly on a real filesystem (globs never return `.`/`..`; both
                    // sort ascending), and it is the house law the same artifact obeys
                    // at candy-code's ToolCatalog/BuiltInCommands sites.
                    foreach (scandir($familyDir) ?: [] as $entry) {
                        if (!str_ends_with($entry, '.ttf')) {
                            continue;
                        }
                        $sub = $familyDir . '/' . $entry;
                        if (is_file($sub)) {
                            $this->lastResolvedPath = $sub;
                            return $sub;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Returns the path to the last successfully resolved font.
     */
    public function lastResolvedPath(): ?string
    {
        return $this->lastResolvedPath;
    }

    /**
     * @return array<string>
     */
    private function buildCandidates(string $family, string $style): array
    {
        // Sanitize family name to prevent path traversal attacks
        $family = basename($family);
        if ($family === '' || strpbrk($family, '/\\') !== false) {
            return [];
        }

        $base = rtrim(str_replace(['-Regular', '-Bold', '-Italic', '-BoldItalic'], '', $family), '-');

        return match ($style) {
            'bold' => [
                $family,
                $base . '-Bold',
                $base . 'Bold',
                $base . 'Bd',
            ],
            'italic' => [
                $family,
                $base . '-Italic',
                $base . 'Italic',
            ],
            'bolditalic' => [
                $family,
                $base . '-BoldItalic',
                $base . 'BoldItalic',
                $base . 'Bi',
            ],
            default => [
                $family,
                $base . '-Regular',
                $base . 'Regular',
                $base,
            ],
        };
    }

    /**
     * @return array<string>
     */
    public static function systemDirs(): array
    {
        $envHome = $_SERVER['HOME'] ?? null;
        $home = is_string($envHome) && $envHome !== '' ? $envHome : '/root';
        return [
            '/usr/share/fonts/truetype/',
            '/usr/share/fonts/opentype/',
            '/usr/share/fonts/X11/Type1/',
            '/usr/share/fonts/X11/TTF/',
            '/usr/local/share/fonts/',
            $home . '/.fonts/',
            $home . '/.local/share/fonts/',
        ];
    }
}
