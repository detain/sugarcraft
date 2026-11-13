<?php

// A scrollable log: 60 rows into a 6-row viewport, driven KEYBOARD-FIRST.
// A keyboard path exists for every mouse gesture: Home/End mirror
// gotoTop()/gotoBottom(), PageUp/PageDown mirror the page moves, plain
// up/down arrows mirror the wheel — an operator on a serial console
// (or a screen reader's key echo) navigates the same states a mouse
// user reaches. Mouse wheel support rides sugar-core Msg objects, and
// update() answers the next state — it mutates nothing.
// The same Msg objects serve a headless test: drive update() and assert
// the yOffset you expect, no terminal required.

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Flexbox\Viewport;

$log = implode("\n", array_map(static fn (int $n): string => sprintf('log line %02d', $n), range(1, 60)));

// Keyboard-driven: the app reads a key, dispatches the matching Msg,
// and takes the new viewport + optional command from update(). At rest
// (smooth scroll off, the default) navigation arms no command.
$vp = Viewport::fromContent($log, 22, 6);
echo "home (arrows, PageUp/PageDown and Home/End all drive update()):\n";
echo $vp->view(), PHP_EOL;

[$secondPage, $cmd] = $vp->update(new KeyMsg(KeyType::PageDown));
assert($cmd === null, 'at rest, navigation arms no command');
echo "\nPageDown:\n", $secondPage->view(), PHP_EOL;

[$end, $cmd] = $secondPage->update(new KeyMsg(KeyType::End));
echo "\nEnd:\n", $end->view(), PHP_EOL;

// Mouse parity: one wheel notch down moves exactly the configured
// delta (3 lines by default) — both input paths address the same
// offsets, and every move has an Undo: scroll back with the opposite
// wheel notch or the opposite arrow key.
[$wheeled, $cmd] = $vp->update(new MouseWheelMsg(0, 0, MouseButton::WheelDown, MouseAction::Press));
echo "\nwheel down (delta 3): yOffset {$vp->yOffset()} -> {$wheeled->yOffset()}\n";
echo $wheeled->view(), PHP_EOL;
