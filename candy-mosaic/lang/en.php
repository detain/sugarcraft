<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Lang;

/**
 * English translations for candy-mosaic user-facing error messages.
 */
return [
    // ImageSource
    'image_source.file_not_found'    => 'File not found: {path}',
    'image_source.cannot_read'       => 'Cannot read file: {path}',
    'image_source.unsupported_format'=> 'Unsupported image format: {path}',
    'image_source.unsupported_mime'  => 'Unsupported MIME type: {mime}',
    'image_source.no_gd'             => 'ext-gd is required but is not available',
    'image_source.gd_load_failed'    => 'GD failed to load image: {path}',
    'image_source.temp_failed'       => 'Failed to create temporary file for in-memory image',
    'image_source.url_fetch_failed'  => 'Failed to fetch image from URL: {url}',
    'image_source.url_bad_status'    => 'Unexpected HTTP status {status} while fetching image from URL',
    'image_source.url_http_missing'  => 'Async URL loading requires react/http. Install it with: composer require react/http',
    'image_source.header_crlf'       => 'Request header names and values must not contain CR or LF characters',
    'image_source.url_invalid_scheme'=> 'URL scheme {scheme} is not in the allowed list: {allowed}',
    'image_source.too_large'         => 'Image dimensions {width}×{height} exceed the maximum of {max} pixels',
    'image_source.file_too_large'    => 'Image file is {size} bytes, exceeding the maximum of {max} bytes',
    'image_source.redirect_no_location' => 'Redirect response from {url} is missing a Location header',
    'image_source.too_many_redirects'   => 'Too many redirects while fetching image from URL: {url}',
    'image_source.url_host_blocked'     => 'URL host {host} resolves to a blocked private/reserved address ({ip})',
    'image_source.url_host_unresolved'  => 'URL host {host} could not be resolved to an IP address',
    'image_source.rgb_bad_dimensions'   => 'Raw RGB dimensions must be positive, got {width}×{height}',
    'image_source.rgb_size_mismatch'    => 'Raw RGB buffer is {actual} bytes; expected {expected}',
    'image_source.gd_alloc_failed'      => 'GD failed to allocate a raw-RGB image buffer',
    'image_source.gd_load_failed_from_string' => 'GD failed to decode image bytes',
    'image_source.crop_failed'          => 'GD failed to crop the image',
    'image_source.gd_create_failed'     => 'GD failed to create an image buffer',

    // DiskCache
    'disk_cache.max_entries'   => 'maxEntries must be >= 1, got {max}',
    'disk_cache.mkdir_failed'  => 'Failed to create cache directory: {dir}',
    'disk_cache.write_failed'  => 'Failed to write cache entry in: {dir}',

    // PixelGrid
    'pixel_grid.alloc_failed'  => 'GD failed to allocate a resize buffer',
    'pixel_grid.decode_failed' => 'GD failed to decode image bytes',

    // Renderer (generic)
    'renderer.invalid_width'  => 'Width must be positive, got {width}',
    'renderer.invalid_height' => 'Height must be positive, got {height}',
    'renderer.gd_load_failed' => 'GD failed to load image',
    'renderer.gd_resize_failed' => 'GD failed to resize the image',
    'renderer.gzcompress_failed' => 'gzcompress() failed — image data could not be compressed',

    // Chafa
    'chafa.command_failed' => 'Chafa command failed: {error}',
    'chafa.not_found'      => 'Chafa command not found. Install with: sudo apt install chafa',

    // Sixel
    'sixel.max_colors_out_of_range' => 'maxColors must be 1-256, got {maxColors}',

    // tmux passthrough
    'tmux.stream_not_sixel' => 'Band streaming is only available for the Sixel renderer, not {name}',

    // Animation
    'animation.empty'                 => 'Animation requires at least one frame',
    'animation.delay_count_mismatch'  => 'Frame count ({frameCount}) and delay count ({delayCount}) must match',
    'animation.index_out_of_range'    => 'Frame index {index} is out of range for this animation',
    'animation.too_many_frames'       => 'Animation exceeds the maximum of {max} frames (got {count})',
    'animation.frame_too_large'       => 'Animation frame {index} dimensions {width}×{height} exceed the maximum of {max} pixels',
    'animation.too_many_pixels'       => 'Animated image declares {frames} frames of {width}×{height} = {total} pixels, exceeding the maximum of {max}',
    'animation.unsupported_format'    => 'Animated loading supports GIF and APNG, not {format}',
    'animation.gif_frame_count_mismatch' => 'Animated GIF carries {expected} frames but the frame decoder returned {got}; refusing to emit a partial animation',
    'animation.gif_frame_layout_mismatch' => 'Animated GIF frame decoder disagrees with the container on {expected} frame positions; refusing to emit possibly-phantom frames',
    'animation.gif_decode_failed'     => 'Animated GIF could not be decoded: {reason}',
    'animation.gif_too_large_for_flip' => 'Animated GIF {width}×{height} exceeds the {max}-pixel frame-grid limit of the GIF decoder',
    'animation.gif_too_many_cells' => 'Animated GIF of {frames} {width}×{height} frames exceeds the {max}-cell budget of the GIF decoder',

    // APNG (pure-PHP frame walk)
    'apng.no_ihdr'           => 'APNG/PNG stream has no IHDR header',
    'apng.truncated'         => 'APNG stream is truncated or malformed',
    'apng.bad_acTL'          => 'APNG acTL chunk is malformed',
    'apng.bad_fcTL'          => 'APNG fcTL chunk is malformed',
    'apng.no_frames'         => 'APNG declares {frames} frames but none could be decoded',
    'apng.no_frame_data'     => 'APNG frame carries no image data',
    'apng.frame_count_mismatch' => 'APNG declares {declared} frames but carries {collected}',
    'apng.sequence'          => 'APNG frame control/data sequence numbers are out of order',
    'apng.unsupported_bit_depth'  => 'APNG bit depth {depth} is not supported (only 8)',
    'apng.unsupported_color_type' => 'APNG colour type {type} is not supported',
    'apng.unsupported_interlace'  => 'Interlaced (Adam7) APNG is not supported',
    'apng.unsupported_method'     => 'APNG uses an unsupported compression/filter method',
    'apng.unsupported_dispose'    => 'APNG fcTL uses reserved dispose_op {op}',
    'apng.unsupported_blend'      => 'APNG fcTL uses reserved blend_op {op}',
    'apng.no_plte'           => 'APNG uses palette colour type but has no PLTE chunk',
    'apng.bad_plte'          => 'APNG PLTE chunk is malformed ({bytes} bytes)',
    'apng.palette_index_out_of_range' => 'APNG palette index {index} is past the end of a {size}-entry palette',
    'apng.inflate_failed'    => 'APNG frame data could not be inflated',
    'apng.bad_frame_length'  => 'APNG frame inflated to {actual} bytes, expected exactly {expected}',
    'apng.unsupported_filter' => 'APNG scanline filter {type} is not one of 0..4',
    'apng.bad_chunk_type'    => 'APNG chunk type {type} is not four ASCII letters',
    'apng.bad_chunk_crc'     => 'APNG chunk {type} failed its CRC check',
    'apng.no_signature'      => 'Stream is not a PNG/APNG (bad 8-byte signature)',
    'apng.unsupported_dimensions' => 'APNG logical screen {width}x{height} is outside the supported 1..{max} range',
];
