<?php

/**
 * French (fr) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'supprimer {names} ? (o/n)',
    'confirm.delete_permanent' => 'supprimer {names} ? DÉFINITIF (o/n)',
    'confirm.copy' => 'copier {names} vers {dest} ? (o/n)',
    'confirm.move' => 'déplacer {names} vers {dest} ? (o/n)',
    'confirm.rename' => 'renommer \'{name}\' en : ',
    'prompt.mkdir' => 'créer un répertoire : ',
    'confirm.selected_entries' => '{count} entrées sélectionnées',
    'confirm.affirmative' => 'o',
    'status.nothing_to_delete' => 'rien à supprimer',
    'status.nothing_to_copy' => 'rien à copier',
    'status.nothing_to_move' => 'rien à déplacer',
    'status.nothing_to_rename' => 'rien à renommer',
    'status.cancelled' => 'annulé',
    'status.refreshed' => 'rafraîchi',
    'status.unreadable' => 'lecture impossible de {dir} — affichage du contenu précédent',
    'status.copy_pending' => 'copie de {count} entrées…',
    'status.deleted' => '{count} entrées supprimées',
    'status.deleted_with_errors' => '{count} entrées supprimées, {errors} erreurs',
    'status.deleted_skipped' => '{count} entrées supprimées ({skipped} ignorées)',
    'status.deleted_permanent' => '{count} entrées supprimées ({permanent} définitives)',
    'status.copied' => '{count} entrées copiées',
    'status.copied_with_errors' => '{count} entrées copiées, {errors} erreurs',
    'status.copied_skipped' => '{count} entrées copiées ({skipped} ignorées)',
    'status.copied_overwrote' => '{count} entrées copiées ({overwrote} écrasées)',
    'status.moved' => '{count} entrées déplacées',
    'status.created' => '{count} dossiers créés',
    'status.mkdir_failed' => 'création impossible : {name}',
    'status.moved_with_errors' => '{count} entrées déplacées, {errors} erreurs',
    'status.moved_skipped' => '{count} entrées déplacées ({skipped} ignorées)',
    'status.moved_overwrote'    => '{count} entrées déplacées ({overwrote} écrasées)',
    'status.renamed' => '{old} renommé en {new}',
    'status.renamed_overwrote'  => '{old} renommé en {new} ({overwrote} écrasées)',
    'status.rename_failed' => 'échec du renommage : {name}',
    'status.rename_invalid'    => 'Renommage refusé : nom invalide {name}',
    'status.nothing_to_undo' => 'rien à annuler',
    'status.undone' => 'annulé : {description}',
    'status.undo_with_errors' => 'annulation de {description} avec {errors} erreur(s)',
    'status.undo_target_occupied' => 'annulation de {description} bloquée : {occupied} cible(s) occupée(s) — originaux conservés dans la corbeille',
    'status.cannot_close_last_tab' => 'Impossible de fermer le dernier onglet',
    'status.redone' => 'refait : {description}',
    'status.redo_with_errors' => 'réplication de {description} avec {errors} erreur(s)',
    'status.nothing_to_redo' => 'rien à refaire',
    'status.copy_undo_noop' => 'copie conservée (rien à annuler)',
    'status.focus' => 'focus : {side}',
    'side.left' => 'gauche',
    'side.right' => 'droite',
    'undo.delete' => 'suppression de {count} élément(s)',
    'undo.create' => 'création de {count} élément(s)',
    'undo.rename' => 'renommage de {count} élément(s)',
    'undo.move' => 'déplacement de {count} élément(s)',
    'undo.copy' => 'copie de {count} élément(s)',
    'keyhelp.default' => 'Tab permuter · ↑↓ jk déplacer · Home/End g/G sauter · Entrée ouvrir · ← h monter · Espace sélectionner · s trier · . cachés · c copier · m déplacer · R renommer · d supprimer · r rafraîchir · q quitter · / rechercher · Esc effacer · t dupliquer l’onglet · ^w fermer l’onglet · ^tab cycler · ^PgUp/^PgDn onglets · u/^z annuler · ^y rétablir',
    'keyhelp.terse' => 'Tab permuter · ↑↓ jk déplacer · Entrée ouvrir · ← h monter · Espace sélectionner · s trier · c copier · m déplacer · R renommer · d supprimer · q quitter · / rechercher · u/^z annuler · ^y rétablir',
    'keyhelp.minimal' => 'Entrée ouvrir · q quitter · / rechercher',
    'search.no_match' => '(aucune correspondance)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[RÉP]',
    'search.type_file' => '[FICHIER]',
    'pane.hidden_suffix' => '+cachés',
    'pane.unreadable' => '(illisible)',
    'pane.more_above' => '{count} de plus en haut',
    'pane.more_below' => '{count} de plus en bas',
    'status.opening_editor' => 'Édition de {name}…',
    'status.opening_pager' => 'Lecture de {name}…',
    'status.running' => 'Exécution de {name}…',
    'status.opened' => 'retour de {name}',
    'status.editor_failed' => '{name} a quitté avec le code {code}',
    'status.no_editor' => 'aucun éditeur trouvé pour {name}',
    'status.no_viewer' => 'aucun visualiseur pour {name}',
    'status.not_executable' => '{name} non exécutable',

    // CF-16 (closes 025, 056, 060): rendered chrome through Lang.
    'search.prompt' => 'Rechercher : ',
    'size.dir' => 'RÉP',
    'size.link' => 'LIEN',
    'sort.name' => 'Nom',
    'sort.mtime' => 'Date',
    'sort.size' => 'Taille',
    'cli.usage' => 'candy-files — gestionnaire de fichiers à deux volets

Utilisation :
  candy-files [left] [right]   Ouvre les volets sur les répertoires donnés
                               (par défaut : cwd à gauche, HOME à droite)
  candy-files --version        Affiche la version
  candy-files --help           Affiche cette aide',
    'cli.dir_missing' => 'répertoire ({side}) {reason} : {dir}',
    'cli.reason_missing' => 'n\'existe pas',
    'cli.reason_unreadable' => 'n\'est pas lisible',
    'status.deleted_trash_hint' => 'déplacé dans la corbeille de session — supprimé définitivement à la fermeture (u restaure jusque-là)',
];
