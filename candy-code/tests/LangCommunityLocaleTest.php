<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Protocol\WebStrings;

/**
 * C-TUI-6.h: the community locale set shipped under `lang/`.
 *
 * `lang/en.php` is the source of truth and `LangParityTest` polices its key
 * set; this test proves the translated catalogues are REAL — a shipped code
 * actually answers in its own language through every consumer path, and a
 * regional tag falls back to its base file (LOCALES.md's ladder:
 * exact → base language → `en`).
 */
final class LangCommunityLocaleTest extends TestCase
{
    /**
     * The 11 non-English codes the owner ruling shipped (LOCALES.md's
     * recommended set, bare base codes plus the one regional file).
     */
    private const COMMUNITY_LOCALES = [
        'fr', 'de', 'es', 'pt', 'pt-br', 'zh-cn', 'zh-tw', 'ja', 'ru', 'it', 'ko',
    ];

    /** Prose keys that must never stay English in a community locale. */
    private const SAMPLE_KEYS = [
        'settings.category.permissions',
        'settings.category.skills',
        'chat.agents.no_mailboxes',
    ];

    private const WEB_SAMPLE_KEY = 'web.agents.none_yet';

    private string $locale;

    protected function setUp(): void
    {
        $this->locale = T::locale();
        T::setLocale('en');
        WebStrings::resetForTesting();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
        WebStrings::resetForTesting();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function communityLocaleProvider(): array
    {
        $cases = [];
        foreach (self::COMMUNITY_LOCALES as $code) {
            $cases[$code] = [$code];
        }

        return $cases;
    }

    public function testTheRecommendedLocaleSetIsFullyShipped(): void
    {
        $shipped = [];
        foreach (scandir(\dirname(__DIR__) . '/lang') ?: [] as $entry) {
            if (\is_string($entry) && \str_ends_with($entry, '.php')) {
                $shipped[] = substr($entry, 0, -4);
            }
        }
        sort($shipped);

        $expected = array_merge(['en'], self::COMMUNITY_LOCALES);
        sort($expected);
        $this->assertSame($expected, $shipped, 'lang/ must carry exactly the source plus the 11 ruled codes');
    }

    #[DataProvider('communityLocaleProvider')]
    public function testSampleKeysResolveToNonEnglish(string $code): void
    {
        foreach (self::SAMPLE_KEYS as $key) {
            $english = Lang::t($key);
            $translated = Lang::inLocale($code, static fn (): string => Lang::t($key));
            $this->assertNotSame($english, $translated, "{$key} stayed English in lang/{$code}.php");
            $this->assertNotSame($key, $translated, "{$key} fell through to the raw key under {$code}");
        }
    }

    #[DataProvider('communityLocaleProvider')]
    public function testWebStringsAnswerTheShippedLocale(string $code): void
    {
        $this->assertSame($code, WebStrings::negotiate($code), "{$code} must negotiate to its own catalogue");

        $english = WebStrings::for('en');
        $bundle = WebStrings::for($code);
        $this->assertSame(array_keys($english), array_keys($bundle), "the web slice lost keys under {$code}");
        $this->assertArrayHasKey(self::WEB_SAMPLE_KEY, $bundle);
        $this->assertNotSame($english[self::WEB_SAMPLE_KEY], $bundle[self::WEB_SAMPLE_KEY],
            self::WEB_SAMPLE_KEY . " stayed English under {$code}");
    }

    #[DataProvider('communityLocaleProvider')]
    public function testRegionalTagFallsBackThroughTheLadder(string $code): void
    {
        // `<code>-xx` has no shipped file. The ladder (exact → base before the
        // FIRST dash → en) then serves the base-language catalogue when one is
        // shipped — `fr-ca` reads `fr` — and English as the floor when it is
        // not: `pt-br-xx` lands on `pt`, `zh-cn-xx` on `en` (only the regional
        // Chinese files ship). Both consumers must walk the same ladder.
        $regional = $code . '-xx';
        $base = \explode('-', $code, 2)[0];
        $landing = \in_array($base, self::COMMUNITY_LOCALES, true) ? $base : 'en';

        $this->assertSame($landing, WebStrings::negotiate($regional), "{$regional} must land on {$landing}");

        $key = self::SAMPLE_KEYS[0];
        $viaLadder = Lang::inLocale($regional, static fn (): string => Lang::t($key));
        $viaLanding = Lang::inLocale($landing, static fn (): string => Lang::t($key));
        $this->assertSame($viaLanding, $viaLadder, "{$regional} must read the {$landing} wording for {$key}");
    }

    #[DataProvider('communityLocaleProvider')]
    public function testPosixLaunchSpellingLandsOnItsCatalogue(string $code): void
    {
        // The README's launch example (`LANG=fr_FR.UTF-8 candy-code`) and the
        // wire's Accept-Language-shaped tags, normalized the way
        // `Lang::useLaunchLocale()` and `WebStrings` normalize them: a bare
        // code arrives as a regional tag and lands on its own file; the three
        // shipped regional files land exactly.
        $posix = match ($code) {
            'pt-br' => 'pt_BR.UTF-8',
            'zh-cn' => 'zh_CN.UTF-8',
            'zh-tw' => 'zh_TW.UTF-8',
            default => $code . '_XX.UTF-8',
        };

        $this->assertSame($code, WebStrings::negotiate($posix), "{$posix} must land on {$code}");

        $key = self::SAMPLE_KEYS[0];
        $viaPosix = Lang::inLocale($posix, static fn (): string => Lang::t($key));
        $viaCode = Lang::inLocale($code, static fn (): string => Lang::t($key));
        $this->assertSame($viaCode, $viaPosix, "{$posix} must read the {$code} wording for {$key}");
    }
}
