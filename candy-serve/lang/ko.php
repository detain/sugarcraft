<?php

/**
 * Korean translations for candy-serve.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => '잘못된 SSH 공개키 형식',
    'repo.create_dir_failed'   => '저장소 디렉토리 생성 실패: {path}',
    'repo.git_init_failed'     => 'git init 실패: {output}',
    'config.not_found'         => '구성 파일을 찾을 수 없음: {path}',
    'config.read_failed'       => '구성 읽기 실패: {path}',
    'ssh.user_cannot_create'   => '사용자 {viewer}은(는) 저장소를 생성할 수 없습니다',
    'cli.banner'               => 'CandyServe v{version}',
    'cli.config_summary'       => "Data path:  {data_path}\nSSH:        {ssh_addr}\nHTTP:       {http_addr}\nGit daemon: {git_addr}",
    'cli.starting_servers'     => '서버 시작 중...',
    'cli.note_ssh2_required'   => '(완전한 데몬 모드에는 ssh2 확장 프로그램과 실행 중인 SSH 데몬이 필요합니다.)',
    'cli.note_run_init'        => "('soft-serve init'을 실행하여 데이터 디렉토리를 먼저 초기화하세요.)",
    'cli.repos_header'         => '저장소:',
    'cli.repo_listing_entry'   => '  - {name}',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.note_not_a_daemon'    => '아직 데몬으로 실행되지 않음 (데몬 모드에는 프로세스 관리자가 필요합니다.)',
    'cli.note_http_help'       => 'HTTP로 Git을 서비스하려면 웹 서버를 이 스크립트로 지정하세요.',
    'cli.note_ssh_help'        => 'SSH 액세스에는 역방향 터널을 사용하거나 ForceCommand로 sshd를 구성하세요.',
    'cli.already_initialized'  => '이미 초기화됨: {path}',
    'cli.initializing'         => 'CandyServe 데이터 디렉토리 초기화 중: {path}',
    'cli.done'                 => '완료.',
    'cli.next_steps'           => '다음 단계:',
    'cli.next_step_1'          => '  1. {path}/config.yaml 편집',
    'cli.next_step_2'          => '  2. SSH 호스트 키 생성: ssh-keygen -t ed25519 -f {path}/ssh/soft_serve_host',
    'cli.next_step_3'          => '  3. CANDY_SERVE_INITIAL_ADMIN_KEYS=내-SSH-공개키 설정',
    'cli.next_step_4'          => '  4. 실행: soft-serve serve --config {path}/config.yaml',
    'cli.usage_user_root'      => '사용법: soft-serve user add|key|list',
    'cli.usage_user_add'       => '사용법: soft-serve user add <username>',
    'cli.usage_user_key'       => '사용법: soft-serve user key <username> [key-file]',
    'cli.user_created'         => "사용자 '{username}' 생성됨 (admin: true)",
    'cli.user_key_hint'        => "SSH 공개키를 추가하려면 'soft-serve user key {username} < key.pub'을 사용하세요.",
    'cli.user_key_read_failed' => '파일에서 키 읽기 실패: {file}',
    'cli.user_key_added'       => "사용자 '{username}'에 키 추가됨",
    'cli.user_keys_header'     => '승인된 키:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "사용자:\n  (등록된 사용자가 없습니다. 'soft-serve user add <username>'을 사용하세요)",
    'cli.usage_repo_root'      => '사용법: soft-serve repo list|create|info',
    'cli.usage_repo_create'    => '사용법: soft-serve repo create <name>',
    'cli.usage_repo_info'      => '사용법: soft-serve repo info <name>',
    'cli.no_repos'             => '저장소가 아직 없습니다.',
    'cli.repo_listing_none'    => '  (아직 저장소 없음)',
    'cli.repo_invalid_name'    => '잘못된 저장소 이름: {name} (영숫자, 점, 밑줄, 하이픈만 사용)',
    'cli.repo_created'         => "저장소 '{name}' 생성됨 ({path})",
    'cli.repo_clone_url'       => '클론 URL: ssh://localhost:23231/{name}',
    'cli.repo_not_found'       => '저장소를 찾을 수 없음: {name}',
    'cli.repo_info'            => "이름:         {name}\n설명:  {description}\n공개:       {is_public}\n비공개:      {is_private}\n푸시 허용:   {allow_push}\n경로:         {path}\n브랜치:     {branches}\n태그:         {tags}",
    'cli.bool_yes'             => '예',
    'cli.bool_no'              => '아니오',
    'cli.none_value'           => '(없음)',
    'cli.unknown_command'      => "알 수 없는 명령: {cmd}\n  도움말은 'soft-serve help'를 실행하세요.",
    'cli.help'                 => "CandyServe — 자체 호스팅 Git 서버\n\n사용법:\n  soft-serve <명령> [옵션]\n\n명령:\n  serve [--config path]    Git 서버 시작\n  init [data-path]         새 데이터 디렉토리 초기화\n  user add <username>      사용자 생성\n  user key <username> <file>  사용자의 SSH 공개키 추가 (stdin에는 - 사용)\n  user list                사용자 목록\n  repo list [data-path]    저장소 목록\n  repo create <name> [data-path]  저장소 생성\n  repo info <name> [data-path]    저장소 정보 표시\n  help, --help, -h         이 도움말 표시\n  version, --version, -v   버전 표시\n\n환경 변수:\n  CANDY_SERVE_DATA_PATH    데이터 디렉토리 (기본값: /tmp/candy-serve)\n\n예:\n  soft-serve init ~/candy-serve-data\n  soft-serve serve --config ~/candy-serve-data/config.yaml\n  soft-serve repo create my-project\n  soft-serve user key alice ~/.ssh/id_ed25519.pub",
];
