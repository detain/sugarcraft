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
    'renderer.gzcompress_failed' => 'gzcompress() failed — image data could not be compressed',

    // Chafa
    'chafa.command_failed' => 'Chafa command failed: {error}',
    'chafa.not_found'      => 'Chafa command not found. Install with: sudo apt install chafa',

    // Sixel
    'sixel.max_colors_out_of_range' => 'maxColors must be 1-256, got {maxColors}',

    // Animation
    'animation.empty'                 => 'Animation requires at least one frame',
    'animation.delay_count_mismatch'  => 'Frame count ({frameCount}) and delay count ({delayCount}) must match',
    'animation.index_out_of_range'    => 'Frame index {index} is out of range for this animation',
];
