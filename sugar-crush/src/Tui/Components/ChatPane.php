<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Renderer as LiveRenderer;

/**
 * The shell's chat pane.
 *
 * Its body is NOT a second transcript renderer: when the {@see App} hosts a
 * {@see \SugarCraft\Crush\Chat}, this delegates to the live
 * {@see LiveRenderer} — the one that carries tool results, diffs, the Veil
 * permission modal, collapse/expand, images and the mouse zone pass — and
 * only supplies the surrounding box (crush_feat.md §5 E7, merge branch).
 * That is the whole point of the merge: the pane layer contributes layout,
 * `src/Renderer.php` contributes content, and neither reimplements the other.
 *
 * Layout contract with {@see \SugarCraft\Crush\Tui\Renderer}: `$cols`/`$rows`
 * are the pane's TOTAL width and height budget (border included) — the columns
 * the shell has left after its sidebars, not the terminal's — and the pane
 * renders exactly that box, so the shell can lay chrome around it without the
 * composed frame growing past the terminal. The hosted chat is re-sized to the
 * pane's INNER box first ({@see \SugarCraft\Crush\Chat::withSize()}) — the live
 * renderer fits its frame to `rows()`/`cols()`, so handing it the terminal size
 * while drawing it into a smaller box is what truncates content mid-glyph.
 *
 * Residual, pre-existing and NOT introduced here: the live renderer does not
 * hard-wrap an assistant turn's Markdown, so a turn whose rendered line is
 * wider than the box it is given still gets clipped — exactly as it is clipped
 * by the terminal edge when that same Chat runs standalone. Sizing the chat to
 * the pane is what stops the shell from *causing* that clip; making long text
 * reflow is a content-model change.
 *
 * The `$a->messages` fallback below stays for a chat-less `App` — the plain
 * engine-state object {@see \SugarCraft\Crush\Runtime} builds — which has a
 * message list but no content model to render it.
 */
final class ChatPane
{
    /**
     * Columns between the pane's left edge and its body's first cell: the
     * left border, then the left padding.
     *
     * Public because the shell needs it to translate mouse coordinates: the
     * hosted chat records its click zones against its own body, and
     * {@see \SugarCraft\Crush\Renderer::setZoneOrigin()} wants the body's
     * position on the terminal.
     */
    public const BODY_COL_INSET = 2;

    /** Rows between the pane's top edge and its body's first row: the top border. */
    public const BODY_ROW_INSET = 1;

    /** Border columns (2) + horizontal padding (2) the box spends around its content. */
    private const CHROME_COLS = self::BODY_COL_INSET * 2;

    /** Top + bottom border rows the box spends around its content. */
    private const CHROME_ROWS = self::BODY_ROW_INSET * 2;

    /**
     * The pane's text bytes only.
     *
     * Kept as the entry point for callers that compose the pane into something
     * else and have no {@see \SugarCraft\Core\Program} to paint an image layer
     * with; {@see renderView()} is what the shell itself uses.
     */
    public static function render(App $a, int $cols, int $rows): string
    {
        return self::renderView($a, $cols, $rows)[0];
    }

    /**
     * The pane's text bytes plus the hosted chat's pixel-graphics layer.
     *
     * The image layer has to ride out of here rather than being dropped:
     * {@see LiveRenderer::renderView()} leaves a Private-Use-Area marker cell in
     * the body for every image-bearing tool result (crush_feat.md §9 E3) and
     * only `Program::renderFrame()` — given the placements on a
     * {@see \SugarCraft\Core\View} — turns those markers back into painted
     * blobs and blank cells. Returning just the string would paint no image AND
     * leak the raw marker bytes into the terminal.
     *
     * Placements need no coordinate fix-up for the pane's position: markers are
     * resolved against the FINAL composed frame by
     * {@see \SugarCraft\Core\ImageOverlay::resolve()}, which derives each
     * paint's row/column from where the marker actually ended up.
     *
     * Mouse ZONES do, and the reasoning above does not carry over to them:
     * they are frozen at scan time, in the coordinate space of the body string
     * built here. {@see \SugarCraft\Crush\Tui\Renderer} is what closes that
     * gap — it knows where this pane landed and declares the body's origin via
     * {@see LiveRenderer::setZoneOrigin()} once the composite is final (hence
     * {@see BODY_COL_INSET}/{@see BODY_ROW_INSET} being public).
     *
     * @return array{0: string, 1: array<int, \SugarCraft\Core\ImagePlacement>}
     */
    public static function renderView(App $a, int $cols, int $rows): array
    {
        $width = max(20, $cols - self::CHROME_COLS);
        $innerRows = max(1, $rows - self::CHROME_ROWS);

        $images = [];
        $messages = $a->messages;
        if ($a->chat !== null) {
            $view = LiveRenderer::renderView($a->chat->withSize($width, $innerRows));
            $body = $view->body;
            $images = $view->images;
        } elseif ($messages === []) {
            $body = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('Welcome to SugarCrush! Start typing to chat...');
        } else {
            $lines = [];
            foreach ($messages as $msg) {
                $lines[] = self::formatMessage($msg);
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::normal()->withTitle(' chat '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Chat
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return [$st->render($body), $images];
    }

    private static function formatMessage(Message $msg): string
    {
        // Surface the concrete message type (e.g. UserMessage) so the
        // transcript shows provenance, not just the role string.
        $shortName = (new \ReflectionClass($msg))->getShortName();
        $tag = Style::new()->foreground(Color::hex('#7d6e98'))->render('[' . $shortName . ']');
        $role = Style::new()->bold()->foreground(Color::hex('#fde68a'))->render($msg->role() . ':');
        $content = Style::new()->foreground(Color::hex('#c5b6dd'))->render($msg->content());
        return "$tag $role $content";
    }
}
