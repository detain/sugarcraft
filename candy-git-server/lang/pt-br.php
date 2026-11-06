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
    'repo.visibility_marker_unwritable' => 'Falha ao gravar o marcador de visibilidade: {file}',
    'config.not_found'         => 'Arquivo de configuração não encontrado: {path}',
    'config.read_failed'       => 'Falha ao ler a configuração: {path}',
    'ssh.user_cannot_create'   => 'O usuário {viewer} não pode criar repositórios',
    'ssh.invalid_repo_name'    => 'Nome de repositório inválido: {name}',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'Repositórios:',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name} (público)',
    'cli.already_initialized'  => 'Já inicializado: {path}',
    'cli.initializing'         => 'Inicializando o diretório de dados do CandyGitServer: {path}',
    'cli.done'                 => 'Concluído.',
    'cli.next_steps'           => 'Próximos passos:',
    'cli.next_step_1'          => '  1. Crie uma conta: candy-git-server user add admin {path}',

    'cli.next_step_2'          => '  2. Crie um repositório: candy-git-server repo create <nome> {path} (adicione --public para publicá-lo para leitura anônima)',

    'cli.next_step_3'          => '  3. Atenda via smart HTTP: candy-git-server serve --http --repo-root {path}/repositories',
    'cli.usage_user_root'      => 'Uso: candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => 'Uso: candy-git-server user add <username> [data-path] [--password <pw>|-]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] mint an HTTP proxy token signature',
    'cli.usage_user_key'       => 'Uso: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Usuário '{username}' criado (admin: true)",
    'cli.user_password_stored' => 'Senha armazenada como hash.',
    'cli.user_key_hint'        => "Use 'candy-git-server user key {username} <key-file|->' para adicionar uma chave SSH pública.",
    'cli.user_key_read_failed' => 'Falha ao ler a chave de: {file}',
    'cli.user_key_added'       => "Chave adicionada para o usuário '{username}'",
    'cli.user_keys_header'     => 'Chaves autorizadas:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Usuários:\n  (Nenhum usuário registrado. Use 'candy-git-server user add <username>')",
    'cli.user_list_header' => "Usuários ({path}):",
    'cli.usage_repo_root'      => 'Uso: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Uso: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Uso: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Ainda não há repositórios.',
    'cli.repo_listing_none'    => '  (ainda sem repositórios)',
    'cli.repo_invalid_name'    => 'Nome de repositório inválido: {name} (use apenas alfanumérico, ponto, sublinhado, hífen, não deve começar com ponto ou hífen)',
    'cli.repo_created'         => "Repositório '{name}' criado em {path}",
    'cli.repo_not_found'       => 'Repositório não encontrado: {name}',
    'cli.repo_info'            => "Nome:         {name}\nDescrição:  {description}\nPúblico:       {is_public}\nPrivado:      {is_private}\nPermitir push:   {allow_push}\nCaminho:         {path}\nBranches:     {branches}\nTags:         {tags}",
    'cli.bool_yes'             => 'sim',
    'cli.bool_no'              => 'não',
    'cli.none_value'           => '(nenhum)',
    'cli.unknown_command'      => "Comando desconhecido: {cmd}\n  Execute 'candy-git-server help' para obter ajuda.",
    'cli.help'                 => "CandyGitServer — Servidor Git auto-hospedável\n\nUso:\n  candy-git-server <comando> [opções]\n\nComandos:\n  serve [--config path]    Iniciar o servidor Git\n  init [data-path]         Inicializar um novo diretório de dados\n  user add <username>      Criar um usuário\n  user key <username> <file>  Adicionar chave SSH pública para o usuário (use - para stdin)\n  user list                Listar usuários\n  repo list [data-path]    Listar repositórios\n  repo create <name> [data-path]  Criar um repositório\n  repo info <name> [data-path]    Mostrar informações do repositório\n  help, --help, -h         Mostrar esta ajuda\n  version, --version, -v   Mostrar versão\n\nAmbiente:\n  CANDY_GIT_SERVER_DATA_PATH    Diretório de dados (padrão: /tmp/candy-git-server)\n\nExemplos:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create meu-projeto\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => 'blob de chave pública SSH curto demais para {type}: {len} < {min} caracteres base64',
    'config.parse_failed'   => 'Configuração YAML inválida: {error}',
    'config.invalid_user_trust_mode' => 'http.user_trust_mode "{mode}" inválido: esperado off, proxy ou token',
    'config.tls_not_supported' => 'http.tls_key_path/tls_cert_path estão definidos, mas nenhum listener HTTPS está conectado ainda; defini-los NÃO ativa TLS. Remova-os até o TLS ser suportado.',
    'jobs.invalid_schedule' => 'Agendamento de tarefa inválido "{schedule}": {reason}',
    'cli.option_needs_value' => 'a opção {option} exige um valor',
    'cli.serve_http_needs_repo_root' => 'serve --http exige --repo-root DIR (o diretório cujos subdiretórios são atendidos)',
    'cli.serve_repo_root_not_dir' => '--repo-root não é um diretório: {path}',
    'cli.serve_http_port_range' => '--http-port fora de faixa: {port}',
    'cli.serve_http_port_not_a_number' => 'serve --http: --http-port deve ser um número, recebido: {port}',
    'cli.serve_http_flag_unsupported' => 'A opção {flag} não é suportada por serve --http (o listener roda em primeiro plano; demonize com um supervisor).',
    'cli.serve_http_unknown_flag' => 'Opção desconhecida para serve --http: {flag}',
    'cli.port_deprecated'      => 'Nota: --port está obsoleto; use --http-port (alias mantido por compatibilidade).',
    'cli.serve_http_banner' => 'listener smart-HTTP em {addr} ({repos} repositórios, {published} públicos, {users} contas; leitura anônima={anon}; o push também é atendido — recusas de hooks viajam in-band)',
];
