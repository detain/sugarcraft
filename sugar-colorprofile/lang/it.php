<?php

/**
 * Italian translations for sugar-colorprofile.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'standard.ansi16_out_of_range' => 'L\'indice del colore ANSI 16 deve essere 0–15, ottenuto: {index}',
    'standard.ansi256_out_of_range' => 'L\'indice del colore ANSI 256 deve essere 0–255, ottenuto: {index}',
    'color.invalid_hex' => 'colore esadecimale non valido: {hex}',
    'colormath.invalid_xyz' => 'La componente XYZ {key} è mancante o non è un numero finito',
    'deltae.invalid_lab' => 'La matrice Lab {label} non contiene la componente numerica "{key}"',
    'deltae.non_finite_lab' => 'La matrice Lab {label} ha una componente "{key}" non finita',
    'distance.euclidean_needs_rgb' => 'la metrica euclidea confronta byte RGB — usa between() invece di betweenLab()',
    'nearest.empty_palette' => 'impossibile abbinare un colore a una tavolozza vuota',
    'probe.unknown_capability' => 'chiave/i di capacità sconosciuta/e: {keys}',
    'nearest.error_shape' => 'withError() prevede una lista di 3 elementi [r, g, b]; ricevuti {count} elemento/i',
    'nearest.error_channel' => 'i canali dell\'accumulatore di withError() devono essere int o float; ricevuto {type}',
    'palette_index.metric' => 'PaletteIndex richiede una metrica che preservi le distanze (Euclidean o Cie76); {metric} non ha una base PCA fissa - usare NearestColor::closest() in alternativa',
];
