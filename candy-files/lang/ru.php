<?php

/**
 * Russian (ru) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => 'удалить {names}? (д/н)',
    'confirm.copy' => 'скопировать {names} в {dest}? (д/н)',
    'confirm.move' => 'переместить {names} в {dest}? (д/н)',
    'confirm.rename' => 'переименовать \'{name}\' в: ',
    'confirm.selected_entries' => 'выбрано элементов: {count}',
    'confirm.affirmative' => 'д',
    'status.nothing_to_delete' => 'нечего удалять',
    'status.nothing_to_copy' => 'нечего копировать',
    'status.nothing_to_move' => 'нечего перемещать',
    'status.nothing_to_rename' => 'нечего переименовывать',
    'status.cancelled' => 'отменено',
    'status.refreshed' => 'обновлено',
    'status.copy_pending' => 'копирую {count} элементов…',
    'status.deleted' => 'удалено элементов: {count}',
    'status.deleted_with_errors' => 'удалено элементов: {count}, ошибок: {errors}',
    'status.deleted_skipped' => 'удалено {count} (пропущено {skipped})',
    'status.copied' => 'скопировано элементов: {count}',
    'status.copied_with_errors' => 'скопировано элементов: {count}, ошибок: {errors}',
    'status.copied_skipped' => 'скопировано {count} (пропущено {skipped})',
    'status.copied_overwrote' => 'скопировано {count} (перезаписано {overwrote})',
    'status.moved' => 'перемещено элементов: {count}',
    'status.moved_with_errors' => 'перемещено элементов: {count}, ошибок: {errors}',
    'status.moved_skipped' => 'перемещено {count} (пропущено {skipped})',
    'status.renamed' => '{old} переименован в {new}',
    'status.rename_failed' => 'сбой переименования: {name}',
    'status.nothing_to_undo' => 'нечего отменять',
    'status.undone' => 'отменено: {description}',
    'status.undo_with_errors' => 'отмена {description}: ошибок {errors}',
    'status.cannot_close_last_tab' => 'Нельзя закрыть последнюю вкладку',
    'status.redone' => 'повторено: {description}',
    'status.redo_with_errors' => 'повтор {description}: ошибок {errors}',
    'status.nothing_to_redo' => 'нечего повторять',
    'status.copy_undo_noop' => 'копия оставлена (нечего отменять)',
    'status.focus' => 'фокус: {side}',
    'side.left' => 'слева',
    'side.right' => 'справа',
    'undo.delete' => 'удаление {count} элемент(ов)',
    'undo.rename' => 'переименование {count} элемент(ов)',
    'undo.move' => 'перемещение {count} элемент(ов)',
    'undo.copy' => 'копирование {count} элемент(ов)',
    'keyhelp.default' => 'Tab смена · ↑↓ jk движение · Home/End g/G переход · Enter открыть · ← h вверх · пробел выбрать · s сортировка · . скрытые · c копировать · m переместить · R переименовать · d удалить · r обновить · q выход · / поиск · Esc сброс · t дублировать вкладку · ^w закрыть вкладку · ^tab переключение · ^PgUp/^PgDn вкладки · u/^z отмена · ^y вернуть',
    'search.no_match' => '(нет совпадений)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[КАТ]',
    'search.type_file' => '[ФАЙЛ]',
    'pane.hidden_suffix' => '+скрытые',
];
