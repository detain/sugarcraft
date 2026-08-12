<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\ToolCall;

/**
 * The shell's left sidebar list of files this session is working on
 * (crush_feat.md §5 E7, the MERGE branch — the pane layer is kept and fed
 * real data rather than retired).
 *
 * Two sources, in this order: the files explicitly attached to the shell
 * ({@see App::$contextFiles}), then the files the hosted {@see
 * \SugarCraft\Crush\Chat}'s transcript shows a tool actually touching.
 *
 * That second source is MODEL-authored (a tool call's `file_path` argument),
 * unlike the operator-supplied first one, so every path label crosses
 * {@see PaneLabel::of()} before reaching the terminal.
 */
final class FilesPane
{
    /** Rows the rounded border's own top and bottom edges cost. */
    private const CHROME_ROWS = 2;

    /** Cells the border (2) plus the horizontal padding (2) cost per row. */
    private const CHROME_COLS = 4;

    public static function render(App $a, int $width, int $rows): string
    {
        $files = self::recentFiles($a, max(1, $rows - self::CHROME_ROWS));

        if ($files === []) {
            $body = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('(no files attached)');
        } else {
            $lines = [];
            foreach ($files as $file) {
                $lines[] = Style::new()
                    ->foreground(Color::hex('#c5b6dd'))
                    ->render(Width::truncate(
                        '📄 ' . PaneLabel::of(basename($file)),
                        max(1, $width - self::CHROME_COLS),
                    ));
            }
            $body = implode("\n", $lines);
        }

        $st = Style::new()
            ->border(Border::rounded()->withTitle(' files '))
            ->padding(0, 1)
            ->width($width);

        $st = $a->pane === \SugarCraft\Crush\Tui\Pane::Files
            ? $st->borderForeground(Color::hex('#00ffaa'))
            : $st->borderForeground(Color::hex('#ff66aa'));

        return $st->render($body);
    }

    /**
     * At most $budget distinct paths, most-recently-touched FIRST.
     *
     * Both halves of that sentence are load-bearing, and an earlier attempt
     * at this pane got both wrong:
     *
     * - BOUNDED. The transcript walk stops the moment $budget distinct paths
     *   are collected, so its cost follows the pane's height, not the
     *   session's length. Emitting one row per file the transcript had EVER
     *   touched measured 83ms per FRAME — i.e. per keystroke — at 1000 files.
     *   For the same reason nothing here touches the filesystem: an `is_dir()`
     *   per recorded call per frame is a syscall storm, so a search ROOT is
     *   told apart from a file by the call's own argument shape instead (see
     *   {@see pathArgument()}).
     * - NEWEST FIRST. {@see \SugarCraft\Crush\Tui\Renderer} clips an over-tall
     *   sidebar with `clipHead()`, which keeps the TOP. Appending
     *   first-seen-first therefore froze the pane on the OLDEST files once a
     *   session passed the pane's height, never showing what it just touched.
     *
     * No "+N more" footer for the same reason: knowing the true total means
     * walking the whole history, which is exactly the walk this avoids.
     *
     * @return list<string>
     */
    private static function recentFiles(App $a, int $budget): array
    {
        $seen = [];

        foreach ($a->contextFiles as $file) {
            $path = (string) $file;
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            if (count($seen) >= $budget) {
                return array_keys($seen);
            }
        }

        $history = $a->chat?->history ?? [];
        for ($i = count($history) - 1; $i >= 0; $i--) {
            foreach (array_reverse($history[$i]->toolCalls) as $call) {
                if (!$call instanceof ToolCall) {
                    continue;
                }
                $path = self::pathArgument($call->arguments);
                if ($path === null || isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                if (count($seen) >= $budget) {
                    return array_keys($seen);
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * The file a tool call touched, or null when it touched none.
     *
     * `file_path` is what every file-shaped built-in ({@see
     * \SugarCraft\Crush\Tools\BuiltIn\Read}, {@see
     * \SugarCraft\Crush\Tools\BuiltIn\Edit}) names its target. A bare `path`
     * is accepted too, for tools using the shorter name — except on a
     * search-shaped call, where `path` is the DIRECTORY being searched rather
     * than a file: {@see \SugarCraft\Crush\Tools\BuiltIn\Glob} and {@see
     * \SugarCraft\Crush\Tools\BuiltIn\Grep} both require a `pattern` next to
     * it, so that key is the tell — and reading it costs nothing where
     * probing the path on disk would cost a syscall per frame.
     *
     * @param array<string, mixed> $arguments
     */
    private static function pathArgument(array $arguments): ?string
    {
        $filePath = $arguments['file_path'] ?? null;
        if (is_string($filePath) && $filePath !== '') {
            return $filePath;
        }

        if (isset($arguments['pattern'])) {
            return null;
        }

        $path = $arguments['path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
