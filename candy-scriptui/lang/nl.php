<?php

/**
 * Dutch translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'lege kleur',
    'style.unrecognised_color' => 'niet-herkende kleur: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "hexkleur moet 3 of 6 hexadecimale cijfers hebben (bijv. '#f80' of '#ff8800'); verkregen '{value}'",
    'style.padding_token_int' => "padding/margin-token moet een integer zijn; gekregen: '{token}'",
    'style.padding_count'     => 'padding/margin vereist 1, 2 of 4 integers; gekregen: {count}',
    'style.bad_entry'         => "--style-entries moeten 'key=value' of 'element.prop=value' zijn; gekregen: '{raw}'",
    'style.unknown_prop'      => "onbekende style-eigenschap: '{prop}'",
    'process.spawn_failed'    => 'kon kindproces niet starten',
    'border.unknown'          => 'onbekende randstijl: {name}',
    'log.unknown_level'       => 'onbekend logniveau: {name}',
    'log.encode_failed' => 'kon logvermelding niet naar json coderen',
    'log.bad_format' => 'ongeldige --format-tekenreeks: {format}',
    'spinner.unknown_style'   => 'onbekende spinnerstijl: {name}',
    'spinner.windows_unsupported' => 'spin kan geen opdrachten uitvoeren op native Windows: het procesbackend wordt daar niet ondersteund — gebruik in plaats daarvan WSL.',
    'format.unknown_theme'    => 'onbekend thema: {name}',
    'io.read_failed' => 'kon bestand niet lezen: {path}',
    'io.write_failed' => 'kon niet naar bestand schrijven: {path}',
    'log.unknown_formatter' => 'onbekende --formatter: {name}',
    'completion.unknown_shell' => 'niet-ondersteunde shell: {shell}',
    'confirm.unknown_default' => 'onbekende --default-waarde: {value}',
    'cursor_mode.unknown' => 'onbekende cursormodus: {mode}',
    'runtime.no_terminal' => '{command} heeft een interactieve terminal nodig; geen sturende terminal beschikbaar.',
    'runtime.bad_timeout' => 'Ongeldige --timeout-waarde \'{value}\' — verwacht een niet-negatief aantal seconden (0 schakelt de deadline uit).',
    'runtime.bad_number' => 'Ongeldige waarde voor {flag}: \'{value}\' — een niet-negatief getal wordt verwacht.',
    'runtime.unknown_choice' => 'Ongeldige waarde voor {option}: \'{value}\'. Toegestaan: {allowed}.',
    'hints.choose' => '↑/↓ verplaats · spatie wisselt (multi) · enter selecteren · esc annuleren',
    'hints.filter' => 'typ om te filteren · ↑/↓ verplaats · tab wisselt (multi) · enter selecteren · esc annuleren',
    'hints.pager' => 'space/PgDn omlaag · PgUp/b omhoog · g begin · G einde · q afsluiten',
    'format.template_unresolved' => 'sjabloon: {count} tijdelijke aanduiding(en) uitgebreid naar leeg: {names} — een variabele moet zijn ingesteld en toegestaan via --allow-env.',
    'runtime.empty_input' => 'De invoer bevatte geen regels; niets te doen.',
    'runtime.conflicting_flags' => 'De opties {first} en {second} kunnen niet worden gecombineerd met verschillende waarden.',
];
