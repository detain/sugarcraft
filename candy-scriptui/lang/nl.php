<?php

/**
 * Dutch translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'Lege kleur.',
    'style.unrecognised_color' => 'Niet-herkende kleur: {value} — Toegestaan: {allowed}.',
    'style.hex_digit_count' => "Hexkleur moet 3 of 6 hexadecimale cijfers hebben (bijv. '#f80' of '#ff8800'); verkregen '{value}'.",
    'style.padding_token_int' => "Padding/margin-token moet een integer zijn; gekregen: '{token}'.",
    'style.padding_count'     => 'Padding/margin vereist 1, 2 of 4 integers; gekregen: {count}.',
    'style.bad_entry'         => "--style-entries moeten 'key=value' of 'element.prop=value' zijn; gekregen: '{raw}'.",
    'style.unknown_prop'      => "Onbekende style-eigenschap: '{prop}'.",
    'process.spawn_failed'    => 'Kon kindproces niet starten',
    'border.unknown'          => 'Onbekende randstijl: {name}.',
    'log.unknown_level'       => 'Onbekend logniveau: {name}.',
    'log.encode_failed' => 'Kon logvermelding niet naar json coderen.',
    'log.bad_format' => 'Ongeldige --format-tekenreeks: {format}.',
    'spinner.unknown_style'   => 'Onbekende spinnerstijl: {name}.',
    'spinner.windows_unsupported' => 'Spin kan geen opdrachten uitvoeren op native Windows: het procesbackend wordt daar niet ondersteund — gebruik in plaats daarvan WSL.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'Onbekend thema: {name}.',
    'io.read_failed' => 'Kon bestand niet lezen: {path}.',
    'io.write_failed' => 'Kon niet naar bestand schrijven: {path}.',
    'runtime.no_terminal' => '{command} heeft een interactieve terminal nodig; geen sturende terminal beschikbaar.',
    'runtime.bad_timeout' => "Ongeldige --timeout-waarde '{value}' — verwacht een niet-negatief aantal seconden of een duur als 5s/250ms/1m30s (0 schakelt de deadline uit).",
    'runtime.bad_number' => "Ongeldige waarde voor {flag}: '{value}' — een niet-negatief getal wordt verwacht.",
    'runtime.pcntl_missing' => 'ext-pcntl is niet geladen: SIGINT/SIGTERM beëindigen dit programma zonder de 130/143-exit-mapping.',
    'runtime.unknown_choice' => "Ongeldige waarde voor {option}: '{value}'. Toegestaan: {allowed}.",
    'hints.choose' => '↑/↓ verplaats · / filtreer · Enter kies · Esc annuleer',
    'hints.choose.multi' => '↑/↓ verplaats · Spatie schakelen · / filtreer · Enter kies · Esc annuleer',
    'hints.filter' => 'filteren · ↑/↓ verplaats · Tab kiest · Enter · Esc',
    'hints.pager' => '↑/↓ j/k verplaats · ←/→ h/l scroll · PgUp/b PgDn/Spatie pagina · Ctrl+U/D half',
    'hints.pager.more' => 'g/G of Home/End begin/einde · q/Esc sluit',
    'format.template_unresolved' => 'Sjabloon: {count} tijdelijke aanduiding(en) uitgebreid naar leeg: {names} — een variabele moet zijn ingesteld en toegestaan via --allow-env.',
    'runtime.empty_input' => 'De invoer bevatte geen regels; niets te doen.',
    'runtime.empty_input_filter' => 'Niets te filteren; de invoer bevatte geen regels.',
    'runtime.empty_input_pager' => 'Niets te bladeren; de invoer bevatte geen regels.',
    'runtime.conflicting_flags' => 'De opties {first} en {second} kunnen niet worden gecombineerd met verschillende waarden.',
    'runtime.empty_selection' => 'Geen item geselecteerd — druk op Spatie om minstens één item aan te vinken vóór Enter.',
];
