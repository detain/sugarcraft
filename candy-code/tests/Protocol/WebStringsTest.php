<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Protocol\WebStrings;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * C-TUI-6.f: the `server.strings` bundle rules — only `web.` keys reach the
 * wire; the ladder answers exact, then base-before-dash, then English; a
 * tag that is not a locale name is refused outright (`../x` can never name
 * a catalogue); and `{placeholders}` ride through untouched for the browser
 * to interpolate.
 *
 * The catalogues here are fixtures under a per-process temp dir; the paths
 * are fixture-shaped data, not wire content.
 */
final class WebStringsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        WebStrings::resetForTesting();
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/webstrings-' . \uniqid('', true);
        self::assertTrue(mkdir($this->dir, 0700));
    }

    protected function tearDown(): void
    {
        WebStrings::resetForTesting();
        foreach ((array) scandir($this->dir) as $entry) {
            if (\is_string($entry) && $entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);
    }

    /** @param array<string, string> $entries */
    private function planting(string $tag, array $entries): void
    {
        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . \var_export($entries, true) . ";\n";
        self::assertNotFalse(file_put_contents($this->dir . '/' . $tag . '.php', $php));
    }

    public function testOnlyWebPrefixedKeysAreExposed(): void
    {
        $this->planting('en', [
            'web.chrome.banner.lost' => 'Connection lost',
            'cli.launch.banner' => 'A CLI launch notice',
            'tui.palette.title' => 'A TUI palette label',
            'provider.claude_code.isolation_notice' => 'Server-side text',
            'web.not.a.string' => 42,
        ]);

        self::assertSame(
            ['web.chrome.banner.lost' => 'Connection lost'],
            WebStrings::for('en', $this->dir),
            'the wire carries the chrome prefix and nothing else',
        );
    }

    public function testTheLadderFallsBackToTheBaseLanguage(): void
    {
        $this->planting('en', ['web.chrome.app.about' => 'about']);
        $this->planting('fr', ['web.chrome.app.about' => 'à propos']);

        // Exact wins when the catalogue shipped.
        self::assertSame(['web.chrome.app.about' => 'à propos'], WebStrings::for('fr', $this->dir));
        // fr-ca has no catalogue of its own; the base before the dash answers.
        self::assertSame(['web.chrome.app.about' => 'à propos'], WebStrings::for('fr-ca', $this->dir));
        self::assertSame('fr', WebStrings::negotiate('fr-CA', $this->dir));
        // A language nobody shipped falls through to English.
        self::assertSame(['web.chrome.app.about' => 'about'], WebStrings::for('de', $this->dir));
        self::assertSame('en', WebStrings::negotiate('de-AT', $this->dir));
    }

    public function testTheNegotiatedEchoIsTheCatalogueActuallyServed(): void
    {
        $this->planting('en', ['web.chrome.app.about' => 'about']);
        $this->planting('pt-br', ['web.chrome.app.about' => 'sobre']);

        self::assertSame('pt-br', WebStrings::negotiate('PT_BR', $this->dir), 'normalization matches Lang\'s launch/locale spelling');
        self::assertSame('pt-br', WebStrings::negotiate('pt-br@u8', $this->dir), 'modifier tails drop, as the env locale sanitizer drops them');
        self::assertSame('en', WebStrings::negotiate('', $this->dir), 'no request means the server default');
        self::assertSame('en', WebStrings::negotiate('C', $this->dir));
        self::assertSame('en', WebStrings::negotiate('posix', $this->dir));
    }

    /**
     * The containment law: a request can only SELECT among the installation's
     * listed catalogues, never construct a path. The grammar refuses the
     * traversal shapes outright.
     */
    public function testPathishTagsAreRefused(): void
    {
        $this->planting('en', ['web.chrome.app.about' => 'about']);

        foreach (['../x', 'en/../../y', 'a b', '../en', 'e/n', '..'] as $tag) {
            try {
                WebStrings::for($tag, $this->dir);
                self::fail('WebStrings::for accepted the non-locale tag ' . $tag);
            } catch (\InvalidArgumentException) {
                // Refused, as the grammar must refuse it.
            }
            try {
                WebStrings::negotiate($tag, $this->dir);
                self::fail('WebStrings::negotiate accepted the non-locale tag ' . $tag);
            } catch (\InvalidArgumentException) {
                // Refused, as the grammar must refuse it.
            }
        }
    }

    public function testPlaceholdersSurviveTheWireUntouched(): void
    {
        $this->planting('en', ['web.chrome.banner.drain' => 'It asks for {n} s to finish its work.']);

        self::assertSame(
            ['web.chrome.banner.drain' => 'It asks for {n} s to finish its work.'],
            WebStrings::for('en', $this->dir),
            'the server passes the {braces} through; the browser interpolates',
        );
    }

    public function testTheRealEnglishCatalogueIsServedFromTheInstallation(): void
    {
        $strings = WebStrings::for('en');

        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 2) . '/lang/en.php';
        $wanted = [];
        foreach ($catalogue as $key => $value) {
            if (\is_string($value) && \str_starts_with((string) $key, WebStrings::PREFIX)) {
                $wanted[(string) $key] = $value;
            }
        }
        \ksort($wanted);

        self::assertNotSame([], $strings, 'the shipped catalogue carries the chrome keys');
        self::assertSame($wanted, $strings, 'every web. key of lang/en.php reaches the wire, nothing else, sorted');
        foreach ($strings as $key => $value) {
            self::assertStringStartsWith('web.', $key);
        }
    }

    /**
     * The handler's mercy leg: ServerMethods::strings() catches the grammar
     * refusal and answers in English instead of faulting, so a browser with a
     * junk navigator.language still gets a working UI. Deleting that catch
     * surfaces as a generic RPC error and reddens this pin.
     */
    public function testTheWireAnswersJunkLocaleTagsInEnglishRatherThanFaulting(): void
    {
        $fixture = ProtocolFixture::new();
        try {
            $result = $fixture->client()->call('server.strings', ['locale' => 'not a locale!']);
        } finally {
            $fixture->tearDown();
        }

        self::assertSame('en', $result['locale'], 'the refused tag settles on English, no RPC error');
        self::assertNotSame([], $result['strings'], 'the English chrome bundle rides the mercy answer');
    }
}
