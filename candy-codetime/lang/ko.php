<?php

/**
 * Korean translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => '{duration}s 기록됨: {project} ({language})',
    'cli.usage' => "사용법: candy-codetime [--backend=file|sqlite] <command> [args]\n\n명령:\n  dashboard              TUI 대시보드를 시작합니다 (선택한 백엔드 사용)\n  push                   <project> <language> <file> [duration]  하트비트 기록\n  milestone              add [name] [description] | list  마일스톤 관리 (sqlite 전용)\n  export                 <csv|json|ics> [days]  하트비트 내보내기 (기본 7일)\n  gaps                   [days]  300초 이상의 추적되지 않은 공백 표시 (기본 7일)\n  backup                 30일보다 오래된 데이터 파일 보관 (복사만 하며 삭제하지 않음)\n  help, --help, -h       이 사용법 표시\n  --version, -v          버전 출력\n\n옵션:\n  --backend=file|sqlite  push/export/gaps/dashboard 의 저장 백엔드 (기본값: file)\n  CANDY_CODETIME_DIR     데이터 디렉터리 (기본값: \$XDG_DATA_HOME/candy-codetime, 미설정 시 \$HOME/.local/share/candy-codetime, HOME도 미설정 시 ./.local/share/candy-codetime)",
    'cli.push_usage' => "사용법: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: 필수 인자가 없습니다 (project, language, file)",
    'cli.push_ignored' => "push: {file} 이 .sugartrackignore 와 일치하여 건너뛰었습니다",
    'cli.push_ignored_wholesale' => 'push: {ignore_file}의 전체 일치 패턴({patterns}개)으로 {file} 건너뜀; "{project}" 프로젝트는 아무것도 기록되지 않습니다 — 파일을 삭제하거나 좁히십시오',
    'cli.push_invalid_duration' => 'push: 지속 시간은 초 단위의 정수여야 합니다. 입력값: "{value}"',
    'cli.invalid_days' => '일수는 정수여야 합니다. 입력값: "{value}"',
    'cli.backend_invalid' => "잘못된 백엔드 (file 또는 sqlite 사용)",
    'cli.backend_unavailable' => "sqlite 백엔드를 사용할 수 없습니다: PHP 확장 sqlite3 이 로드되지 않았습니다",
    'cli.storage_error' => 'candy-codetime: 저장소 오류: {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime: 대시보드에는 대화형 터미널이 필요합니다 (stdin과 stdout이 TTY가 아닙니다). 터미널 없이 출력하려면 candy-codetime export <csv|json|ics>를 사용하세요',
    'cli.milestone_usage' => "사용법: candy-codetime milestone add [name] [description] | list",
    'cli.milestone_missing_args' => "milestone add: 필수 인자가 없습니다 (name)",
    'cli.milestone_added' => "마일스톤이 추가되었습니다: {name}",
    'cli.milestone_list_header' => "마일스톤:",
    'cli.milestone_list_empty' => "기록된 마일스톤이 없습니다.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "사용법: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: 잘못된 형식 (csv, json 또는 ics 사용)",
    'cli.gaps_no_data' => "공백을 찾지 못했습니다.",
    'cli.gaps_header' => "추적되지 않은 시간 공백:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: 파일 {count} 개를 보관했습니다",
    'cli.backup_done_one' => 'backup: 파일 {count} 개를 보관했습니다',
    'cli.backup_none' => "backup: 새로 보관할 항목이 없습니다",
    'cli.backup_sqlite_notice' => 'backup: SQLite 데이터베이스 {db}는 이 명령으로 보관되지 않습니다 — 안전한 사본은 sqlite3 <db> ".backup <dest>" 또는 VACUUM INTO를 사용하십시오',
    'cli.unknown_command' => "candy-codetime: 알 수 없는 명령: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} 은(는) 지원이 중단되었습니다. 대신 CANDY_CODETIME_DIR 을 설정하세요.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
