<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SettingsTier;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tests\Support\FlushesLocaleMemoisedCataloguesTrait;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\McpPanel;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Tui\Settings\SettingsEditor;
use SugarCraft\Crush\Tui\Settings\SettingsSavedMsg;
use SugarCraft\Crush\Tui\Settings\SettingsSources;
use SugarCraft\Sprinkles\Style;

/**
 * The TUI's own slice of the `crush` catalogue (audit 15b-14, step 15b-14-4b):
 * the `tui.*` / `palette.*` keys the renderer, the shell and the TUI widgets
 * ask for.
 *
 * Two halves. The census closes the gap {@see \SugarCraft\Crush\Tests\LangParityTest}
 * leaves by design: that test reads only `Lang::t('…')` call sites, while the
 * TUI keeps many keys in constants (status-bar form lists, permission option
 * tables, failure heads) that reach `Lang::t()` through a variable. Here every
 * quoted `tui.`/`palette.` literal in src must exist in English, and every
 * such English key must still be asked for somewhere.
 *
 * The width half renders under a pseudo-locale whose every letter is its
 * FULLWIDTH form — twice the cells of the English — and holds each surface to
 * its terminal: a translated label is measured by display width, never
 * bytes, so a longer translation is cut, not wrapped past the edge.
 */
final class TuiLangTest extends TestCase
{
    private const LANG_DIR = __DIR__ . '/../../lang';

    /** The `{placeholder}` and letter runs of a catalogue value. */
    private const PLACEHOLDER = '/(\{\w+\})/';

    private string $locale;

    private ?string $pseudoDir = null;

    use FlushesLocaleMemoisedCataloguesTrait;

    protected function setUp(): void
    {
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        if ($this->pseudoDir !== null) {
            T::overrideNamespace('crush', self::LANG_DIR);
            self::flushLocaleMemoisedCatalogues();
            foreach (glob($this->pseudoDir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->pseudoDir);
            $this->pseudoDir = null;
        }
        T::setLocale($this->locale);
    }

    // ── census ──────────────────────────────────────────────────────────

    public function testEveryTuiKeyQuotedInSrcExistsInEnglish(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $missing = [];
        foreach (self::quotedTuiKeys() as $key => $file) {
            if (!\array_key_exists($key, $en)) {
                $missing[] = "{$key} ({$file})";
            }
        }

        self::assertSame([], $missing, 'tui./palette. keys src names with no lang/en.php entry');
    }

    public function testEveryEnglishTuiKeyIsStillAskedFor(): void
    {
        $en = require self::LANG_DIR . '/en.php';
        $quoted = self::quotedTuiKeys();
        $dead = [];
        foreach (array_keys($en) as $key) {
            if ((str_starts_with($key, 'tui.') || str_starts_with($key, 'palette.')) && !isset($quoted[$key])) {
                $dead[] = $key;
            }
        }

        self::assertSame([], $dead, 'lang/en.php tui./palette. keys nothing in src asks for');
    }

    public function testTheCensusSeesKeysHeldInConstants(): void
    {
        // Known-positive: these reach Lang::t() only through a constant, so a
        // census that missed them would pass while proving nothing.
        $quoted = self::quotedTuiKeys();
        foreach (['tui.key_help.too_small_2', 'tui.permission.allow_once', 'tui.settings.failed.saved', 'tui.dashboard.group.working'] as $key) {
            self::assertArrayHasKey($key, $quoted);
        }
    }

    // ── English is unchanged ────────────────────────────────────────────

    public function testEnglishResolvesEveryKeyToItsOwnValue(): void
    {
        foreach (require self::LANG_DIR . '/en.php' as $key => $value) {
            if (str_starts_with($key, 'tui.') || str_starts_with($key, 'palette.')) {
                self::assertSame($value, Lang::t($key), $key);
            }
        }
    }

    public function testAFailedSaveIsPaintedInTheErrorColourInEveryLocale(): void
    {
        $errorOpen = self::sgrOpen(Style::new()->foreground(Theme::default()->shellError));
        $failed = SettingsEditor::open(self::sources())
            ->withSaved(SettingsSavedMsg::failed(SettingsTier::You, ['maxToolSteps'], 'BOOM'));

        $english = $failed->view(Theme::default(), 140, 30);
        self::assertStringContainsString($errorOpen . 'Not saved: BOOM', $english, 'the English status line is the one it always was');

        $this->usePseudoLocale();
        $failed = SettingsEditor::open(self::sources())
            ->withSaved(SettingsSavedMsg::failed(SettingsTier::You, ['maxToolSteps'], 'BOOM'));
        $head = Lang::t('tui.settings.failed.saved');
        self::assertNotSame('Not saved', $head, 'the pseudo-locale is live');
        self::assertStringContainsString(
            $errorOpen . $head . ': BOOM',
            $failed->view(Theme::default(), 140, 30),
            'the error colour hung on the English word "Not"',
        );
    }

    // ── width ───────────────────────────────────────────────────────────

    public function testTheSettingsViewHoldsItsSizeWhenTranslated(): void
    {
        $this->usePseudoLocale();
        $editor = SettingsEditor::open(self::sources());

        foreach ([[40, 12], [69, 20], [90, 24], [140, 30]] as [$cols, $rows]) {
            foreach ([$editor, $editor->stage('maxToolSteps', 40), $editor->withConfirm(SettingsEditor::CONFIRM_DISCARD)] as $view) {
                $lines = explode("\n", Ansi::strip($view->view(Theme::default(), $cols, $rows)));
                self::assertCount($rows, $lines, "{$cols}x{$rows}");
                foreach ($lines as $line) {
                    self::assertSame($cols, Width::string($line), "{$cols}x{$rows}: {$line}");
                }
            }
        }
    }

    public function testTheMcpPanelKeepsItsWidthWhenTranslated(): void
    {
        $this->usePseudoLocale();
        foreach ([Bootstrap::MCP_ABSENT, Bootstrap::MCP_UNTRUSTED, Bootstrap::MCP_OUTSIDE_TREE] as $status) {
            $out = McpPanel::render(['status' => $status, 'path' => '/tmp/example/.mcp.json', 'servers' => [], 'error' => null], 40);
            foreach (explode("\n", $out) as $line) {
                self::assertLessThanOrEqual(40, Width::string($line), $line);
            }
        }
    }

    public function testTheSessionPickerIsNoWiderTranslatedThanInEnglish(): void
    {
        $rows = [];
        for ($i = 0; $i < 4; $i++) {
            $rows[] = ['sessionId' => "s{$i}", 'sessionName' => "Session {$i}", 'summary' => "Summary {$i}", 'gitBranch' => null, 'lastActivity' => '2024-01-01T00:00:00Z', 'turns' => $i];
        }

        foreach ([40, 60, 100] as $width) {
            $english = self::widest(SessionPicker::new($rows, now: 1_704_067_200)->render($width, 12, Theme::default()));

            $this->usePseudoLocale();
            $translated = self::widest(SessionPicker::new($rows, now: 1_704_067_200)->render($width, 12, Theme::default()));
            $this->useEnglish();

            self::assertLessThanOrEqual($english, $translated, "picker at {$width} columns");
        }
    }

    public function testTheFrameHoldsTheTerminalWidthWhenTranslated(): void
    {
        $this->usePseudoLocale();
        foreach ([40, 80, 120] as $cols) {
            foreach ([new Chat(), new Chat(inputBuf: 'hello', inFlight: true)] as $chat) {
                $frame = Renderer::render($chat->withSize($cols, 24));
                foreach (explode("\n", self::visible($frame)) as $line) {
                    self::assertLessThanOrEqual($cols, Width::string($line), "{$cols} cols: {$line}");
                }
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Every quoted `tui.`/`palette.` key in the TUI layer's sources (the
     * renderer, the shell, `Chat.php`'s notice emitters, `Tui/` and
     * `Palette/`), mapped to the first file naming it. Scoped to those files because elsewhere the same shape is
     * something else: `KeyBindingRegistry` ids such as `palette.move` are
     * binding ids, not catalogue keys.
     *
     * @return array<string, string>
     */
    private static function quotedTuiKeys(): array
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $files = [$src . '/Renderer.php', $src . '/App/App.php', $src . '/Chat.php'];
        foreach (['/Tui', '/Palette'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $keys = [];
        foreach ($files as $path) {
            foreach (token_get_all((string) file_get_contents($path)) as $token) {
                if (\is_array($token) && $token[0] === \T_CONSTANT_ENCAPSED_STRING
                    && preg_match("/\\A'((?:tui|palette)\\.[a-z0-9_.]+)'\\z/", $token[1], $m) === 1) {
                    $keys[$m[1]] ??= substr($path, \strlen($src) + 1);
                }
            }
        }

        return $keys;
    }

    private static function sources(): SettingsSources
    {
        return SettingsSources::fromLaunch('/nonexistent-root', null, null, null, []);
    }

    /** The SGR a style opens with, read off a one-letter render. */
    private static function sgrOpen(Style $style): string
    {
        $painted = $style->render('Z');

        return substr($painted, 0, (int) strpos($painted, 'Z'));
    }

    private static function widest(string $text): int
    {
        return max(array_map(static fn (string $line): int => Width::string($line), explode("\n", Ansi::strip($text))));
    }

    /** A frame as the terminal shows it: no SGR, no zone sentinels. */
    private static function visible(string $frame): string
    {
        return (string) preg_replace('/\xEE\x80\x80\/?[A-Za-z0-9._:-]*\xEE\x80\x81/', '', Ansi::strip($frame));
    }

    /**
     * Switch `crush` to a generated `xx` locale: every value of lang/en.php
     * with its ASCII letters made FULLWIDTH (two cells each) and its
     * placeholders kept, beside a copy of en.php as the fallback.
     */
    private function usePseudoLocale(): void
    {
        if ($this->pseudoDir === null) {
            $dir = sys_get_temp_dir() . '/crush-pseudo-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir($dir, 0o700, true);
            $en = require self::LANG_DIR . '/en.php';
            $wide = [];
            foreach ($en as $key => $value) {
                $parts = preg_split(self::PLACEHOLDER, $value, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
                $wide[$key] = implode('', array_map(
                    static fn (string $part): string => preg_match(self::PLACEHOLDER, $part) === 1
                        ? $part
                        : (string) preg_replace_callback('/[A-Za-z]/', static fn (array $c): string => mb_chr(\ord($c[0]) + 0xFEE0, 'UTF-8'), $part),
                    $parts,
                ));
            }
            copy(self::LANG_DIR . '/en.php', $dir . '/en.php');
            file_put_contents($dir . '/xx.php', '<?php return ' . var_export($wide, true) . ";\n");
            $this->pseudoDir = $dir;
        }

        T::overrideNamespace('crush', $this->pseudoDir);
        T::setLocale('xx');
        self::flushLocaleMemoisedCatalogues();
    }

    private function useEnglish(): void
    {
        T::overrideNamespace('crush', self::LANG_DIR);
        T::setLocale('en');
    }
}
