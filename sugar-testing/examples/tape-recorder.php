<?php

declare(strict_types=1);

/**
 * TapeRecorder — build a VHS `.tape` script and show the rendered lines.
 *
 *   php examples/tape-recorder.php
 *
 * The generated tape is saved to a temporary path and printed. Note the
 * escaped second Type line: control bytes inside typed text are
 * neutralized so they can never forge a standalone directive line — a
 * raw "\nSet FontSize 4242" payload would otherwise be executed by the
 * CI GIF renderer.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Testing\Tape\TapeRecorder;

$tapePath = sys_get_temp_dir() . '/sugar-testing-tape-recorder-demo.tape';

$recorder = TapeRecorder::to($tapePath)
    ->header(theme: 'TokyoNight')
    ->type('php examples/tape-recorder.php')
    ->enter()
    ->sleep(1)
    ->type("q\nSet FontSize 4242") // hostile payload — stays inside the quotes
    ->comment('done — every line is a directive or a comment')
    ->line('Enter');

$recorder->save();

echo "=== Rendered tape at {$tapePath} ===\n\n";
echo file_get_contents($tapePath);
