<?php

/**
 * French translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'fichier SQLite introuvable : {path}',
    'complete.in_literal' => 'rien à compléter dans une chaîne ou un commentaire',
    'complete.nothing' => 'rien à compléter — tapez le début d\'un mot-clé',
    'complete.none_keyword' => 'aucun mot-clé ne complète "{word}"',
    'complete.none_table' => 'aucune table ne complète "{word}"',
    'complete.none_column' => 'aucune colonne ne complète "{word}"',
    'complete.none_qualified' => 'aucune colonne de {qualifier} ne complète "{word}"',
    'complete.list' => '{count} complètent "{word}" : {list}{more}',
    'complete.done' => 'complété {word} → {insert}',
    'complete.inserted' => 'inséré {insert}',
    'edit.refuse_readonly' => 'session en lecture seule — l\'édition est désactivée',
    'edit.refuse_no_table' => 'ouvrez d\'abord l\'aperçu d\'une table — les résultats de requête ne s\'éditent pas sur place',
    'edit.refuse_no_rows' => 'aucune ligne sous le curseur',
    'edit.refuse_no_pk' => 'cette table n\'a pas de clé primaire — les lignes ne peuvent pas être adressées en sécurité ; écrivez l\'instruction dans le volet requête',
    'edit.refuse_pk_hidden' => 'la clé primaire de cette ligne est NULL ou un substitut binaire — elle ne peut pas être adressée',
    'edit.refuse_binary' => 'les cellules binaires ou volumineuses ne sont pas modifiables ici — utilisez une instruction dans le volet requête',
    'edit.refuse_schema' => 'impossible de lire le schéma de {table} — édition refusée',
    'edit.prompt_cell' => 'modifier {table}.{column} = {value}  (entrée aperçu · ctrl+n NULL · échap annule)',
    'edit.prompt_insert' => 'nouvelle ligne dans {table} — {column} ({type}) [{n}/{total}] = {value}  (entrée suivant · vide = DEFAULT · ctrl+n NULL · échap annule)',
    'edit.prompt_confirm' => '{sql}  (entrée valide · échap annule)',
    'edit.cancelled' => 'édition annulée',
    'edit.unchanged' => 'valeur inchangée — rien d\'écrit',
    'edit.done_update' => '1 ligne mise à jour dans {table}',
    'edit.done_insert' => '1 ligne insérée dans {table}',
    'edit.done_delete' => '1 ligne supprimée de {table}',
    'edit.none_changed' => 'aucune ligne modifiée — la valeur y était déjà ou la ligne a disparu ; aperçu actualisé',
    'edit.failed' => 'échec de l\'écriture — annulée, rien n\'a changé : {reason}',
    'edit.too_many' => 'refusé : l\'instruction toucherait {count} lignes — annulée, rien n\'a changé',
];
