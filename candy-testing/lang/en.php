<?php

declare(strict_types=1);

return [
    // Input validation
    'input.invalid_arrow' => 'Invalid arrow direction: {dir}',

    // Golden file operations
    'golden.write_failed' => 'Failed to write golden file: {path}',

    // Tape recorder operations
    'tape.write_failed' => 'Failed to write tape file: {path}',

    // ProgramSimulator errors
    'simulator.no_model_property' => 'Program has no model property to extract',
    'simulator.cmd_loop_overflow' => 'Cmd message loop exceeded {max} cycles',
];
