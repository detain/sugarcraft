<?php

/**
 * 'ru' translations for sugar-readline.
 *
 * Keys ride lang/en.php verbatim; the {placeholder} tokens are contract
 * (LOCALES.md named-token law — values may reorder them, never rename
 * them). '[y/n]' is key notation and stays untranslated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return array (
  'confirm.hint' => '[y/n]',
  'confirm.no' => 'Нет',
  'confirm.yes' => 'Да',
  'history.append_lock_failed' => 'не удалось захватить блокировку добавления для {path}',
  'history.changed_under_us' => 'путь истории изменился у нас под ногами (возможна подмена симлинка): {path}',
  'history.chmod_failed' => 'не удалось установить 0600 для файла истории {path}',
  'history.clear_temp_failed' => 'не удалось создать временный файл для очистки истории в {dir}',
  'history.create_failed' => 'не удалось создать файл истории {path}',
  'history.lock_acquire_failed' => 'не удалось захватить блокировку записи истории {path}',
  'history.lock_changed' => 'блокировка истории изменилась у нас под ногами (возможна подмена симлинка): {path}',
  'history.lock_open_failed' => 'не удалось открыть блокировку записи истории {path}',
  'history.not_regular' => 'путь истории не является обычным файлом (открыт через подмену?): {path}',
  'history.open_failed' => 'не удалось открыть файл истории {path}',
  'history.replace_failed' => 'не удалось заменить файл истории {path}',
  'history.symlink_refused' => 'отклонён симлинк у {what}: {path}',
  'history.temp_create_failed' => 'не удалось создать временный файл для перезаписи истории в {dir}',
  'history.temp_lock_failed' => 'не удалось заблокировать временный файл перезаписи истории: {path}',
  'history.temp_open_failed' => 'не удалось открыть временный файл перезаписи истории: {path}',
  'multiselect.hint' => '[мультивыбор] выбрано: {count}',
  'multiselect.hint_max' => '[мультивыбор] выбрано: {count} (макс {max})',
  'multiselect.hint_min' => '[мультивыбор] выбрано: {count} (мин {min})',
  'multiselect.hint_min_max' => '[мультивыбор] выбрано: {count} (мин {min}) (макс {max})',
  'selection.filter_prefix' => '[фильтр] ',
  'selection.matches_one' => ' ({count} совпадение)',
  'selection.matches_other' => ' ({count} совпадений)',
  'selection.no_matches' => '(нет совпадений)',
  'selection.page' => 'Стр. {page}/{of}',
  'text.invalid_input' => 'Некорректный ввод',
  'text.search_line_forward' => '{label}(i-поиск)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(сбой i-поиск)`{query}\': ',
  'text.search_line_reverse' => '{label}(обратный-i-поиск)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(сбой обратный-i-поиск)`{query}\': ',
);
