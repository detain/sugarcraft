<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Spring\SpringCollection;

/**
 * Pins the 2.0.0 removal of the pre-rename and pre-2.0 aliases.
 *
 * Every alias listed in {@see self::removedAliases()} was a thin forwarder to
 * a canonical method, marked REMOVED-IN: 2.0.0 while it lived. The removal
 * is complete when: the legacy names are gone from their classes, the
 * canonical names still answer, the README "Removed aliases (2.0.0)" table
 * agrees with the roster below, and nothing in src/ carries a deprecation
 * marker or a get-prefixed public accessor.
 */
final class AccessorDeprecationTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     *         [short class, removed method, canonical replacement]
     */
    public static function removedAliases(): array
    {
        return [
            'SpringCollection::add' => ['SpringCollection', 'add', 'withSpring'],
            'SpringCollection::remove' => ['SpringCollection', 'remove', 'without'],
            'SpringCollection::setTarget' => ['SpringCollection', 'setTarget', 'withTarget'],
            'SpringCollection::get' => ['SpringCollection', 'get', 'position'],
            'SpringCollection::getTarget' => ['SpringCollection', 'getTarget', 'target'],
            'SpringConfig::springAt60Fps' => ['SpringConfig', 'springAt60Fps', 'spring'],
            'Easing\CubicBezier::evaluate' => ['Easing\CubicBezier', 'evaluate', 'ease'],
            'Gravity::standard' => ['Gravity', 'standard', 'Projectile::gravity'],
            'Gravity::terminal' => ['Gravity', 'terminal', 'Projectile::terminalGravity'],
            'Gravity::standardYDown' => ['Gravity', 'standardYDown', 'Projectile::gravityYDown'],
            'Gravity::terminalYDown' => ['Gravity', 'terminalYDown', 'Projectile::terminalGravityYDown'],
        ];
    }

    /**
     * The bare accessors that replaced the get-prefixed forms answer the same
     * state: target() after withTarget() reflects the retarget and leaves the
     * original collection untouched; position() reads the seeded position.
     */
    public function testTheBareAccessorsAnswerTheStateTheLegacyNamesUsedTo(): void
    {
        $collection = (new SpringCollection())
            ->withSpring('a', new \SugarCraft\Spring\Spring(1.0 / 60.0, 6.0, 1.0), 7.0, 0.0, 10.0);

        $this->assertSame(7.0, $collection->position('a'));
        $this->assertSame(10.0, $collection->target('a'));

        $retargeted = $collection->withTarget('a', 50.0);
        $this->assertSame(50.0, $retargeted->target('a'));
        $this->assertSame(10.0, $collection->target('a'), 'withTarget() must not mutate the original');
    }

    #[DataProvider('removedAliases')]
    public function testRemovedAliasIsGoneFromItsClass(string $class, string $legacy, string $canonical): void
    {
        $fqn = 'SugarCraft\\Spring\\' . $class;
        $this->assertFalse(method_exists($fqn, $legacy), $fqn . '::' . $legacy . '() must be removed in 2.0.0');
    }

    #[DataProvider('removedAliases')]
    public function testCanonicalReplacementExists(string $class, string $legacy, string $canonical): void
    {
        [$targetClass, $targetMethod] = str_contains($canonical, '::')
            ? explode('::', $canonical, 2)
            : [$class, $canonical];
        $fqn = 'SugarCraft\\Spring\\' . $targetClass;
        $this->assertTrue(method_exists($fqn, $targetMethod), $fqn . '::' . $targetMethod . '() must exist');
    }

    public function testTheGravityFacadeClassIsGone(): void
    {
        $this->assertFalse(class_exists('SugarCraft\\Spring\\Gravity'), 'the Gravity façade was removed in 2.0.0');
    }

    /**
     * No public get-prefixed accessor survives anywhere in src/: the house
     * convention is bare accessors, and the sweep ends with zero exceptions.
     */
    public function testNoGetPrefixedAccessorRemainsInSrc(): void
    {
        $hits = [];
        foreach (self::srcFiles() as $path => $source) {
            if (preg_match_all('/\bfunction\s+(get(?:[A-Z]\w*)?)\s*\(/', $source, $m)) {
                foreach ($m[1] as $name) {
                    $hits[] = basename($path) . '::' . $name;
                }
            }
        }
        $this->assertSame([], $hits, 'get-prefixed accessors escaped the 2.0.0 removal');
    }

    /**
     * Removal is complete: no deprecation marker or REMOVED-IN stamp is left
     * in src/, because every deprecated surface was deleted rather than kept.
     */
    public function testNoDeprecationMarkerRemainsInSrc(): void
    {
        $hits = [];
        foreach (self::srcFiles() as $path => $source) {
            if (preg_match_all('/@deprecated|REMOVED-IN/', $source, $m)) {
                $hits[] = basename($path) . ' x' . count($m[0]);
            }
        }
        $this->assertSame([], $hits, 'src/ still carries deprecation markers');
    }

    /**
     * Docs leg: the README "Removed aliases (2.0.0)" table must list exactly
     * the roster above, with the same removed name and the same replacement.
     */
    public function testTheReadmeRemovedAliasTableAgreesWithTheRoster(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $sections = preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY);
        $section = '';
        foreach ($sections as $candidate) {
            if (str_starts_with($candidate, 'Removed aliases')) {
                $section = $candidate;
                break;
            }
        }
        $this->assertNotSame('', $section, 'README must carry a "Removed aliases" section');

        preg_match_all(
            '/^\| `(?P<class>[A-Za-z\\\\]+)` \| `(?P<legacy>[A-Za-z0-9]+)\(\)` \| `(?P<use>[A-Za-z:]+)\(\)` \|$/m',
            $section,
            $rows,
            PREG_SET_ORDER,
        );

        $fromReadme = [];
        foreach ($rows as $row) {
            $fromReadme[] = $row['class'] . '|' . $row['legacy'] . '|' . $row['use'];
        }
        $fromRoster = [];
        foreach (self::removedAliases() as [$class, $legacy, $canonical]) {
            $fromRoster[] = $class . '|' . $legacy . '|' . $canonical;
        }
        sort($fromReadme);
        sort($fromRoster);
        $this->assertSame($fromRoster, $fromReadme, 'README removed-alias table drifted from the roster');
    }

    /**
     * @return array<string, string> path => source for every file under src/
     */
    private static function srcFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        return $files;
    }
}
