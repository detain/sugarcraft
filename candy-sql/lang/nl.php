<?php

/**
 * Dutch translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite-bestand niet gevonden: {path}',
    'complete.in_literal' => 'niets aan te vullen binnen een tekenreeks of opmerking',
    'complete.nothing' => 'niets aan te vullen — typ het begin van een sleutelwoord',
    'complete.none_keyword' => 'geen sleutelwoord vult "{word}" aan',
    'complete.none_table' => 'geen tabel vult "{word}" aan',
    'complete.none_column' => 'geen kolom vult "{word}" aan',
    'complete.none_qualified' => 'geen kolom van {qualifier} vult "{word}" aan',
    'complete.list' => '{count} vullen "{word}" aan: {list}{more}',
    'complete.done' => 'aangevuld {word} → {insert}',
    'complete.inserted' => 'ingevoegd {insert}',
    'edit.refuse_readonly' => 'alleen-lezen sessie — bewerken is uitgeschakeld',
    'edit.refuse_no_table' => 'open eerst een tabelvoorbeeld — queryresultaten zijn niet ter plaatse te bewerken',
    'edit.refuse_no_rows' => 'geen rij onder de cursor',
    'edit.refuse_no_pk' => 'deze tabel heeft geen primaire sleutel — rijen zijn niet veilig aan te wijzen; schrijf de instructie in het queryvenster',
    'edit.refuse_pk_hidden' => 'de primaire sleutel van deze rij is NULL of een binaire plaatshouder — de rij is niet aan te wijzen',
    'edit.refuse_binary' => 'binaire of grote cellen zijn hier niet te bewerken — gebruik een instructie in het queryvenster',
    'edit.refuse_schema' => 'schema van {table} niet te lezen — bewerken geweigerd',
    'edit.prompt_cell' => 'bewerk {table}.{column} = {value}  (enter voorbeeld · ctrl+n NULL · esc annuleert)',
    'edit.prompt_insert' => 'nieuwe rij in {table} — {column} ({type}) [{n}/{total}] = {value}  (enter volgende · leeg = DEFAULT · ctrl+n NULL · esc annuleert)',
    'edit.prompt_confirm' => '{sql}  (enter voert uit · esc annuleert)',
    'edit.cancelled' => 'bewerking geannuleerd',
    'edit.unchanged' => 'waarde ongewijzigd — niets geschreven',
    'edit.done_update' => '1 rij bijgewerkt in {table}',
    'edit.done_insert' => '1 rij ingevoegd in {table}',
    'edit.done_delete' => '1 rij verwijderd uit {table}',
    'edit.none_changed' => 'geen rij gewijzigd — de waarde stond er al of de rij is weg; voorbeeld ververst',
    'edit.failed' => 'schrijven mislukt — teruggedraaid, niets gewijzigd: {reason}',
    'edit.too_many' => 'geweigerd: de instructie zou {count} rijen raken — teruggedraaid, niets gewijzigd',
];
