<?php

declare(strict_types=1);

/**
 * How an `Edit`/`Write` result renders: a gutter-numbered unified diff.
 *
 * `Edit` and `Write` return the unified diff on {@see ToolResult}'s dedicated
 * `$diff` field rather than folded into `$result`, precisely so
 * {@see \SugarCraft\Crush\Renderer::renderDiff()} can paint it as a diff —
 * old/new line numbers in the gutter, +/- colouring, one logical line per
 * terminal row — instead of the renderer string-scanning free text for
 * something that looks diff-shaped. This seeds one such result directly, so
 * the demo shows the renderer without needing a model to decide to edit
 * anything.
 *
 * The diff is deliberately the shape that used to break the render
 * invariant: a hunk carrying a line longer than the frame. It clips rather
 * than wraps, because the diff renderer owes exactly one row per logical line.
 *
 * @see .vhs/diff.tape
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\ToolResult;

// Eight rendered rows — the three header lines plus five body lines, one of
// them deliberately wider than the frame.
//
// Size is load-bearing, not incidental: the tool block this diff hangs off is
// drawn header row → collapse indicator → diff, and the transcript is bottom-
// anchored. A hunk tall enough to fill the chat viewport on its own pushes the
// header and the `… 1 line hidden (ctrl+o)` indicator off the top, which makes
// the Ctrl+O beat in `.vhs/diff.tape` toggle a line nobody can see — the frames
// either side of it came out byte-identical. Keeping the hunk short enough that
// the WHOLE block fits is what makes the toggle observable.
//
// The `+` line is still longer than the diff box, because that is the shape
// that used to break the render invariant: it must CLIP, not wrap, since the
// diff renderer owes exactly one terminal row per logical line.
$diff = <<<'DIFF'
--- a/src/Tui/TerminalBackground.php
+++ b/src/Tui/TerminalBackground.php
@@ -95,4 +95,4 @@
     public static function isDark(?array $env = null): bool
     {
-        return self::$observed ?? self::detect($env);
+        return self::override($env ?? self::defaultEnv()) ?? self::$observed ?? self::detect($env);
     }
DIFF;

$chat = new Chat(
    history: [
        Message::user('make the SUGARCRUSH_BACKGROUND override outrank the OSC 11 answer'),
        Message::assistant('Lifting the override above the observed reply:'),
        Message::assistant('')->withToolResults([
            new ToolResult(
                name: 'Edit',
                result: 'Applied 1 hunk to src/Tui/TerminalBackground.php',
                id: 'call_demo_1',
                diff: $diff,
                durationMs: 12,
                description: 'edit(file_path: "src/Tui/TerminalBackground.php")',
            ),
        ]),
    ],
    themeName: 'adaptive',
);

(new Program(App::new(new EchoProvider(), 'echo')->withChat($chat), Chat::programOptions()))->run();
