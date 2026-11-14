<?php

/**
 * Spanish translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'archivo SQLite no encontrado: {path}',
    'complete.in_literal' => 'nada que completar dentro de una cadena o comentario',
    'complete.nothing' => 'nada que completar — escribe el inicio de una palabra clave',
    'complete.none_keyword' => 'ninguna palabra clave completa "{word}"',
    'complete.none_table' => 'ninguna tabla completa "{word}"',
    'complete.none_column' => 'ninguna columna completa "{word}"',
    'complete.none_qualified' => 'ninguna columna de {qualifier} completa "{word}"',
    'complete.list' => '{count} completan "{word}": {list}{more}',
    'complete.done' => 'completado {word} → {insert}',
    'complete.inserted' => 'insertado {insert}',
    'edit.refuse_readonly' => 'sesión de solo lectura — la edición está desactivada',
    'edit.refuse_no_table' => 'abre primero la vista previa de una tabla — los resultados de consultas no se pueden editar en sitio',
    'edit.refuse_no_rows' => 'no hay ninguna fila bajo el cursor',
    'edit.refuse_no_pk' => 'esta tabla no tiene clave primaria — las filas no se pueden direccionar con seguridad; escribe la sentencia en el panel de consulta',
    'edit.refuse_pk_hidden' => 'la clave primaria de esta fila es NULL o un marcador binario — no se puede direccionar',
    'edit.refuse_binary' => 'las celdas binarias o grandes no se pueden editar aquí — usa una sentencia en el panel de consulta',
    'edit.refuse_schema' => 'no se puede leer el esquema de {table} — edición rechazada',
    'edit.prompt_cell' => 'editar {table}.{column} = {value}  (enter previsualiza · ctrl+n NULL · esc cancela)',
    'edit.prompt_insert' => 'nueva fila en {table} — {column} ({type}) [{n}/{total}] = {value}  (enter siguiente · vacío = DEFAULT · ctrl+n NULL · esc cancela)',
    'edit.prompt_confirm' => '{sql}  (enter confirma · esc cancela)',
    'edit.cancelled' => 'edición cancelada',
    'edit.unchanged' => 'valor sin cambios — nada escrito',
    'edit.done_update' => 'actualizada 1 fila en {table}',
    'edit.done_insert' => 'insertada 1 fila en {table}',
    'edit.done_delete' => 'eliminada 1 fila de {table}',
    'edit.none_changed' => 'ninguna fila cambió — el valor ya estaba o la fila ya no existe; vista previa actualizada',
    'edit.failed' => 'la escritura falló — revertida, nada cambió: {reason}',
    'edit.too_many' => 'rechazado: la sentencia afectaría a {count} filas — revertida, nada cambió',
    'ui.title.tables' => ' tablas ',
    'ui.title.rows' => ' filas ',
    'ui.title.query' => ' consulta ',
    'ui.title.admin' => ' admin ',
    'ui.title.explain' => ' plan ',
    'ui.help.query' => 'tab  cambiar panel  ·  ctrl+r / ctrl+e / ctrl+intro  ejecutar  ·  enter  nueva línea  ·  ↑/↓  historial  ·  esc / ctrl+c  cancelar consulta  ·  ctrl+c  salir en reposo',
    'ui.help.rows' => 'tab  cambiar panel  ·  j/k  fila  ·  h/l  celda  ·  e  editar  ·  o  nueva fila  ·  D  eliminar  ·  y  copiar  ·  x  exportar  ·  q / esc  salir',
    'ui.help.browse' => 'tab  cambiar panel  ·  enter  cargar tabla  ·  x  exportar  ·  i  importar  ·  q / esc  salir',
    'ui.help.empty' => 'tab  cambiar panel  ·  q / esc  salir',
    'param.prompt' => 'marcador {index}/{total} ({label}) - escriba el valor, Enter lo vincula (vacío = NULL), Esc cancela',
    'param.line' => 'parámetro {label} ({index}/{total}) = {value} · vacío = NULL · Enter sigue · Esc cancela',
    'param.cancel' => 'entrada de parámetro cancelada - consulta no ejecutada',
    'param.mixed' => 'mezclar ? y :name no está soportado - use un solo estilo',
    'help.title' => 'ayuda de teclas - panel {pane}',
    'help.close' => 'pulse cualquier tecla para cerrar',
    'help.cycle' => 'cambiar de panel',
    'help.list_cursor' => 'mover el cursor de lista',
    'help.editor_cursor' => 'mover el cursor del editor',
    'help.cell_cursor' => 'mover el cursor de celda',
    'help.edit_cell' => 'editar la celda bajo el cursor',
    'help.insert_row' => 'insertar fila',
    'help.delete_row' => 'eliminar la fila bajo el cursor',
    'help.yank_cell' => 'copiar la celda al portapapeles',
    'help.load_table' => 'cargar la tabla resaltada',
    'help.newline' => 'nueva línea en el editor',
    'help.run' => 'ejecutar la consulta',
    'help.fav_add' => 'guardar en favoritos',
    'help.fav_remove' => 'quitar de favoritos',
    'help.backspace' => 'borrar el último carácter',
    'help.file_line' => 'abrir la línea de exportación / importación',
    'help.history_trend' => 'leer la tendencia del historial',
    'help.tx' => 'control de transacciones (:tx)',
    'help.param_prompt' => 'pregunta cada parámetro vinculado',
    'help.overlay' => 'esta ayuda de teclas',
    'help.quit' => 'cancelar ejecución / salir según panel',
    'help.cancel_quit' => 'cancelar la consulta activa; si no, salir',
    'help.ad_pause' => 'pausar/reanudar la actualización automática',
    'help.ad_reset' => 'actualizar datos de la página (vaciar caché)',
    'help.ad_rows' => 'recorrer filas',
    'help.ad_edit' => 'editar la variable seleccionada',
    'help.ad_rw_filter' => 'alternar filtro lectura/escritura',
    'help.ad_search' => 'enfocar el campo de búsqueda',
    'help.ad_sleeping_filter' => 'alternar ocultar inactivas',
    'help.ad_kill' => 'preparar kill de conexión/consulta',
    'help.ad_confirm' => 'ejecutar la acción preparada (si no, cancelar)',
    'help.ad_stage' => 'preparar commit (PerfSchema)',
    'help.ad_units' => 'alternar formato de unidades (Informes)',
    'help.ad_csv' => 'generar CSV en el estado de la página (Informes)',
    'help.ad_alerts' => 'descartar todas las alertas (Panel)',
    'help.ad_debug' => 'borrar registro / carril de errores (Depuración)',
    'help.ad_pages' => 'cambiar de página de administración',
    'yank.refuse_no_cell' => 'sin celda bajo el cursor',
    'yank.osc52' => 'celda copiada al portapapeles - el terminal puede ignorar OSC 52',
    'yank.plain' => 'sin OSC 52 en este terminal - texto de la celda: {text}',
    'yank.plain_null' => 'sin OSC 52 en este terminal - la celda es NULL',
    'yank.too_big' => 'la celda tiene {bytes} bytes - supera el límite OSC 52',
    'ui.help.result' => 'tab  cambiar panel  ·  j/k  fila  ·  h/l  celda  ·  y  copiar  ·  x  exportar  ·  q / esc  salir',
    'ui.help.admin' => '1-{count}  elegir página  ·  j/k  navegar  ·  p  pausar  ·  r  reiniciar  ·  tab  cambiar panel  ·  q  volver  ·  esc  cancelar diálogo o consulta',
    'ui.no_tables' => '(sin tablas)',
    'ui.no_match' => '(sin coincidencias)',
    'ui.loading' => 'cargando…',
    'ui.empty' => '(vacío)',
    'ui.admin_unavailable' => 'página de administración no disponible: {reason}',
    'ui.error' => 'error: {message}',
    'io.prompt_export' => 'a CSV → {path}  (enter escribe · esc cancela)',
    'io.prompt_replace' => '¿REEMPLAZAR {path}?  (enter confirma · esc cancela)',
    'io.prompt_import_path' => 'importar CSV ← {path}  (enter · esc cancela)',
    'io.prompt_import_table' => 'importando {source} → tabla {table}  (enter · esc cancela)',
    'bar.tables' => 'Tablas: {count}',
    'bar.cycle' => 'Tab:cambiar',
    'bar.navigate' => '↑↓:navegar',
    'bar.load' => 'Enter:cargar',
    'bar.run' => 'Ctrl+R:ejecutar',
    'bar.select' => '1-{count}:elegir',
    'bar.nav' => 'j/k:nav',
    'bar.quit' => 'q:salir',
    'bar.paused' => '[PAUSADO]',
    'bar.tx' => '[TX]',
    'picker.title' => 'conexiones guardadas',
    'picker.help' => '[↑↓/j/k] elegir   [Enter] conectar   [q] cancelar',
    'result.empty' => '(conjunto de resultados vacío)',
    'explain.no_plan' => '(sin plan de consulta — la consulta no devolvió filas)',
    'explain.title' => ' PLAN DE CONSULTA ',
    'status.live_refresh_off' => 'actualización en vivo de admin desactivada — ssl-mode verificado por TLS (solo transporte síncrono)',
    'status.async_failed' => 'El comando asíncrono falló: {message}',
    'status.file_cancelled' => 'línea de archivo cancelada',
    'status.path_required' => 'se requiere una ruta — esc cancela',
    'status.no_such_file' => 'no existe el archivo — siga escribiendo o esc cancela',
    'status.table_required' => 'indique la tabla de destino — esc cancela',
    'status.export_failed' => 'falló la exportación: {message}',
    'status.exported' => '{count} filas exportadas a {path}',
    'status.import_failed' => 'falló la importación: {message}',
    'status.imported' => '{path} importado en {table}',
    'status.history' => 'historial {pos}/{count}',
    'status.conn_lost' => 'conexión perdida; reintente',
    'status.destructive' => '⚠ sentencia destructiva — ctrl+r de nuevo (sin modificar) para confirmar',
    'status.running' => 'ejecutando consulta…',
    'status.explain_failed' => 'falló EXPLAIN: {message}',
    'status.explain_plan' => 'plan de ejecución — cualquier consulta o vista previa de tabla lo reemplaza',
    'status.end_of_table' => 'fin de la tabla — no hay más páginas',
    'status.first_page' => 'ya está en la primera página',
    'status.nothing_to_save' => 'nada que guardar',
    'status.saved' => 'guardado ({count})',
    'status.removed' => 'eliminado ({count})',
    'status.row_one' => '1 fila',
    'status.rows' => '{count} filas',
    'status.page' => 'página {page} (filas {first}–{last})',
    'editor.placeholder' => '-- escriba SQL, ctrl+r para ejecutar --',
    'err.async_query_unsupported' => 'Este controlador no admite consultas asíncronas',
    'err.async_browse_unsupported' => 'Exploración asíncrona no admitida por este controlador',
    'dash.title' => 'Panel de rendimiento',
    'dash.fetching' => 'Obteniendo métricas del servidor...',
    'dash.back_hint' => 'Pulse [q] para volver a la vista de tablas',
    'dash.recent_queries' => 'Consultas recientes',
    'dash.no_queries' => '(aún no hay consultas)',
    'dash.header_pg' => 'Panel de rendimiento{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Panel de rendimiento{status} | MySQL {version} | Tiempo activo: {uptime}',
    'dash.uptime_segment' => ' | Tiempo activo: {uptime}',
    'pane.process_list' => 'Lista de procesos',
    'pane.variables' => 'Variables',
    'pane.status' => 'Estado',
    'pane.query_stats' => 'Estadísticas de consultas',
    'pane.dashboard' => 'Panel',
    'pane.table_stats' => 'Estadísticas de tablas',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Depuración',
    'page.no_data' => 'Aún no hay datos disponibles.',
    'page.error' => 'Error: {message}',
    'page.loading' => 'Cargando…',
    'page.fetching' => 'Obteniendo datos del servidor.',
    'debug.title' => 'Registro de depuración',
    'debug.entries' => ' ({count} entradas)',
    'debug.errors_only' => '[solo errores]',
    'debug.empty' => '(sin entradas de registro)',
    'debug.more' => '↑ {count} entradas más',
    'debug.help' => '[j/k] nav  [f] solo errores  [c] limpiar  [q] volver',
    'reports.cannot_access' => 'No se puede acceder a los informes de rendimiento: {message}',
    'reports.title' => 'Informes de rendimiento',
    'reports.categories' => 'Categorías',
    'reports.no_data' => '(sin datos)',
    'reports.loading' => 'Cargando informes…',
    'reports.loading_one' => 'Cargando {caption}…',
    'reports.no_data_for' => 'No hay datos disponibles para {caption}',
    'reports.help' => '[j/k] filas  [h/l] categoría  [[]/[]] informe  [,/.] columna  [c] unidad  [r] actualizar  [x] exportar  [q] volver',
    'reports.unavailable' => 'Informes de rendimiento no disponibles',
    'reports.ensure' => 'Asegúrese de usar MySQL 5.6.6+ con performance_schema=enabled y el esquema sys instalado.',
    'conn.kill_cancelled' => 'Kill cancelado',
    'conn.none_selected' => 'Ninguna conexión seleccionada',
    'conn.refuse_background' => 'Se rechaza terminar un hilo en segundo plano',
    'conn.verb_kill_query' => 'Kill de consulta',
    'conn.verb_kill' => 'Kill',
    'conn.sent' => '{verb} enviado a la conexión {id}',
    'conn.failed' => '{verb} falló para la conexión {id}',
    'conn.confirm_verb_query' => 'Cancelar consulta en ejecución en',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '¿{verb} conexión {id} ({user}@{host})?  [y] confirmar   [otra tecla] cancelar',
    'conn.help_empty' => '[j/k] nav  [f] filtro  [r] actualizar',
    'conn.help_full' => '[j/k] nav  [K] kill  [X] kill de consulta  [f] filtro  [r] actualizar',
    'conn.vars_fail' => 'No se pudieron obtener las variables del servidor',
    'vars.load_fail' => 'No se pudieron cargar las variables: {message}',
    'vars.unknown_error' => 'Error desconocido',
    'vars.not_dynamic' => 'Error 1238: \'{name}\' no es dinámica — pulse [p] para usar PERSIST_ONLY',
    'vars.new_value' => 'nuevo valor > ',
    'vars.edit_head' => 'Editar ({mode}) {name} (actual: {current})',
    'vars.scope_explain' => 'GLOBAL = solo en ejecución (se pierde al reiniciar) · PERSIST = ejecución + archivo de configuración · PERSIST_ONLY = solo archivo de configuración (se aplica en el próximo reinicio)',
    'vars.edit_help' => 'Introduzca el nuevo valor, [p] cambiar modo, [Enter] confirmar, [Esc] cancelar',
    'reports.back' => '[q] volver',
    'err.dsn_empty' => 'El DSN no puede estar vacío',
    'err.dsn_no_separator' => 'DSN no válido: falta el separador "://"',
    'err.driver_unsupported' => 'Controlador no admitido: {driver}',
    'err.dsn_sqlite_invalid' => 'DSN de SQLite no válido',
    'err.dsn_parse_failed' => 'DSN no válido: falló parse_url()',
    'err.dsn_no_host' => 'DSN no válido: falta el host',
    'err.driver_choose' => 'Controlador no admitido: {driver} — elija uno de {list}',
    'err.args_missing' => 'Faltan argumentos obligatorios: pase --driver y --db (o use --dsn)',
    'err.opt_needs_value' => 'La opción --{name} requiere un valor',
    'err.opt_unknown' => 'Opción desconocida: --{name}',
    'err.csv_not_found' => 'Archivo CSV no encontrado: {path}',
    'err.csv_unreadable' => 'No se puede leer el archivo CSV \'{path}\': compruebe que existe y es legible',
    'err.csv_no_headers' => 'No se pueden leer los encabezados CSV de: {path}',
    'err.csv_no_columns' => 'El archivo CSV no tiene columnas: {path}',
    'err.dest_unwritable' => 'No se puede abrir el destino \'{path}\' para escritura: compruebe que el directorio existe y es escribible',
    'err.export_buffer' => 'No se pudo crear el búfer de exportación',
    'err.csv_partial' => 'La exportación CSV de {table} escribió {written} de {expected} filas — la tabla cambió durante la exportación o el controlador ignoró la ventana de paginación; se rechaza presentar un archivo parcial como completo',
    'err.sql_partial' => 'El volcado SQL de {table} escribió {written} de {expected} filas — la tabla cambió durante el volcado o el controlador ignoró la ventana de paginación; se rechaza presentar un archivo parcial como completo',
    'err.table_unreadable' => 'Tabla no encontrada o ilegible: {table} ({message})',
    'err.dml_no_prepare' => 'esta conexión no puede ejecutar sentencias preparadas',
    'err.dml_refused' => 'el servidor rechazó la sentencia',
    'alert.connection_critical' => 'Uso de conexiones críticamente alto',
    'alert.connection_elevated' => 'Uso de conexiones elevado',
    'alert.aborted_elevated' => 'Tasa de conexiones abortadas elevada',
    'alert.thread_elevated' => 'Cantidad de hilos en ejecución elevada',
    'alert.slow_query_time' => 'Tiempo de consulta lenta establecido en {seconds}s',
    'alert.connections_at' => 'Conexiones al {pct}% del máximo ({connected}/{max})',
    'alert.connection_error_one' => '{count} error de conexión detectado',
    'alert.connection_error_other' => '{count} errores de conexión detectados',
    'alert.threshold_ratio_connection_warning' => 'El umbral de aviso de conexiones debe estar entre 0.0 y 1.0',
    'alert.threshold_ratio_connection_critical' => 'El umbral crítico de conexiones debe estar entre 0.0 y 1.0',
    'alert.threshold_ratio_thread_running' => 'El umbral de hilos en ejecución debe estar entre 0.0 y 1.0',
    'alert.threshold_aborted_rate' => 'El umbral de tasa de abortos debe ser >= 0.0',
    'alert.threshold_slow_query' => 'El umbral de consulta lenta debe ser >= 0.0',
    'alert.threshold_connection_errors' => 'El umbral de errores de conexión debe ser >= 0',
    'history.err_mkdir' => 'No se puede crear el directorio del historial: {dir}',
    'history.err_chmod' => 'No se puede proteger el archivo del historial: {path} (modo {mode})',
    'history.err_unusable' => 'El almacén del historial no es utilizable: {error} — elimina el archivo del historial o corrige sus permisos para recuperarlo.',
    'query.cancelled' => 'Consulta asíncrona cancelada',
    'tx.unknown' => 'Comando desconocido — :tx inicia, confirma o revierte una transacción',
    'tx.async_refusal' => ':tx cubre la sesión sincrónica; el transporte asincrónico aún no tiene una rama de transacción coordinada — ejecute BEGIN/COMMIT explícitos o use la ruta sincrónica',
    'tx.already_open' => 'Ya hay una transacción abierta — :tx commit o :tx rollback',
    'tx.no_tx' => 'No hay transacción abierta — :tx inicia una',
    'tx.opened' => 'Transacción iniciada — las sentencias se agrupan hasta :tx commit o :tx rollback',
    'tx.committed' => 'Transacción confirmada',
    'tx.rolled_back' => 'Transacción revertida',
    'tx.arm_rollback' => 'ROLLBACK descarta todos los cambios desde BEGIN — pulse de nuevo la tecla de ejecución para confirmar',
    'tx.failed' => 'Error en el comando de transacción: {message}',
    'history.none' => 'Nada que leer — el historial de estado solo registra sesiones de la familia MySQL (opcional con --history)',
    'history.empty' => 'Sin muestras en los últimos {minutes} minutos — la tendencia necesita al menos dos',
    'history.unreadable' => 'Almacén de historial ilegible: {message}',
    'history.status' => 'Tendencia del historial — {samples} filas en los últimos {minutes} minutos',
    'query.timed_out' => 'La consulta asíncrona agotó el tiempo tras {seconds}s',
    'reports.err_meta_missing' => 'Archivo de metadatos de informes no encontrado: {path}',
    'reports.err_meta_read' => 'Error al leer el archivo de metadatos de informes: {path}',
    'reports.err_not_in_catalog' => 'Informe no encontrado en el catálogo: {name}',
    'reports.err_view_unavailable' => 'Vista no disponible en este servidor: {name}',
    'reports.err_loading' => 'Error al cargar el informe: {error}',
    'variables.err_meta_missing' => 'Archivo de metadatos de variables no encontrado: {path}',
    'variables.err_meta_read' => 'Error al leer el archivo de metadatos de variables: {path}',
    'resilience.statement_timeout_default' => 'Se agotó el tiempo de ejecución de la sentencia',
    'resilience.statement_timeout_after' => 'Se agotó el tiempo de ejecución de la sentencia tras {seconds} s',
    'variables.err_prepare' => 'No se pudo preparar la sentencia en esta conexión',
    'variables.err_not_editable' => 'Variable no editable',
    'variables.err_persist_mariadb' => 'Persistir requiere MariaDB 11.1+ — este servidor no implementa SET PERSIST.',
    'variables.err_persist_mysql' => 'Persistir requiere MySQL 8.0.16+ — este servidor no implementa SET PERSIST.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: nombre de usuario vacío — se rechaza conectar a MySQL con la identidad supuesta "root"; indica un usuario explícito',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: nombre de usuario vacío — se rechaza conectar a PostgreSQL con la identidad supuesta "postgres"; indica un usuario explícito',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: sslmode no reconocido "{mode}"; se esperaba uno de {allowed} — descartar un error tipográfico en silencio degradaría a "prefer", que admite texto plano',
    'store.err_read' => 'No se puede leer el archivo de conexiones: {path} (elimínalo o corrige sus permisos para recuperarlo)',
    'store.err_json' => 'El archivo de conexiones no es JSON válido ({error}): {path}',
    'store.err_shape' => 'El archivo de conexiones debe ser un objeto JSON con un mapa "connections": {path}',
    'store.err_connections_type' => 'El valor "connections" debe ser un objeto JSON de nombre -> ajustes: {path}',
    'store.err_unknown' => 'Conexión desconocida \'{name}\' — conexiones guardadas: {saved}',
    'store.none' => '(ninguna)',
    'store.err_pass_env_unset' => 'La conexión \'{name}\' lee su contraseña de ${var} — expórtala antes de conectar',
    'store.err_mkdir' => 'No se puede crear el directorio de conexiones: {dir}',
    'store.err_write' => 'No se puede escribir el archivo de conexiones: {path}',
    'store.err_replace' => 'No se puede reemplazar el archivo de conexiones: {path}',
    'store.err_entry_type' => 'La conexión \'{name}\' debe corresponder a un objeto JSON de ajustes',
    'store.err_plaintext_pass' => 'La conexión \'{name}\' guarda un \'pass\' en texto plano — connections.json nunca contiene secretos: reemplázalo por "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => 'La conexión \'{name}\' tiene un ajuste desconocido \'{field}\' — permitidos: {allowed}',
    'store.err_setting_type' => 'El ajuste \'{field}\' de la conexión \'{name}\' debe ser una cadena o un número',
    'store.err_setting_empty' => 'El ajuste \'{field}\' de la conexión \'{name}\' no debe estar vacío',
    'store.err_needs_driver' => 'La conexión \'{name}\' necesita un \'driver\'',
    'store.err_driver' => 'La conexión \'{name}\' usa un controlador no admitido \'{driver}\' — elige uno de: {allowed}',
    'store.err_port' => 'El ajuste \'port\' de la conexión \'{name}\' debe ser un número',
    'store.err_pass_env' => 'El ajuste \'pass-env\' de la conexión \'{name}\' debe ser un nombre de variable de entorno',
    'store.err_name' => 'Nombre de conexión no válido \'{name}\' — usa letras, dígitos, punto, guion o guion bajo (1-64 caracteres)',
    'store.err_secure' => 'No se puede proteger {path} (modo {mode})',
    'db.err_dsn_semicolon' => 'El host y el nombre de la base de datos no deben contener \';\' — es el separador de opciones del DSN de PDO',
    'db.err_unknown_pg_ssl' => 'ssl-mode de PostgreSQL desconocido: {mode}',
    'db.err_unknown_mysql_ssl' => 'ssl-mode de MySQL desconocido: {mode}',
    'db.err_pg_driver' => 'No se puede conectar a un controlador que no es PostgreSQL con el conector de PostgreSQL',
    'db.err_mysql_driver' => 'El conector de MySQL solo acepta el controlador mysql (recibido \'{driver}\')',
    'db.err_reconnect' => 'Error al reconectar tras un error de conexión: {error}',
    'db.err_quote_no_connection' => 'No se puede entrecomillar sin conexión',
    'dml.err_key_required' => 'se requiere una clave de fila: se rechaza una sentencia que podría afectar a más de una fila',
    'dml.err_key_null' => 'la columna clave {column} es NULL: no se puede direccionar la fila',
    'dml.err_empty_table' => 'el nombre de la tabla no debe estar vacío',
    'dml.err_empty_column' => 'el nombre de la columna no debe estar vacío',
    'dml.err_empty_key_column' => 'el nombre de la columna clave no debe estar vacío',
    'status.already_saved' => 'ya guardado ({count})',
    'status.not_in_favorites' => 'no está en favoritos ({count})',
    'status.gtid_mariadb' => 'GTID_MODE no está disponible: MariaDB no tiene la variable gtid_mode.',
    'status.gtid_version' => 'GTID_MODE requiere MySQL 5.7.6 o posterior (el servidor es {version}).',
    'caption.blocks_hit' => 'Bloques en caché',
    'caption.blocks_read' => 'Bloques leídos',
    'caption.buffer_pool_read_reqs' => 'Solicitudes de lectura del buffer pool',
    'caption.buffer_pool_usage' => 'Uso del buffer pool',
    'caption.buffer_pool_write_reqs' => 'Solicitudes de escritura del buffer pool',
    'caption.bytes_in' => 'Bytes entrantes',
    'caption.bytes_out' => 'Bytes salientes',
    'caption.cache_hit_rate' => 'Tasa de aciertos de caché',
    'caption.commits' => 'Confirmaciones',
    'caption.connections' => 'Conexiones',
    'caption.deadlocks' => 'Interbloqueos',
    'caption.disk_reads_not_from_pool' => 'Lecturas de disco (no del pool)',
    'caption.disk_write_requests' => 'Solicitudes de escritura en disco',
    'caption.doublewrite_writes' => 'Escrituras doublewrite',
    'caption.innodb_disk_reads' => 'Lecturas de disco de InnoDB',
    'caption.innodb_disk_writes' => 'Escrituras de disco de InnoDB',
    'caption.insert_buffer' => 'Búfer de inserción',
    'caption.pages_created' => 'Páginas creadas',
    'caption.pages_flushed' => 'Páginas volcadas',
    'caption.pages_read' => 'Páginas leídas',
    'caption.read_ahead' => 'Lectura anticipada',
    'caption.redo_log_bytes_written' => 'Bytes escritos en el redo log',
    'caption.redo_log_writes' => 'Escrituras del redo log',
    'caption.rollbacks' => 'Reversiones',
    'caption.row_lock_time' => 'Tiempo de bloqueo de filas',
    'caption.row_lock_waits' => 'Esperas de bloqueo de filas',
    'caption.shared_buffers' => 'Búferes compartidos',
    'caption.sql_statements' => 'Sentencias SQL',
    'caption.table_open_cache' => 'Caché de tablas abiertas',
    'caption.temp_bytes' => 'Bytes temporales',
    'caption.temp_files' => 'Archivos temporales',
    'caption.transactions' => 'Transacciones',
    'caption.tuples_fetched' => 'Tuplas obtenidas',
    'caption.tuples_returned' => 'Tuplas devueltas',
    'caption.traffic' => 'Tráfico',
    'caption.key_efficiency' => 'Eficiencia de claves',
    'caption.selects_per_second' => 'Selects por segundo',
    'caption.innodb_buffer_usage' => 'Uso del búfer de InnoDB',
    'caption.innodb_reads_per_second' => 'Lecturas de InnoDB por segundo',
    'caption.innodb_writes_per_second' => 'Escrituras de InnoDB por segundo',
    'widget.tip.bytes_in' => 'Tráfico entrante: {Bytes_received} bytes en total',
    'widget.tip.bytes_out' => 'Tráfico saliente: {Bytes_sent} bytes en total',
    'widget.tip.client_connections' => 'Conexiones de clientes: {Threads_connected}',
    'widget.tip.connections_level' => 'Nivel de conexiones frente a max_connections',
    'widget.tip.table_open_cache' => 'Tasa de aciertos de la caché de tablas abiertas',
    'widget.tip.sql_statements' => 'Tasas de sentencias SQL: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'Porcentaje de uso del buffer pool de InnoDB (basado en bytes)',
    'widget.tip.buffer_pool_read_reqs' => 'Solicitudes de lectura del buffer pool de InnoDB por segundo',
    'widget.tip.buffer_pool_write_reqs' => 'Solicitudes de escritura del buffer pool de InnoDB por segundo',
    'widget.tip.disk_reads_not_from_pool' => 'Lecturas del buffer pool de InnoDB desde disco por segundo',
    'widget.tip.innodb_disk_writes' => 'Bytes de datos de InnoDB escritos en disco por segundo',
    'widget.tip.disk_write_requests' => 'Solicitudes de escritura de archivos de datos de InnoDB por segundo',
    'widget.tip.innodb_disk_reads' => 'Bytes de datos de InnoDB leídos del disco por segundo',
    'widget.tip.row_lock_waits' => 'Esperas de bloqueo de filas de InnoDB por segundo',
    'widget.tip.row_lock_time' => 'Tiempo de bloqueo de filas de InnoDB por segundo (milisegundos)',
    'widget.tip.pages_flushed' => 'Páginas de InnoDB volcadas por segundo',
    'widget.tip.pages_created' => 'Páginas de InnoDB creadas por segundo',
    'widget.tip.pages_read' => 'Páginas de InnoDB leídas por segundo',
    'widget.tip.insert_buffer' => 'Tamaño del búfer de inserción de InnoDB (páginas)',
    'widget.tip.read_ahead' => 'Lectura anticipada del buffer pool de InnoDB por segundo',
    'widget.tip.redo_log_bytes_written' => 'Bytes escritos en el redo log de InnoDB por segundo',
    'widget.tip.redo_log_writes' => 'Escrituras del redo log de InnoDB por segundo',
    'widget.tip.doublewrite_writes' => 'Escrituras doublewrite de InnoDB por segundo',
    'widget.tip.alter_rate' => 'Tasa de comandos ALTER (todos los Com_alter_*)',
    'widget.tip.create_rate' => 'Tasa de comandos CREATE (todos los Com_create_*)',
    'widget.tip.drop_rate' => 'Tasa de comandos DROP (todos los Com_drop_*)',
    'widget.tip.select_rate' => 'Tasa de SELECT',
    'widget.tip.insert_rate' => 'Tasa de INSERT',
    'widget.tip.update_rate' => 'Tasa de UPDATE',
    'widget.tip.delete_rate' => 'Tasa de DELETE',
    'widget.tip.tuples_fetched_total' => 'Filas obtenidas de la BD: {tup_fetched} en total',
    'widget.tip.tuples_fetched_rate' => 'Tasa de obtención de filas',
    'widget.tip.tuples_returned_total' => 'Filas devueltas al cliente: {tup_returned} en total',
    'widget.tip.tuples_returned_rate' => 'Tasa de devolución de filas',
    'widget.tip.blocks_read_total' => 'Bloques de disco leídos: {blks_read} en total',
    'widget.tip.blocks_hit_total' => 'Aciertos de caché de búfer: {blks_hit} en total',
    'widget.tip.active_backends' => 'Backends activos: {numbackends}',
    'widget.tip.transaction_rates' => 'Tasas de transacciones: confirmaciones / reversiones',
    'widget.tip.commit_rate' => 'Tasa de confirmación de transacciones',
    'widget.tip.rollback_rate' => 'Tasa de reversión de transacciones',
    'widget.tip.tuple_insert_rate' => 'Tasa de inserción de tuplas',
    'widget.tip.tuple_update_rate' => 'Tasa de actualización de tuplas',
    'widget.tip.tuple_delete_rate' => 'Tasa de eliminación de tuplas',
    'widget.tip.deadlock_rate' => 'Tasa de interbloqueos',
    'widget.tip.shared_buffers' => 'Configuración shared_buffers de PostgreSQL (tamaño del buffer pool)',
    'widget.tip.cache_hit_rate' => 'Tasa de aciertos de caché de búfer: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Tasa de creación de archivos temporales',
    'widget.tip.temp_bytes' => 'Bytes escritos en archivos temporales por segundo',
    'dash.data_written' => 'datos escritos {kb} kB/s',
    'dash.writes_ops' => 'escrituras {n} ops/s',
    'dash.log_written' => 'log escrito {kb} kB/s',
    'dash.read_reqs' => 'solicitudes de lectura {n} págs/s',
    'dash.write_reqs' => 'solicitudes de escritura {n} págs/s',
    'dash.disk_reads' => 'lecturas de disco {n} /s',
    'metrics.busy_proxy' => 'proxy de ocupación {pct}%',
    'metrics.cpu_load' => 'carga de CPU {load}',
    'metrics.no_samples' => 'aún no hay muestras',
    'metrics.connections_count' => '{count} conexiones',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'Métricas del servidor',
    'explain.query_block' => 'BLOQUE DE CONSULTA #{id} (coste: {cost})',
    'variables.footer_tree' => '[j/k] árbol',
    'variables.footer_rows' => '[j/k] filas',
    'variables.footer_categories' => '[c] categorías',
    'variables.footer_edit' => '[Enter/e] editar (dinámico)',
    'variables.footer_rw_filter' => '[w] filtro rw',
    'variables.footer_search' => '[s] buscar',
    'variables.footer_back' => '[q] volver',
    'srv.title' => 'Estado del servidor',
    'srv.features' => 'Características',
    'srv.stored_programs' => 'Programas almacenados',
    'srv.directories' => 'Directorios',
    'srv.data_directory' => 'Directorio de datos',
    'srv.temp_directory' => 'Directorio temporal',
    'srv.log_directory' => 'Directorio de registros',
    'srv.pid_file' => 'Archivo PID',
    'srv.tls_active' => 'TLS activo',
    'srv.tls_cipher' => 'Cifrado TLS',
    'srv.tls_version' => 'Versión de TLS',
    'srv.ssl_support' => 'Compatibilidad con SSL',
    'srv.ssl_ca' => 'CA de SSL',
    'srv.ssl_cert' => 'Certificado SSL',
    'srv.ssl_key' => 'Clave SSL',
    'srv.tls_secure' => 'TLS / conexión segura',
    'srv.replication' => 'Replicación',
    'srv.repl_denied' => 'Privilegios insuficientes (REPLICATION CLIENT)',
    'srv.repl_error' => 'Error al acceder al estado de la replicación',
    'srv.not_configured' => 'No configurado',
    'srv.channel' => 'Canal',
    'srv.source_host' => 'Host de origen',
    'srv.source_port' => 'Puerto de origen',
    'srv.replica_io' => 'IO de réplica en ejecución',
    'srv.replica_sql' => 'SQL de réplica en ejecución',
    'srv.seconds_behind' => 'Segundos de retraso respecto al origen',
    'srv.relay_log_file' => 'Archivo de relay log',
    'srv.relay_pos' => 'Pos. del relay',
    'srv.repl_channel_title' => 'Replicación › {channel}',
    'srv.default' => 'Predeterminado',
    'srv.firewall' => 'Cortafuegos',
    'srv.mysql_firewall' => 'Cortafuegos de MySQL',
    'srv.caught_up' => '0 s (al día)',
    'srv.host' => 'Host',
    'srv.socket' => 'Socket',
    'srv.port' => 'Puerto',
    'srv.version' => 'Versión',
    'srv.uptime' => 'Tiempo activo',
    'srv.running_since' => 'En ejecución desde',
    'srv.unknown' => 'Desconocido',
    'srv.server_information' => 'Información del servidor',
    'perf.tab_easy_setup' => 'Configuración sencilla',
    'perf.tab_instruments' => 'Instrumentos',
    'perf.tab_consumers' => 'Consumidores',
    'perf.tab_actors' => 'Actores',
    'perf.tab_objects' => 'Objetos',
    'perf.tab_threads' => 'Hilos',
    'perf.tab_options' => 'Opciones',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[SOLO LECTURA]',
    'perf.nothing_to_commit' => 'Nada que confirmar',
    'perf.commit_planning_failed' => 'Falló la planificación de la confirmación: {error}',
    'perf.err_prepare' => 'Error al preparar la sentencia: {sql}',
    'perf.committed_one' => 'Confirmada {count} sentencia',
    'perf.committed_other' => 'Confirmadas {count} sentencias',
    'perf.applied_one' => '{label} aplicado — {count} sentencia',
    'perf.applied_other' => '{label} aplicado — {count} sentencias',
    'perf.commit_failed' => '{label} falló en la sentencia {position} de {total}: {error} — las sentencias anteriores se aplicaron; el estado del servidor es parcial.',
    'perf.err_access_denied' => 'Acceso denegado: faltan privilegios necesarios',
    'perf.err_not_enabled' => 'Performance Schema no está habilitado',
    'perf.err_cannot_connect' => 'No se puede conectar a la base de datos',
    'perf.state_fully' => 'Totalmente habilitado',
    'perf.state_default' => 'Configuración predeterminada',
    'perf.state_custom' => 'Configuración personalizada',
    'perf.state_disabled' => 'Deshabilitado',
    'perf.state_unknown' => 'Desconocido',
    'perf.current_state' => 'Estado actual: {state}',
    'perf.readonly_note' => '(Modo de solo lectura: sin privilegios para modificar)',
    'perf.keys_hint' => 'Tecla "[" Habilitar Performance Schema completo   Tecla "]" Deshabilitar   Tecla "." Restablecer valores predeterminados',
    'perf.default_instruments' => 'Instrumentos predeterminados: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Consumidores predeterminados: events_statements_history, events_waits_history, etc.',
    'perf.none_instruments' => '(no hay instrumentos disponibles)',
    'perf.none_consumers' => '(no hay consumidores disponibles)',
    'perf.none_actors' => '(no hay actores configurados)',
    'perf.none_objects' => '(no hay reglas de objetos configuradas)',
    'perf.none_threads' => '(no hay hilos disponibles)',
    'perf.total_instruments_one' => 'Total: {count} instrumento',
    'perf.total_instruments_other' => 'Total: {count} instrumentos',
    'perf.total_consumers_one' => 'Total: {count} consumidor',
    'perf.total_consumers_other' => 'Total: {count} consumidores',
    'perf.total_actors_one' => 'Total: {count} actor',
    'perf.total_actors_other' => 'Total: {count} actores',
    'perf.total_objects_one' => 'Total: {count} regla de objeto',
    'perf.total_objects_other' => 'Total: {count} reglas de objetos',
    'perf.total_threads_one' => 'Total: {count} hilo',
    'perf.total_threads_other' => 'Total: {count} hilos',
    'perf.col_thread_id' => 'ID de hilo',
    'perf.col_name' => 'Nombre',
    'perf.col_type' => 'Tipo',
    'perf.col_user' => 'Usuario',
    'perf.timer_options' => 'Opciones del temporizador',
    'perf.none_timers' => '(no hay temporizadores disponibles - solo lectura)',
    'perf.col_timer_name' => 'Nombre del temporizador',
    'perf.col_implementation' => 'Implementación',
    'perf.col_scale_factor' => 'Factor de escala',
    'perf.timer_readonly_note' => 'La configuración del temporizador es de solo lectura (determinada por la compilación del servidor)',
    'perf.footer_nav' => '[j/k] navegar  [Space] alternar',
    'perf.footer_actions' => '  [c] preparar confirmación ([y] ejecutar)  [r] revertir',
    'perf.footer_back' => '  [q] volver',
    'perf.pending_one' => 'Pendiente: {count} cambio',
    'perf.pending_other' => 'Pendiente: {count} cambios',
    'cli.err_not_tty' => 'este lanzador controla un terminal interactivo y se niega a volcar ANSI a una tubería (salida 2) — ejecútalo en un pty (p. ej. script -qec \'candy-sql app.sqlite\' /dev/null) o renderiza sin interfaz desde código de biblioteca (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'grabación del historial DESHABILITADA ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' exige TLS verificado — el transporte de administración de ReactPHP no puede cumplirlo, así que la actualización en vivo de administración está DESHABILITADA y las consultas/cargas de tablas se ejecutan de forma síncrona sobre la conexión TLS.',
    'cli.no_connection_selected' => 'no se seleccionó ninguna conexión — indica una ruta, un DSN o --connect=<name>',
    'cli.usage_line' => 'USO: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # para información de uso completa',
    'cli.tip_saved_connections' => '       Consejo: guarda conexiones con nombre en {path} y lanza con --connect=<name>.',
    'help.summary' => 'candy-sql - Cliente SQL de terminal para SQLite, MySQL y PostgreSQL',
    'help.usage' => 'USO
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGUMENTOS
  path-or-dsn
    Ruta del archivo de base de datos SQLite, p. ej.: app.sqlite
    O una cadena DSN, p. ej.:  mysql://user:pass@localhost:3306/dbname

  Si se omite por completo: en un terminal interactivo con conexiones
  guardadas, un selector las ofrece; en caso contrario se imprime una
  línea de uso breve y el proceso termina con estado 2 (invocación
  incorrecta) — ejecute --help para ver esta pantalla completa.',
    'help.options' => 'OPCIONES
  --help, -h
    Muestra esta pantalla de ayuda.

  --version, -V
    Muestra el número de versión.

  --connect=<name>
    Conecta con la entrada indicada del archivo de conexiones guardadas
    ($XDG_CONFIG_HOME/candy-sql/connections.json, o por defecto
    $HOME/.config/candy-sql/connections.json). No se puede combinar con
    argumentos de conexión explícitos. Las contraseñas nunca se guardan
    en disco: una entrada nombra su secreto mediante "pass-env" y la
    variable se lee al conectar. Vea CONEXIONES GUARDADAS más abajo.',
    'help.option_dsn' => '  --dsn=<dsn>
    Cadena de conexión DSN completa.
    Formato: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Ejemplos:
      mysql://root:secret@localhost:3306/mydb?ssl-mode=require
      pgsql://postgres:pass@db.example.com:5432/production
      sqlite:///var/data/app.sqlite
      sqlite://:memory:',
    'help.option_params' => '  --driver=<driver>
  --db=<dbname>
  --host=<host>
  --port=<port>
  --user=<user>
  --pass=<pass>
  --ssl-mode=<mode>
    Parámetros de conexión individuales (todos salvo --driver y --db son opcionales).
    --driver debe ser uno de: sqlite, mysql, pgsql
    --ssl-mode es "prefer" por defecto en MySQL. "require" y "verify_*" exigen
    TLS con verificación del certificado del servidor; la confianza en la CA
    debe configurarse en el servidor o mediante my.cnf (vea el README). En esos
    modos verificados se rechaza el transporte ReactPHP, capaz de texto plano —
    las consultas se ejecutan de forma síncrona sobre la conexión TLS y la
    actualización en vivo de la administración queda desactivada (un aviso
    de inicio y la línea de estado de la aplicación lo indican).
    Prefiera CANDY_SQL_PASS a --pass= para que la contraseña no aparezca
    en la línea de comandos del proceso (visible con ps).',
    'help.examples' => 'EJEMPLOS
  # SQLite (por defecto — ruta simple o DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # MySQL mediante DSN
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # MySQL mediante opciones individuales
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'CONEXIONES GUARDADAS
  Liste los servidores con nombre una vez y conéctese siempre a ellos:

    $HOME/.config/candy-sql/connections.json  (directorio 0700, archivo 0600)',
    'help.saved_fields' => '  Campos: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Cualquier otro campo se rechaza, y un campo "pass" en texto
  plano se rechaza siempre — haga que pass-env apunte a una variable.
  Un inicio sin argumentos en un terminal interactivo presenta estas
  entradas como selector; --connect=<name> conecta con una directamente.',
    'help.editing' => 'EDICIÓN
  El panel Rows de una vista previa de TABLE es una cuadrícula editable:
  h/l (o ←/→) mueven el cursor de celda, y tres acordes modifican datos —
  cada uno muestra la sentencia exacta en la línea de estado y no escribe
  nada hasta que Enter confirma:

    e   editar la celda bajo el cursor (Ctrl+N pone NULL)
    o   insertar una fila (Enter recorre las columnas; una entrada vacía
        deja la columna en su DEFAULT, Ctrl+N guarda NULL)
    D   eliminar la fila bajo el cursor

  Las filas se direccionan solo por su PRIMARY KEY completa: una tabla sin
  ella, una clave NULL/binaria, una celda binaria y cualquier resultado de
  consulta se rechazan con una línea de estado explícita. Los valores son
  parámetros enlazados; cada escritura se ejecuta en una transacción que
  se revierte salvo que se toque exactamente una fila (un UPDATE que escribe
  el valor ya presente se informa como sin cambios). Esc cancela una línea
  de edición abierta.',
    'help.editing_readonly' => '  --read-only
    Desactiva los acordes de edición durante la sesión. Protege de un
    descuido; no filtra las sentencias del panel Query — para una garantía
    firme, conéctese como usuario de base de datos de solo lectura.',
    'help.history' => 'HISTORIAL
  Por defecto cada instantánea de sondeo de administración se registra en
  $HOME/.candy-sql/history.db (directorio 0700 / archivo 0600) como pista
  de auditoría sin conexión. Las páginas Dashboard y Reports de la
  aplicación no leen este archivo — consultan el servidor en vivo.',
    'help.history_flags' => '  --no-history
    Desactiva el registro por completo (no se escribe nada en disco).

  --history=<path>
    Guarda las instantáneas en <path> en lugar de la ubicación por defecto.',
    'help.keyboard' => 'ATAJOS DE TECLADO (TUI)
  Tab          Recorre el foco por los paneles (Tables → Rows → Query → Admin)
  ↑ / ↓        Navega por las filas de la lista (o j/k) — en el panel Tables el
               movimiento es solo local; nunca carga una vista previa
  /            (Panel Tables) abre una línea de filtro en vivo sobre los nombres de
               tabla; las letras la amplían, ↑/↓ mueven el cursor, Enter confirma y
               carga, Retroceso edita, Esc abandona y borra
  Enter        Carga las filas de la tabla seleccionada
  Space        Carga las filas de la tabla seleccionada (alias de Enter)
  PgDn / PgUp  (Panel Tables/Rows, en una vista previa de tabla) pagina la vista
               previa en ventanas de 100 filas — el tope de exploración pasó a ser un tamaño de página
  Ctrl+Enter   Ejecuta la consulta actual
  Ctrl+R       Ejecuta la consulta actual
  Ctrl+E       Ejecuta la consulta actual
  EXPLAIN …    (Editor Query, palabra clave inicial) un EXPLAIN escrito pasa por
               ExplainView: el panel Rows muestra el árbol del plan en color, no la
               cuadrícula plana del proveedor — y cualquier modificador ANALYZE se
               ELIMINA, el inspector nunca ejecuta lo que inspecciona
  e / o / D    (Panel Rows, vista previa de tabla) editar la celda / insertar una
               fila / eliminar la fila — vea EDICIÓN; h/l mueven el cursor de celda
  Ctrl+Space   Completa en el cursor (editor Query): palabras clave SQL, nombres de
               tabla tras FROM/JOIN/INTO/UPDATE y columnas según el contexto
               (WHERE/SELECT/ORDER BY, calificadas `alias.` / `table.`, la lista de
               INSERT INTO t (...)) a partir del esquema cargado. Un candidato se
               completa, un prefijo común avanza; si no, la línea de estado lista los candidatos
  Ctrl+F       Añade la consulta actual a la lista de favoritos (solo estado de
               sesión — ningún panel la muestra y no se guarda)
  Ctrl+Shift+F Quita la consulta actual de la lista de favoritos
  1-8          Salta a un panel de administración (solo con el panel Admin enfocado)
  q            Sale desde los paneles Tables/Rows; vuelve a Tables desde Admin
  x            (Tables/Rows, resultado en vivo) exporta las filas actuales a una
               ruta CSV — el rechazo de sobrescritura queda armado hasta que Enter confirma
  i            (Tables/Rows) importa un archivo CSV en una tabla con nombre
  Ctrl+S       Exporta el resultado/vista previa en vivo a CSV desde cualquier panel
  Esc          Sale desde los paneles Tables/Rows; cancela un diálogo de Admin abierto
  Backspace    Borra el carácter anterior al cursor (editor Query)',
    'help.see_also' => 'VÉASE TAMBIÉN
  https://github.com/sugarcraft/candy-sql',
];
