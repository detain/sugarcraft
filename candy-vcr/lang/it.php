<?php

/**
 * it translations for candy-vcr.
 *
 * Placeholder names are identical across locales; only their order may move.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'app.unknown_subcommand' => 'candy-vcr: sottocomando sconosciuto \'{name}\'',
    'app.usage' => 'uso: candy-vcr <sottocomando> [opzioni...]',
    'app.subcommands' => 'sottocomandi:',
    'app.version_flag' => 'Stampa la versione ed esce.',
    'cli.usage_label' => 'uso:',
    'cli.err' => 'candy-vcr {verb}: {message}',
    'cli.unknown_option' => 'candy-vcr {verb}: opzione sconosciuta {option}',
    'cli.idle_trim_positive' => '--idle-trim deve essere > 0 secondi',
    'cli.idle_trim_requires' => '--idle-trim richiede un numero positivo di secondi',
    'choice.invalid' => '{flag} deve essere uno tra {known}, ricevuto "{got}"',
    'choice.invalid_type' => '{flag} deve essere uno tra {known}, ricevuto {got}',
    'fps.not_numeric' => '--fps deve essere un numero positivo, ricevuto {value}.',
    'fps.not_positive' => '--fps deve essere un numero finito e positivo di fotogrammi al secondo, ricevuto {value}.',
    'summary.record' => 'Registra la sessione PTY di un comando in una cassetta',
    'summary.inspect' => 'Elenca gli eventi (o i fotogrammi con --frames) di una cassetta',
    'summary.replay' => 'Trasmette su stdout l\'output registrato di una cassetta',
    'summary.diff' => 'Confronta due cassette evento per evento',
    'summary.stats' => 'Mostra le statistiche della cassetta',
    'summary.migrate' => 'Migra una cassetta alla versione di formato corrente',
    'desc.render_tape' => 'Renderizza un file .tape in un .gif',
    'desc.render_batch' => 'Renderizza tutti i file .tape di una directory',
    'opt.tape' => 'Percorso del file .tape',
    'opt.dir' => 'Directory contenente i file .tape',
    'opt.output_gif' => 'Percorso del .gif di output (predefinito: come l\'input con estensione .gif)',
    'opt.output_dir' => 'Directory di output dei .gif (predefinita: la directory di origine)',
    'opt.font' => 'Nome della famiglia di font TTF (predefinito: JetBrainsMono)',
    'opt.recursive' => 'Cerca ricorsivamente',
    'opt.theme' => 'Nome del tema (predefinito: TokyoNight)',
    'opt.fps' => 'Fotogrammi al secondo (predefinito: 30)',
    'opt.backend' => 'Backend di rasterizzazione: gd|imagick (predefinito: gd)',
    'opt.encoder' => 'Encoder GIF: ffmpeg|php (predefinito: ffmpeg)',
    'opt.strict' => 'Errore sulle direttive sconosciute invece di ignorarle',
    'opt.shell' => 'Esegue i comandi digitati in questa shell sotto un vero PTY e cattura l\'output del programma (modalità exec). Sovrascrive il Set Shell del tape.',
    'opt.exec' => 'Abilita la modalità exec con una shell predefinita ($SHELL o /bin/sh) quando il tape non imposta Set Shell',
    'opt.dry_run' => 'Stampa il flusso di eventi compilato come JSONL invece di scrivere un GIF',
    'render.failed' => 'Fallito: {message}',
    'render.cwd_hint' => ' (cwd: {cwd})',
    'render.tape_not_found' => 'file tape non trovato: {path}{hint}',
    'render.tape_too_large' => 'il file tape {path} supera il limite di {cap} byte',
    'render.cannot_read_tape' => 'impossibile leggere il file tape: {path}',
    'render.rendering' => 'Rendering di {name} ...',
    'render.progress' => '  {frames} fotogrammi renderizzati (celle di {w}x{h} px)',
    'render.warning' => 'Avviso: {warning}',
    'render.gif_written' => 'GIF scritto in {path}',
    'batch.not_directory' => 'Non è una directory: {dir}',
    'batch.none_found' => 'Nessun file .tape trovato in {dir}',
    'batch.rendering' => 'Rendering di {total} tape in {dir}',
    'batch.output_directory' => 'Directory di output: {dir}',
    'batch.claimed' => 'saltato — {gif} già riservato da {tape}',
    'batch.col_filename' => 'file',
    'batch.col_status' => 'stato',
    'batch.col_time' => 'tempo',
    'batch.total' => 'Totale: {total} | Riusciti: {ok} | Falliti: {failed} | Tempo: {seconds}s',
    'diff.identical' => 'le cassette sono identiche (campi dell\'intestazione uguali; eventi uguali per tipo, contenuto e timestamp entro 2 ms)',
    'diff.count' => '{count} differenza/e',
    'diff.dimensions' => 'dimensioni dell\'intestazione: {a_cols}x{a_rows} != {b_cols}x{b_rows}',
    'diff.event_count' => 'numero di eventi: {a} != {b}',
    'diff.missing_in_a' => 'event[{index}]: assente in A, presente in B ({kind})',
    'diff.missing_in_b' => 'event[{index}]: presente in A ({kind}), assente in B',
    'diff.kind_differs' => 'event[{index}]: tipo {a} != {b}',
    'diff.time_differs' => 'event[{index}]: t differisce ({a} != {b})',
    'diff.payload_differs' => 'event[{index}] contenuto diverso ({kind})',
    'inspect.fps_requires_value' => '--fps richiede un valore',
    'inspect.since_invalid' => '--since deve essere un numero non negativo',
    'inspect.until_invalid' => '--until deve essere un numero non negativo',
    'inspect.shown' => '{shown} / {total} evento/i mostrato/i',
    'inspect.frames_footer' => 'frames: {total}  unici: {unique}  deduplicati: {deduped}',
    'inspect.header' => 'cassetta v{version}  {cols}x{rows}  runtime={runtime}  created={created}  durata={duration}s  eventi={events}',
    'inspect.screenshot' => 'schermata: {path}',
    'migrate.help_dry_run' => 'Convalida la migrazione senza scrivere file',
    'migrate.help_info' => 'Mostra i migratori registrati ed esce',
    'migrate.help_input' => 'Input: solo cassette JSONL (.cas/.jsonl); gli input .cast/.yaml/.tape/.gz vengono rifiutati',
    'migrate.too_many' => 'troppi argomenti',
    'migrate.cannot_read' => 'impossibile leggere {path}',
    'migrate.refused_kind' => '{path} è un file {kind}; migrate legge solo cassette JSONL (.cas/.jsonl)',
    'migrate.kind_cast' => 'cast asciinema',
    'migrate.kind_tape' => 'script tape',
    'migrate.already_latest' => 'la cassetta è già all\'ultima versione di formato (v{version})',
    'migrate.dry_run' => 'dry-run: migrerebbe v{from} → v{to}',
    'migrate.output_gz' => 'l\'output non può essere un percorso .gz; migrate scrive JSONL semplice',
    'migrate.backup_symlink' => 'il percorso di backup {path} è un collegamento simbolico - scrittura attraverso di esso rifiutata',
    'migrate.backup_exists' => 'il backup {path} esiste già - sovrascrittura rifiutata',
    'migrate.backup_create_failed' => 'impossibile creare il backup in {path}',
    'migrate.chmod_failed' => 'impossibile restringere i permessi di {path}',
    'migrate.copy_failed' => 'impossibile copiare {input} in {backup}',
    'migrate.backed_up' => 'originale salvato in {path}',
    'migrate.write_failed' => 'impossibile scrivere {path}: {reason}',
    'migrate.done' => 'v{from} → v{to} migrato ({count} eventi)',
    'migrate.info_title' => 'sistema di migrazione di candy-vcr',
    'migrate.info_none' => 'nessun migratore registrato',
    'stats.unexpected_argument' => 'argomento inatteso {arg}',
    'stats.header' => 'cassetta v{version}  {cols}x{rows}',
    'stats.header_meta' => 'runtime={runtime}  created={created}',
    'stats.events' => 'Eventi: {count}',
    'stats.duration' => 'Durata: {seconds}s',
    'stats.raw_bytes' => 'byte grezzi({count})',
    'stats.input_msgs' => 'Msg di input: {parts}',
    'stats.output_bytes' => 'Byte di output: {bytes}',
    'stats.avg_output' => 'Output medio/evento: {bytes} byte',
    'replay.speed_invalid' => '--speed deve essere \'instant\' o \'realtime\'',
    'replay.help_speed' => '--speed predefinito: instant. Il moltiplicatore playbackSpeed
dell\'intestazione vale per il rendering (render-tape/render-batch), non per la riproduzione dal vivo.',
    'replay.interrupted' => 'interrotto dopo {played} di {total} eventi ({bytes} byte di output)',
    'replay.tape_no_output' => '{path} è una sorgente tape senza byte di output registrati — la riproduzione non mostra nulla; usa \'candy-vcr render-tape\' per renderizzare i file .tape',
    'replay.played' => 'riprodotti {played} di {total} eventi ({bytes} byte di output, {seconds} s di tempo registrato)',
    'record.output_gz' => '--output non può essere un percorso .gz; record scrive JSONL semplice — usa un nome .cas/.jsonl (gzip tramite CompressedJsonlFormat nell\'API)',
    'record.refuse_overwrite' => 'rifiuto di sovrascrivere la cassetta esistente {path} — passa --force per sostituirla',
    'record.command_not_found' => 'comando non trovato: {cmd}',
    'record.incomplete' => 'cattura della cassetta INCOMPLETA ({count} scrittura/e fallita/e) in {path}',
    'record.recorded' => 'candy-vcr: {path} registrato (uscita {code})',
    'record.output_requires' => '--output richiede un percorso',
    'record.env_regex_invalid' => '--env-regex=\'{regex}\' non è un pattern PCRE valido',
    'record.unknown_option' => 'opzione sconosciuta {option}',
    'record.shell_with_cmd' => '--shell non può essere combinato con un <cmd> posizionale',
    'record.dims_invalid' => '--cols e --rows devono essere interi positivi (cols={cols} rows={rows})',
    'record.env_all_warning' => 'candy-vcr record: ATTENZIONE --env-all ha catturato l\'intero ambiente dell\'host SENZA filtrare i segreti ({count} variabili): {names}',
    'record.none' => '(nessuna)',
    'record.termios_skipped' => 'snapshot termios saltato ({reason})',
    'record.raw_failed' => 'applicazione della modalità raw non riuscita ({reason})',
    'record.help_output' => 'File cassetta da scrivere (predefinito: session-<timestamp>.cas)',
    'record.help_force' => 'Sostituisce una destinazione --output esistente. Senza, record
rifiuta (uscita 2) invece di troncare in silenzio una cassetta
già presente su disco.',
    'record.help_cols' => 'Colonne iniziali del terminale (predefinito: 80)',
    'record.help_rows' => 'Righe iniziali del terminale (predefinito: 24)',
    'record.help_no_ctty' => 'Avvia senza terminale di controllo (Ctrl+C non raggiungerà il
programma registrato; predefinito: ctty attivo)',
    'record.help_shell' => 'Avvia $SHELL -l (o /bin/sh -l) invece di un <cmd> esplicito.
Mutuamente esclusivo con un <cmd> posizionale.',
    'record.help_env' => 'Cattura l\'ambiente dell\'host nell\'intestazione della cassetta.
Disattivato di default — la cattura è opzionale per non esporre
l\'intero ambiente shell del chiamante. Le chiavi che corrispondono
al regex dei segreti (vedi --env-regex) vengono rimosse.',
    'record.help_env_regex' => 'Sostituisce il regex di rimozione dei segreti (predefinito, prudente:
/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i).
Un pattern vuoto viene rifiutato — non si può più usare per
disattivare il filtro. Implica --env.',
    'record.help_env_all' => 'PERICOLOSO: cattura l\'INTERO ambiente dell\'host senza filtrare i
segreti — la cassetta conterrà le credenziali in chiaro e ogni nome
di variabile catturato viene stampato come avviso su stderr. Da usare
solo in ambienti isolati e fidati. Implica --env.
(--env-allow-secrets è un alias legacy.)',
    'record.help_idle_trim' => 'Comprime gli intervalli tra eventi più lunghi di N secondi.
Gli eventi accorciati portano `t` (compresso) e `tRaw` (originale)
così `replay --no-trim` può tornare al ritmo reale.',
];
