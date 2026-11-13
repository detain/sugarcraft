<?php

/**
 * Spanish translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Formato de clave SSH pública inválido',
    'repo.create_dir_failed'   => 'Error al crear el directorio del repositorio: {path}',
    'repo.git_init_failed'     => 'Error en git init: {output}',
    'repo.visibility_marker_unwritable' => 'No se pudo escribir el marcador de visibilidad: {file}',
    'config.not_found'         => 'Archivo de configuración no encontrado: {path}',
    'config.read_failed'       => 'Error al leer la configuración: {path}',
    'ssh.user_cannot_create'   => 'El usuario {viewer} no puede crear repositorios',
    'ssh.invalid_repo_name'    => 'Nombre de repositorio no válido: {name}',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'Repositorios:',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name} (público)',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Ya inicializado: {path}',
    'cli.initializing'         => 'Inicializando directorio de datos de CandyGitServer: {path}',
    'cli.done'                 => 'Hecho.',
    'cli.next_steps'           => 'Próximos pasos:',
    'cli.next_step_1'          => '  1. Cree una cuenta: candy-git-server user add --admin admin {path}',

    'cli.next_step_2'          => '  2. Cree un repositorio: candy-git-server repo create <nombre> {path} (añada --public para publicarlo en lectura anónima)',

    'cli.next_step_3'          => '  3. Sirva por smart HTTP: candy-git-server serve --http --repo-root {path}/repositories',
    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Uso: candy-git-server user add|key|list|token|remove|deactivate',
    'cli.usage_user_add'       => 'Uso: candy-git-server user add <username> [data-path] [--password <pw>|-] [--admin|--read]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] genera una firma de token de proxy HTTP',
'cli.usage_user_key'       => 'Uso: candy-git-server user key <username> [key-file]',    'cli.usage_user_remove' => 'Uso: candy-git-server user remove <username> [data-path]',
    'cli.usage_user_deactivate' => 'Uso: candy-git-server user deactivate <username> [data-path]',
    'cli.user_removed' => "Usuario '{username}' eliminado de {path}",
    'cli.user_deactivated' => "Usuario '{username}' desactivado (active: {active})",
    'cli.user_already_deactivated_note' => "El usuario '{username}' ya estaba inactivo; nada cambió salvo volver a publicar la fila.",
    'cli.user_created'         => "Usuario '{username}' creado (admin: {admin})",
    // S21: least-privilege `user add` flags (finding 036).
    'cli.user_add_flags_conflict' => '--admin y --read son mutuamente excluyentes.',
    'cli.user_password_stored' => 'Contraseña almacenada como hash.',
    'cli.user_key_hint'        => "Use 'candy-git-server user key {username} <key-file|->' para agregar una clave SSH pública.",
    'cli.user_key_read_failed' => 'Error al leer la clave desde: {file}',
    'cli.user_key_added'       => "Clave agregada para el usuario '{username}'",
    'cli.user_keys_header'     => 'Claves autorizadas:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Usuarios:\n  (No hay usuarios registrados. Use 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Usuarios ({path}):",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Uso: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Uso: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Uso: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Aún no hay repositorios.',
    'cli.repo_listing_none'    => '  (sin repositorios aún)',
    'cli.repo_invalid_name'    => 'Nombre de repositorio inválido: {name} (use solo alfanumérico, punto, guion bajo, guion, no debe empezar con punto ni guion)',
    'cli.repo_created'         => "Repositorio '{name}' creado en {path}",
    'cli.repo_exists' => "El repositorio '{name}' ya existe en {path}",
    'cli.repo_not_found'       => 'Repositorio no encontrado: {name}',
    'cli.repo_info'            => "Nombre:         {name}\nDescripción:  {description}\nPúblico:       {is_public}\nPrivado:      {is_private}\nPermitir push:   {allow_push}\nRuta:         {path}\nRamas:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'sí',
    'cli.bool_no'              => 'no',
    'cli.none_value'           => '(ninguna)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Comando desconocido: {cmd}\n  Ejecute 'candy-git-server help' para obtener ayuda.",
    'cli.help'                 => "CandyGitServer — Servidor Git autoalojable\n\nUso:\n  candy-git-server <comando> [opciones]\n\nComandos:\n  serve [--config path]    Iniciar el servidor Git\n  init [data-path]         Inicializar un nuevo directorio de datos\n  user add <username>      Crear un usuario\n  user key <username> <file>  Agregar clave SSH pública para el usuario (use - para stdin)\n  user list                Listar usuarios\n  repo list [data-path]    Listar repositorios\n  repo create <name> [data-path]  Crear un repositorio\n  repo info <name> [data-path]    Mostrar información del repositorio\n  help, --help, -h         Mostrar esta ayuda\n  version, --version, -v   Mostrar versión\n\nEntorno:\n  CANDY_GIT_SERVER_DATA_PATH    Directorio de datos (predeterminado: /tmp/candy-git-server)\n\nEjemplos:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mi-proyecto\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => 'blob de clave pública SSH demasiado corto para {type}: {len} < {min} caracteres base64',
    'config.parse_failed'   => 'configuración YAML no válida: {error}',
    'config.invalid_user_trust_mode' => 'http.user_trust_mode "{mode}" no válido: se esperaba off, proxy o token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path están definidos pero aún no hay un listener HTTPS; definirlos NO activa TLS. Elimínelos hasta que TLS sea compatible.',
    'jobs.invalid_schedule' => 'programación de trabajo no válida "{schedule}": {reason}',
    'cli.option_needs_value' => 'la opción {option} requiere un valor',
    'cli.serve_http_needs_repo_root' => 'serve --http requiere --repo-root DIR (el directorio cuyos subdirectorios se sirven)',
    'cli.serve_repo_root_not_dir' => '--repo-root no es un directorio: {path}',
    'cli.serve_http_port_range' => '--http-port fuera de rango: {port}',
    'cli.serve_http_port_not_a_number' => 'serve --http: --http-port debe ser un número, se recibió: {port}',
    'cli.serve_http_flag_unsupported' => 'La opción {flag} no está soportada por serve --http (el listener corre en primer plano; demonice con un supervisor).',
    'cli.serve_http_unknown_flag' => 'Opción desconocida para serve --http: {flag}',
    'cli.port_deprecated'      => 'Nota: --port está obsoleto; use --http-port en su lugar (alias mantenido por compatibilidad).',
    'cli.serve_http_banner' => 'listener smart-HTTP en {addr} ({repos} repos, {published} públicos, {users} cuentas; lectura anónima={anon}; el push también se sirve — los rechazos de hooks viajan en banda)',

// bin/candy-git-server — Step 17: remaining CLI refusals
    'cli.init_temp_open_failed' => 'init: no se pudo abrir la configuración temporal en {tmp}',
    'cli.init_publish_failed' => 'init: no se pudo publicar la configuración en {data}/config.yaml',
    'cli.warn_tmp_data_path' => 'advertencia: la ruta de datos {path} está en el directorio temporal del sistema; los usuarios locales pueden enumerarla y los limpiadores de temp pueden borrarla — use CANDY_GIT_SERVER_DATA_PATH para despliegues reales',
    'cli.init_foreign_owner' => 'init: se rechaza adoptar {path} propiedad del uid {uid}; ejecute como ese usuario o elija una ruta de datos propia',
    'cli.repo_root_unreadable' => 'serve: no se puede leer la raíz de repositorios {path}',
    'cli.token_no_config' => 'token: no hay configuración legible en {data}/config.yaml — ejecute init o indiquee una ruta de datos',
    'cli.token_no_secret' => 'token: http.auth_secret no esta configurado para esta ruta de datos; definalo en la seccion http: de config.yaml antes de emitir tokens',
    'cli.token_ttl_capped' => 'token: el TTL solicitado de {ttl}s supera el limite de {max}s configurado en {key}; reduzca el TTL o eleve esa clave en config.yaml',
    'cli.password_flag_needs_value' => 'candy-git-server: --password requiere un valor (\'-\' lee de stdin)',
    'cli.password_flag_empty' => 'candy-git-server: --password requiere un valor no vacio',
    'cli.username_invalid' => 'Nombre de usuario no valido: \'{username}\' (letras, digitos, punto, guion bajo, guion; no solo puntos)',
    'cli.user_exists' => 'El usuario \'{username}\' ya existe en {path}',
    'cli.user_not_found' => 'No existe el usuario \'{username}\' en {path} — ejecute primero \'candy-git-server user add {username}\'.',
];
