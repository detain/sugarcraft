<?php

/**
 * Russian translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'Неверный формат SSH-ключа',
    'repo.create_dir_failed'   => 'Не удалось создать каталог репозитория: {path}',
    'repo.git_init_failed'     => 'git init не удался: {output}',
    'config.not_found'         => 'Файл конфигурации не найден: {path}',
    'config.read_failed'       => 'Не удалось прочитать конфигурацию: {path}',
    'ssh.user_cannot_create'   => 'Пользователь {viewer} не может создавать репозитории',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => 'Запуск серверов...',
    'cli.note_ssh2_required'   => '(Полный режим демона требует расширения ssh2 и работающего SSH-демона.)',
    'cli.note_run_init'        => "(Сначала выполните 'candy-git-server init' для инициализации каталога данных.)",
    'cli.repos_header'         => 'Репозитории:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => 'Ещё не запущен как демон (режим демона требует менеджер процессов.)',
    'cli.note_http_help'       => 'Для обслуживания Git через HTTP направьте веб-сервер на этот скрипт.',
    'cli.note_ssh_help'        => 'Для SSH-доступа используйте обратный туннель или настройте sshd с ForceCommand.',
    'cli.already_initialized'  => 'Уже инициализировано: {path}',
    'cli.initializing'         => 'Инициализация каталога данных CandyGitServer: {path}',
    'cli.done'                 => 'Готово.',
    'cli.next_steps'           => 'Следующие шаги:',
    'cli.next_step_1'          => '  1. Отредактируйте {path}/config.yaml',
    'cli.next_step_2'          => '  2. Сгенерируйте SSH-хост ключ: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. Установите CANDY_GIT_SERVER_INITIAL_ADMIN_KEYS=ваш-ssh-публичный-ключ',
    'cli.next_step_4'          => '  4. Выполните: candy-git-server serve --config {path}/config.yaml',
    'cli.usage_user_root'      => 'Использование: candy-git-server user add|key|list',
    'cli.usage_user_add'       => 'Использование: candy-git-server user add <username>',
    'cli.usage_user_key'       => 'Использование: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "Пользователь '{username}' создан (admin: true)",
    'cli.user_key_hint'        => "Используйте 'candy-git-server user key {username} < key.pub' для добавления SSH-ключа.",
    'cli.user_key_read_failed' => 'Не удалось прочитать ключ из: {file}',
    'cli.user_key_added'       => "Ключ добавлен для пользователя '{username}'",
    'cli.user_keys_header'     => 'Авторизованные ключи:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "Пользователи:\n  (Нет зарегистрированных пользователей. Используйте 'candy-git-server user add <username>')",
    'cli.usage_repo_root'      => 'Использование: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'Использование: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'Использование: candy-git-server repo info <name>',
    'cli.no_repos'             => 'Репозиториев пока нет.',
    'cli.repo_listing_none'    => '  (пока нет репозиториев)',
    'cli.repo_invalid_name'    => 'Недопустимое имя репозитория: {name} (используйте только буквы, цифры, точку, подчёркивание, дефис)',
    'cli.repo_created'         => "Репозиторий '{name}' создан в {path}",
    'cli.repo_clone_url'       => 'URL клона: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => 'Репозиторий не найден: {name}',
    'cli.repo_info'            => "Имя:         {name}\nОписание:  {description}\nПубличный:       {is_public}\nПриватный:      {is_private}\nРазрешить push:   {allow_push}\nПуть:         {path}\nВетки:     {branches}\nТеги:         {tags}",
    'cli.bool_yes'             => 'да',
    'cli.bool_no'              => 'нет',
    'cli.none_value'           => '(нет)',
    'cli.unknown_command'      => "Неизвестная команда: {cmd}\n  Выполните 'candy-git-server help' для справки.",
    'cli.help'                 => "CandyGitServer — Самохостимый Git-сервер\n\nИспользование:\n  candy-git-server <команда> [опции]\n\nКоманды:\n  serve [--config path]    Запустить Git-сервер\n  init [data-path]         Инициализировать новый каталог данных\n  user add <username>      Создать пользователя\n  user key <username> <file>  Добавить SSH-ключ для пользователя (используйте - для stdin)\n  user list                Список пользователей\n  repo list [data-path]    Список репозиториев\n  repo create <name> [data-path]  Создать репозиторий\n  repo info <name> [data-path]    Показать информацию о репозитории\n  help, --help, -h         Показать эту справку\n  version, --version, -v   Показать версию\n\nПеременные окружения:\n  CANDY_GIT_SERVER_DATA_PATH    Каталог данных (по умолчанию: /tmp/candy-git-server)\n\nПримеры:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
];
