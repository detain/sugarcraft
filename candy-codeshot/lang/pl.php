<?php

/**
 * Polish translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: nierozpoznana flaga '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: nieznany motyw '{name}'. Znane: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: nieznany styl okna '{style}'. Znane: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: nieznany format '{format}'. Znane: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd jest wymagany do wyjścia PNG',
    'cli.font_not_found'      => "candy-codeshot: nie znaleziono pliku czcionki: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' nie jest prawidłowym plikiem czcionki (oczekiwano TTF, OTF, WOFF lub WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: nieprawidłowy format podświetlenia '{format}'. Użyj start:koniec (np. 3:7) lub start:koniec:#kolor (np. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: nie można odczytać danych wejściowych',
    'cli.path_outside_cwd'    => "candy-codeshot: ścieżka '{path}' jest poza bieżącym katalogiem roboczym (użyj --unsafe-paths, aby zezwolić)",
    'cli.write_failed'        => "candy-codeshot: nie można zapisać do '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} wymaga wartości",
    'cli.bad_number'          => "candy-codeshot: {option} oczekuje liczby całkowitej nieujemnej, otrzymano '{value}'",
    'cli.png_ignores'         => "candy-codeshot: wyjście PNG ignoruje opcje tylko-SVG: {options} (użyj --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: odrzucono podświetlenie '{spec}': {reason}",
    'cli.format_mismatch'     => "candy-codeshot: format wyjściowy '{format}' nie pasuje do rozszerzenia '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - renderuje kod lub wyjście terminala do zrzutu SVG albo PNG

Użycie:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Wartości flag są osobnymi argumentami: "--theme dracula", a nie "--theme=dracula"
(postać "=" jest odrzucana jako nierozpoznana flaga).

Opcje:
  --theme <nazwa>         Nazwa motywu: dark, light, dracula, tokyo-night, nord (domyślnie: dark)
  --padding <n>           Margines wokół treści (domyślnie: 24)
  --no-window             Ukryj wystrój okna (sygnalizator, pasek tytułu)
  --no-shadow             Wyłącz cień
  --no-border             Ukryj ramkę
  --line-numbers          Pokaż numery linii
  --border-radius <n>     Promień rogów (domyślnie: 8)
  --window-style <styl>   Styl okna: macos, windows-terminal, iterm, hyper, none (domyślnie: macos)
  --ligatures             Włącz ligatury (font-variant-ligatures: normal; tylko SVG)
  --font <ścieżka>        Ścieżka do pliku czcionki TTF/OTF/WOFF/WOFF2 do osadzenia w SVG (tylko SVG)
  --unsafe-paths          Zezwól na ścieżki wejścia/--font/--output poza CWD
  --highlight <start:koniec[:kolor]>
                          Podświetl linie start:koniec z opcjonalnym kolorem (domyślny kolor: #fffbe6; tylko SVG)
  --format <svg|png>      Format wyjściowy (domyślnie: svg; png wymaga ext-gd)
  -t, --type <język>      Wymuś język podświetlania składni (domyślnie: nazwa pliku, shebang, treść)
  -o, --output <ścieżka>  Zapisz wyjście do pliku zamiast na stdout
  -h, --help              Pokaż ten komunikat pomocy

Uwagi:
  Ścieżki wejścia i wyjścia muszą pozostać w bieżącym katalogu roboczym,
  chyba że podano --unsafe-paths. Wyjście PNG ignoruje opcje tylko-SVG i zgłasza
  każdą odrzuconą opcję na stderr.

Kody wyjścia:
  0  sukces
  1  brakujący autoload composera (problem z instalacją)
  2  błąd użycia lub wejścia (zła flaga, nieodczytywalna ścieżka, odrzucone podświetlenie, ...)

Przykłady:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
