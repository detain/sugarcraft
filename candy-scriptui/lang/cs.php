<?php

/**
 * Czech translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'Prázdná barva.',
    'style.unrecognised_color' => 'Nerozpoznaná barva: {value} — Pouze: {allowed}.',
    'style.hex_digit_count' => "Hex barva musí mít 3 nebo 6 hexadecimálních číslic (např. '#f80' nebo '#ff8800'); získáno '{value}'.",
    'style.padding_token_int' => "Padding/margin token musí být integer; obdrženo: '{token}'.",
    'style.padding_count'     => 'Padding/margin vyžaduje 1, 2 nebo 4 integery; obdrženo: {count}.',
    'style.bad_entry'         => "--style položky musí být 'klíč=hodnota' nebo 'prvek.prop=hodnota'; obdrženo: '{raw}'.",
    'style.unknown_prop'      => "Neznámá vlastnost stylu: '{prop}'.",
    'process.spawn_failed'    => 'Nelze spustit dceřiný proces',
    'border.unknown'          => 'Neznámý styl okraje: {name}.',
    'log.unknown_level'       => 'Neznámá úroveň logování: {name}.',
    'log.encode_failed' => 'Nepodařilo se zakódovat záznam protokolu do json.',
    'log.bad_format' => 'Neplatný řetězec --format: {format}.',
    'spinner.unknown_style'   => 'Neznámý styl spinneru: {name}.',
    'spinner.windows_unsupported' => 'Spin nelze použít na nativním Windows: procesní backend tam není podporován — místo toho použijte WSL.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'Neznámé téma: {name}.',
    'io.read_failed' => 'Soubor nelze přečíst: {path}.',
    'io.write_failed' => 'Do souboru nelze zapsat: {path}.',
    'runtime.no_terminal' => '{command} vyžaduje interaktivní terminál; řídicí terminál není dostupný.',
    'runtime.bad_timeout' => "Neplatná hodnota --timeout '{value}' — očekává se nezáporný počet sekund nebo délka jako 5s/250ms/1m30s (0 deadline vypíná).",
    'runtime.bad_number' => "Neplatná hodnota {flag} '{value}' — očekáváno nezáporné celé číslo.",
    'runtime.pcntl_missing' => 'ext-pcntl není načten: SIGINT/SIGTERM ukončí tento program bez mapování ukončovacího kódu 130/143.',
    'runtime.unknown_choice' => "Neplatná hodnota pro {option}: '{value}'. Pouze: {allowed}.",
    'hints.choose' => '↑/↓ posun · mezerník přepíná (více) · enter vybrat · esc zrušit',
    'hints.filter' => 'psít pro filtrování · ↑/↓ posun · tabulátor přepíná (více) · enter vybrat · esc zrušit',
    'hints.pager' => 'space/PgDn dolů · PgUp/b nahoru · g začátek · G konec · q quit',
    'format.template_unresolved' => 'Šablona: {count} zástupných symbolů se rozbalilo na prázdné: {names} — proměnnou je třeba nastavit a povolit přes --allow-env.',
    'runtime.empty_input' => 'Vstup neobsahoval žádné řádky; nic k dispozici.',
    'runtime.empty_input_filter' => 'Nic ke filtrování; vstup neobsahoval žádné řádky.',
    'runtime.empty_input_pager' => 'Nic k stránkování; vstup neobsahoval žádné řádky.',
    'runtime.conflicting_flags' => 'Příznaky {first} a {second} nelze kombinovat s různými hodnotami.',
    'runtime.empty_selection' => 'Není vybrána žádná položka — před stiskem Enter alespoň jednu označte mezníkem.',
];
