<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Probe\Capability as PaletteCapability;

/**
 * EVERY `Capability::` CASE THIS LIBRARY NAMES MUST EXIST ON THE ENUM.
 *
 * `Mosaic::autoFromPalette()` shipped `PaletteCapability::Iterm2Image` against
 * an enum that spells the case `ITerm2`. PHP resolves an enum case at USE time,
 * not at compile time, so the file parsed, the class loaded, and the whole
 * no-TTY fallback threw `Error: Undefined constant` for every terminal that did
 * not match the Kitty branch first — the one branch tested above it.
 *
 * IT SURVIVED BECAUSE NOTHING EVER REACHED THE LINE. It was found from a
 * SIBLING repository: sugar-crush's round-49 suite closed descriptor 0, which
 * made `Detect::probe()` throw for the first time and drove execution past the
 * Kitty arm. A defect reachable only under a condition no test creates is
 * invisible to a suite that never creates it, so this guard does not go through
 * `autoFromPalette()` at all — it reads the source and checks the names.
 *
 * WHY A SOURCE SCAN AND NOT MORE BRANCH COVERAGE. Covering today's five
 * branches would not have caught this and would not catch the next one: the
 * failure mode is a NAME, and the alphabet that matters is "every case named
 * anywhere in `src/`", which only a scan can express.
 */
final class PaletteCapabilityReferenceTest extends TestCase
{
    public function testEveryPaletteCapabilityCaseNamedInSourceExists(): void
    {
        $named = self::casesNamedInSource();
        self::assertNotSame([], $named, 'the scanner found no references at all, so it proves nothing');

        $real = array_map(static fn (PaletteCapability $c): string => $c->name, PaletteCapability::cases());

        self::assertSame([], array_values(array_diff($named, $real)), sprintf(
            "src/ names a Capability case the enum does not declare.\nNamed: %s\nEnum:  %s",
            implode(', ', $named),
            implode(', ', $real),
        ));
    }

    /**
     * KNOWN-POSITIVE CONTROL (rule 15): the scanner has to be able to SEE a bad
     * name, or the assertion above passes on an empty result forever.
     */
    public function testTheScannerSeesAnUndeclaredCaseName(): void
    {
        $source = "<?php\n\$a = PaletteCapability::ITerm2;\n\$b = PaletteCapability::NotARealCase;\n";
        self::assertSame(['ITerm2', 'NotARealCase'], self::casesNamedIn($source));
    }

    /** @return list<string> */
    private static function casesNamedInSource(): array
    {
        $names = [];
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../src'));
        foreach ($dir as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $names = [...$names, ...self::casesNamedIn((string) file_get_contents($file->getPathname()))];
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private static function casesNamedIn(string $source): array
    {
        preg_match_all('/PaletteCapability::([A-Za-z_][A-Za-z0-9_]*)/', $source, $m);

        return array_values(array_unique(array_filter(
            $m[1],
            static fn (string $n): bool => $n !== 'cases' && $n !== 'from' && $n !== 'tryFrom',
        )));
    }
}
