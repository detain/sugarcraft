<?php

declare(strict_types=1);

/**
 * EscapeDecoder micro-demo — types, keystrokes, forged frames, and drains.
 *
 *   php examples/decode.php
 *
 * Every frame below is written with explicit hex escapes so the file is
 * immune to editor/terminal mangling of raw control bytes.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\ResizeEvent;
use SugarCraft\Input\Event\TerminalReplyEvent;
use SugarCraft\Input\EscapeDecoder;

$decoder = new EscapeDecoder();

$frames = [
    'literal keys'            => "ls\r",
    'legacy arrow (CSI A)'    => "\x1b[A",
    'Kitty ArrowUp (57352)'   => "\x1b[57352;1u",
    'Kitty ArrowUp release'   => "\x1b[57352;1:3u",
    'modifyOtherKeys Ctrl+A'  => "\x1b[27;5;97~",
    'SGR 1016 press (pixels)' => "\x1b[<0;640:10;480:7M",
    'forged mouse frame'      => "\x1b[<0;x;yM",
    'device-attributes reply' => "\x1b[?62;1c",
    'focus gained'            => "\x1b[I",
    'bracketed paste'         => "\x1b[200~echo hi\n\x1b[201~",
];

foreach ($frames as $label => $bytes) {
    echo str_pad($label, 26), ' | ', addcslashes($bytes, "\x00..\x1f\x7f..\xff"), "\n";

    foreach ($decoder->decode($bytes) as $event) {
        echo '  -> ';

        match (true) {
            $event instanceof KeyEvent => printf(
                "key %s %s%s (raw %s)\n",
                $event->isRelease() ? '[release]' : '        ',
                $event->key,
                $event->modifiers->value() === 0 ? '' : '+' . $event->modifiers->value(),
                addcslashes($event->raw, "\x00..\x1f\x7f..\xff"),
            ),
            $event instanceof MouseEvent => printf(
                "mouse %s button=%d at (%d,%d)\n",
                $event->action,
                $event->button,
                $event->x,
                $event->y,
            ),
            $event instanceof TerminalReplyEvent => printf(
                "reply drained family=%s params=%s\n",
                $event->family,
                json_encode($event->params),
            ),
            $event instanceof FocusEvent => printf(
                "focus %s (raw %s)\n",
                $event->gained ? 'gained' : 'lost',
                addcslashes((string) $event->raw, "\x00..\x1f\x7f..\xff"),
            ),
            $event instanceof PasteEvent => printf(
                "paste %d bytes%s\n",
                strlen($event->content),
                $event->truncated ? ' (TRUNCATED)' : '',
            ),
            $event instanceof ResizeEvent => printf(
                "resize %dx%d\n",
                $event->cols,
                $event->rows,
            ),
            default => print "event\n",
        };
    }

    echo "\n";
}

printf(
    "counters: drained replies=%d  dropped unknown=%d  discarded paste bytes=%d\n",
    $decoder->drainedReplyCount(),
    $decoder->droppedUnknownCount(),
    $decoder->discardedPasteBytes(),
);
