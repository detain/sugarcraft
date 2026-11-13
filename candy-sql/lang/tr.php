<?php

/**
 * Turkish translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite dosyası bulunamadı: {path}',
    'complete.in_literal' => 'dize veya yorum içinde tamamlanacak bir şey yok',
    'complete.nothing' => 'tamamlanacak bir şey yok — bir anahtar sözcüğün başlangıcını yazın',
    'complete.none_keyword' => '"{word}" için tamamlayan anahtar sözcük yok',
    'complete.none_table' => '"{word}" için tamamlayan tablo yok',
    'complete.none_column' => '"{word}" için tamamlayan sütun yok',
    'complete.none_qualified' => '{qualifier} içinde "{word}" için tamamlayan sütun yok',
    'complete.list' => '"{word}" için {count} tamamlama: {list}{more}',
    'complete.done' => 'tamamlandı {word} → {insert}',
    'complete.inserted' => 'eklendi {insert}',
    'edit.refuse_readonly' => 'salt okunur oturum — düzenleme devre dışı',
    'edit.refuse_no_table' => 'önce bir tablo önizlemesi açın — sorgu sonuçları yerinde düzenlenemez',
    'edit.refuse_no_rows' => 'imlecin altında satır yok',
    'edit.refuse_no_pk' => 'bu tablonun birincil anahtarı yok — satırlar güvenle adreslenemez; ifadeyi sorgu bölmesinde yazın',
    'edit.refuse_pk_hidden' => 'bu satırın birincil anahtarı NULL veya ikili yer tutucu — adreslenemez',
    'edit.refuse_binary' => 'ikili veya büyük hücreler burada düzenlenemez — sorgu bölmesinde bir ifade kullanın',
    'edit.refuse_schema' => '{table} şeması okunamıyor — düzenleme reddedildi',
    'edit.prompt_cell' => '{table}.{column} düzenle = {value}  (enter önizler · ctrl+n NULL · esc iptal)',
    'edit.prompt_insert' => '{table} içinde yeni satır — {column} ({type}) [{n}/{total}] = {value}  (enter sonraki · boş = DEFAULT · ctrl+n NULL · esc iptal)',
    'edit.prompt_confirm' => '{sql}  (enter onaylar · esc iptal)',
    'edit.cancelled' => 'düzenleme iptal edildi',
    'edit.unchanged' => 'değer değişmedi — hiçbir şey yazılmadı',
    'edit.done_update' => '{table} içinde 1 satır güncellendi',
    'edit.done_insert' => '{table} içine 1 satır eklendi',
    'edit.done_delete' => '{table} içinden 1 satır silindi',
    'edit.none_changed' => 'hiçbir satır değişmedi — değer zaten oradaydı veya satır yok; önizleme yenilendi',
    'edit.failed' => 'yazma başarısız — geri alındı, hiçbir şey değişmedi: {reason}',
    'edit.too_many' => 'reddedildi: ifade {count} satırı etkilerdi — geri alındı, hiçbir şey değişmedi',
];
