<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Style\Bar\HelpBar;
use SugarCraft\Style\Bar\StatusBar;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * Accessor-era census guard (bare-accessor era, 2026-10-24).
 *
 * sugar-style carries 34 public get* accessors. Their dispositions:
 *
 *  - 2 are renamed bare in this era (ERA_PAIRS below): the bare slot was
 *    free, so the accessor follows the fleet's no-get law with a one-line
 *    {@see Style::getBorderSides()} style @deprecated forwarder carrying
 *    REMOVED-IN: 2.0.0;
 *  - 1 predates the era (LEGACY_ALIASES): Border::getTitles already
 *    delegates to titles() and is @deprecated without a REMOVED-IN marker;
 *  - 31 are documented LEGAL EXCEPTIONS (COLLIDER_EXCEPTIONS): the bare
 *    twin is a live fluent setter — PHP admits one method per name — and
 *    under the owner ruling of 2026-10-24 the get* spelling is their
 *    PERMANENT accessor name, not a deprecation candidate. Each collider
 *    carries the canonical exception sentence in its own docblock plus a
 *    roster row below; Style.php's class-level paragraph frames the same
 *    family, and its colliders are walked per-method exactly like the
 *    Bar ones (an absent or reworded sentence reddens the guard).
 *
 * The discovery leg walks src/ and fails closed on any get* without a
 * disposition (new accessor) or any roster row no longer on disk (stale).
 * A sentinel fixture proves the walk is not blind.
 */
final class BareAccessorEraTest extends TestCase
{
    private const REMOVED_IN_MARKER = 'REMOVED-IN: 2.0.0';

    /** legacy accessor => bare canonical name, renamed by this era. */
    private const ERA_PAIRS = [
        'Style::getDefaultProfile'      => 'defaultProfile',
        'Style::getBorderSides'         => 'borderSides',
    ];

    /** Aliased before the marker convention existed; leave as found. */
    private const LEGACY_ALIASES = [
        'Border::getTitles'             => 'titles',
    ];

    /**
     * Accessors whose bare twin is a live fluent setter — settled by the
     * owner ruling of 2026-10-24 as permanent legal exceptions: the get*
     * spelling is their canonical name, so they are neither renamed nor
     * deprecated. Value rows: [bare, disposition, evidence] — disposition
     * is 'exception' for every collider (each carries the canonical
     * exception sentence in its own docblock) and evidence is the
     * accessor's declaration line in the current source.
     */
    private const COLLIDER_EXCEPTIONS = [
        'Style::getUnderlineColor'=> ['underlineColor', 'exception', 'Style.php:208'],
        'Style::getUnderlineStyle'=> ['underlineStyle', 'exception', 'Style.php:229'],
        'Style::getPaddingChar'   => ['paddingChar', 'exception', 'Style.php:248'],
        'Style::getMarginChar'    => ['marginChar', 'exception', 'Style.php:266'],
        'Style::getHyperlink'     => ['hyperlink', 'exception', 'Style.php:565'],
        'Style::getForeground'    => ['foreground', 'exception', 'Style.php:740'],
        'Style::getBackground'    => ['background', 'exception', 'Style.php:746'],
        'Style::getWidth'         => ['width', 'exception', 'Style.php:762'],
        'Style::getHeight'        => ['height', 'exception', 'Style.php:768'],
        'Style::getMaxWidth'      => ['maxWidth', 'exception', 'Style.php:774'],
        'Style::getMaxHeight'     => ['maxHeight', 'exception', 'Style.php:780'],
        'Style::getAlign'         => ['align', 'exception', 'Style.php:786'],
        'Style::getVerticalAlign' => ['verticalAlign', 'exception', 'Style.php:792'],
        'Style::getBorder'        => ['border', 'exception', 'Style.php:798'],
        'Style::getBorderForeground'=> ['borderForeground', 'exception', 'Style.php:804'],
        'Style::getBorderBackground'=> ['borderBackground', 'exception', 'Style.php:810'],
        'Style::getColorProfile'  => ['colorProfile', 'exception', 'Style.php:816'],
        'Style::getTabWidth'      => ['tabWidth', 'exception', 'Style.php:823'],
        'Style::getMarginBackground'=> ['marginBackground', 'exception', 'Style.php:829'],
        'Style::getPadding'       => ['padding', 'exception', 'Style.php:838'],
        'Style::getMargin'        => ['margin', 'exception', 'Style.php:846'],
        'HelpBar::getKeySeparator'=> ['keySeparator', 'exception', 'HelpBar.php:160'],
        'HelpBar::getSeparator'   => ['separator', 'exception', 'HelpBar.php:170'],
        'HelpBar::getKeyStyle'    => ['keyStyle', 'exception', 'HelpBar.php:180'],
        'HelpBar::getLabelStyle'  => ['labelStyle', 'exception', 'HelpBar.php:190'],
        'HelpBar::getWidth'       => ['width', 'exception', 'HelpBar.php:200'],
        'StatusBar::getSeparator' => ['separator', 'exception', 'StatusBar.php:183'],
        'StatusBar::getWidth'     => ['width', 'exception', 'StatusBar.php:193'],
        'StatusBar::getStyle'     => ['style', 'exception', 'StatusBar.php:203'],
        'StatusBar::getCaps'      => ['caps', 'exception', 'StatusBar.php:215'],
        'StatusBar::getFillChar'  => ['fillChar', 'exception', 'StatusBar.php:225'],
    ];

    public function testEveryPublicGetAccessorCarriesAKnownDisposition(): void
    {
        $found   = self::publicGetAccessorsIn(dirname(__DIR__) . '/src');
        $rostered = array_merge(
            array_keys(self::ERA_PAIRS),
            array_keys(self::LEGACY_ALIASES),
            array_keys(self::COLLIDER_EXCEPTIONS),
        );
        sort($found);
        sort($rostered);
        self::assertSame(
            $rostered,
            $found,
            'public get* accessors on disk and the disposition roster have diverged (add a row / remove a stale row)',
        );
    }

    public function testTheSentinelViolationCannotEscapeTheDiscoveryWalk(): void
    {
        $dir = sys_get_temp_dir() . '/style-accessor-sentinel-' . getmypid();
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) {
            self::fail('could not create ' . $dir);
        }
        try {
            file_put_contents($dir . '/SentinelLeak.php', <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace Sentinel;
                final class SentinelLeak
                {
                    public function getSentinelLeak(): bool { return true; }
                }
                PHP);
            self::assertSame(
                ['SentinelLeak::getSentinelLeak'],
                self::publicGetAccessorsIn($dir),
                'the discovery walk is blind — the census above proves nothing',
            );
        } finally {
            unlink($dir . '/SentinelLeak.php');
            @rmdir($dir);
        }
    }

    /**
     * @return iterable<string, array{0:string, 1:array{0:string,1:string,2:string}}>
     */
    public static function colliderExceptionProvider(): iterable
    {
        foreach (self::COLLIDER_EXCEPTIONS as $legacy => $row) {
            yield $legacy => [$legacy, $row];
        }
    }

    /**
     * @dataProvider colliderExceptionProvider
     */
    public function testEachColliderExceptionIsPermanentAndStillBlockedByItsBareSetter(
        string $legacy,
        array $row
    ): void {
        [$bare, $disposition, $evidence] = $row;
        [$short, $method] = explode('::', $legacy);
        $class = self::classNamed($short);
        $reflection = new \ReflectionClass($class);

        self::assertTrue(
            $reflection->hasMethod($method),
            "roster row {$legacy} is stale — gone from disk ({$evidence}); re-derive the census",
        );
        self::assertTrue(
            $reflection->hasMethod($bare),
            "{$legacy} is no longer blocked — its bare {$bare}() vanished, so the exception no longer applies; re-derive the roster",
        );
        $setter = $reflection->getMethod($bare);
        self::assertGreaterThanOrEqual(1, $setter->getNumberOfParameters(), "{$bare}() must still be a setter");
        self::assertSame('self', (string) $setter->getReturnType(), "{$bare}() must still return self (fluent)");
        self::assertStringNotContainsString(
            '@deprecated',
            (string) self::docblockOf($reflection->getMethod($method)),
            "{$legacy} is a permanent exception under the ruling — it must not carry a deprecation",
        );

        // The exception sentence must sit in THIS method's own docblock — a
        // 700-char source window leaks the neighbouring getter's wording
        // and hides deletions. Phrase checks flatten whitespace so the
        // sentence may wrap freely inside the docblock.
        $ownDocblock = (string) self::docblockOf($reflection->getMethod($method));
        $ownSentences = (string) preg_replace(
            ['/^[ \t]*\**[ \t]*/m', '/\s+/u'],
            ['', ' '],
            $ownDocblock,
        );
        if ($disposition === 'exception') {
            self::assertStringContainsString(
                'Legal exception (owner ruling 2026-10-24)',
                $ownSentences,
                "collider {$legacy} lost its canonical exception sentence (renamed or reworded without re-deriving)",
            );
            self::assertStringContainsString(
                "this {$method}() read",
                $ownSentences,
                "the exception sentence adjacent to {$legacy} does not speak about {$legacy} itself",
            );
            self::assertStringContainsString(
                "bare name {$bare}()",
                $ownSentences,
                "the exception sentence adjacent to {$legacy} does not name its colliding setter {$bare}()",
            );
            self::assertStringNotContainsString(
                'owner-pending',
                $ownSentences,
                "{$legacy} is settled permanent API — pending-ruling wording must never return",
            );
        } else {
            self::fail("roster status '{$disposition}' at {$legacy} is not a known disposition");
        }
    }

    public function testEachEraPairKeepsItsBcContract(): void
    {
        foreach (self::ERA_PAIRS as $legacy => $bare) {
            [$short, $method] = explode('::', $legacy);
            $class = self::classNamed($short);
            $reflection = new \ReflectionClass($class);

            self::assertTrue($reflection->hasMethod($bare), "bare twin {$bare}() missing");
            $forwarder = $reflection->getMethod($method);
            $twin = $reflection->getMethod($bare);

            self::assertSame($twin->isStatic(), $forwarder->isStatic(), "{$legacy} staticness drifted");
            self::assertSame(
                (string) $twin->getReturnType(),
                (string) $forwarder->getReturnType(),
                "{$legacy} return type drifted",
            );
            self::assertSame(
                $twin->getNumberOfParameters(),
                $forwarder->getNumberOfParameters(),
                "{$legacy} arity drifted",
            );

            $doc = (string) self::docblockOf($forwarder);
            self::assertStringContainsString('@deprecated', $doc);
            self::assertStringContainsString(self::REMOVED_IN_MARKER, $doc);
            self::assertStringContainsString('{@see self::' . $bare . '()}', $doc);
            self::assertStringNotContainsString(
                self::REMOVED_IN_MARKER,
                (string) self::docblockOf($twin),
                "the bare twin must not be deprecation-marked",
            );

            $body = self::methodBody($forwarder);
            self::assertSame(
                1,
                substr_count($body, 'return '),
                "{$legacy} must be a one-line delegation to {$bare}()",
            );
            self::assertMatchesRegularExpression(
                '/return (?:\$this->|self::|static::)' . preg_quote($bare, '/') . '\(\);/',
                $body,
                "{$legacy} must be a one-line delegation to {$bare}()",
            );
        }
    }

    public function testTheBareTwinsReadTheSameStateAsTheirForwarders(): void
    {
        $style = Style::new();
        self::assertSame($style->getBorderSides(), $style->borderSides());

        $saved = Style::defaultProfile();
        try {
            self::assertSame($saved, Style::getDefaultProfile());
            Style::setDefaultProfile(\SugarCraft\Core\Util\ColorProfile::Ansi256);
            self::assertSame(Style::defaultProfile(), Style::getDefaultProfile());
        } finally {
            Style::setDefaultProfile($saved);
        }
        self::assertSame($saved, Style::getDefaultProfile(), 'ambient restore must hold');
    }

    public function testThePreEraBorderAliasStaysAnAlias(): void
    {
        foreach (self::LEGACY_ALIASES as $legacy => $bare) {
            [$short, $method] = explode('::', $legacy);
            $reflection = new \ReflectionClass(self::classNamed($short));
            self::assertTrue($reflection->hasMethod($bare));
            $doc = (string) self::docblockOf($reflection->getMethod($method));
            self::assertStringContainsString('@deprecated', $doc);
            self::assertStringNotContainsString(
                self::REMOVED_IN_MARKER,
                $doc,
                "pre-era alias keeps its own wording; adding the marker would claim a removal date nobody decided",
            );
            self::assertMatchesRegularExpression(
                '/return \$this->' . preg_quote($bare, '/') . '\(\);/m',
                self::methodBody($reflection->getMethod($method)),
            );
        }
    }

    public function testTheDeprecationWindowIsExactlyWhatTheRostersClaim(): void
    {
        $marked = [];
        $deprecated = [];
        foreach (self::phpFilesIn(dirname(__DIR__) . '/src') as $file) {
            foreach (self::methodDocblocks((string) file_get_contents($file)) as $name => $doc) {
                if (str_contains($doc, '@deprecated')) {
                    $deprecated[] = $name;
                }
                if (str_contains($doc, self::REMOVED_IN_MARKER)) {
                    $marked[] = $name;
                }
            }
        }
        sort($marked);
        sort($deprecated);
        $eraMarked = array_keys(self::ERA_PAIRS);
        sort($eraMarked);
        self::assertSame(
            $eraMarked,
            $marked,
            'only the era forwarders may carry the REMOVED-IN marker',
        );
        $expectedDeprecated = array_merge(
            array_keys(self::ERA_PAIRS),
            array_keys(self::LEGACY_ALIASES),
            ['SolverFactory::default'],
        );
        sort($expectedDeprecated);
        self::assertSame(
            $expectedDeprecated,
            $deprecated,
            'the @deprecated population changed — reconcile it with the rosters',
        );
    }

    public function testTheReadmeCarriesTheEraSectionAndAgreesWithTheCensus(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('## Accessor era (2026-10-24)', $readme);
        $flat = preg_replace('/\s+/u', ' ', $readme);
        self::assertStringContainsString('defaultProfile()', $flat);
        self::assertStringContainsString('borderSides()', $flat);
        self::assertStringContainsString(
            count(self::COLLIDER_EXCEPTIONS) . ' accessors are permanent legal exceptions',
            (string) $flat,
            'the README collider tally drifted from the roster',
        );
        self::assertStringNotContainsString('Style::getDefaultProfile()', $readme, 'docs must teach the bare canonical read');
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /**
     * @return list<string> "ShortClass::getFoo" sorted by file walk order
     */
    private static function publicGetAccessorsIn(string $dir): array
    {
        $found = [];
        foreach (self::phpFilesIn($dir) as $file) {
            $tokens = \PhpToken::tokenize((string) file_get_contents($file));
            $class = null;
            $insideInterface = false;
            $pendingPublic = false;
            foreach ($tokens as $index => $token) {
                if ($token->id === \T_CLASS) {
                    $class = $tokens[$index + 2]->text;
                    $insideInterface = false;
                } elseif ($token->id === \T_INTERFACE) {
                    $class = null;
                    $insideInterface = true;
                } elseif ($token->id === \T_PUBLIC) {
                    $pendingPublic = true;
                } elseif ($token->id === \T_FUNCTION && $pendingPublic && $class !== null && !$insideInterface) {
                    $name = $tokens[$index + 2]->text;
                    if (preg_match('/^get[A-Z]/', $name) === 1) {
                        $found[] = $class . '::' . $name;
                    }
                    $pendingPublic = false;
                } elseif ($token->id !== \T_WHITESPACE && $token->id !== \T_STATIC && $token->id !== \T_READONLY) {
                    $pendingPublic = false;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string,string> method name => its docblock text
     */
    private static function methodDocblocks(string $source): array
    {
        $docs = [];
        $tokens = \PhpToken::tokenize($source);
        $class = null;
        $lastDoc = null;
        foreach ($tokens as $index => $token) {
            if ($token->id === \T_CLASS) {
                $class = $tokens[$index + 2]->text;
            } elseif ($token->id === \T_DOC_COMMENT) {
                $lastDoc = $token->text;
            } elseif ($token->id === \T_FUNCTION) {
                if ($class !== null && $lastDoc !== null) {
                    $docs[$class . '::' . $tokens[$index + 2]->text] = $lastDoc;
                }
                $lastDoc = null;
            } elseif ($token->id !== \T_WHITESPACE && $token->id !== \T_PUBLIC && $token->id !== \T_STATIC
                && $token->id !== \T_PROTECTED && $token->id !== \T_PRIVATE && $token->id !== \T_ABSTRACT
                && $token->id !== \T_READONLY) {
                $lastDoc = null;
            }
        }

        return $docs;
    }

    private static function docblockOf(\ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment();

        return $doc === false ? null : $doc;
    }

    private static function methodBody(\ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /** @return class-string */
    private static function classNamed(string $short): string
    {
        return match ($short) {
            'Style'     => Style::class,
            'Border'    => Border::class,
            'HelpBar'   => HelpBar::class,
            'StatusBar' => StatusBar::class,
            default     => self::fail("roster class {$short} is not wired into the probe"),
        };
    }
}
