<?php

/**
 * Korean translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite 파일을 찾을 수 없음: {path}',
    'complete.in_literal' => '문자열이나 주석 안에서는 완성할 수 없습니다',
    'complete.nothing' => '완성할 항목이 없습니다 — 키워드의 앞부분을 입력하세요',
    'complete.none_keyword' => '"{word}"을(를) 완성하는 키워드가 없습니다',
    'complete.none_table' => '"{word}"을(를) 완성하는 테이블이 없습니다',
    'complete.none_column' => '"{word}"을(를) 완성하는 열이 없습니다',
    'complete.none_qualified' => '{qualifier}에 "{word}"을(를) 완성하는 열이 없습니다',
    'complete.list' => '"{word}" 완성 후보 {count}개: {list}{more}',
    'complete.done' => '완성됨 {word} → {insert}',
    'complete.inserted' => '삽입됨 {insert}',
    'edit.refuse_readonly' => '읽기 전용 세션입니다 — 편집이 비활성화되었습니다',
    'edit.refuse_no_table' => '먼저 테이블 미리보기를 여세요 — 쿼리 결과는 제자리에서 편집할 수 없습니다',
    'edit.refuse_no_rows' => '커서 아래에 행이 없습니다',
    'edit.refuse_no_pk' => '이 테이블에는 기본 키가 없습니다 — 행을 안전하게 지정할 수 없으니 쿼리 창에서 문을 작성하세요',
    'edit.refuse_pk_hidden' => '이 행의 기본 키가 NULL이거나 바이너리 자리표시자입니다 — 지정할 수 없습니다',
    'edit.refuse_binary' => '바이너리 또는 큰 셀은 여기서 편집할 수 없습니다 — 쿼리 창에서 문을 사용하세요',
    'edit.refuse_schema' => '{table}의 스키마를 읽을 수 없습니다 — 편집이 거부되었습니다',
    'edit.prompt_cell' => '{table}.{column} 편집 = {value}  (Enter 미리보기 · Ctrl+N NULL · Esc 취소)',
    'edit.prompt_insert' => '{table}에 새 행 — {column} ({type}) [{n}/{total}] = {value}  (Enter 다음 · 비움 = DEFAULT · Ctrl+N NULL · Esc 취소)',
    'edit.prompt_confirm' => '{sql}  (Enter 확정 · Esc 취소)',
    'edit.cancelled' => '편집이 취소되었습니다',
    'edit.unchanged' => '값이 변경되지 않았습니다 — 아무것도 쓰지 않았습니다',
    'edit.done_update' => '{table}에서 1개 행을 갱신했습니다',
    'edit.done_insert' => '{table}에 1개 행을 삽입했습니다',
    'edit.done_delete' => '{table}에서 1개 행을 삭제했습니다',
    'edit.none_changed' => '변경된 행이 없습니다 — 값이 이미 같거나 행이 없습니다. 미리보기를 새로 고쳤습니다',
    'edit.failed' => '쓰기 실패 — 롤백됨, 변경 없음: {reason}',
    'edit.too_many' => '거부됨: 이 문은 {count}개 행에 영향을 줍니다 — 롤백됨, 변경 없음',
];
