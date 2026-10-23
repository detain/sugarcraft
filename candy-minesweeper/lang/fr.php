<?php

/**
 * French translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'plateau trop petit',
    'board.minecount_out_of_range' => 'mineCount hors plage',
    'board.rows_shape_mismatch' => 'la forme des lignes ne correspond pas à largeur/hauteur',
    'custom.min_rows' => 'les lignes doivent être au moins 2',
    'custom.min_cols' => 'les colonnes doivent être au moins 2',
    'custom.max_rows' => 'les lignes doivent être au plus 50',
    'custom.max_cols' => 'les colonnes doivent être au plus 50',
    'custom.min_mines' => 'au moins 1 mine est requise',
    'custom.max_mines' => 'trop de mines — laissez une zone de départ sûre',
    'ui.status' => 'mines : {mines}  ·  drapeaux : {flags}  ·  restants : {remaining}  ·  temps : {time}',
    'ui.boom' => '💥 boum — appuyez sur r pour recommencer',
    'ui.cleared' => '★ gagné — appuyez sur r pour rejouer',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  déplacer  ·  espace / entrée  révéler',
    'ui.help_board' => 'f  drapeau  ·  c / clic du milieu  accord  ·  clic droit  drapeau',
    'ui.help_session' => 'r  recommencer  ·  S  sauvegarder  ·  L  charger  ·  q / Esc / Ctrl+C  quitter',
];
