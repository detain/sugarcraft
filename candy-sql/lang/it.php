<?php

/**
 * Italian translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'file SQLite non trovato: {path}',
    'complete.in_literal' => 'niente da completare dentro una stringa o un commento',
    'complete.nothing' => 'niente da completare — digita l\'inizio di una parola chiave',
    'complete.none_keyword' => 'nessuna parola chiave completa "{word}"',
    'complete.none_table' => 'nessuna tabella completa "{word}"',
    'complete.none_column' => 'nessuna colonna completa "{word}"',
    'complete.none_qualified' => 'nessuna colonna di {qualifier} completa "{word}"',
    'complete.list' => '{count} completano "{word}": {list}{more}',
    'complete.done' => 'completato {word} → {insert}',
    'complete.inserted' => 'inserito {insert}',
    'edit.refuse_readonly' => 'sessione di sola lettura — la modifica è disattivata',
    'edit.refuse_no_table' => 'apri prima l\'anteprima di una tabella — i risultati delle query non sono modificabili sul posto',
    'edit.refuse_no_rows' => 'nessuna riga sotto il cursore',
    'edit.refuse_no_pk' => 'questa tabella non ha una chiave primaria — le righe non si possono indirizzare in sicurezza; scrivi l\'istruzione nel pannello query',
    'edit.refuse_pk_hidden' => 'la chiave primaria di questa riga è NULL o un segnaposto binario — non può essere indirizzata',
    'edit.refuse_binary' => 'le celle binarie o grandi non sono modificabili qui — usa un\'istruzione nel pannello query',
    'edit.refuse_schema' => 'impossibile leggere lo schema di {table} — modifica rifiutata',
    'edit.prompt_cell' => 'modifica {table}.{column} = {value}  (invio anteprima · ctrl+n NULL · esc annulla)',
    'edit.prompt_insert' => 'nuova riga in {table} — {column} ({type}) [{n}/{total}] = {value}  (invio avanti · vuoto = DEFAULT · ctrl+n NULL · esc annulla)',
    'edit.prompt_confirm' => '{sql}  (invio conferma · esc annulla)',
    'edit.cancelled' => 'modifica annullata',
    'edit.unchanged' => 'valore invariato — nulla scritto',
    'edit.done_update' => 'aggiornata 1 riga in {table}',
    'edit.done_insert' => 'inserita 1 riga in {table}',
    'edit.done_delete' => 'eliminata 1 riga da {table}',
    'edit.none_changed' => 'nessuna riga modificata — il valore c\'era già o la riga non esiste più; anteprima aggiornata',
    'edit.failed' => 'scrittura non riuscita — annullata, nulla è cambiato: {reason}',
    'edit.too_many' => 'rifiutato: l\'istruzione toccherebbe {count} righe — annullata, nulla è cambiato',
];
