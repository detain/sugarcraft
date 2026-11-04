<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * C4 — a runtime dependency must be declared as one.
 *
 * sugar-vterm sat in `require-dev` + `suggest` while eleven `src/` files
 * (the whole render pipeline: TapeToGif, Renderer, FrameStream, FrameDedup,
 * Raster/**, InspectCommand, Msg/BuiltinSerializer) load it unconditionally.
 * A clean `composer install` of the published package therefore produced an
 * autoloader with no `SugarCraft\Vterm\` root, and the flagship
 * `php bin/vcr render-tape` died with "Class SugarCraft\Vterm\Terminal not
 * found" — the very path README sells as shipped.
 *
 * In this monorepo the defect is invisible: the dev vendor is linked with
 * every sibling, and the suite runs in require-dev mode by definition. The
 * guard is therefore static — it re-derives the namespace roots that `src/`
 * and `bin/` actually reference and demands each one be declared in the
 * runtime `require` block, so the same misplacement can never return.
 *
 * Optional dependencies stay legitimate the same way: `symfony/yaml` is
 * referenced from `src/` but is confined behind an explicit `class_exists`
 * door in YamlFormat, so it remains a `suggest` rather than a `require`.
 * The third leg pins that door, because the door is the only thing the
 * declaration is honest about.
 */
final class RuntimeDependencyDeclarationTest extends TestCase
{
    /**
     * Namespace root => package names that may declare it in `require`.
     * A root used by shipped code must be covered by one of its packages.
     *
     * @return array<string, list<string>>
     */
    private const ROOT_TO_PACKAGES = [
        'AnsiParser' => ['sugarcraft/sugar-ansi-parser'],
        'Core' => ['sugarcraft/sugar-core'],
        'Pty' => ['sugarcraft/sugar-pty'],
        'Vterm' => ['sugarcraft/sugar-vterm'],
    ];

    /**
     * Packages that are deliberately NOT runtime dependencies, and the file
     * that keeps them optional. Each must stay out of `require` while its
     * guard stands; drop the guard and the package has to be required.
     *
     * @var array<string, string>  package name => guarded source file
     */
    private const OPTIONAL_WITH_GUARD = [
        'symfony/yaml' => 'src/Format/YamlFormat.php',
    ];

    public function testEveryReferencedSugarCraftRootIsADeclaredRuntimeDependency(): void
    {
        $required = self::requiredPackages();
        $offenders = [];

        foreach (self::referencedRoots() as $root => $files) {
            $packages = self::ROOT_TO_PACKAGES[$root] ?? [];
            if ($packages === [] || !self::anySet($packages, $required)) {
                $offenders[$root] = $files;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "src/ and bin/ reference SugarCraft roots that no `require` entry provides — "
            . "a clean composer install cannot load them. Declare the package in require "
            . "(a `require` bump only: never a repositories[] entry in a lib manifest). Offending roots: "
            . self::describe($offenders),
        );
    }

    public function testRequireDevNeverHarboursARuntimeDependency(): void
    {
        $referenced = array_keys(self::referencedRoots());
        $offenders = [];

        foreach (self::sugarCraftPackagesIn('require-dev') as $package) {
            foreach (self::namespaceRootsOf($package) as $root) {
                if (!in_array($root, $referenced, true)) {
                    continue;
                }
                $offenders[] = "{$package} (provides SugarCraft\\{$root}) is in require-dev while src/ and bin/ load it";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'require-dev is not installed for consumers — ' . implode('; ', $offenders) . '.',
        );
    }

    public function testSuggestNeverAdvertisesAPackageThatIsRequired(): void
    {
        $required = self::requiredPackages();
        $offenders = [];

        foreach (array_keys(self::manifest()['suggest'] ?? []) as $package) {
            if (in_array((string) $package, $required, true)) {
                $offenders[] = (string) $package;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '`suggest` describes what is optional; listing an installed require understates it '
            . '(the stale sugar-vterm row read "Enables ScreenAssertion" while the whole render '
            . 'pipeline needed it). Offending rows: ' . implode(', ', $offenders) . '.',
        );
    }

    public function testAnOptionalPackageStaysOptionalOnlyBehindItsGuard(): void
    {
        $required = self::requiredPackages();

        foreach (self::OPTIONAL_WITH_GUARD as $package => $file) {
            $contents = self::read($file);

            $this->assertFalse(
                in_array($package, $required, true),
                "{$package} is guarded for absence in {$file} — requiring it anyway removes the fallback it documents.",
            );

            $door = 'class_exists';
            $this->assertStringContainsString(
                $door,
                $contents,
                "{$file} must keep its explicit absence check: without it {$package} is a hard runtime "
                . 'dependency and must move to require (which is exactly how the sugar-vterm defect shipped).',
            );
        }
    }

    public function testTheScanSeesEveryReferenceForm(): void
    {
        // The token walk below reads T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED.
        // A grouped import (`use SugarCraft\{A, B};`) lexes neither and would
        // make the guard silently partial, so the shape is refused outright.
        $grouped = [];
        foreach (self::shippedFiles() as $relative => $absolute) {
            if (str_contains((string) file_get_contents($absolute), 'SugarCraft\\{')) {
                $grouped[] = $relative;
            }
        }

        $this->assertSame([], $grouped, 'grouped SugarCraft imports are invisible to the dependency scan — spell them out.');
    }

    public function testTheKnownRuntimeRootsAreStillTheWholeStory(): void
    {
        // Fail-closed against a new sibling dependency arriving unrostered:
        // adding a package to require without teaching this test which root
        // it provides reddens, so the mapping cannot quietly drift stale.
        $roots = array_keys(self::referencedRoots());
        sort($roots);

        $expected = array_keys(self::ROOT_TO_PACKAGES);
        sort($expected);

        $this->assertSame(
            $expected,
            $roots,
            'referencedRoots() must equal the rostered roots — roster the new package in ROOT_TO_PACKAGES '
            . 'and in the require block of the same commit.',
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $path = \dirname(__DIR__, 2) . '/composer.json';
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            self::fail("{$path} must parse as a JSON object");
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private static function requiredPackages(): array
    {
        return array_values(array_filter(
            array_keys((array) (self::manifest()['require'] ?? [])),
            static fn (string $name): bool => !str_starts_with($name, 'php') && !str_starts_with($name, 'ext-'),
        ));
    }

    /**
     * @return list<string>
     */
    private static function sugarCraftPackagesIn(string $section): array
    {
        return array_values(array_filter(
            array_keys((array) (self::manifest()[$section] ?? [])),
            static fn (string $name): bool => str_starts_with($name, 'sugarcraft/'),
        ));
    }

    /**
     * The `SugarCraft\X\` roots an installed package publishes.
     *
     * @return list<string>
     */
    private static function namespaceRootsOf(string $package): array
    {
        $short = substr($package, strlen('sugarcraft/'));
        $path = \dirname(__DIR__, 2) . "/vendor/sugarcraft/{$short}/composer.json";
        if (!is_file($path)) {
            self::fail("{$package} is not installed — run composer install before the suite (missing {$path}).");
        }

        $autoload = (array) (json_decode((string) file_get_contents($path), true)['autoload']['psr-4'] ?? []);
        $roots = [];
        foreach (array_keys($autoload) as $namespace) {
            if (preg_match('#^SugarCraft\\\\([A-Za-z0-9_]+)\\\\?$#', (string) $namespace, $m) === 1) {
                $roots[] = $m[1];
            }
        }

        return $roots;
    }

    /**
     * Namespace roots referenced by shipped code => files that reference them.
     *
     * Comments and string literals are skipped: prose about a package is not
     * a load of that package (and the render pipeline's docblocks name
     * sugar-vterm classes the code already imports anyway).
     *
     * @return array<string, list<string>>
     */
    private static function referencedRoots(): array
    {
        $found = [];
        foreach (self::shippedFiles() as $relative => $absolute) {
            $tokens = \PhpToken::tokenize((string) file_get_contents($absolute));
            foreach ($tokens as $token) {
                if (!$token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                    continue;
                }
                if (preg_match('#^\\\\?SugarCraft\\\\([A-Za-z0-9_]+)#', $token->text, $m) !== 1) {
                    continue;
                }
                if ($m[1] === 'Vcr') {
                    continue; // the library's own namespace
                }
                $found[$m[1]][$relative] = true;
            }
        }

        $out = [];
        foreach ($found as $root => $files) {
            $list = array_keys($files);
            sort($list);
            $out[$root] = $list;
        }
        ksort($out);

        return $out;
    }

    /**
     * Every .php file under src/ plus the bin/ entrypoint.
     *
     * @return array<string, string>
     */
    private static function shippedFiles(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files['src/' . ltrim(substr($file->getPathname(), strlen($root . '/src')), '/')] = $file->getPathname();
            }
        }

        foreach ((array) (self::manifest()['bin'] ?? []) as $binary) {
            $path = $root . '/' . $binary;
            if (is_file($path)) {
                $files[(string) $binary] = $path;
            }
        }

        ksort($files);

        return $files;
    }

    private static function read(string $relative): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $relative);
    }

    /**
     * @param array<string, list<string>> $offenders
     */
    private static function describe(array $offenders): string
    {
        $parts = [];
        foreach ($offenders as $root => $files) {
            $parts[] = "SugarCraft\\{$root} (" . implode(', ', array_slice($files, 0, 3))
                . (count($files) > 3 ? ', +' . (count($files) - 3) : '') . ')';
        }

        return implode('; ', $parts);
    }

    /**
     * @param list<string> $needles
     * @param list<string> $haystack
     */
    private static function anySet(array $needles, array $haystack): bool
    {
        return array_intersect($needles, $haystack) !== [];
    }
}
