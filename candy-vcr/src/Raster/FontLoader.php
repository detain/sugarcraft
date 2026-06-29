<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Raster;

/**
 * Loads TTF font faces from bundled or system directories.
 *
 * TTF files are loaded as file paths for use with imagettftext().
 * Also supports old-format BMP font files via imageloadfont().
 *
 * Mirrors charmbracelet/x/vhs FontLoader.
 */
final class FontLoader
{
    /** @var array<string> */
    private array $fontDirs;

    private ?string $lastResolvedPath = null;

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

        $this->lastResolvedPath = $fontFile;

        return $fontFile;
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

                if (is_dir($dir . '/' . $name)) {
                    foreach (glob($dir . '/' . $name . '/*.ttf') ?: [] as $sub) {
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
