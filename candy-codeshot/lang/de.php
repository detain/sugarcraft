<?php

/**
 * German translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: unbekanntes Flag '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: unbekanntes Theme '{name}'. Bekannt: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: unbekannter Fensterstil '{style}'. Bekannt: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: unbekanntes Format '{format}'. Bekannt: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd wird für PNG-Ausgabe benötigt',
    'cli.font_not_found'      => "candy-codeshot: Schriftdatei nicht gefunden: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' ist keine gültige Schriftdatei (erwartet TTF, OTF, WOFF oder WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: ungültiges Highlight-Format '{format}'. Format start:end (z. B. 3:7) oder start:end:#farbe (z. B. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: Eingabe konnte nicht gelesen werden',
    'cli.path_outside_cwd'    => "candy-codeshot: Pfad '{path}' liegt außerhalb des aktuellen Arbeitsverzeichnisses (mit --unsafe-paths erlauben)",
    'cli.write_failed'        => "candy-codeshot: Schreiben fehlgeschlagen '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} erwartet einen Wert",
    'cli.bad_number'          => "candy-codeshot: {option} erwartet eine nicht-negative Ganzzahl, erhalten '{value}'",
    'cli.png_ignores'         => "candy-codeshot: PNG-Ausgabe ignoriert SVG-only-Optionen: {options} (bitte --format svg verwenden)",
    'cli.highlight_rejected'  => "candy-codeshot: Highlight '{spec}' abgelehnt: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: Ausgabeformat '{format}' passt nicht zur Erweiterung von '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - Code oder Terminal-Ausgabe als SVG- oder PNG-Screenshot rendern

Aufruf:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Flag-Werte sind eigene Argumente: "--theme dracula", nicht "--theme=dracula"
(die "=" Form wird als unbekanntes Flag abgelehnt).

Optionen:
  --theme <name>          Theme-Name: dark, light, dracula, tokyo-night, nord (Standard: dark)
  --padding <n>           Abstand um den Inhalt (Standard: 24)
  --no-window             Fensterchrome ausblenden (Ampel, Titelleiste)
  --no-shadow             Schlagschatten deaktivieren
  --no-border             Rahmen ausblenden
  --line-numbers          Zeilennummern im Gutter anzeigen
  --border-radius <n>     Eckenradius (Standard: 8)
  --window-style <stil>   Fensterstil: macos, windows-terminal, iterm, hyper, none (Standard: macos)
  --ligatures             Ligaturen aktivieren (font-variant-ligatures: normal; nur SVG)
  --font <pfad>           Pfad zu einer TTF/OTF/WOFF/WOFF2-Schriftdatei zum Einbetten ins SVG (nur SVG)
  --unsafe-paths          Eingabe-/--font-/--output-Pfade außerhalb des CWD erlauben
  --highlight <start:end[:farbe]>
                          Zeilen start:end mit optionaler Farbe hervorheben (Standardfarbe: #fffbe6; nur SVG)
  --format <svg|png>      Ausgabeformat (Standard: svg; png benötigt ext-gd)
  -t, --type <sprache>    Sprache für Syntax-Highlighting erzwingen (Standard: Dateiname, Shebang, Inhalt)
  -o, --output <pfad>     Ausgabe in Datei statt nach stdout schreiben
  -h, --help              Diese Hilfe anzeigen

Hinweise:
  Eingabe- und Ausgabepfade müssen innerhalb des aktuellen Arbeitsverzeichnisses
  bleiben, außer --unsafe-paths wird angegeben. PNG-Ausgabe ignoriert die genannten
  SVG-only-Optionen und meldet jede verworfene Option auf stderr.

Exit-Codes:
  0  Erfolg
  1  fehlender Composer-Autoload (Installationsproblem)
  2  Benutzungs- oder Eingabefehler (ungültiges Flag, unlesbarer Pfad, abgelehntes Highlight, ...)

Beispiele:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
