<?php

/**
 * Czech translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'soubor SQLite nenalezen: {path}',
    'complete.in_literal' => 'uvnitř řetězce nebo komentáře není co doplňovat',
    'complete.nothing' => 'není co doplnit — napište začátek klíčového slova',
    'complete.none_keyword' => 'žádné klíčové slovo nedoplňuje "{word}"',
    'complete.none_table' => 'žádná tabulka nedoplňuje "{word}"',
    'complete.none_column' => 'žádný sloupec nedoplňuje "{word}"',
    'complete.none_qualified' => 'žádný sloupec z {qualifier} nedoplňuje "{word}"',
    'complete.list' => '{count} doplňuje "{word}": {list}{more}',
    'complete.done' => 'doplněno {word} → {insert}',
    'complete.inserted' => 'vloženo {insert}',
    'edit.refuse_readonly' => 'relace jen pro čtení — úpravy jsou vypnuty',
    'edit.refuse_no_table' => 'nejdřív otevřete náhled tabulky — výsledky dotazu nelze upravovat přímo',
    'edit.refuse_no_rows' => 'pod kurzorem není žádný řádek',
    'edit.refuse_no_pk' => 'tabulka nemá primární klíč — řádky nelze bezpečně adresovat; napište příkaz v panelu dotazu',
    'edit.refuse_pk_hidden' => 'primární klíč řádku je NULL nebo binární zástupce — řádek nelze adresovat',
    'edit.refuse_binary' => 'binární nebo velké buňky zde nelze upravovat — použijte příkaz v panelu dotazu',
    'edit.refuse_schema' => 'nelze načíst schéma tabulky {table} — úprava odmítnuta',
    'edit.prompt_cell' => 'úprava {table}.{column} = {value}  (enter náhled · ctrl+n NULL · esc zruší)',
    'edit.prompt_insert' => 'nový řádek v {table} — {column} ({type}) [{n}/{total}] = {value}  (enter další · prázdné = DEFAULT · ctrl+n NULL · esc zruší)',
    'edit.prompt_confirm' => '{sql}  (enter potvrdí · esc zruší)',
    'edit.cancelled' => 'úprava zrušena',
    'edit.unchanged' => 'hodnota se nezměnila — nic nezapsáno',
    'edit.done_update' => 'aktualizován 1 řádek v {table}',
    'edit.done_insert' => 'vložen 1 řádek do {table}',
    'edit.done_delete' => 'smazán 1 řádek z {table}',
    'edit.none_changed' => 'žádný řádek se nezměnil — hodnota tam už byla nebo řádek zmizel; náhled obnoven',
    'edit.failed' => 'zápis selhal — vráceno zpět, nic se nezměnilo: {reason}',
    'edit.too_many' => 'odmítnuto: příkaz by zasáhl {count} řádků — vráceno zpět, nic se nezměnilo',
];
