<?php

declare(strict_types=1);

namespace SugarCraft\Query\Terminal;

use SugarCraft\Core\Util\Color;
use SugarCraft\Kit\Frame;
use SugarCraft\Query\App;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Pane;
use SugarCraft\Sprinkles\Style;

/**
 * Wraps TUI content in a full terminal frame with title bar and status bar.
 *
 * The generic full-screen chrome — the double-line box, the exact-height
 * normalisation, ANSI-width-aware padding, and the constant line count the
 * frame-diff renderer relies on — lives in {@see Frame} (candy-kit). This class
 * is the candy-query-specific adapter: it measures the terminal, builds the
 * app's title bar (name / table count / dsn / server version) and status bar
 * (context-sensitive keybar), and hands them to Frame.
 *
 * @internal Renderer consumer only — not part of the public sugar-query API.
 */
final class BorderFrame
{
    /**
     * Wrap content in a full terminal frame sized to the current terminal.
     */
    public static function wrap(App $a, string $content): string
    {
        // One size lookup so the frame matches the dimensions the content was
        // rendered at; fall back to the Renderer's modern-terminal default.
        try {
            $size = \SugarCraft\Query\Renderer::getTerminalSize();
            $width = $size['cols'];
            $height = $size['rows'];
        } catch (\Throwable) {
            $width = 200;
            $height = 60;
        }

        return Frame::new()
            ->withTitle(self::buildTitleBar($a))
            ->withStatus(self::buildStatusBar($a))
            ->render($content, $width, $height);
    }

    /**
     * Build the title bar content.
     *
     * Shows: SugarSQL │ Tables: N │ dsn │ version. Only non-empty values are
     * included.
     */
    private static function buildTitleBar(App $a): string
    {
        $titleStyle = Style::new()->bold()->foreground(Color::ansi(6)); // bold cyan
        $infoStyle = Style::new()->foreground(Color::hex('#cbd5e3'));   // light slate
        $sepStyle = Style::new()->foreground(Color::hex('#64748b'));    // slate

        $parts = [];

        // App name in bold cyan.
        $parts[] = $titleStyle->render('SugarSQL');

        // Table count.
        $parts[] = 'Tables: ' . count($a->tables);

        // Connection info (dsn) — only if non-empty.
        $dsn = $a->db->dsn();
        if ($dsn !== '') {
            $parts[] = $infoStyle->render($dsn);
        }

        // Server version from serverContext, or flavor fallback.
        $version = self::serverVersion($a);
        if ($version !== '') {
            $parts[] = $infoStyle->render($version);
        }

        return implode($sepStyle->render(' │ '), $parts);
    }

    /**
     * Build the status bar content with context-sensitive keyboard shortcuts.
     */
    private static function buildStatusBar(App $a): string
    {
        $segments = ['Tab:cycle', '↑↓:navigate'];

        if ($a->pane === Pane::Tables || $a->pane === Pane::Rows) {
            $segments[] = 'Enter:load';
        }
        if ($a->pane === Pane::Query) {
            $segments[] = 'Ctrl+R:run';
        }
        if ($a->pane === Pane::Admin) {
            $segments[] = '1-6:select';
            $segments[] = 'j/k:nav';
            $segments[] = '[admin:' . $a->adminPane->value . ']';
        }
        $segments[] = 'q:quit';

        $status = implode('  ', $segments);

        // Show PAUSED indicator when the admin dashboard is paused.
        if ($a->pane === Pane::Admin && $a->paused) {
            $status .= '  ' . Style::new()->bold()->foreground(Color::ansi(6))->render('[PAUSED]');
        }

        return $status;
    }

    /**
     * Get server version string from App.
     */
    private static function serverVersion(App $a): string
    {
        if ($a->serverContext !== null) {
            return $a->serverContext->versionString();
        }

        // Fallback: show flavor for non-SQLite databases.
        if ($a->flavor !== Flavor::Sqlite) {
            return $a->flavor->value;
        }

        return '';
    }
}
