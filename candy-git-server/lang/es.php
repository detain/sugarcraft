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
    'config.not_found'         => 'Archivo de configuración no encontrado: {path}',
    'config.read_failed'       => 'Error al leer la configuración: {path}',
    'ssh.user_cannot_create'   => 'El usuario {viewer} no puede crear repositorios',

    // bin/candy-git-server — banner + status output
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Iniciando servidores...',
    'cli.note_ssh2_required'   => '(El modo daemon completo requiere la extensión ssh2 y un demonio SSH en ejecución.)',
    'cli.note_run_init'        => "(Use 'candy-git-server init' para inicializar su directorio de datos primero.)",
    'cli.repos_header'         => 'Repositorios:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Aún no se ejecuta como daemon (el modo daemon necesita un gestor de procesos.)',
    'cli.note_http_help'       => 'Para servir Git por HTTP, apunte su servidor web a este script.',
    'cli.note_ssh_help'        => 'Para acceso SSH, use un túnel inverso o configure sshd con ForceCommand.',

    // bin/candy-git-server — init
    'cli.already_initialized'  => 'Ya inicializado: {path}',
    'cli.initializing'         => 'Inicializando directorio de datos de CandyGitServer: {path}',
    'cli.done'                 => 'Hecho.',
    'cli.next_steps'           => 'Próximos pasos:',
    'cli.next_step_1'          => '  1. Edite {path}/config.yaml',
    'cli.next_step_2'          => '  2. Genere la clave de host SSH: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Configure CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=su-clave-pública-ssh',
    'cli.next_step_4'          => '  4. Ejecute: candy-git-server serve --config {path}/config.yaml',

    // bin/candy-git-server — user
    'cli.usage_user_root'      => 'Uso: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Uso: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Uso: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Usuario '{username}' creado (admin: true)",
    'cli.user_key_hint'        => "Use 'candy-git-server user key {username} < key.pub' para agregar una clave SSH pública.",
    'cli.user_key_read_failed' => 'Error al leer la clave desde: {file}',
    'cli.user_key_added'       => "Clave agregada para el usuario '{username}'",
    'cli.user_keys_header'     => 'Claves autorizadas:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Usuarios:\n  (No hay usuarios registrados. Use 'candy-git-server user add <username>')",

    // bin/candy-git-server — repo
    'cli.usage_repo_root'      => 'Uso: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Uso: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Uso: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Aún no hay repositorios.',
    'cli.repo_listing_none'    => '  (sin repositorios aún)',
    'cli.repo_invalid_name'    => 'Nombre de repositorio inválido: {name} (use solo alfanumérico, punto, guion bajo, guion)',
    'cli.repo_created'         => "Repositorio '{name}' creado en {path}",
    'cli.repo_clone_url'       => 'URL de clon: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Repositorio no encontrado: {name}',
    'cli.repo_info'            => "Nombre:         {name}\nDescripción:  {description}\nPúblico:       {is_public}\nPrivado:      {is_private}\nPermitir push:   {allow_push}\nRuta:         {path}\nRamas:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'sí',
    'cli.bool_no'              => 'no',
    'cli.none_value'           => '(ninguna)',

    // bin/candy-git-server — help / unknown
    'cli.unknown_command'      => "Comando desconocido: {cmd}\n  Ejecute 'candy-git-server help' para obtener ayuda.",
    'cli.help'                 => "CandyGitServer — Servidor Git autoalojable\n\nUso:\n  candy-git-server <comando> [opciones]\n\nComandos:\n  serve [--config path]    Iniciar el servidor Git\n  init [data-path]         Inicializar un nuevo directorio de datos\n  user add <username>      Crear un usuario\n  user key <username> <file>  Agregar clave SSH pública para el usuario (use - para stdin)\n  user list                Listar usuarios\n  repo list [data-path]    Listar repositorios\n  repo create <name> [data-path]  Crear un repositorio\n  repo info <name> [data-path]    Mostrar información del repositorio\n  help, --help, -h         Mostrar esta ayuda\n  version, --version, -v   Mostrar versión\n\nEntorno:\n  CANDY_GIT_SERVER_DATA_PATH    Directorio de datos (predeterminado: /tmp/candy-git-server)\n\nEjemplos:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create mi-proyecto\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
