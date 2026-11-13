<?php

/**
 * Italian translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: flag non riconosciuto '{flag}'",
'cli.unexpected_argument'  => "candy-codeshot: argomento inatteso '{arg}' - al massimo un file di input",
    'cli.unknown_theme'       => "candy-codeshot: tema sconosciuto '{name}'. Conosciuti: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: stile finestra sconosciuto '{style}'. Conosciuti: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: formato sconosciuto '{format}'. Conosciuti: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd è obbligatorio per l\'output PNG',
    'cli.font_not_found'      => "candy-codeshot: file di font non trovato: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' non è un file di font valido (attesi TTF, OTF, WOFF o WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: formato evidenziazione non valido '{format}'. Usa inizio:fine (es. 3:7) o inizio:fine:#colore (es. 3:7:#fffbe6)",
    'cli.read_failed'          => "candy-codeshot: impossibile leggere l'input da '{path}'",
    'cli.read_failed_stdin'     => "candy-codeshot: impossibile leggere l'input da stdin",
    'cli.input_too_large'     => "candy-codeshot: l'input supera il limite di {limit} byte (lettura rifiutata)",
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
                          Evidenzia le righe inizio:fine con colore opzionale (colore predefinito: il colore di evidenziazione del tema; solo SVG)
  --format <svg|png>      Formato di output (predefinito: svg; png richiede ext-gd)
  -t, --type <lingua>     Forza la lingua per l'evidenziazione sintattica (predefinito: nome file, shebang, contenuto)
  -o, --output <percorso> Scrivi l'output in un file invece che su stdout
  -h, --help              Mostra questo messaggio di aiuto
  -v, --version           Stampa la versione ed esci

Note:
  I percorsi di input e output devono restare nella directory di lavoro corrente,
  salvo --unsafe-paths. L'output PNG ignora le opzioni solo-SVG e annuncia ciascuna
  opzione scartata su stderr. PNG senza file --output è rifiutato quando
  stdout è un terminale; la lettura interattiva di stdin stampa un avviso
  e attende Ctrl-D.
  Un "-" isolato designa stdin (comunque il default senza file); "--" termina l'analisi delle opzioni e
  ogni token successivo è un operando. I valori di theme e window-style sono confrontati senza distinzione
  di maiuscole e di trattini bassi ("Tokyo_Night" e "tokyo-night" scelgono lo stesso tema).

Codici di uscita:
  0  successo
  1  errore di I/O (input illeggibile, output non scrivibile, autoload mancante)
  2  errore di uso o input (flag sconosciuto, valore mancante, numero non valido, tema/formato/stile sconosciuto, percorso o font rifiutato, evidenziazione rifiutata, directory come input, ext-gd mancante, PNG verso il terminale rifiutato, rifiuto durante il rendering)

Esempi:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
    'cli.is_directory'        => 'candy-codeshot: il percorso di input "{path}" è una directory - passa un file o invia via stdin',
    'cli.png_to_tty'          => 'candy-codeshot: rifiuto di scrivere byte PNG grezzi su un terminale (usa --output <file.png>)',
    'cli.stdin_tty'           => 'candy-codeshot: lettura da stdin - passa un file o invia l\'input (Ctrl-D per terminare)',
    'cli.render_failed'     => 'candy-codeshot: rendering non riuscito: {reason}',
    'cli.pixels_exceeded'    => 'candy-codeshot: output {format} troppo grande ({width} x {height} = {pixels} pixel; limite {cap}). Riduci contenuto, margine o numero di righe.',
    'cli.font_too_large'     => 'candy-codeshot: file del font troppo grande da incorporare: {size} byte superano il limite di {cap} ({path})',
];
