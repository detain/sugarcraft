<?php

/**
 * 'tr' translations for sugar-readline.
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
  'confirm.no' => 'Hayır',
  'confirm.yes' => 'Evet',
  'history.append_lock_failed' => '{path} üzerinde ekleme kilidi alınamıyor',
  'history.changed_under_us' => 'geçmiş yolu bizim altımızda değişti (olası symlink değişimi): {path}',
  'history.chmod_failed' => 'geçmiş dosyası {path} üzerinde 0600 ayarlanamıyor',
  'history.clear_temp_failed' => 'geçmişi temizlemek için {dir} içinde geçici dosya oluşturulamıyor',
  'history.create_failed' => 'geçmiş dosyası {path} oluşturulamıyor',
  'history.lock_acquire_failed' => 'geçmiş yazma kilidi {path} alınamıyor',
  'history.lock_changed' => 'geçmiş kilidi bizim altımızda değişti (olası symlink değişimi): {path}',
  'history.lock_open_failed' => 'geçmiş yazma kilidi {path} açılamıyor',
  'history.not_regular' => 'geçmiş yolu normal bir dosya değil (değişim üzerinden mi açıldı?): {path}',
  'history.open_failed' => 'geçmiş dosyası {path} açılamıyor',
  'history.replace_failed' => 'geçmiş dosyası {path} değiştirilemiyor',
  'history.symlink_refused' => '{what} için symlink reddediliyor: {path}',
  'history.temp_create_failed' => 'geçmişi yeniden yazmak için {dir} içinde geçici dosya oluşturulamıyor',
  'history.temp_lock_failed' => 'geçmiş yeniden yazma geçici dosyası kilitlenemiyor: {path}',
  'history.temp_open_failed' => 'geçmiş yeniden yazma geçici dosyası açılamıyor: {path}',
  'multiselect.hint' => '[çoklu seçim] seçili: {count}',
  'multiselect.hint_max' => '[çoklu seçim] seçili: {count} (en çok {max})',
  'multiselect.hint_min' => '[çoklu seçim] seçili: {count} (en az {min})',
  'multiselect.hint_min_max' => '[çoklu seçim] seçili: {count} (en az {min}) (en çok {max})',
  'multiselect.notice_unmarked' => '{max} sınırını korumak için {item} işaretinden çıkarıldı',
  'selection.filter_prefix' => '[süzgeç] ',
  'selection.matches_one' => ' ({count} eşleşme)',
  'selection.matches_other' => ' ({count} eşleşme)',
  'selection.no_matches' => '(eşleşme yok)',
  'selection.page' => 'Sayfa {page}/{of}',
  'textarea.notice_max_lines' => 'satır eklenemiyor: en fazla {max} sınırı aşıldı',
  'text.invalid_input' => 'Geçersiz giriş',
  'text.notice_no_history' => 'geçmiş boş',
  'text.search_line_forward' => '{label}(i-arama)`{query}\': ',
  'text.search_line_forward_failed' => '{label}(başarısız i-arama)`{query}\': ',
  'text.search_line_reverse' => '{label}(geri-i-arama)`{query}\': ',
  'text.search_line_reverse_failed' => '{label}(başarısız geri-i-arama)`{query}\': ',
);
