<?php

/**
 * Korean translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "항목이 기록되었습니다: {project} ({language}) +{duration}s",
    'cli.usage' => "사용법: candy-codetime [--backend=file|sqlite] <command> [args]\n\n명령:\n  dashboard              하트비트 기록\n  push                   <project> <language> <file> [duration]  마일스톤 관리 (sqlite 전용)\n  milestone              <add|list> [name] [description]  하트비트 내보내기 (기본 7일)\n  export                 <csv|json|ics> [days]  300초 이상의 추적되지 않은 공백 표시 (기본 7일)\n  gaps                   [days]  30일보다 오래된 데이터 파일 보관 (복사만 하며 삭제하지 않음)\n  backup                 이 사용법 표시\n  help, --help           버전 출력\n  --version, -v          기본값: file\n\n옵션:\n  --backend=file|sqlite  push/export/gaps/dashboard 의 저장 백엔드 (기본값: file)\n  CANDY_CODETIME_DIR     데이터 디렉터리 (기본값: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "사용법: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: 필수 인자가 없습니다 (project, language, file)",
    'cli.push_ignored' => "push: {file} 이 .sugartrackignore 와 일치하여 건너뛰었습니다",
    'cli.push_invalid_duration' => "push: 지속 시간은 초 단위의 음이 아닌 정수여야 합니다. 입력값: \"{value}\"",
    'cli.backend_invalid' => "잘못된 백엔드 (file 또는 sqlite 사용)",
    'cli.backend_unavailable' => "sqlite 백엔드를 사용할 수 없습니다: PHP 확장 sqlite3 이 로드되지 않았습니다",
    'cli.milestone_usage' => "사용법: candy-codetime milestone <add|list> [name] [description]",
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
    'cli.backup_none' => "backup: 새로 보관할 항목이 없습니다",
    'cli.unknown_command' => "candy-codetime: 알 수 없는 명령: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} 은(는) 지원이 중단되었습니다. 대신 CANDY_CODETIME_DIR 을 설정하세요.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
