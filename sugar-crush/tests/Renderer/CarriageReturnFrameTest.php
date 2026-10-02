<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mosaic\Mosaic;

/**
 * Audit 15b-07: no raw carriage return may reach the frame from any row.
 *
 * `Sanitize::untrusted()` keeps CR by contract, but a CR on the terminal wire
 * is a cursor motion, not text: it returns the cursor to column 0 of the
 * current physical row, and the rest of that row overwrites the left pane and
 * the borders while the frame string still looks like one row to the diff
 * renderer. Progress bars (`git clone`, `npm install`) and CRLF files make
 * this the common case, not the hostile one.
 *
 * Every row kind that paints model-, tool- or user-authored text is driven
 * with `a\rb`, `visible\rHIDDEN` and `x\r\ny`. The frame must hold no CR, the
 * text on BOTH sides of it must stay visible (mapped, not dropped), and the
 * rows that are one click-zone line — tool name, description, running
 * placeholder, image notice — must stay one line.
 */
final class CarriageReturnFrameTest extends TestCase
{
    /** Distinctive bookends so "both halves visible" cannot pass by accident. */
    private const LEFT = 'qL7';
    private const RIGHT = 'zR9';

    /**
     * @return array<string, array{0: string}>
     */
    public static function payloads(): array
    {
        return [
            'lone CR' => ["a\rb"],
            'overwrite' => ["visible\rHIDDEN"],
            'CRLF' => ["x\r\ny"],
        ];
    }

    /**
     * @return array<string, array{0: \Closure(string): Chat, 1: ?string}>
     *         builder, and the marker of the row that must stay ONE line (null
     *         for rows that may legitimately break at the CR)
     */
    private static function rowKinds(): array
    {
        $chat = static fn (array $history): Chat => (new Chat(history: $history))->withSize(100, 40);

        return [
            'user' => [static fn (string $t): Chat => $chat([Message::user($t, 0)]), null],
            'system' => [static fn (string $t): Chat => $chat([Message::system($t, 0)]), null],
            'tool name' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::ok($t, 'ok', 'call_1')]),
            ]), '🔧 tool:'],
            'tool description' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([
                    ToolResult::ok('bash', 'ok', 'call_1')->withDescription($t),
                ]),
            ]), '🔧 tool:'],
            // A collapsed SUCCESS body is hidden behind "N lines hidden"; an
            // error body is always painted (clipped), so it is the collapsed case.
            'collapsed tool error' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::error('bash', $t, 'call_1')]),
            ]), null],
            // Ctrl+O on a Bash result with a progress bar — the everyday source.
            'expanded tool result' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::ok('bash', $t, 'call_1')]),
            ])->toggleToolOutput('call_1'), null],
            'expanded tool error' => [static fn (string $t): Chat => $chat([
                Message::assistant('')->withToolResults([ToolResult::error('bash', $t, 'call_1')]),
            ])->toggleToolOutput('call_1'), null],
            // Built directly rather than via Message::toolRunning(): that factory
            // already flattens to one line, and the renderer must not rely on it.
            'running tool placeholder' => [static fn (string $t): Chat => $chat([
                new Message(role: Role::System, content: $t, createdAt: 0, pendingToolCallId: 'call_1'),
            ]), 'running:'],
            'collapsed image notice' => [static fn (string $t): Chat => new Chat(
                history: [Message::assistant('')->withToolResults([
                    new ToolResult(name: 'Doctor', result: 'report', id: 'call_img', imageBytes: 'not-a-png', imageProtocol: $t),
                ])],
                rows: 40,
                cols: 100,
                mosaic: Mosaic::sixel(),
            ), 'image hidden'],
        ];
    }

    /**
     * @return array<string, array{0: \Closure(string): Chat, 1: ?string, 2: string}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (self::rowKinds() as $kind => [$build, $singleLine]) {
            foreach (self::payloads() as $name => [$payload]) {
                $out["{$kind} / {$name}"] = [$build, $singleLine, $payload];
            }
        }

        return $out;
    }

    /**
     * @param \Closure(string): Chat $build
     */
    #[DataProvider('cases')]
    public function testNoCarriageReturnReachesTheFrame(\Closure $build, ?string $singleLine, string $payload): void
    {
        $frame = Renderer::render($build(self::LEFT . $payload . self::RIGHT));

        $this->assertStringNotContainsString(
            "\r",
            $frame,
            'raw CR reached the frame near: ' . $this->around($frame, "\r"),
        );
    }

    /**
     * Guards the guard: the CR is MAPPED, so the text it would have painted
     * over is still on screen — and a row that is simply not rendered cannot
     * pass the CR assertion vacuously.
     *
     * @param \Closure(string): Chat $build
     */
    #[DataProvider('cases')]
    public function testTheTextOnBothSidesOfTheCarriageReturnStaysVisible(\Closure $build, ?string $singleLine, string $payload): void
    {
        $plain = Ansi::strip(Renderer::render($build(self::LEFT . $payload . self::RIGHT)));
        [$before, $after] = preg_split('/\r\n|\r/', $payload) ?: ['', ''];

        $this->assertStringContainsString(self::LEFT . $before, $plain);
        $this->assertStringContainsString($after . self::RIGHT, $plain);
    }

    /**
     * The tool row is a single-line click zone located by its recorded head,
     * so mapping CR to LF must not split it into two rows: the halves have to
     * share the row that carries the marker.
     *
     * @param \Closure(string): Chat $build
     */
    #[DataProvider('cases')]
    public function testSingleLineRowsStayOneLine(\Closure $build, ?string $singleLine, string $payload): void
    {
        if ($singleLine === null) {
            $this->addToAssertionCount(1);

            return;
        }

        $plain = Ansi::strip(Renderer::render($build(self::LEFT . $payload . self::RIGHT)));
        $rows = array_values(array_filter(
            explode("\n", $plain),
            static fn (string $row): bool => str_contains($row, $singleLine),
        ));
        [$before, $after] = preg_split('/\r\n|\r/', $payload) ?: ['', ''];

        $this->assertCount(1, $rows, "exactly one row carries '{$singleLine}'");
        $this->assertStringContainsString(self::LEFT . $before, $rows[0]);
        $this->assertStringContainsString($after . self::RIGHT, $rows[0]);
    }

    /**
     * The hosted shell frame (App + panes), where the overwrite is worst: the
     * CR would land the rest of the row on top of the Files pane to its left.
     */
    public function testTheHostedAppFrameCarriesNoCarriageReturn(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('stub');
        $chat = new Chat(history: [
            Message::user('visible' . "\r" . 'HIDDEN-OVERWRITE', 0),
            Message::system("note\r\nSPOOF", 0),
        ]);

        foreach ([[50, 16], [80, 16], [120, 16]] as [$cols, $rows]) {
            [$app] = App::new($provider, 'm')->withChat($chat)->update(new WindowSizeMsg($cols, $rows));
            $view = $app->view();
            $frame = \is_string($view) ? $view : $view->body;

            $this->assertStringNotContainsString("\r", $frame, "{$cols}x{$rows}: " . $this->around($frame, "\r"));
            $plain = Ansi::strip($frame);
            $this->assertStringContainsString('visible', $plain, "{$cols}x{$rows}");
            $this->assertStringContainsString('HIDDEN', $plain, "{$cols}x{$rows}");
            $this->assertStringContainsString('SPOOF', $plain, "{$cols}x{$rows}");
        }
    }

    private function around(string $haystack, string $needle): string
    {
        $at = strpos($haystack, $needle);

        return $at === false ? '' : bin2hex(substr($haystack, max(0, $at - 16), 40));
    }
}
