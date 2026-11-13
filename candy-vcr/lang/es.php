<?php

/**
 * es translations for candy-vcr.
 *
 * Placeholder names are identical across locales; only their order may move.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'app.unknown_subcommand' => 'candy-vcr: subcomando desconocido \'{name}\'',
    'app.usage' => 'uso: candy-vcr <subcomando> [opciones...]',
    'app.subcommands' => 'subcomandos:',
    'app.version_flag' => 'Muestra la versión y sale.',
    'cli.usage_label' => 'uso:',
    'cli.err' => 'candy-vcr {verb}: {message}',
    'cli.unknown_option' => 'candy-vcr {verb}: opción desconocida {option}',
    'cli.idle_trim_positive' => '--idle-trim debe ser > 0 segundos',
    'cli.idle_trim_requires' => '--idle-trim requiere un número positivo de segundos',
    'choice.invalid' => '{flag} debe ser uno de {known}, se recibió "{got}"',
    'choice.invalid_type' => '{flag} debe ser uno de {known}, se recibió {got}',
    'fps.not_numeric' => '--fps debe ser un número positivo, se recibió {value}.',
    'fps.not_positive' => '--fps debe ser un número finito y positivo de fotogramas por segundo, se recibió {value}.',
    'summary.record' => 'Graba la sesión PTY de un comando en un cassette',
    'summary.inspect' => 'Lista los eventos (o los fotogramas con --frames) de un cassette',
    'summary.replay' => 'Emite por stdout la salida grabada de un cassette',
    'summary.diff' => 'Compara dos cassettes evento por evento',
    'summary.stats' => 'Muestra estadísticas del cassette',
    'summary.migrate' => 'Migra un cassette a la versión de formato actual',
    'desc.render_tape' => 'Renderiza un archivo .tape a un .gif',
    'desc.render_batch' => 'Renderiza todos los archivos .tape de un directorio',
    'opt.tape' => 'Ruta del archivo .tape',
    'opt.dir' => 'Directorio que contiene archivos .tape',
    'opt.output_gif' => 'Ruta del .gif de salida (predeterminado: igual que la entrada con extensión .gif)',
    'opt.output_dir' => 'Directorio de salida de los .gif (predeterminado: el directorio de origen)',
    'opt.font' => 'Nombre de la familia de fuente TTF (predeterminado: JetBrainsMono)',
    'opt.recursive' => 'Buscar recursivamente',
    'opt.theme' => 'Nombre del tema (predeterminado: TokyoNight)',
    'opt.fps' => 'Fotogramas por segundo (predeterminado: 30)',
    'opt.backend' => 'Backend de rasterizado: gd|imagick (predeterminado: gd)',
    'opt.encoder' => 'Codificador GIF: ffmpeg|php (predeterminado: ffmpeg)',
    'opt.strict' => 'Fallar ante directivas desconocidas en lugar de omitirlas',
    'opt.shell' => 'Ejecutar los comandos tecleados en este shell bajo un PTY real y capturar la salida del programa (modo exec). Reemplaza el Set Shell del tape.',
    'opt.exec' => 'Activar el modo exec con un shell predeterminado ($SHELL o /bin/sh) cuando el tape no define Set Shell',
    'opt.dry_run' => 'Imprimir el flujo de eventos compilado como JSONL en lugar de escribir un GIF',
    'render.failed' => 'Falló: {message}',
    'render.cwd_hint' => ' (cwd: {cwd})',
    'render.tape_not_found' => 'archivo tape no encontrado: {path}{hint}',
    'render.tape_too_large' => 'el archivo tape {path} supera el límite de {cap} bytes',
    'render.cannot_read_tape' => 'no se puede leer el archivo tape: {path}',
    'render.rendering' => 'Renderizando {name} ...',
    'render.progress' => '  {frames} fotogramas renderizados (celdas de {w}x{h} px)',
    'render.warning' => 'Aviso: {warning}',
    'render.gif_written' => 'GIF escrito en {path}',
    'batch.not_directory' => 'No es un directorio: {dir}',
    'batch.none_found' => 'No se encontraron archivos .tape en {dir}',
    'batch.rendering' => 'Renderizando {total} tape(s) en {dir}',
    'batch.output_directory' => 'Directorio de salida: {dir}',
    'batch.claimed' => 'omitido — {gif} ya reservado por {tape}',
    'batch.col_filename' => 'archivo',
    'batch.col_status' => 'estado',
    'batch.col_time' => 'tiempo',
    'batch.total' => 'Total: {total} | Correctos: {ok} | Fallidos: {failed} | Tiempo: {seconds}s',
    'diff.identical' => 'los cassettes son idénticos (los campos de cabecera coinciden; los eventos coinciden en tipo, contenido y marcas de tiempo dentro de 2 ms)',
    'diff.count' => '{count} diferencia(s)',
    'diff.dimensions' => 'dimensiones de cabecera: {a_cols}x{a_rows} != {b_cols}x{b_rows}',
    'diff.event_count' => 'número de eventos: {a} != {b}',
    'diff.missing_in_a' => 'event[{index}]: falta en A, presente en B ({kind})',
    'diff.missing_in_b' => 'event[{index}]: presente en A ({kind}), falta en B',
    'diff.kind_differs' => 'event[{index}]: tipo {a} != {b}',
    'diff.time_differs' => 'event[{index}]: t difiere ({a} != {b})',
    'diff.payload_differs' => 'event[{index}] contenido difiere ({kind})',
    'inspect.fps_requires_value' => '--fps requiere un valor',
    'inspect.since_invalid' => '--since debe ser un número no negativo',
    'inspect.until_invalid' => '--until debe ser un número no negativo',
    'inspect.shown' => '{shown} / {total} evento(s) mostrado(s)',
    'inspect.frames_footer' => 'frames: {total}  únicos: {unique}  deduplicados: {deduped}',
    'inspect.header' => 'cassette v{version}  {cols}x{rows}  runtime={runtime}  created={created}  duración={duration}s  eventos={events}',
    'inspect.screenshot' => 'captura: {path}',
    'migrate.help_dry_run' => 'Validar la migración sin escribir archivos',
    'migrate.help_info' => 'Mostrar los migradores registrados y salir',
    'migrate.help_input' => 'Entrada: solo cassettes JSONL (.cas/.jsonl); se rechazan entradas .cast/.yaml/.tape/.gz',
    'migrate.too_many' => 'demasiados argumentos',
    'migrate.cannot_read' => 'no se puede leer {path}',
    'migrate.refused_kind' => '{path} es un archivo {kind}; migrate solo lee cassettes JSONL (.cas/.jsonl)',
    'migrate.kind_cast' => 'cast de asciinema',
    'migrate.kind_tape' => 'script tape',
    'migrate.already_latest' => 'el cassette ya está en la última versión de formato (v{version})',
    'migrate.dry_run' => 'dry-run: migraría v{from} → v{to}',
    'migrate.output_gz' => 'la salida no puede ser una ruta .gz; migrate escribe JSONL plano',
    'migrate.backup_symlink' => 'la ruta de copia {path} es un enlace simbólico - se rechaza escribir a través de él',
    'migrate.backup_exists' => 'la copia {path} ya existe - se rechaza sobrescribirla',
    'migrate.backup_create_failed' => 'no se pudo crear la copia en {path}',
    'migrate.chmod_failed' => 'no se pueden restringir los permisos de {path}',
    'migrate.copy_failed' => 'no se pudo copiar {input} a {backup}',
    'migrate.backed_up' => 'original copiado en {path}',
    'migrate.write_failed' => 'no se pudo escribir {path}: {reason}',
    'migrate.done' => 'v{from} → v{to} migrado ({count} eventos)',
    'migrate.info_title' => 'sistema de migración de candy-vcr',
    'migrate.info_none' => 'no hay migradores registrados',
    'stats.unexpected_argument' => 'argumento inesperado {arg}',
    'stats.header' => 'cassette v{version}  {cols}x{rows}',
    'stats.header_meta' => 'runtime={runtime}  created={created}',
    'stats.events' => 'Eventos: {count}',
    'stats.duration' => 'Duración: {seconds}s',
    'stats.raw_bytes' => 'bytes sin procesar({count})',
    'stats.input_msgs' => 'Msgs de entrada: {parts}',
    'stats.output_bytes' => 'Bytes de salida: {bytes}',
    'stats.avg_output' => 'Salida media/evento: {bytes} bytes',
    'replay.speed_invalid' => '--speed debe ser \'instant\' o \'realtime\'',
    'replay.help_speed' => '--speed es instant por omisión. El multiplicador playbackSpeed de la
cabecera se aplica al renderizado (render-tape/render-batch), no a la reproducción en vivo.',
    'replay.interrupted' => 'interrumpido tras {played} de {total} eventos ({bytes} bytes de salida)',
    'replay.tape_no_output' => '{path} es una fuente tape sin bytes de salida grabados — la reproducción no muestra nada; use \'candy-vcr render-tape\' para renderizar archivos .tape',
    'replay.played' => 'reproducidos {played} de {total} eventos ({bytes} bytes de salida, {seconds} s de tiempo grabado)',
    'record.output_gz' => '--output no puede ser una ruta .gz; record escribe JSONL plano — use un nombre .cas/.jsonl (gzip mediante CompressedJsonlFormat en la API)',
    'record.refuse_overwrite' => 'se rechaza sobrescribir el cassette existente {path} — pase --force para reemplazarlo',
    'record.command_not_found' => 'comando no encontrado: {cmd}',
    'record.incomplete' => 'captura del cassette INCOMPLETA ({count} escritura(s) fallida(s)) en {path}',
    'record.recorded' => 'candy-vcr: {path} grabado (salida {code})',
    'record.output_requires' => '--output requiere una ruta',
    'record.env_regex_invalid' => '--env-regex=\'{regex}\' no es un patrón PCRE válido',
    'record.unknown_option' => 'opción desconocida {option}',
    'record.shell_with_cmd' => '--shell no puede combinarse con un <cmd> posicional',
    'record.dims_invalid' => '--cols y --rows deben ser enteros positivos (cols={cols} rows={rows})',
    'record.env_all_warning' => 'candy-vcr record: AVISO --env-all capturó todo el entorno del host SIN filtrar secretos ({count} variables): {names}',
    'record.none' => '(ninguna)',
    'record.termios_skipped' => 'instantánea de termios omitida ({reason})',
    'record.raw_failed' => 'falló la aplicación del modo raw ({reason})',
    'record.help_output' => 'Archivo de cassette a escribir (predeterminado: session-<marca de tiempo>.cas)',
    'record.help_force' => 'Reemplazar un destino --output existente. Sin esto, record
se niega (salida 2) en lugar de truncar en silencio un
cassette que ya está en disco.',
    'record.help_cols' => 'Columnas iniciales del terminal (predeterminado: 80)',
    'record.help_rows' => 'Filas iniciales del terminal (predeterminado: 24)',
    'record.help_no_ctty' => 'Lanzar sin terminal de control (Ctrl+C no llegará al
programa grabado; predeterminado: ctty activado)',
    'record.help_shell' => 'Lanzar $SHELL -l (o /bin/sh -l) en lugar de un <cmd> explícito.
Excluyente con un <cmd> posicional.',
    'record.help_env' => 'Capturar el entorno del host en la cabecera del cassette.
Desactivado por omisión — la captura es opcional para no filtrar
el entorno completo del shell. Las claves que coinciden con el
regex de secretos (véase --env-regex) se eliminan.',
    'record.help_env_regex' => 'Sustituir el regex que elimina secretos (predeterminado, prudente:
/(SECRET|TOKEN|KEY|PASSWORD|API|CRED|AUTH|PRIV)/i).
Un patrón vacío se rechaza — ya no sirve para desactivar
el filtrado. Implica --env.',
    'record.help_env_all' => 'PELIGROSO: capturar TODO el entorno del host sin filtrar secretos
— el cassette contendrá las credenciales en claro y cada nombre de
variable capturado se imprime como aviso en stderr. Úselo solo en
entornos aislados y de confianza. Implica --env.
(--env-allow-secrets es un alias heredado.)',
    'record.help_idle_trim' => 'Comprimir los huecos entre eventos mayores de N segundos.
Los eventos recortados llevan `t` (comprimido) y `tRaw` (original)
para que `replay --no-trim` recupere el ritmo real.',
];
