<?php

/**
 * Dutch translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'ANSI 16-kleurenindex moet 0–15 zijn, gekregen: {index}',
    'standard.ansi256_out_of_range' => 'ANSI 256-kleurenindex moet 0–255 zijn, gekregen: {index}',
    'color.invalid_hex' => 'ongeldige hex-kleur: {hex}',
    'colormath.invalid_xyz' => 'XYZ-component {key} ontbreekt of is geen eindig getal',
    'deltae.invalid_lab' => 'De Lab-matrix {label} mist de numerieke component "{key}"',
    'deltae.non_finite_lab' => 'De Lab-matrix {label} heeft een niet-eindige component "{key}"',
    'distance.euclidean_needs_rgb' => 'de Euclidische metriek vergelijkt RGB-bytes — gebruik between() in plaats van betweenLab()',
    'nearest.empty_palette' => 'kan een kleur niet laten overeenkomen met een lege palet',
    'probe.unknown_capability' => 'onbekende capability-sleutels: {keys}',
    'nearest.error_shape' => 'withError() verwacht een lijst van 3 elementen [r, g, b]; {count} element(en) ontvangen',
    'nearest.error_channel' => 'withError()-accumulatorkanalen moeten int of float zijn; ontvangen: {type}',
    'palette_index.metric' => 'PaletteIndex vereist een afstandsbehoudende metriek (Euclidean of Cie76); {metric} heeft geen vaste PCA-basis - gebruik in plaats daarvan NearestColor::closest()',
    'stream.invalid_resource' => 'de {label}-stream moet een geldige bron zijn',
];
