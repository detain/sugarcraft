<?php

/**
 * French (fr) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'supprimer {names} ? (o/n)',
    'confirm.copy' => 'copier {names} vers {dest} ? (o/n)',
    'confirm.move' => 'déplacer {names} vers {dest} ? (o/n)',
    'confirm.rename' => 'renommer \'{name}\' en : ',
    'confirm.selected_entries' => '{count} entrées sélectionnées',
    'status.nothing_to_delete' => 'rien à supprimer',
    'status.nothing_to_copy' => 'rien à copier',
    'status.nothing_to_move' => 'rien à déplacer',
    'status.nothing_to_rename' => 'rien à renommer',
    'status.cancelled' => 'annulé',
    'status.refreshed' => 'rafraîchi',
    'status.copy_pending' => 'copie de {count} entrées…',
    'status.deleted' => '{count} entrées supprimées',
    'status.deleted_with_errors' => '{count} entrées supprimées, {errors} erreurs',
    'status.deleted_skipped' => '{count} entrées supprimées ({skipped} ignorées)',
    'status.copied' => '{count} entrées copiées',
    'status.copied_with_errors' => '{count} entrées copiées, {errors} erreurs',
    'status.copied_skipped' => '{count} entrées copiées ({skipped} ignorées)',
    'status.copied_overwrote' => '{count} entrées copiées ({overwrote} écrasées)',
    'status.moved' => '{count} entrées déplacées',
    'status.moved_with_errors' => '{count} entrées déplacées, {errors} erreurs',
    'status.moved_skipped' => '{count} entrées déplacées ({skipped} ignorées)',
    'status.renamed' => '{old} renommé en {new}',
    'status.rename_failed' => 'échec du renommage : {name}',
    'status.nothing_to_undo' => 'rien à annuler',
    'status.undone' => 'annulé : {description}',
    'status.undo_with_errors' => 'annulation de {description} avec {errors} erreur(s)',
    'status.cannot_close_last_tab' => 'Impossible de fermer le dernier onglet',
    'status.redone' => 'refait : {description}',
    'status.redo_with_errors' => 'réplication de {description} avec {errors} erreur(s)',
    'status.nothing_to_redo' => 'rien à refaire',
    'status.copy_undo_noop' => 'copie conservée (rien à annuler)',
    'undo.delete' => 'suppression de {count} élément(s)',
    'undo.rename' => 'renommage de {count} élément(s)',
    'undo.move' => 'déplacement de {count} élément(s)',
    'undo.copy' => 'copie de {count} élément(s)',
    'keyhelp.default' => 'Tab permuter · ↑↓ jk déplacer · Entrée ouvrir · ← h monter · Espace sélectionner · s trier · . cachés · c copier · m déplacer · R renommer · d supprimer · r rafraîchir · q quitter · / rechercher · t nouvel onglet · ^w fermer l’onglet · ^tab cycler',
    'search.no_match' => '(aucune correspondance)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[RÉP]',
    'search.type_file' => '[FICHIER]',
    'pane.hidden_suffix' => '+cachés',
];
