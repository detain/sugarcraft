<?php

/**
 * German translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Style/StyleBuilder.php
    'style.empty_color'       => 'leere Farbe',
    'style.unrecognised_color' => 'nicht erkannte Farbe: {value} — allowed: {allowed}',
    'style.hex_digit_count' => "Hex-Farbe muss 3 oder 6 Hex-Ziffern haben (z. B. '#f80' oder '#ff8800'); erhalten: '{value}'",
    'style.padding_token_int' => "Padding/Margin-Token muss eine Ganzzahl sein; erhalten: '{token}'",
    'style.padding_count'     => 'Padding/Margin benötigt 1, 2 oder 4 Ganzzahlen; erhalten: {count}',

    // Style/SubStyleParser.php
    'style.bad_entry'         => "--style-Einträge müssen 'schlüssel=wert' oder 'element.prop=wert' sein; erhalten: '{raw}'",
    'style.unknown_prop'      => "unbekannte Style-Eigenschaft: '{prop}'",

    // Process/RealProcess.php
    'process.spawn_failed'    => 'Kindprozess konnte nicht gestartet werden',

    // Command/TableCommand.php
    'border.unknown'          => 'unbekannter Rahmenstil: {name}',

    // Log/LogLevel.php
    'log.unknown_level'       => 'unbekannte Log-Stufe: {name}',
    'log.encode_failed' => 'Logeintrag konnte nicht als json kodiert werden',
    'log.bad_format' => 'ungültige --format-Zeichenkette: {format}',

    // Command/SpinCommand.php
    'spinner.unknown_style'   => 'unbekannter Spinner-Stil: {name}',
    'spinner.windows_unsupported' => 'spin kann auf nativem Windows keine Befehle ausführen: das Prozess-Backend wird dort nicht unterstützt — verwenden Sie stattdessen WSL.',
    'spinner.output_truncated' => '…[truncated]',

    // Command/FormatCommand.php
    'format.unknown_theme'    => 'unbekanntes Theme: {name}',
    'io.read_failed' => 'Datei konnte nicht gelesen werden: {path}',
    'io.write_failed' => 'Datei konnte nicht geschrieben werden: {path}',
    'log.unknown_formatter' => 'unbekannter --formatter: {name}',
    'completion.unknown_shell' => 'nicht unterstützte Shell: {shell}',
    'confirm.unknown_default' => 'unbekannter --default-Wert: {value}',
    'cursor_mode.unknown' => 'unbekannter Cursor-Modus: {mode}',
    'runtime.no_terminal' => '{command} benötigt ein interaktives Terminal; kein steuerndes Terminal verfügbar.',
    'runtime.bad_timeout' => 'Ungültiger --timeout-Wert \'{value}\' — erwartet wird eine nicht negative Sekundenanzahl (0 deaktiviert die Frist).',
    'runtime.bad_number' => 'Ungültiger {flag}-Wert \'{value}\' — erwartet wird eine nicht negative Ganzzahl.',
    'runtime.pcntl_missing' => 'ext-pcntl ist nicht geladen: SIGINT/SIGTERM beenden dieses Programm ohne die 130/143-Exit-Zuordnung.',
    'runtime.unknown_choice' => 'Ungültiger Wert für {option}: \'{value}\'. Erlaubt: {allowed}.',
    'hints.choose' => '↑/↓ bewegen · Leertaste umschalten (Mehrfach) · Enter auswählen · Esc abbrechen',
    'hints.filter' => 'tippen zum Filtern · ↑/↓ bewegen · Tab umschalten (Mehrfach) · Enter auswählen · Esc abbrechen',
    'hints.pager' => 'Space/PgDn abwärts · PgUp/b aufwärts · g Anfang · G Ende · q beenden',
    'format.template_unresolved' => 'Vorlage: {count} Platzhalter wurde(n) als leer expandiert: {names} — eine Variable muss gesetzt und über --allow-env erlaubt sein.',
    'runtime.empty_input' => 'Die Eingabe enthielt keine Zeilen; nichts zu tun.',
    'runtime.conflicting_flags' => 'Die Optionen {first} und {second} können nicht mit unterschiedlichen Werten kombiniert werden.',
];
