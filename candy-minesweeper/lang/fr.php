<?php

/**
 * French translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'plateau trop petit',
    'board.minecount_out_of_range' => 'nombre de mines hors plage',
    'board.rows_shape_mismatch' => 'les données de la grille ne correspondent pas au plateau {width}×{height}',
    'custom.min_rows' => 'les lignes doivent être au moins 2',
    'custom.min_cols' => 'les colonnes doivent être au moins 2',
    'custom.max_rows' => 'les lignes doivent être au plus 50',
    'custom.max_cols' => 'les colonnes doivent être au plus 50',
    'custom.min_mines' => 'au moins 1 mine est requise',
    'custom.max_mines' => 'trop de mines — laissez une zone de départ sûre',
    'custom.min_area'            => 'une grille personnalisée exige au moins 10 cases (lignes × colonnes)',
    'custom.label' => '{width}×{height} · {mines} mines',
    'ui.status' => 'mines : {mines}  ·  drapeaux : {flags}  ·  restants : {remaining}  ·  temps : {time}',
    'ui.status_stats' => '{preset}  ·  drapeaux : {flags}  ·  restants : {remaining}  ·  temps : {time}  ·  record : {best}',
    'ui.preset_easy' => 'facile',
    'ui.preset_standard' => 'standard',
    'ui.preset_medium' => 'moyen',
    'ui.preset_expert' => 'expert',
    'ui.boom' => '💥 boum — appuyez sur r pour recommencer',
    'ui.cleared' => '★ gagné — appuyez sur r pour rejouer',
    'ui.help_rules' => 'un chiffre = mines voisines · drapeau si suspect · ouvrez tout pour gagner',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  déplacer  ·  espace / entrée  révéler',
    'ui.help_board' => 'clic gauche  ouvrir · f / clic droit  drapeau · c / milieu  ouvrir autour',
    'ui.help_loss' => '{mark} drapeau faux · * mine manquée',
    'ui.help_session' => 'r  rejouer  ·  S  sauvegarder  ·  L  charger  ·  q / Esc / Ctrl+C  quitter',
    'ui.saved' => 'Partie sauvegardée',
    'ui.restarted' => 'Plateau relancé',
    'ui.loaded' => 'Sauvegarde chargée',
    'ui.no_save' => 'Aucune sauvegarde à charger',
    'ui.load_refused' => 'Sauvegarde refusée (illisible ou altérée)',
    'ui.save_failed' => 'Échec de la sauvegarde',
    'ui.chord_not_ready' => 'le groupement exige des drapeaux égalant le chiffre',
    'ui.confirm_quit' => 'appuyez encore sur q pour quitter — la partie est non sauvegardée',
    'ui.resume' => 'Une sauvegarde attend — appuyez sur L pour la charger',
    'ui.new_best' => 'NOUVEAU RECORD',
    'ui.beat_best' => '{prev} → NOUVEAU RECORD',
    'ui.fit_notice' => 'candy-minesweeper nécessite un terminal de {need} lignes ; celui-ci en a {have}. Agrandissez la fenêtre — la partie reprend dès qu’elle tient. q pour quitter.',
];
