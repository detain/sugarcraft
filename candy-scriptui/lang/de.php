<?php

/**
 * German translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'Leere Farbe.',
    'style.unrecognised_color' => 'Nicht erkannte Farbe: {value} — Erlaubt: {allowed}.',
    'style.hex_digit_count' => "Hex-Farbe muss 3 oder 6 Hex-Ziffern haben (z. B. '#f80' oder '#ff8800'); erhalten: '{value}'.",
    'style.padding_token_int' => "Padding/Margin-Token muss eine Ganzzahl sein; erhalten: '{token}'.",
    'style.padding_count'     => 'Padding/Margin benötigt 1, 2 oder 4 Ganzzahlen; erhalten: {count}.',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style-Einträge müssen 'schlüssel=wert' oder 'element.prop=wert' sein; erhalten: '{raw}'.",
    'style.unknown_prop'      => "Unbekannte Style-Eigenschaft: '{prop}'.",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'Kindprozess konnte nicht gestartet werden',

    // Command/TableCommand.php
    'border.unknown'          => 'Unbekannter Rahmenstil: {name}.',

    // Log/LogLevel.php
    'log.unknown_level'       => 'Unbekannte Log-Stufe: {name}.',
    'log.encode_failed' => 'Logeintrag konnte nicht als json kodiert werden.',
    'log.bad_format' => 'Ungültige --format-Zeichenkette: {format}.',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'Unbekannter Spinner-Stil: {name}.',
    'spinner.windows_unsupported' => 'Spin kann auf nativem Windows keine Befehle ausführen: das Prozess-Backend wird dort nicht unterstützt — verwenden Sie stattdessen WSL.',
    'spinner.output_truncated' => '…[truncated]',

    // Command/FormatCommand.php
    'format.unknown_theme'    => 'Unbekanntes Theme: {name}.',
    'io.read_failed' => 'Datei konnte nicht gelesen werden: {path}.',
    'io.write_failed' => 'Datei konnte nicht geschrieben werden: {path}.',
    'runtime.no_terminal' => '{command} benötigt ein interaktives Terminal; kein steuerndes Terminal verfügbar.',
    'runtime.bad_timeout' => "Ungültiger --timeout-Wert '{value}' — erwartet wird eine nicht negative Sekundenanzahl oder eine Angabe wie 5s/250ms/1m30s (0 deaktiviert die Frist).",
    'runtime.bad_number' => "Ungültiger {flag}-Wert '{value}' — erwartet wird eine nicht negative Ganzzahl.",
    'runtime.pcntl_missing' => 'ext-pcntl ist nicht geladen: SIGINT/SIGTERM beenden dieses Programm ohne die 130/143-Exit-Zuordnung.',
    'runtime.unknown_choice' => "Ungültiger Wert für {option}: '{value}'. Erlaubt: {allowed}.",
    'hints.choose' => '↑/↓ bewegen · / filtern · Enter wählen · Esc abbrechen',
    'hints.choose.multi' => '↑/↓ bewegen · Leertaste umschalten · / filtern · Enter wählen · Esc abbrechen',
    'hints.filter' => 'Filter tippen · ↑/↓ bewegen · Tab wählt · Enter · Esc',
    'hints.pager' => '↑/↓ j/k bewegen · ←/→ h/l scrollen · PgUp/b PgDn/Leer seite · Ctrl+U/D halb',
    'hints.pager.more' => 'g/G oder Home/End Anfang/Ende · q/Esc beenden',
    'format.template_unresolved' => 'Vorlage: {count} Platzhalter wurde(n) als leer expandiert: {names} — eine Variable muss gesetzt und über --allow-env erlaubt sein.',
    'runtime.empty_input' => 'Die Eingabe enthielt keine Zeilen; nichts zu tun.',
    'runtime.empty_input_filter' => 'Nichts zu filtern; die Eingabe enthielt keine Zeilen.',
    'runtime.empty_input_pager' => 'Nichts zu blättern; die Eingabe enthielt keine Zeilen.',
    'runtime.empty_items' => 'Keine Elemente angegeben — übergeben Sie sie als Argumente oder mit --item.',
    'runtime.conflicting_flags' => 'Die Optionen {first} und {second} können nicht mit unterschiedlichen Werten kombiniert werden.',
    'runtime.empty_selection' => 'Kein Eintrag ausgewählt — drücken Sie Leertaste, um vor Enter mindestens einen Eintrag zu markieren.',
    'runtime.legacy_env'        => '{var} ist veraltet; setzen Sie stattdessen {replacement}.',
];
