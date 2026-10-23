<?php

/**
 * Italian translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: flag non riconosciuto '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: tema sconosciuto '{name}'. Conosciuti: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: stile finestra sconosciuto '{style}'. Conosciuti: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: formato sconosciuto '{format}'. Conosciuti: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd è obbligatorio per l\'output PNG',
    'cli.font_not_found'      => "candy-codeshot: file di font non trovato: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' non è un file di font valido (attesi TTF, OTF, WOFF o WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: formato evidenziazione non valido '{format}'. Usa inizio:fine (es. 3:7) o inizio:fine:#colore (es. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: impossibile leggere l\'input',
    'cli.path_outside_cwd'    => "candy-codeshot: il percorso '{path}' è fuori dalla directory di lavoro corrente (usare --unsafe-paths per consentirlo)",
    'cli.write_failed'        => "candy-codeshot: impossibile scrivere '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} richiede un valore",
    'cli.bad_number'          => "candy-codeshot: {option} prevede un intero non negativo, ricevuto '{value}'",
    'cli.png_ignores'         => "candy-codeshot: l'output PNG ignora le opzioni solo-SVG: {options} (usare --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: evidenziazione '{spec}' rifiutata: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: il formato di output '{format}' non corrisponde all'estensione di '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - renderizza codice o output del terminale in uno screenshot SVG o PNG

Uso:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

I valori dei flag sono argomenti separati: "--theme dracula", non "--theme=dracula"
(la forma "=" è rifiutata come flag non riconosciuto).

Opzioni:
  --theme <nome>          Nome del tema: dark, light, dracula, tokyo-night, nord (predefinito: dark)
  --padding <n>           Spazio attorno al contenuto (predefinito: 24)
  --no-window             Nascondi la finestra (semafori, barra del titolo)
  --no-shadow             Disattiva l'ombra
  --no-border             Nascondi il bordo
  --line-numbers          Mostra i numeri di riga
  --border-radius <n>     Raggio degli angoli (predefinito: 8)
  --window-style <stile>  Stile finestra: macos, windows-terminal, iterm, hyper, none (predefinito: macos)
  --ligatures             Abilita le legature (font-variant-ligatures: normal; solo SVG)
  --font <percorso>       Percorso di un file di font TTF/OTF/WOFF/WOFF2 da incorporare nel SVG (solo SVG)
  --unsafe-paths          Consenti percorsi di input/--font/--output fuori dalla CWD
  --highlight <inizio:fine[:colore]>
                          Evidenzia le righe inizio:fine con colore opzionale (colore predefinito: #fffbe6; solo SVG)
  --format <svg|png>      Formato di output (predefinito: svg; png richiede ext-gd)
  -t, --type <lingua>     Forza la lingua per l'evidenziazione sintattica (predefinito: nome file, shebang, contenuto)
  -o, --output <percorso> Scrivi l'output in un file invece che su stdout
  -h, --help              Mostra questo messaggio di aiuto

Note:
  I percorsi di input e output devono restare nella directory di lavoro corrente,
  salvo --unsafe-paths. L'output PNG ignora le opzioni solo-SVG e annuncia ciascuna
  opzione scartata su stderr.

Codici di uscita:
  0  successo
  1  autoload composer mancante (problema di installazione)
  2  errore d'uso o di input (flag non valido, percorso illeggibile, evidenziazione rifiutata, ...)

Esempi:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
