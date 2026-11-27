<?php

/**
 * French translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => 'La largeur de colonne doit être >= 0',
    'tabs.bad_index'              => 'index d\'onglet hors plage',
    'tabs.neg_width'              => 'la largeur des onglets doit être >= 0',
    'tabs.neg_padding'            => 'la marge de l\'étiquette d\'onglet doit être >= 0',
    'tabs.zone_key_empty'         => 'la clé de zone d\'onglet ne doit pas être vide',
    'tabs.close_glyph_empty'      => 'le symbole de fermeture d\'onglet ne doit pas être vide',
    'tabs.scroll_mark_empty'      => 'la marque de défilement d\'onglet ne doit pas être vide',
    'table.dim_nonneg'            => 'la largeur/hauteur du tableau doit être >= 0',
    'table.page_size_nonneg'      => 'la taille de page doit être >= 0',
    'table.set_columns_type'      => 'les colonnes doivent être des instances de colonne',
    'table.sort_unknown_column'   => 'colonne de tri introuvable : {column}',
    'progress.width_nonneg'       => 'la largeur de la progression doit être >= 0',
    'tree.dim_nonneg'             => 'la largeur/hauteur de l\'arbre doit être >= 0',
    'help.width_nonneg'           => 'la largeur de l\'aide doit être >= 0',
    'stopwatch.interval_positive' => 'l\'intervalle du chronomètre doit être > 0',
    'timer.duration_nonneg'       => 'la durée du minuteur doit être >= 0',
    'timer.interval_positive'     => 'l\'intervalle du minuteur doit être > 0',
    'paginator.arabic_format_invalid' => 'le format arabe doit être un format valide de deux entiers',
    'progress.bad_percent_format' => 'le format de pourcentage doit être un format valide de un entier',
    'progress.bad_value_format' => 'le format de valeur doit être un format valide de deux entiers',
    'progress.rune_cell_width' => 'les caractères de la barre doivent mesurer exactement une cellule',
    'progress.color_func_type' => 'la fonction de couleur doit renvoyer une couleur',
    'tabs.next_tab' => 'onglet suivant',
    'tabs.prev_tab' => 'onglet précédent',
    'tabs.jump_to' => 'onglet {index}',
    'tabs.close_tab' => 'fermer l\'onglet',
    'timer.paused_tag' => 'en pause',
    'timer.timeout_tag' => 'délai expiré',
    'stopwatch.not_started_tag' => 'non démarré',
    'stopwatch.paused_tag' => 'en pause',
    'key.bad_binding' => 'liaison de touches invalide {key} : utilisez un nom de touche en minuscules (ctrl+c, shift+tab, pageup, f5) ou un caractère imprimable',
    'key.bad_binding_type' => 'les touches de la liaison doivent être des chaînes, reçu : {key}',
    'tree.use_branch_for_children' => 'utiliser la fabrique branch() pour un nœud avec des enfants',
    'button.pressed' => '{label} activé',
    'rule.length_nonneg' => 'la longueur de la règle doit être >= 0',
    'rule.bad_rune' => 'le caractère de la règle doit occuper exactement une cellule',

    // Help/HelpOverlay.php, Key/KeyView.php
    'help_overlay.height_positive' => 'la hauteur de la surcouche d’aide doit être >= 1',
    'key_view.bad_columns' => 'les colonnes de la vue des touches doivent être une liste de listes de noms d’action',
    'key_view.column_size_nonneg' => 'la taille de colonne de la vue des touches doit être >= 0',

    // Confirm/Confirm.php
    'confirm.word_empty' => 'le mot de confirmation à saisir ne doit pas être vide',
];
