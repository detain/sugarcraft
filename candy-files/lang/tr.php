<?php

/**
 * Turkish (tr) translations for candy-files.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'confirm.delete' => '{names} silinsin mi? (e/h)',
    'confirm.copy' => '{names}, {dest} konumuna kopyalansın mı? (e/h)',
    'confirm.move' => '{names}, {dest} konumuna taşınsın mı? (e/h)',
    'confirm.rename' => '\'{name}\' yeni adı: ',
    'confirm.selected_entries' => '{count} öğe seçildi',
    'status.nothing_to_delete' => 'silinecek bir şey yok',
    'status.nothing_to_copy' => 'kopyalanacak bir şey yok',
    'status.nothing_to_move' => 'taşınacak bir şey yok',
    'status.nothing_to_rename' => 'yeniden adlandırılacak bir şey yok',
    'status.cancelled' => 'iptal edildi',
    'status.refreshed' => 'yenilendi',
    'status.copy_pending' => '{count} öğe kopyalanıyor…',
    'status.deleted' => '{count} öğe silindi',
    'status.deleted_with_errors' => '{count} öğe silindi, {errors} hata',
    'status.deleted_skipped' => '{count} öğe silindi ({skipped} atlandı)',
    'status.copied' => '{count} öğe kopyalandı',
    'status.copied_with_errors' => '{count} öğe kopyalandı, {errors} hata',
    'status.copied_skipped' => '{count} öğe kopyalandı ({skipped} atlandı)',
    'status.copied_overwrote' => '{count} öğe kopyalandı ({overwrote} üzerine yazıldı)',
    'status.moved' => '{count} öğe taşındı',
    'status.moved_with_errors' => '{count} öğe taşındı, {errors} hata',
    'status.moved_skipped' => '{count} öğe taşındı ({skipped} atlandı)',
    'status.renamed' => '{old} → {new} olarak adlandırıldı',
    'status.rename_failed' => 'yeniden adlandırma başarısız: {name}',
    'status.nothing_to_undo' => 'geri alınacak bir şey yok',
    'status.undone' => 'geri alındı: {description}',
    'status.undo_with_errors' => '{description} geri alınırken {errors} hata',
    'status.cannot_close_last_tab' => 'Son sekme kapatılamaz',
    'status.redone' => 'yinelendi: {description}',
    'status.redo_with_errors' => '{description} yinelenirken {errors} hata',
    'status.nothing_to_redo' => 'yinelenacak bir şey yok',
    'status.copy_undo_noop' => 'kopya yerinde bırakıldı (geri alınacak bir şey yok)',
    'undo.delete' => '{count} ögeyi sil',
    'undo.rename' => '{count} ögeyi yeniden adlandır',
    'undo.move' => '{count} ögeyi taşı',
    'undo.copy' => '{count} ögeyi kopyala',
    'keyhelp.default' => 'Tab değiştir · ↑↓ jk hareket · Enter aç · ← h yukarı · space seç · s sırala · . gizli · c kopyala · m taşı · R adlandır · d sil · r yenile · q çık · / ara · t yeni sekme · ^w sekmeyi kapat · ^tab dön',
    'search.no_match' => '(eşleşme yok)',
    'search.counter' => '({current}/{total})',
    'search.type_dir' => '[DİZİN]',
    'search.type_file' => '[DOSYA]',
    'pane.hidden_suffix' => '+gizli',
];
