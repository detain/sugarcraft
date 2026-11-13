<?php

/**
 * French translations for sugar-core.
 *
 * Keys are flat dot-paths under the `core.*` namespace registered by
 * {@see \SugarCraft\Core\Lang}. Values may use `{name}` placeholders that
 * are substituted at lookup time by {@see \SugarCraft\Core\I18n\T::translate()}.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Util/Color.php
    'color.rgb_out_of_range'      => 'composant RVB hors plage [0,255] : {value}',
    'color.invalid_hex'           => 'couleur hex invalide : {hex}',
    'color.ansi_out_of_range'     => 'index ansi hors plage [0,15] : {index}',
    'color.ansi256_out_of_range'  => 'index ansi256 hors plage [0,255] : {index}',

    // Util/Ansi.php
    'ansi.invalid_fg_code'        => 'code couleur 16 couleurs fg invalide : {code}',
    'ansi.invalid_bg_code'        => 'code couleur 16 couleurs bg invalide : {code}',
    'ansi.component_out_of_range' => '{label} hors plage [0,255] : {value}',

    // Program.php
    'program.proc_open_failed'    => 'proc_open a échoué pour : {cmd}',
    'color.parse_empty' => 'impossible d\'analyser un nom de couleur vide',
    'color.parse_unknown' => 'nom de couleur inconnu : {name}',
    'ansi.invalid_scs_slot' => 'emplacement SCS invalide : {slot} (attendu 0-3 pour G0-G3)',
    'ansi.invalid_scs_designator' => 'sélecteur SCS invalide : {designator} (un octet attendu entre 0x30 et 0x7E)',
    'ansi.invalid_sixel_repeat' => 'le nombre de répétitions sixel ne doit pas être négatif : {count}',
    'semaphore.limit_invalid' => 'la limite du sémaphore doit être au moins 1, reçue {limit}',
    'semaphore.limit_busy' => 'sémaphore occupé : {held} en cours, {waiting} en attente',
    'semaphore.shutdown' => 'sémaphore fermé avant qu\'un permis puisse être accordé',
    'semaphore.closed' => 'le sémaphore est fermé et ne peut plus accorder de permis',
    'semaphore.derive_closed' => 'impossible de dériver un nouveau pool d\'un sémaphore fermé',
    'lru_map.capacity_invalid' => 'la capacité LRU doit être au moins 1, reçue {capacity}',
    'lru_map.capacity_too_large' => 'la capacité LRU {capacity} dépasse la garde de croissance {max}',
    'lru_map.evict_from_empty' => 'impossible d\'évincer depuis une carte LRU vide',
    'errors.negative_not_allowed' => 'valeur négative non autorisée pour {name}',
    'errors.value_must_be_positive' => '{name} doit être positif, reçue {value}',
    'errors.value_out_of_range' => '{name} doit être entre {min} et {max}, reçue {value}',

    // InputReader.php
    'input.incomplete_overflow' => 'incomplete terminal escape sequence exceeded the {limit}-byte ceiling and was discarded',
    'program.exec_deadline_exceeded' => 'exec `{cmd}` exceeded the {seconds}s capture deadline ({bytes} bytes captured) and was terminated',
    'program.exec_select_unsupported' => 'exec capture cannot stream-select these pipes on this platform; stdout and stderr were read sequentially with blocking reads',
];
