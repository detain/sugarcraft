<?php

/**
 * Korean (ko) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => '{names} 삭제할까요? (y/n)',
    'confirm.copy' => '{names}을(를) {dest}에 복사할까요? (y/n)',
    'confirm.move' => '{names}을(를) {dest}에 이동할까요? (y/n)',
    'confirm.rename' => '\'{name}\' 새 이름: ',
    'confirm.selected_entries' => '{count}개 항목 선택됨',
    'status.nothing_to_delete' => '삭제할 항목 없음',
    'status.nothing_to_copy' => '복사할 항목 없음',
    'status.nothing_to_move' => '이동할 항목 없음',
    'status.nothing_to_rename' => '이름 바꿀 항목 없음',
    'status.cancelled' => '취소됨',
    'status.refreshed' => '새로고침됨',
    'status.copy_pending' => '{count}개 항목 복사 중…',
    'status.deleted' => '{count}개 항목 삭제됨',
    'status.deleted_with_errors' => '{count}개 항목 삭제, 오류 {errors}건',
    'status.deleted_skipped' => '{count}개 항목 삭제, 건너뜀 {skipped}건',
    'status.copied' => '{count}개 항목 복사됨',
    'status.copied_with_errors' => '{count}개 항목 복사, 오류 {errors}건',
    'status.copied_skipped' => '{count}개 항목 복사, 건너뜀 {skipped}건',
    'status.copied_overwrote' => '{count}개 항목 복사, 덮어쓰기 {overwrote}건',
    'status.moved' => '{count}개 항목 이동됨',
    'status.moved_with_errors' => '{count}개 항목 이동, 오류 {errors}건',
    'status.moved_skipped' => '{count}개 항목 이동, 건너뜀 {skipped}건',
    'status.renamed' => '{old} → {new} 이름 변경됨',
    'status.rename_failed' => '이름 변경 실패: {name}',
    'status.nothing_to_undo' => '실행 취소할 항목 없음',
    'status.undone' => '실행 취소됨: {description}',
    'status.undo_with_errors' => '{description} 실행 취소 중 오류 {errors}건',
    'status.cannot_close_last_tab' => '마지막 탭은 닫을 수 없음',
    'status.redone' => '다시 실행됨: {description}',
    'status.redo_with_errors' => '{description} 다시 실행 중 오류 {errors}건',
    'status.nothing_to_redo' => '다시 실행할 항목 없음',
    'status.copy_undo_noop' => '복사본 유지 (실행 취소 없음)',
    'undo.delete' => '{count}개 항목 삭제',
    'undo.rename' => '{count}개 항목 이름 변경',
    'undo.move' => '{count}개 항목 이동',
    'undo.copy' => '{count}개 항목 복사',
    'keyhelp.default' => 'Tab 전환 · ↑↓ jk 이동 · Enter 열기 · ← h 위로 · Space 선택 · s 정렬 · . 숨김 · c 복사 · m 이동 · R 이름변경 · d 삭제 · r 새로고침 · q 종료 · / 검색 · t 새 탭 · ^w 탭 닫기 · ^tab 순환',
    'search.no_match' => '(일치 없음)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[폴더]',
    'search.type_file' => '[파일]',
    'pane.hidden_suffix' => '+숨김',
];
