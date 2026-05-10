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

    // PixelGrid
    'pixel_grid.alloc_failed'  => 'GD failed to allocate a resize buffer',
    'pixel_grid.decode_failed' => 'GD failed to decode image bytes',

    // Renderer (generic)
    'renderer.invalid_width'  => 'Width must be positive, got {width}',
    'renderer.invalid_height' => 'Height must be positive, got {height}',
    'renderer.gd_load_failed' => 'GD failed to load image',

    // Chafa
    'chafa.command_failed' => 'Chafa command failed: {error}',
    'chafa.not_found'      => 'Chafa command not found. Install with: sudo apt install chafa',
];
