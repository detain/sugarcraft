<?php

/**
 * Polish translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'nie znaleziono pliku SQLite: {path}',
    'complete.in_literal' => 'w ciągu znaków lub komentarzu nie ma czego uzupełniać',
    'complete.nothing' => 'nie ma czego uzupełnić — wpisz początek słowa kluczowego',
    'complete.none_keyword' => 'żadne słowo kluczowe nie uzupełnia "{word}"',
    'complete.none_table' => 'żadna tabela nie uzupełnia "{word}"',
    'complete.none_column' => 'żadna kolumna nie uzupełnia "{word}"',
    'complete.none_qualified' => 'żadna kolumna z {qualifier} nie uzupełnia "{word}"',
    'complete.list' => '{count} uzupełnia "{word}": {list}{more}',
    'complete.done' => 'uzupełniono {word} → {insert}',
    'complete.inserted' => 'wstawiono {insert}',
    'edit.refuse_readonly' => 'sesja tylko do odczytu — edycja jest wyłączona',
    'edit.refuse_no_table' => 'najpierw otwórz podgląd tabeli — wyników zapytania nie można edytować w miejscu',
    'edit.refuse_no_rows' => 'brak wiersza pod kursorem',
    'edit.refuse_no_pk' => 'ta tabela nie ma klucza głównego — wierszy nie można bezpiecznie adresować; wpisz polecenie w panelu zapytania',
    'edit.refuse_pk_hidden' => 'klucz główny tego wiersza to NULL lub binarny zastępnik — nie można go zaadresować',
    'edit.refuse_binary' => 'komórek binarnych lub dużych nie można tu edytować — użyj polecenia w panelu zapytania',
    'edit.refuse_schema' => 'nie można odczytać schematu {table} — edycja odrzucona',
    'edit.prompt_cell' => 'edycja {table}.{column} = {value}  (enter podgląd · ctrl+n NULL · esc anuluje)',
    'edit.prompt_insert' => 'nowy wiersz w {table} — {column} ({type}) [{n}/{total}] = {value}  (enter dalej · puste = DEFAULT · ctrl+n NULL · esc anuluje)',
    'edit.prompt_confirm' => '{sql}  (enter zatwierdza · esc anuluje)',
    'edit.cancelled' => 'edycja anulowana',
    'edit.unchanged' => 'wartość bez zmian — nic nie zapisano',
    'edit.done_update' => 'zaktualizowano 1 wiersz w {table}',
    'edit.done_insert' => 'wstawiono 1 wiersz do {table}',
    'edit.done_delete' => 'usunięto 1 wiersz z {table}',
    'edit.none_changed' => 'żaden wiersz się nie zmienił — wartość już tam była lub wiersza nie ma; podgląd odświeżono',
    'edit.failed' => 'zapis nie powiódł się — wycofano, nic się nie zmieniło: {reason}',
    'edit.too_many' => 'odrzucono: polecenie dotknęłoby {count} wierszy — wycofano, nic się nie zmieniło',
];
