<?php

/**
 * Korean translations for candy-kv.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.entry_unreadable' => '항목을 저장했지만 더 이상 읽을 수 없습니다 (만료, 삭제 또는 손상)',
    'store.cannot_read'         => '파일을 읽을 수 없음: {path}',
    'cli.usage_set'             => '사용법: {bin} set <키> [값] [--ttl SECONDS] [--binary|--text]',
    'cli.usage_get'             => '사용법: {bin} get <키> [--sanitize]',
    'cli.usage_delete'          => '사용법: {bin} delete <키>',
    'cli.usage_import'           => '사용법: {bin} import <json|yaml|yml> <경로> [--no-atomic] [--if-exists=MODE]',
    'cli.usage_export'            => '사용법: {bin} export <json|yaml|yml> [데이터베이스] [패턴]',
    'cli.deleted_n'             => '{count}개 항목을 삭제했습니다.',
    'cli.unknown_command'       => '알 수 없는 명령: {cmd}',
    'cli.deleted_none'           => '삭제된 항목 없음: "{key}"와(과) 일치하는 항목이 없습니다.',
    'cli.pattern_not_supported'  => 'get은 정확한 키를 받습니다, 패턴이 아닙니다. 패턴 검색은 "{bin} list <pattern>"을(를) 사용하세요.',
    'store.did_you_mean'         => '키를 찾을 수 없습니다; \'{key}\'을(를) 의미했나요?',
    'database.query_failed'      => '데이터베이스 쿼리가 실패했습니다.',
    'cli.key_not_found'          => '키를 찾을 수 없습니다: {key}',
    'cli.deleted_one'            => '1개를 삭제했습니다.',
    'cli.imported_one'           => '1개를 가져왔습니다.',
    'cli.imported_n'             => '{count}개를 가져왔습니다.',
    'cli.overwrote_one'          => '1개를 덮어썼습니다.',
    'cli.overwrote_n'            => '{count}개를 덮어썼습니다.',
    'cli.would_delete_one'       => '1개를 삭제합니다.',
    'cli.would_delete_n'         => '{count}개를 삭제합니다.',
    'cli.pruned_n'               => '만료된 항목 {count}개를 제거했습니다.',
    'cli.pruned_one'             => '만료된 항목 1개를 제거했습니다.',
    'cli.pruned_none'            => '제거할 만료된 항목이 없습니다.',
];
