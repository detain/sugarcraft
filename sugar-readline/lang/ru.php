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
