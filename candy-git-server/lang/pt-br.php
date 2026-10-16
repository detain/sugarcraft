<?php

/**
 * Brazilian Portuguese translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Formato de chave SSH pública inválido',
    'repo.create_dir_failed'   => 'Falha ao criar o diretório do repositório: {path}',
    'repo.git_init_failed'     => 'git init falhou: {output}',
    'config.not_found'         => 'Arquivo de configuração não encontrado: {path}',
    'config.read_failed'       => 'Falha ao ler a configuração: {path}',
    'ssh.user_cannot_create'   => 'O usuário {viewer} não pode criar repositórios',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Iniciando servidores...',
    'cli.note_ssh2_required'   => '(O modo daemon completo requer a extensão ssh2 e um daemon SSH em execução.)',
    'cli.note_run_init'        => "(Use 'candy-git-server init' para inicializar seu diretório de dados primeiro.)",
    'cli.repos_header'         => 'Repositórios:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Ainda não está em execução como daemon (o modo daemon requer um gerenciador de processos.)',
    'cli.note_http_help'       => 'Para servir Git via HTTP, aponte seu servidor web para este script.',
    'cli.note_ssh_help'        => 'Para acesso SSH, use um túnel reverso ou configure o sshd com ForceCommand.',
    'cli.already_initialized'  => 'Já inicializado: {path}',
    'cli.initializing'         => 'Inicializando o diretório de dados do CandyGitServer: {path}',
    'cli.done'                 => 'Concluído.',
    'cli.next_steps'           => 'Próximos passos:',
    'cli.next_step_1'          => '  1. Edite {path}/config.yaml',
    'cli.next_step_2'          => '  2. Gere a chave de host SSH: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Defina CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=sua-chave-pública-ssh',
    'cli.next_step_4'          => '  4. Execute: candy-git-server serve --config {path}/config.yaml',
    'cli.usage_user_root'      => 'Uso: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Uso: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Uso: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Usuário '{username}' criado (admin: true)",
    'cli.user_key_hint'        => "Use 'candy-git-server user key {username} < key.pub' para adicionar uma chave SSH pública.",
    'cli.user_key_read_failed' => 'Falha ao ler a chave de: {file}',
    'cli.user_key_added'       => "Chave adicionada para o usuário '{username}'",
    'cli.user_keys_header'     => 'Chaves autorizadas:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Usuários:\n  (Nenhum usuário registrado. Use 'candy-git-server user add <username>')",
    'cli.usage_repo_root'      => 'Uso: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Uso: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Uso: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Ainda não há repositórios.',
    'cli.repo_listing_none'    => '  (ainda sem repositórios)',
    'cli.repo_invalid_name'    => 'Nome de repositório inválido: {name} (use apenas alfanumérico, ponto, sublinhado, hífen)',
    'cli.repo_created'         => "Repositório '{name}' criado em {path}",
    'cli.repo_clone_url'       => 'URL de clone: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Repositório não encontrado: {name}',
    'cli.repo_info'            => "Nome:         {name}\nDescrição:  {description}\nPúblico:       {is_public}\nPrivado:      {is_private}\nPermitir push:   {allow_push}\nCaminho:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'sim',
    'cli.bool_no'              => 'não',
    'cli.none_value'           => '(nenhum)',
    'cli.unknown_command'      => "Comando desconhecido: {cmd}\n  Execute 'candy-git-server help' para obter ajuda.",
    'cli.help'                 => "CandyGitServer — Servidor Git auto-hospedável\n\nUso:\n  candy-git-server <comando> [opções]\n\nComandos:\n  serve [--config path]    Iniciar o servidor Git\n  init [data-path]         Inicializar um novo diretório de dados\n  user add <username>      Criar um usuário\n  user key <username> <file>  Adicionar chave SSH pública para o usuário (use - para stdin)\n  user list                Listar usuários\n  repo list [data-path]    Listar repositórios\n  repo create <name> [data-path]  Criar um repositório\n  repo info <name> [data-path]    Mostrar informações do repositório\n  help, --help, -h         Mostrar esta ajuda\n  version, --version, -v   Mostrar versão\n\nAmbiente:\n  CANDY_GIT_SERVER_DATA_PATH    Diretório de dados (padrão: /tmp/candy-git-server)\n\nExemplos:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create meu-projeto\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
