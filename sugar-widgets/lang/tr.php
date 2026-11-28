<?php

/**
 * Turkish translations for sugar-widgets.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'column.width_nonneg'         => 'Sütun genişliği >= 0 olmalıdır',
    'tabs.bad_index'              => 'sekme indeksi aralık dışında',
    'tabs.neg_width'              => 'sekme genişliği >= 0 olmalıdır',
    'tabs.neg_padding'            => 'sekme etiketi dolgusu >= 0 olmalıdır',
    'tabs.zone_key_empty'         => 'sekme bölgesi anahtarı boş olamaz',
    'tabs.close_glyph_empty'      => 'sekme kapatma simgesi boş olamaz',
    'tabs.scroll_mark_empty'      => 'sekme kaydırma işareti boş olmamalıdır',
    'table.dim_nonneg'            => 'tablo genişlik/yüksekliği >= 0 olmalıdır',
    'table.page_size_nonneg'      => 'sayfa boyutu >= 0 olmalıdır',
    'table.set_columns_type'      => 'sütunlar birer sütun örneği olmalı',
    'table.sort_unknown_column'   => 'sıralama sütunu bulunamadı: {column}',
    'progress.width_nonneg'       => 'ilerleme genişliği >= 0 olmalıdır',
    'tree.dim_nonneg'             => 'ağaç genişlik/yüksekliği >= 0 olmalıdır',
    'help.width_nonneg'           => 'yardım genişliği >= 0 olmalıdır',
    'stopwatch.interval_positive' => 'stopwatch aralığı > 0 olmalıdır',
    'timer.duration_nonneg'       => 'zamanlayıcı süresi >= 0 olmalıdır',
    'timer.interval_positive'     => 'zamanlayıcı aralığı > 0 olmalıdır',
    'paginator.arabic_format_invalid' => 'Arapça biçim, iki tamsayı için geçerli bir biçim olmalıdır',
    'progress.bad_percent_format' => 'yüzde biçimi tek tamsayılı geçerli bir biçim olmalıdır',
    'progress.bad_value_format' => 'değer biçimi iki tamsayılı geçerli bir biçim olmalıdır',
    'progress.rune_cell_width' => 'çubuk karakterleri tam olarak bir görüntü hücresi genişliğinde olmalıdır',
    'progress.color_func_type' => 'renk işlevi bir renk değeri döndürmeli',
    'tabs.next_tab' => 'sonraki sekme',
    'tabs.prev_tab' => 'önceki sekme',
    'tabs.jump_to' => 'sekme {index}',
    'tabs.close_tab' => 'sekmeyi kapat',
    'timer.paused_tag' => 'duraklatıldı',
    'timer.timeout_tag' => 'zaman aşımı',
    'stopwatch.not_started_tag' => 'başlatılmadı',
    'stopwatch.paused_tag' => 'duraklatıldı',
    'key.bad_binding' => 'geçersiz tuş bağı {key}: küçük harfli tuş adı (ctrl+c, shift+tab, pageup, f5) veya tek yazılabilir karakter kullanın',
    'key.bad_binding_type' => 'tuş bağları dize olmalıdır, alınan tür: {key}',
    'tree.use_branch_for_children' => 'bir düğümün alt düğümleri varsa branch() fabrikasını kullanın',
    'button.pressed' => '{label} etkinleştirildi',
    'rule.length_nonneg' => 'çizgi uzunluğu >= 0 olmalıdır',
    'rule.bad_rune' => 'çizgi karakteri tam olarak bir hücre kaplamalıdır',

    // Help/HelpOverlay.php, Key/KeyView.php
    'help_overlay.height_positive' => 'yardım katmanının yüksekliği >= 1 olmalıdır',
    'key_view.bad_columns' => 'tuş görünümü sütunları eylem adı listelerinden oluşan bir liste olmalıdır',
    'key_view.column_size_nonneg' => 'tuş görünümü sütun boyutu >= 0 olmalıdır',

    // Confirm/Confirm.php
    'confirm.word_empty' => 'yazılacak onay sözcüğü boş olamaz',
];
