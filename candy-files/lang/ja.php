<?php

/**
 * Japanese (ja) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => '{names} を削除しますか? (y/n)',
    'confirm.copy' => '{names} を {dest} へコピーしますか? (y/n)',
    'confirm.move' => '{names} を {dest} へ移動しますか? (y/n)',
    'confirm.rename' => '\'{name}\' の新しい名前: ',
    'confirm.selected_entries' => '{count} 件選択中',
    'status.nothing_to_delete' => '削除する項目がありません',
    'status.nothing_to_copy' => 'コピーする項目がありません',
    'status.nothing_to_move' => '移動する項目がありません',
    'status.nothing_to_rename' => '名前変更する項目がありません',
    'status.cancelled' => 'キャンセルしました',
    'status.refreshed' => '更新しました',
    'status.copy_pending' => '{count} 件をコピー中…',
    'status.deleted' => '{count} 件削除しました',
    'status.deleted_with_errors' => '{count} 件削除、エラー {errors} 件',
    'status.deleted_skipped' => '{count} 件削除、スキップ {skipped} 件',
    'status.copied' => '{count} 件コピーしました',
    'status.copied_with_errors' => '{count} 件コピー、エラー {errors} 件',
    'status.copied_skipped' => '{count} 件コピー、スキップ {skipped} 件',
    'status.copied_overwrote' => '{count} 件コピー、上書き {overwrote} 件',
    'status.moved' => '{count} 件移動しました',
    'status.moved_with_errors' => '{count} 件移動、エラー {errors} 件',
    'status.moved_skipped' => '{count} 件移動、スキップ {skipped} 件',
    'status.renamed' => '{old} を {new} に名前変更しました',
    'status.rename_failed' => '名前変更に失敗: {name}',
    'status.nothing_to_undo' => '元に戻す操作がありません',
    'status.undone' => '元に戻しました: {description}',
    'status.undo_with_errors' => '{description} の取り消しでエラー {errors} 件',
    'status.cannot_close_last_tab' => '最後のタブは閉じけません',
    'status.redone' => 'やり直しました: {description}',
    'status.redo_with_errors' => '{description} のやり直しのエラー {errors} 件',
    'status.nothing_to_redo' => 'やり直す操作がありません',
    'status.copy_undo_noop' => 'コピーはそのままです (元に戻す必要なし)',
    'undo.delete' => '{count} 項目の削除',
    'undo.rename' => '{count} 項目の名前変更',
    'undo.move' => '{count} 項目の移動',
    'undo.copy' => '{count} 項目のコピー',
    'keyhelp.default' => 'Tab 切替 · ↑↓ jk 移動 · Enter 開く · ← h 上へ · Space 選択 · s 並べ替え · . 隠し表示 · c コピー · m 移動 · R 名前変更 · d 削除 · r 再読込 · q 終了 · / 検索 · t 新規タブ · ^w タブを閉じる · ^tab タブ巡回',
    'search.no_match' => '(一致なし)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[DIR]',
    'search.type_file' => '[FILE]',
    'pane.hidden_suffix' => '+隠し',
];
