<?php

declare(strict_types=1);

return [
    // Input validation
    'input.invalid_arrow' => 'Invalid arrow direction: {dir}',

    // Golden file operations
    'golden.write_failed' => 'Failed to write golden file: {path}',
    'golden.path_traversal' => 'Golden path escapes the fixtures directory: {path}',

    // Tape recorder operations
    'tape.write_failed' => 'Failed to write tape file: {path}',

    // ProgramSimulator errors
    'simulator.no_model_property' => 'Program has no model property to extract',
    'simulator.cmd_loop_overflow' => 'Cmd message loop exceeded {max} cycles',

    // Graphics decoders (SugarCraft\Testing\Graphics)
    'graphics.detect.unknown_protocol' => 'Stream does not introduce a recognised graphics protocol (Sixel/Kitty/iTerm2)',
    'graphics.gd.unavailable' => 'The GD extension is required to materialise this image',
    'graphics.payload_not_image' => 'The decoded payload is not a readable image',

    'graphics.sixel.missing_dcs_header' => 'Sixel stream is missing its DCS header (ESC P)',
    'graphics.sixel.unterminated' => 'Sixel stream is missing its ST terminator (ESC \\)',
    'graphics.sixel.missing_introducer_q' => 'Sixel DCS is missing the "q" color-space introducer',
    'graphics.sixel.missing_raster_declaration' => 'Sixel stream is missing the " raster size declaration',
    'graphics.sixel.bad_raster' => 'Sixel raster declaration has a non-positive width or height',
    'graphics.sixel.bad_color_introducer' => 'Sixel color introducer (#) is malformed (bad register number or truncated color)',
    'graphics.sixel.unknown_color_space' => 'Sixel color space model {model} is not supported (expected RGB)',
    'graphics.sixel.undefined_color' => 'Sixel selects undefined color register {index}',
    'graphics.sixel.rle_missing_count' => 'Sixel repeat introducer (!) is missing its repeat count',
    'graphics.sixel.rle_missing_data' => 'Sixel repeat introducer (!) is missing its data character',
    'graphics.sixel.unexpected_char' => 'Sixel stream has unexpected character "{char}"',
    'graphics.sixel.color_not_selected' => 'Sixel data appeared before a color register was selected',
    'graphics.sixel.rle_oversize' => 'Sixel run exceeds the declared raster width of {width}',
    'graphics.sixel.raster_overflow' => 'Sixel band overruns the declared raster height of {height}',
    'graphics.sixel.color_component_out_of_range' => 'Sixel color component {value} exceeds the 0..100 percent range',

    'graphics.kitty.missing_begin' => 'Kitty stream has no graphics transmit (ESC P q or ESC _ G)',
    'graphics.kitty.unterminated_begin' => 'Kitty transmit header is not closed by ST (ESC \\)',
    'graphics.kitty.missing_end' => 'Kitty transmit is missing its m=0 end marker',
    'graphics.kitty.bad_chunk' => 'Kitty data chunk is malformed (expected m=<0|1>,<base64>)',
    'graphics.kitty.invalid_base64' => 'Kitty payload is not valid base64',
    'graphics.kitty.decompress_failed' => 'Kitty zlib payload failed to inflate',
    'graphics.kitty.no_payload' => 'Kitty image {id} carries no payload data',
    'graphics.kitty.payload_not_image' => 'Kitty payload does not decode to a readable image',
    'graphics.kitty.unterminated_apc' => 'Kitty APC transmit is not closed by ST or BEL',
    'graphics.kitty.bad_parameter' => 'Kitty control parameter "{token}" is not a key=value pair',

    'graphics.iterm2.missing_osc' => 'iTerm2 stream is missing its OSC 1337 introducer',
    'graphics.iterm2.unterminated' => 'iTerm2 sequence is missing its BEL or ST terminator',
    'graphics.iterm2.missing_separator' => 'iTerm2 File= sequence is missing the ":" data separator',
    'graphics.iterm2.invalid_base64' => 'iTerm2 payload is not valid base64',
    'graphics.iterm2.no_payload' => 'iTerm2 sequence carries no image payload data',
    'graphics.iterm2.payload_not_image' => 'iTerm2 payload does not decode to a readable image',
    'graphics.iterm2.bad_parameter' => 'iTerm2 argument "{token}" is not a key=value pair',
];
