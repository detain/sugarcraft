<?php

/**
 * Czech translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: neznámý příznak '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: neznámé téma '{name}'. Známá: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: neznámý styl okna '{style}'. Známé: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: neznámý formát '{format}'. Známé: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd je vyžadován pro výstup PNG',
    'cli.font_not_found'      => "candy-codeshot: soubor písma nenalezen: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' není platný soubor písma (očekáváno TTF, OTF, WOFF nebo WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: neplatný formát zvýraznění '{format}'. Použijte start:konec (např. 3:7) nebo start:konec:#barva (např. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: nelze číst vstup',
    'cli.path_outside_cwd'    => "candy-codeshot: cesta '{path}' je mimo aktuální pracovní adresář (povolte pomocí --unsafe-paths)",
    'cli.write_failed'        => "candy-codeshot: nelze zapisovat do '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} vyžaduje hodnotu",
    'cli.bad_number'          => "candy-codeshot: {option} očekává nezáporné celé číslo, obdrženo '{value}'",
    'cli.png_ignores'         => "candy-codeshot: výstup PNG ignoruje pouze-SVG volby: {options} (použijte --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: zvýraznění '{spec}' zamítnuto: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: formát výstupu '{format}' neodpovídá příponě souboru '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - vykreslí kód nebo terminálový výstup do SVG či PNG screenshotu

Použití:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Hodnoty příznaků jsou samostatné argumenty: "--theme dracula", ne "--theme=dracula"
(tvar "=" je odmítnut jako neznámý příznak).

Volby:
  --theme <název>         Název tématu: dark, light, dracula, tokyo-night, nord (výchozí: dark)
  --padding <n>           odsazení kolem obsahu (výchozí: 24)
  --no-window             Skrýt výzdobu okna (světla, titulek)
  --no-shadow             Vypnout stín
  --no-border             Skrýt rámeček
  --line-numbers          Zobrazit čísla řádků
  --border-radius <n>     Poloměr rohů (výchozí: 8)
  --window-style <styl>   Styl okna: macos, windows-terminal, iterm, hyper, none (výchozí: macos)
  --ligatures             Povolit ligatury (font-variant-ligatures: normal; pouze SVG)
  --font <cesta>          Cesta ke souboru písma TTF/OTF/WOFF/WOFF2 pro vložení do SVG (pouze SVG)
  --unsafe-paths          Povolit cesty vstupu/--font/--output mimo CWD
  --highlight <start:konec[:barva]>
                          Zvýraznit řádky start:konec s volitelnou barvou (výchozí barva: #fffbe6; pouze SVG)
  --format <svg|png>      Formát výstupu (výchozí: svg; png vyžaduje ext-gd)
  -t, --type <jazyk>      Vynutit jazyk zvýraznění syntaxe (výchozí: název souboru, shebang, obsah)
  -o, --output <cesta>    Zapsat výstup do souboru místo na stdout
  -h, --help              Zobrazit tuto nápovědu

Poznámky:
  Cesty vstupu a výstupu musí zůstat uvnitř aktuálního pracovního adresáře,
  pokud není zadáno --unsafe-paths. Výstup PNG ignoruje výše uvedené pouze-SVG
  volby a každou vynechanou volbu oznámí na stderr.

Návratové kódy:
  0  úspěch
  1  chybějící composer autoload (problém s instalací)
  2  chyba použití nebo vstupu (špatný příznak, nečitelná cesta, zamítnuté zvýraznění, ...)

Příklady:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
