<?php

/**
 * Dutch translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: onbekende vlag '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: onbekend thema '{name}'. Bekend: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: onbekende vensterstijl '{style}'. Bekend: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: onbekend formaat '{format}'. Bekend: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd is vereist voor PNG-uitvoer',
    'cli.font_not_found'      => "candy-codeshot: lettertypebestand niet gevonden: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' is geen geldig lettertypebestand (verwacht TTF, OTF, WOFF of WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: ongeldig markeringformaat '{format}'. Gebruik begin:einde (bijv. 3:7) of begin:einde:#kleur (bijv. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: kan invoer niet lezen',
    'cli.path_outside_cwd'    => "candy-codeshot: pad '{path}' valt buiten de huidige werkmap (gebruik --unsafe-paths om dit toe te staan)",
    'cli.write_failed'        => "candy-codeshot: kan niet schrijven naar '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} vereist een waarde",
    'cli.bad_number'          => "candy-codeshot: {option} verwacht een niet-negatief getal, gekregen '{value}'",
    'cli.png_ignores'         => "candy-codeshot: PNG-uitvoer negeert SVG-only-opties: {options} (gebruik --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: markering '{spec}' geweigerd: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: uitvoerformaat '{format}' komt niet overeen met de extensie van '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - render code of terminaluitvoer als SVG- of PNG-screenshot

Gebruik:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Vlagwaarden zijn aparte argumenten: "--theme dracula", niet "--theme=dracula"
(de "=" vorm wordt geweigerd als onbekende vlag).

Opties:
  --theme <naam>          Themanaam: dark, light, dracula, tokyo-night, nord (standaard: dark)
  --padding <n>           Ruimte rond de inhoud (standaard: 24)
  --no-window             Verberg vensterchroom (verkeerslichten, titelbalk)
  --no-shadow             slagschad uitschakelen
  --no-border             Verberg kader
  --line-numbers          Laat regelnummers zien
  --border-radius <n>     Hoekradius (standaard: 8)
  --window-style <stijl>  Vensterstijl: macos, windows-terminal, iterm, hyper, none (standaard: macos)
  --ligatures             Ligaturen inschakelen (font-variant-ligatures: normal; alleen SVG)
  --font <pad>            Pad naar een TTF/OTF/WOFF/WOFF2-lettertypebestand om in de SVG te bedden (alleen SVG)
  --unsafe-paths          Invoer-/--font-/--output-paden buiten de CWD toestaan
  --highlight <begin:einde[:kleur]>
                          Markeer regels begin:einde met optionele kleur (standaardkleur: #fffbe6; alleen SVG)
  --format <svg|png>      Uitvoerformaat (standaard: svg; png vereist ext-gd)
  -t, --type <taal>       Taal voor syntaxismarkering afdwingen (standaard: bestandsnaam, shebang, inhoud)
  -o, --output <pad>      Uitvoer naar bestand schrijven in plaats van stdout
  -h, --help              Dit help-bericht tonen

Opmerkingen:
  Invoer- en uitvoerpaden moeten binnen de huidige werkmap blijven, tenzij
  --unsafe-paths wordt gegeven. PNG-uitvoer negeert de SVG-only-opties hierboven
  en meldt elke weggelaten optie op stderr.

Exitcodes:
  0  succes
  1  ontbrekende composer-autoload (installatieprobleem)
  2  gebruiks- of invoerfout (ongeldige vlag, onleesbaar pad, geweigerde markering, ...)

Voorbeelden:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
