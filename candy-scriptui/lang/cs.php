<?php

/**
 * Czech translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'prázdná barva',
    'style.unrecognised_color' => 'nerozpoznaná barva: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "hex barva musí mít 3 nebo 6 hexadecimálních číslic (např. '#f80' nebo '#ff8800'); získáno '{value}'",
    'style.padding_token_int' => "padding/margin token musí být integer; obdrženo: '{token}'",
    'style.padding_count'     => 'padding/margin vyžaduje 1, 2 nebo 4 integery; obdrženo: {count}',
    'style.bad_entry'         => "--style položky musí být 'klíč=hodnota' nebo 'prvek.prop=hodnota'; obdrženo: '{raw}'",
    'style.unknown_prop'      => "neznámá vlastnost stylu: '{prop}'",
    'process.spawn_failed'    => 'nelze spustit dceřiný proces',
    'border.unknown'          => 'neznámý styl okraje: {name}',
    'log.unknown_level'       => 'neznámá úroveň logování: {name}',
    'log.encode_failed' => 'nepodařilo se zakódovat záznam protokolu do json',
    'log.bad_format' => 'neplatný řetězec --format: {format}',
    'spinner.unknown_style'   => 'neznámý styl spinneru: {name}',
    'spinner.windows_unsupported' => 'spin nelze použít na nativním Windows: procesní backend tam není podporován — místo toho použijte WSL.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'neznámé téma: {name}',
    'io.read_failed' => 'soubor nelze přečíst: {path}',
    'io.write_failed' => 'do souboru nelze zapsat: {path}',
    'log.unknown_formatter' => 'neznámý --formatter: {name}',
    'completion.unknown_shell' => 'nepodporovaný shell: {shell}',
    'confirm.unknown_default' => 'neznámá hodnota --default: {value}',
    'cursor_mode.unknown' => 'neznámý režim kurzoru: {mode}',
    'runtime.no_terminal' => '{command} vyžaduje interaktivní terminál; řídicí terminál není dostupný.',
    'runtime.bad_timeout' => 'Neplatná hodnota --timeout \'{value}\' — očekává se nezáporný počet sekund (0 deadline vypíná).',
    'runtime.bad_number' => 'Neplatná hodnota {flag} \'{value}\' — očekáváno nezáporné celé číslo.',
    'runtime.unknown_choice' => 'Neplatná hodnota pro {option}: \'{value}\'. Pouze: {allowed}.',
    'hints.choose' => '↑/↓ posun · mezerník přepíná (více) · enter vybrat · esc zrušit',
    'hints.filter' => 'psít pro filtrování · ↑/↓ posun · tabulátor přepíná (více) · enter vybrat · esc zrušit',
    'hints.pager' => 'space/PgDn dolů · PgUp/b nahoru · g začátek · G konec · q quit',
    'format.template_unresolved' => 'šablona: {count} zástupných symbolů se rozbalilo na prázdné: {names} — proměnnou je třeba nastavit a povolit přes --allow-env.',
    'runtime.empty_input' => 'Vstup neobsahoval žádné řádky; nic k dispozici.',
    'runtime.conflicting_flags' => 'Příznaky {first} a {second} nelze kombinovat s různými hodnotami.',
];
