<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\SessionPicker;

/**
 * The session picker's layout pinned as text goldens at four widths
 * (Appendix P §3.2): which columns survive as the box narrows (time ≥44,
 * turns ≥56, badge ≥60, provider/model ≥68, the mouse cluster ≥50), the
 * group headings, the indented sub-agent row, a wide-character title cut by
 * cells, and the footer's `cwd · branch · "last prompt"` (audit B1/B3).
 *
 * The goldens are the SGR-stripped paint, so they pin layout rather than the
 * theme's colours. Regenerate with `UPDATE_GOLDENS=1` after an intended
 * layout change, and read the diff before committing it.
 *
 * Independently of the goldens, no painted line may be wider than the box:
 * the diff renderer paints one logical line per terminal row, so an overlong
 * row wraps into the next and collides with it.
 *
 * @internal
 */
final class SessionPickerGoldenTest extends TestCase
{
    private const GOLDEN_DIR = __DIR__ . '/../fixtures/session-picker';

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    private function picker(): SessionPicker
    {
        $row = static fn (array $fields): array => $fields + [
            'summary' => '',
            'gitBranch' => null,
            'kind' => 'main',
            'turns' => 0,
            'provider' => 'sglang',
            'model' => 'dsv4-flash',
        ];

        $rows = [
            $row(['sessionId' => 'a1', 'sessionName' => 'Auth refactor', 'title' => 'Auth refactor', 'summary' => 'make the login controller use the new guard', 'gitBranch' => 'main', 'lastActivity' => '2026-09-15 11:58:00', 'cwd' => '/work/app', 'pinned' => true, 'turns' => 41, 'live' => true]),
            $row(['sessionId' => 'b2', 'sessionName' => 'Fix flaky PTY test', 'title' => 'Fix flaky PTY test', 'summary' => 'why is the PTY test flaky?', 'gitBranch' => 'main', 'lastActivity' => '2026-09-15 11:00:00', 'turns' => 12, 'subagents' => 1, 'children' => 1]),
            $row(['sessionId' => 's7', 'sessionName' => 'Map the login flow', 'title' => 'Map the login flow', 'lastActivity' => '2026-09-15 10:30:00', 'kind' => 'subagent', 'parentId' => 'b2', 'agent' => 'explore', 'status' => 'complete']),
            $row(['sessionId' => 'c3', 'sessionName' => '漢字のセッション名前がとても長いタイトル', 'title' => '漢字のセッション名前がとても長いタイトル', 'lastActivity' => '2026-09-15 09:00:00', 'turns' => 1, 'provider' => 'claude-code', 'model' => '']),
            $row(['sessionId' => 'd4', 'sessionName' => '(untitled d4000000…)', 'title' => null, 'lastActivity' => '2026-09-14 10:00:00', 'kind' => 'background', 'turns' => 1, 'status' => 'complete']),
            $row(['sessionId' => 'e5', 'sessionName' => 'Auth refactor (branch)', 'title' => 'Auth refactor (branch)', 'lastActivity' => '2026-09-14 09:00:00', 'kind' => 'branch', 'parentId' => 'a1', 'turns' => 38]),
            $row(['sessionId' => 'f6', 'sessionName' => 'Old thing', 'title' => 'Old thing', 'lastActivity' => '2025-12-01 09:00:00', 'turns' => 2]),
        ];

        return SessionPicker::new($rows, SessionPicker::PAGE_SIZE, false, 'main', 'zz', strtotime('2026-09-15 12:00:00 UTC'))
            ->withShowChildren();
    }

    /** @return array<string, array{int}> */
    public static function widths(): array
    {
        return ['40' => [40], '60' => [60], '80' => [80], '120' => [120]];
    }

    #[DataProvider('widths')]
    public function testTheLayoutAtEachWidthMatchesItsGolden(int $width): void
    {
        $this->assertGolden("picker-{$width}.txt", self::plain($this->picker()->render($width, 24, Theme::byName('dark'))));
    }

    #[DataProvider('widths')]
    public function testNoPaintedLineIsWiderThanTheBox(int $width): void
    {
        $pickers = [
            'list' => $this->picker(),
            'filter' => $this->picker()->withQuery('auth'),
            'rename' => $this->picker()->handleKey('r')[0],
            'delete' => $this->picker()->handleKey('down')[0]->handleKey('d')[0],
            'preview' => $this->picker()->withPreview('a1', [str_repeat('you: 漢字 ', 40)]),
        ];

        foreach ($pickers as $state => $picker) {
            foreach (explode("\n", $picker->render($width, 24, Theme::byName('dark'))) as $i => $line) {
                // The box is the width it is handed plus its border and padding.
                $this->assertLessThanOrEqual($width + 4, Width::string($line), "{$state}: line {$i} at width {$width}");
                $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "{$state}: line {$i} cut a character");
            }
        }
    }

    public function testTheColumnsDropRightToLeftAsTheBoxNarrows(): void
    {
        $row = function (int $width): string {
            $lines = explode("\n", self::plain($this->picker()->render($width, 24, Theme::byName('dark'))));
            foreach ($lines as $line) {
                if (str_contains($line, 'Fix flaky')) {
                    return $line;
                }
            }

            return '';
        };

        $this->assertStringNotContainsString('1h', $row(40), 'no time column below 44');
        $this->assertStringContainsString('1h', $row(50));
        $this->assertStringNotContainsString('12 turns', $row(50), 'no turns below 56');
        $this->assertStringContainsString('12 turns', $row(56));
        $this->assertStringNotContainsString('+1 ag', $row(56), 'no badge below 60');
        $this->assertStringContainsString('+1 ag', $row(60));
        $this->assertStringNotContainsString('sglang', $row(60), 'no model below 68');
        $this->assertStringContainsString('sglang', $row(68));
    }

    public function testTheFooterShowsTheLastPromptNotTheSystemPrompt(): void
    {
        $footer = array_reverse(explode("\n", self::plain($this->picker()->render(80, 24, Theme::byName('dark')))))[0];

        $this->assertSame('  /work/app · main · "make the login controller use the new guard"', $footer);
    }

    private static function plain(string $rendered): string
    {
        return (string) preg_replace('/\x1b\[[0-9;:]*[A-Za-z]/', '', $rendered);
    }

    private function assertGolden(string $name, string $actual): void
    {
        $path = self::GOLDEN_DIR . '/' . $name;
        if (getenv('UPDATE_GOLDENS') === '1') {
            if (!is_dir(self::GOLDEN_DIR)) {
                mkdir(self::GOLDEN_DIR, 0o755, true);
            }
            file_put_contents($path, $actual . "\n");
        }

        $this->assertFileExists($path, "no golden at {$path}; run with UPDATE_GOLDENS=1 to create it");
        $this->assertSame((string) file_get_contents($path), $actual . "\n", "layout drifted from {$name}");
    }
}
