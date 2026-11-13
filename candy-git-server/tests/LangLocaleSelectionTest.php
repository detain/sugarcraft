<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\GitServer\Lang;

/**
 * The operator-facing copy is only as real as the machinery that picks it:
 * these pins guard the locale switch, the cross-locale key parity that
 * twelve-persona reviews kept eroding, and the fact that the serve --http
 * refusals and the startup banner route through Lang instead of being
 * hardcoded English.
 */
final class LangLocaleSelectionTest extends TestCase
{
    private const LOCALES = ['ar', 'cs', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko', 'nl', 'pl', 'pt-br', 'pt', 'ru', 'tr', 'zh-cn', 'zh-tw'];

    protected function tearDown(): void
    {
        T::setLocale('en');
    }

    public function testEnvironmentVariableSelectsTheCatalogue(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-git-server');
        self::assertStringContainsString("getenv('CANDY_GIT_SERVER_LANG')", $bin);
        self::assertStringContainsString('T::setLocale($locale)', $bin);

        // Behavioural half: a non-English locale actually changes lookups.
        T::setLocale('de');
        $german = Lang::t('cli.done');
        T::setLocale('en');
        self::assertNotSame($german, Lang::t('cli.done'), 'switching locale must change the catalogue');
        self::assertStringContainsString('fertig', \strtolower($german));
    }

    public function testUnknownLocaleFallsThroughToEnglish(): void
    {
        T::setLocale('qq-ZZ');
        // Lookup order is locale → base language → en, so every shipped key
        // still resolves for a nonsense locale.
        self::assertSame(Lang::t('cli.done'), (static function (): string {
            T::setLocale('en');

            return Lang::t('cli.done');
        })());
    }

    public function testEveryLocaleCarriesExactlyTheEnglishKeySet(): void
    {
        $english = \array_keys(include \dirname(__DIR__) . '/lang/en.php');
        self::assertNotEmpty($english);
        foreach (self::LOCALES as $locale) {
            $keys = \array_keys(include \dirname(__DIR__) . "/lang/{$locale}.php");
            // Guard-genre: a key that exists only in en.php silently ships
            // untranslated copy for five runtime messages' worth of history.
            $sortEnglish = $english;
            $sortKeys = $keys;
            \sort($sortEnglish);
            \sort($sortKeys);
            self::assertSame(
                $sortEnglish,
                $sortKeys,
                "locale {$locale} diverges from the en key set",
            );
            self::assertCount(\count($english), $keys);
        }
    }

    public function testEveryLocaleKeepsTheEnglishPlaceholders(): void
    {
        // Key parity alone lets a translation drop or rename {path}; the
        // placeholder set per key must match en exactly.
        $english = include \dirname(__DIR__) . '/lang/en.php';
        foreach (self::LOCALES as $locale) {
            $table = include \dirname(__DIR__) . "/lang/{$locale}.php";
            foreach ($english as $key => $text) {
                \preg_match_all('/\{[a-z_]+\}/', $text, $want);
                \preg_match_all('/\{[a-z_]+\}/', $table[$key], $got);
                $w = $want[0];
                $g = $got[0];
                \sort($w);
                \sort($g);
                self::assertSame($w, $g, "{$locale}: {$key} placeholders diverge from en");
            }
        }
    }

    public function testHelpListsEveryRealCommandInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $help = (include \dirname(__DIR__) . "/lang/{$locale}.php")['cli.help'];
            foreach (['init [data-path] [--admin NAME', 'user remove', 'user deactivate', 'repo collaborator', 'repo mirror', 'repo push-access', 'CANDY_GIT_SERVER_BOOTSTRAP_ADMIN'] as $needle) {
                self::assertStringContainsString($needle, $help, "{$locale} help is stale: missing {$needle}");
            }
        }
    }

    public function testServeOperatorCopyRoutesThroughLang(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-git-server');
        foreach (['cli.option_needs_value', 'cli.serve_http_needs_repo_root',
            'cli.serve_repo_root_not_dir', 'cli.serve_http_port_range',
            'cli.serve_http_banner', ] as $key) {
            self::assertStringContainsString(
                "Lang::t('{$key}'",
                $bin,
                "serve --http copy must reach the wire via Lang ({$key})",
            );
        }
        // The retired fiction strings stay retired (they were live until the
        // push transport shipped; an accidental reintroduction reddens here).
        foreach (['transports are not implemented', ': {$arg} requires a value', 'INITIAL_ADMIN_KEYS'] as $gone) {
            self::assertStringNotContainsString($gone, $bin);
        }
    }

    public function testRemainingCliRefusalsRouteThroughLang(): void
    {
        // Step 17: the init/token/password/username refusals that once were
        // hardcoded English now reach the wire through Lang, and the English
        // catalogue carries each of them. Bootstrap copy (autoload failure,
        // the deprecated-env shim) and exception passthroughs deliberately
        // stay raw — they are not translatable operator prose.
        $bin = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-git-server');
        $en = include \dirname(__DIR__) . '/lang/en.php';
        foreach ([
            'cli.init_temp_open_failed' => ['tmp'],
            'cli.init_publish_failed' => ['data'],
            'cli.token_no_config' => ['data'],
            'cli.token_no_secret' => [],
            'cli.password_flag_empty' => [],
            'cli.username_invalid' => ['username'],
            'cli.user_exists' => ['username', 'path'],
            'cli.user_not_found' => ['username', 'path'],
        ] as $key => $params) {
            self::assertStringContainsString(
                "Lang::t('{$key}'",
                $bin,
                "CLI refusal copy must reach the wire via Lang ({$key})",
            );
            self::assertArrayHasKey($key, $en, "en.php must carry {$key}");
            foreach ($params as $param) {
                self::assertStringContainsString(
                    '{' . $param . '}',
                    (string) $en[$key],
                    "{$key} must interpolate {{$param}}",
                );
            }
        }

        // The mint refusal still names the ONLY parsed signing key.
        self::assertStringContainsString('http.auth_secret', (string) $en['cli.token_no_secret']);

        // 096 retirement (S36): value-less --password is no longer a
        // refusal — it is the headline STDIN form — so the needs_value
        // copy must never come back.
        self::assertStringNotContainsString('cli.password_flag_needs_value', $bin);
        self::assertArrayNotHasKey('cli.password_flag_needs_value', $en);
    }

    public function testInitNextStepsTeachTheWorkingRecipe(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-git-server');
        // The three printed steps must all resolve their {path} placeholder
        // and teach the shipped transport (serve --http), not ssh fiction.
        self::assertStringContainsString("Lang::t('cli.next_step_3', ['path' => \$dataPath])", $bin);
        $en = include \dirname(__DIR__) . '/lang/en.php';
        self::assertStringContainsString('serve --http --repo-root', $en['cli.next_step_3']);
        self::assertStringContainsString('init {path} --admin <name>', $en['cli.next_step_1']);
        // 019: the printed onboarding step must not mint a factor-less
        // account — the retired 'user add --admin admin' shape persisted
        // password_hash: null and refused pushes forever, off a name the
        // attack list guesses first. STDIN steer replaces it.
        self::assertStringContainsString('--admin-password-stdin', $en['cli.next_step_1']);
        self::assertStringNotContainsString('--admin admin', $en['cli.next_step_1']);
        self::assertStringContainsString('--public', $en['cli.next_step_2']);
        self::assertArrayNotHasKey('cli.next_step_4', $en);
    }

    public function testInitStampCarriesNoPhantomPersistenceKeys(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-git-server');
        self::assertStringNotContainsString('driver: "sqlite"', $bin);
        self::assertStringNotContainsString('candy-git-server.db', $bin);
    }
}
