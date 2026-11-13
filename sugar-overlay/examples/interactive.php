<?php

declare(strict_types=1);

/**
 * sugar-overlay interactive demo — an animated reveal, then a scripted
 * mouse dismiss verdict, with no event loop (sugar-overlay-069).
 *
 * Time is SIMULATED: a fixed progress array is pumped through animate(),
 * one frame per step, and the session's full-frame/diff behaviour prints
 * alongside. Input is SIMULATED too: one crafted MouseMsg is fed through
 * scan() + hit()/isClickOutside() and the dismissal verdict is printed.
 * Nothing here waits on a terminal — the same shape runs under VHS and in
 * CI (offline, bounded, exit 0).
 *
 * Run: php examples/interactive.php
 * (after `composer install` in sugar-overlay/ — vendor/ is gitignored,
 * so a cold clone's require above fatals until dependencies exist)
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Overlay\Animation\AnimationKind;
use SugarCraft\Overlay\{Position, Veil};

$background = implode("\n", [
    str_repeat('~', 60),
    'function main() {                                      ',
    '    run();                                              ',
    '    log("ready");                                       ',
    str_repeat('~', 60),
]);

$veil = Veil::new()
    ->withBackdrop(50)
    ->withAnimation(AnimationKind::FADE)
    ->withClickOutsideDismiss(true);

// The button is marked BEFORE compositing: its zone sentinels ride the
// frame to the wire, where scan() can find them again.
$dialog = implode("\n", [
    '┌────────────┐',
    '│  Deploy?   │',
    '│  [ OK ]    │',
    '└────────────┘',
]);
$marked = str_replace('[ OK ]', $veil->mark('ok-button', '[ OK ]'), $dialog);

// ─── Animated reveal: scripted ticks through animate() ────────────────────
$frame = '';
foreach ([0.0, 0.2, 0.4, 0.6, 0.8, 1.0] as $progress) {
    // Label honestly from the session, not the loop index: whatever the
    // veil has not remembered yet must be emitted whole.
    $kind = $veil->lastFullFrame() === null ? 'full frame' : 'diff';
    $frame = $veil->animate($marked, $background, Position::CENTER, Position::CENTER, $progress);
    echo $frame;
    printf(
        "\nprogress %.1f -> %s (%d bytes)\n",
        $progress,
        $kind,
        strlen($frame),
    );
}

// ─── Settle and scan: the diff carries no zone envelopes, the full frame does
$settledVeil = $veil->withoutSession();   // fresh session: emits the settled frame whole
$scanned = $settledVeil->scan(
    $settledVeil->animate($marked, $background, Position::CENTER, Position::CENTER, 1.0),
);
$rect = $scanned->lastZoneRect('ok-button');
printf(
    "zone 'ok-button' painted at col %d, row %d, %d x %d\n",
    $rect['x'],
    $rect['y'],
    $rect['w'],
    $rect['h'],
);

// ─── Inside click: resolves the button, dismissal stays silent ─────────────
$inside = new MouseMsg(
    $rect['x'] + intdiv($rect['w'], 2),
    $rect['y'] + intdiv($rect['h'], 2),
    MouseButton::Left,
    MouseAction::Press,
);
$hit = $scanned->hit($inside->x, $inside->y);
printf(
    "click at (%d, %d) -> %s, isClickOutside=%s\n",
    $inside->x,
    $inside->y,
    $hit?->id ?? 'no zone',
    $scanned->isClickOutside($inside) ? 'DISMISS' : 'stay',
);

// ─── Outside click: nothing under the cursor, dismissal fires ──────────────
$outside = new MouseMsg(1, 1, MouseButton::Left, MouseAction::Press);
$miss = $scanned->hit($outside->x, $outside->y);
printf(
    "click at (%d, %d) -> %s, isClickOutside=%s\n",
    $outside->x,
    $outside->y,
    $miss?->id ?? 'no zone',
    $scanned->isClickOutside($outside) ? 'DISMISS' : 'stay',
);

echo "a host takes the DISMISS verdict and closes the modal; the zone id does the rest.\n";
