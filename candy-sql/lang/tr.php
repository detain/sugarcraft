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
    'ui.title.tables' => ' tablolar ',
    'ui.title.rows' => ' satırlar ',
    'ui.title.query' => ' sorgu ',
    'ui.title.admin' => ' yönetim ',
    'ui.title.explain' => ' plan ',
    'ui.help.query' => 'tab  bölme değiştir  ·  ctrl+r / ctrl+e / ctrl+enter  çalıştır  ·  enter  yeni satır  ·  ↑/↓  geçmiş  ·  esc / ctrl+c  sorguyu iptal et  ·  ctrl+c  boşta çık',
    'ui.help.rows' => 'tab  bölme değiştir  ·  j/k  satır  ·  h/l  hücre  ·  e  düzenle  ·  o  yeni satır  ·  D  sil  ·  y  kopyala  ·  x  dışa aktar  ·  q / esc  çık',
    'ui.help.browse' => 'tab  bölme değiştir  ·  enter  tabloyu yükle  ·  x  dışa aktar  ·  i  içe aktar  ·  q / esc  çık',
    'ui.help.empty' => 'tab  bölme değiştir  ·  q / esc  çık',
    'param.prompt' => 'işaretçi {index}/{total} ({label}) - değeri yazın, Enter bağlar (boş = NULL), Esc iptal eder',
    'param.line' => 'parametre {label} ({index}/{total}) = {value} · boş = NULL · Enter sonraki · Esc iptal',
    'param.cancel' => 'parametre girişi iptal edildi - sorgu çalıştırılmadı',
    'param.mixed' => '? ve :name karışımı desteklenmiyor - tek bir stil seçin',
    'yank.refuse_no_cell' => 'imlecin altında hücre yok',
    'yank.osc52' => 'hücre panoya kopyalandı - uçbirim OSC 52’yi yoksayabilir',
    'yank.plain' => 'bu uçbirimde OSC 52 yok - hücre metni: {text}',
    'yank.plain_null' => 'bu uçbirimde OSC 52 yok - hücre NULL',
    'yank.too_big' => 'hücre {bytes} bayt - OSC 52 sınırını aşıyor',
    'ui.help.result' => 'tab  bölme değiştir  ·  j/k  satır  ·  h/l  hücre  ·  y  kopyala  ·  x  dışa aktar  ·  q / esc  çık',
    'ui.help.admin' => '1-{count}  sayfa seç  ·  j/k  gezin  ·  p  duraklat  ·  r  sıfırla  ·  tab  bölme değiştir  ·  q  geri  ·  esc  iletişim veya sorgu iptal',
    'ui.no_tables' => '(tablo yok)',
    'ui.no_match' => '(eşleşme yok)',
    'ui.loading' => 'yükleniyor…',
    'ui.empty' => '(boş)',
    'ui.admin_unavailable' => 'yönetim sayfası kullanılamıyor: {reason}',
    'ui.error' => 'hata: {message}',
    'io.prompt_export' => 'CSV\'ye → {path}  (enter yazar · esc iptal)',
    'io.prompt_replace' => '{path} DEĞİŞTİRİLSİN Mİ?  (enter onaylar · esc iptal)',
    'io.prompt_import_path' => 'CSV içe aktar ← {path}  (enter · esc iptal)',
    'io.prompt_import_table' => '{source} içe aktarılıyor → tablo {table}  (enter · esc iptal)',
    'bar.tables' => 'Tablolar: {count}',
    'bar.cycle' => 'Tab:değiştir',
    'bar.navigate' => '↑↓:gezin',
    'bar.load' => 'Enter:yükle',
    'bar.run' => 'Ctrl+R:çalıştır',
    'bar.select' => '1-{count}:seç',
    'bar.nav' => 'j/k:gezin',
    'bar.quit' => 'q:çık',
    'bar.paused' => '[DURAKLATILDI]',
    'bar.tx' => '[TX]',
    'picker.title' => 'kayıtlı bağlantılar',
    'picker.help' => '[↑↓/j/k] seç   [Enter] bağlan   [q] iptal',
    'result.empty' => '(boş sonuç kümesi)',
    'explain.no_plan' => '(sorgu planı yok — sorgu satır döndürmedi)',
    'explain.title' => ' SORGU PLANI ',
    'status.live_refresh_off' => 'yönetim canlı yenilemesi devre dışı — TLS doğrulamalı ssl-mode (yalnızca eşzamanlı aktarım)',
    'status.async_failed' => 'Eşzamansız komut başarısız: {message}',
    'status.file_cancelled' => 'dosya satırı iptal edildi',
    'status.path_required' => 'yol gerekli — esc iptal eder',
    'status.no_such_file' => 'dosya yok — yazmaya devam edin veya esc iptal eder',
    'status.table_required' => 'hedef tabloyu adlandırın — esc iptal eder',
    'status.export_failed' => 'dışa aktarma başarısız: {message}',
    'status.exported' => '{count} satır {path} dosyasına aktarıldı',
    'status.import_failed' => 'içe aktarma başarısız: {message}',
    'status.imported' => '{path}, {table} tablosuna aktarıldı',
    'status.history' => 'geçmiş {pos}/{count}',
    'status.conn_lost' => 'bağlantı koptu; yeniden deneyin',
    'status.destructive' => '⚠ yıkıcı ifade — onaylamak için ctrl+r\'ye tekrar basın (değiştirmeden)',
    'status.running' => 'sorgu çalıştırılıyor…',
    'status.explain_failed' => 'EXPLAIN başarısız: {message}',
    'status.explain_plan' => 'yürütme planı — herhangi bir sorgu veya tablo önizlemesi bunun yerini alır',
    'status.end_of_table' => 'tablonun sonu — başka sayfa yok',
    'status.first_page' => 'zaten ilk sayfada',
    'status.nothing_to_save' => 'kaydedilecek bir şey yok',
    'status.saved' => 'kaydedildi ({count})',
    'status.removed' => 'kaldırıldı ({count})',
    'status.row_one' => '1 satır',
    'status.rows' => '{count} satır',
    'status.page' => 'sayfa {page} (satır {first}–{last})',
    'editor.placeholder' => '-- SQL yazın, ctrl+r ile çalıştırın --',
    'err.async_query_unsupported' => 'Bu sürücü eşzamansız sorguları desteklemiyor',
    'err.async_browse_unsupported' => 'Bu sürücü için eşzamansız gezinme desteklenmiyor',
    'dash.title' => 'Performans Panosu',
    'dash.fetching' => 'Sunucu metrikleri alınıyor...',
    'dash.back_hint' => 'Tablo görünümüne dönmek için [q] tuşuna basın',
    'dash.recent_queries' => 'Son Sorgular',
    'dash.no_queries' => '(henüz sorgu yok)',
    'dash.header_pg' => 'Performans Panosu{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'Performans Panosu{status} | MySQL {version} | Çalışma süresi: {uptime}',
    'dash.uptime_segment' => ' | Çalışma süresi: {uptime}',
    'pane.process_list' => 'Süreç Listesi',
    'pane.variables' => 'Değişkenler',
    'pane.status' => 'Durum',
    'pane.query_stats' => 'Sorgu İstatistikleri',
    'pane.dashboard' => 'Pano',
    'pane.table_stats' => 'Tablo İstatistikleri',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'Hata Ayıklama',
    'page.no_data' => 'Henüz veri yok.',
    'page.error' => 'Hata: {message}',
    'page.loading' => 'Yükleniyor…',
    'page.fetching' => 'Veriler sunucudan alınıyor.',
    'debug.title' => 'Hata Ayıklama Günlüğü',
    'debug.entries' => ' ({count} kayıt)',
    'debug.errors_only' => '[yalnızca hatalar]',
    'debug.empty' => '(günlük kaydı yok)',
    'debug.more' => '↑ {count} kayıt daha',
    'debug.help' => '[j/k] gezin  [f] yalnız hatalar  [c] temizle  [q] geri',
    'reports.cannot_access' => 'Performans Raporlarına erişilemiyor: {message}',
    'reports.title' => 'Performans Raporları',
    'reports.categories' => 'Kategoriler',
    'reports.no_data' => '(veri yok)',
    'reports.loading' => 'Raporlar yükleniyor…',
    'reports.loading_one' => '{caption} yükleniyor…',
    'reports.no_data_for' => '{caption} için veri yok',
    'reports.help' => '[j/k] satırlar  [h/l] kategori  [[]/[]] rapor  [,/.] sütun  [c] birim  [r] yenile  [x] dışa aktar  [q] geri',
    'reports.unavailable' => 'Performans Raporları Kullanılamıyor',
    'reports.ensure' => 'performance_schema=enabled ve sys şeması kurulu MySQL 5.6.6+ olduğundan emin olun.',
    'conn.kill_cancelled' => 'Kill iptal edildi',
    'conn.none_selected' => 'Bağlantı seçilmedi',
    'conn.refuse_background' => 'Arka plan iş parçacığı sonlandırılmayı reddetti',
    'conn.verb_kill_query' => 'Sorguyu sonlandır',
    'conn.verb_kill' => 'Sonlandır',
    'conn.sent' => '{verb} {id} bağlantısına gönderildi',
    'conn.failed' => '{id} bağlantısı için {verb} başarısız',
    'conn.confirm_verb_query' => 'Şu bağlantıdaki çalışan sorguyu iptal et:',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} bağlantı {id} ({user}@{host})?  [y] onayla   [başka tuş] iptal',
    'conn.help_empty' => '[j/k] gezin  [f] filtre  [r] yenile',
    'conn.help_full' => '[j/k] gezin  [K] kill  [X] sorgu kill  [f] filtre  [r] yenile',
    'conn.vars_fail' => 'Sunucu değişkenleri alınamadı',
    'vars.load_fail' => 'Değişkenler yüklenemedi: {message}',
    'vars.unknown_error' => 'Bilinmeyen hata',
    'vars.not_dynamic' => 'Hata 1238: \'{name}\' dinamik değil — PERSIST_ONLY için [p] tuşuna basın',
    'vars.new_value' => 'yeni değer > ',
    'vars.edit_head' => 'Düzenle ({mode}) {name} (şu an: {current})',
    'vars.scope_explain' => 'GLOBAL = yalnızca çalışma zamanı (yeniden başlatmada kaybolur) · PERSIST = çalışma zamanı + yapılandırma dosyası · PERSIST_ONLY = yalnızca yapılandırma dosyası (sonraki yeniden başlatmada uygulanır)',
    'vars.edit_help' => 'Yeni değeri girin, [p] mod değiştir, [Enter] onayla, [Esc] iptal',
    'reports.back' => '[q] geri',
    'err.dsn_empty' => 'DSN boş olamaz',
    'err.dsn_no_separator' => 'Geçersiz DSN: "://" ayırıcısı eksik',
    'err.driver_unsupported' => 'Desteklenmeyen sürücü: {driver}',
    'err.dsn_sqlite_invalid' => 'Geçersiz SQLite DSN',
    'err.dsn_parse_failed' => 'Geçersiz DSN: parse_url() başarısız',
    'err.dsn_no_host' => 'Geçersiz DSN: ana makine eksik',
    'err.driver_choose' => 'Desteklenmeyen sürücü: {driver} — şunlardan birini seçin: {list}',
    'err.args_missing' => 'Gerekli bağımsız değişkenler eksik: hem --driver hem --db verin (veya --dsn kullanın)',
    'err.opt_needs_value' => '--{name} seçeneği bir değer gerektirir',
    'err.opt_unknown' => 'Bilinmeyen seçenek: --{name}',
    'err.csv_not_found' => 'CSV dosyası bulunamadı: {path}',
    'err.csv_unreadable' => '\'{path}\' CSV dosyası okunamıyor: var olduğunu ve okunabilir olduğunu kontrol edin',
    'err.csv_no_headers' => 'CSV başlıkları okunamıyor: {path}',
    'err.csv_no_columns' => 'CSV dosyasında sütun yok: {path}',
    'err.dest_unwritable' => '\'{path}\' hedefi yazma için açılamıyor: dizinin var olduğunu ve yazılabilir olduğunu kontrol edin',
    'err.export_buffer' => 'Dışa aktarma arabelleği oluşturulamadı',
    'err.csv_partial' => '{table} CSV dışa aktarımı {expected} satırın {written} tanesini yazdı — tablo dışa aktarım sırasında değişti veya sürücü sayfalama penceresini yok saydı; kısmi dosya tam olarak sunulmayacak',
    'err.sql_partial' => '{table} SQL dökümü {expected} satırın {written} tanesini yazdı — tablo döküm sırasında değişti veya sürücü sayfalama penceresini yok saydı; kısmi dosya tam olarak sunulmayacak',
    'err.table_unreadable' => 'Tablo bulunamadı veya okunamıyor: {table} ({message})',
    'err.dml_no_prepare' => 'bu bağlantı hazırlanmış ifadeleri çalıştıramıyor',
    'err.dml_refused' => 'sunucu ifadeyi reddetti',
    'alert.connection_critical' => 'Bağlantı kullanımı kritik düzeyde yüksek',
    'alert.connection_elevated' => 'Bağlantı kullanımı yüksek',
    'alert.aborted_elevated' => 'İptal edilen bağlantı oranı yüksek',
    'alert.thread_elevated' => 'Çalışan iş parçacığı sayısı yüksek',
    'alert.slow_query_time' => 'Yavaş sorgu süresi {seconds}sn olarak ayarlandı',
    'alert.connections_at' => 'Bağlantılar azaminin %{pct} düzeyinde ({connected}/{max})',
    'alert.connection_error_one' => '{count} bağlantı hatası algılandı',
    'alert.connection_error_other' => '{count} bağlantı hatası algılandı',
    'alert.threshold_ratio_connection_warning' => 'Bağlantı uyarı eşiği 0.0 ile 1.0 arasında olmalıdır',
    'alert.threshold_ratio_connection_critical' => 'Bağlantı kritik eşiği 0.0 ile 1.0 arasında olmalıdır',
    'alert.threshold_ratio_thread_running' => 'Çalışan iş parçacığı eşiği 0.0 ile 1.0 arasında olmalıdır',
    'alert.threshold_aborted_rate' => 'İptal oranı eşiği >= 0.0 olmalıdır',
    'alert.threshold_slow_query' => 'Yavaş sorgu eşiği >= 0.0 olmalıdır',
    'alert.threshold_connection_errors' => 'Bağlantı hataları eşiği >= 0 olmalıdır',
    'history.err_mkdir' => 'Geçmiş dizini oluşturulamıyor: {dir}',
    'history.err_chmod' => 'Geçmiş deposu dosyası korunamıyor: {path} (mod {mode})',
    'history.err_unusable' => 'Geçmiş deposu kullanılamıyor: {error} — kurtarmak için geçmiş dosyasını silin veya izinlerini düzeltin.',
    'query.cancelled' => 'Eşzamansız sorgu iptal edildi',
    'tx.unknown' => 'Bilinmeyen komut — :tx bir işlemi başlatır, onaylar veya geri alır',
    'tx.async_refusal' => ':tx yinel oturumu kapsar; eş zamansız taşıyıcının henüz koordineli bir işlem kolu yok — açık BEGIN/COMMIT çalıştırın veya yinel yolu kullanın',
    'tx.already_open' => 'Zaten açık bir işlem var — :tx commit veya :tx rollback',
    'tx.no_tx' => 'Açık işlem yok — :tx bir tane başlatır',
    'tx.opened' => 'İşlem başlatıldı — ifadeler :tx commit veya :tx rollback kadar onunla gider',
    'tx.committed' => 'İşlem onaylandı',
    'tx.rolled_back' => 'İşlem geri alındı',
    'tx.arm_rollback' => 'ROLLBACK, BEGIN sonrası tüm değişiklikleri atar — onaylamak için çalıştırma tuşuna yeniden basın',
    'tx.failed' => 'İşlem komutu başarısız oldu: {message}',
    'history.none' => 'Okunacak bir şey yok — durum geçmişi yalnızca MySQL ailesi oturumlarını kaydeder (--history ile açılır)',
    'history.empty' => 'Son {minutes} dakikada örnek yok — eğilim için en az iki gerekir',
    'history.unreadable' => 'Geçmiş deposu okunamıyor: {message}',
    'history.status' => 'Geçmiş eğilimi — son {minutes} dakikadan {samples} satır',
    'query.timed_out' => 'Eşzamansız sorgu {seconds}sn sonra zaman aşımına uğradı',
    'reports.err_meta_missing' => 'Rapor meta veri dosyası bulunamadı: {path}',
    'reports.err_meta_read' => 'Rapor meta veri dosyası okunamadı: {path}',
    'reports.err_not_in_catalog' => 'Rapor katalogda bulunamadı: {name}',
    'reports.err_view_unavailable' => 'Görünüm bu sunucuda kullanılamıyor: {name}',
    'reports.err_loading' => 'Rapor yüklenirken hata: {error}',
    'variables.err_meta_missing' => 'Değişken meta veri dosyası bulunamadı: {path}',
    'variables.err_meta_read' => 'Değişken meta veri dosyası okunamadı: {path}',
    'resilience.statement_timeout_default' => 'İfade yürütme zaman aşımına uğradı',
    'resilience.statement_timeout_after' => 'İfade yürütme {seconds} sn sonra zaman aşımına uğradı',
    'variables.err_prepare' => 'Bu bağlantıda ifade hazırlanamadı',
    'variables.err_not_editable' => 'Değişken düzenlenemez',
    'variables.err_persist_mariadb' => 'Kalıcı kılmak MariaDB 11.1+ gerektirir — bu sunucu SET PERSIST uygulamıyor.',
    'variables.err_persist_mysql' => 'Kalıcı kılmak MySQL 8.0.16+ gerektirir — bu sunucu SET PERSIST uygulamıyor.',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: boş kullanıcı adı — MySQL\'e tahmin edilen "root" kimliğiyle bağlanma reddedildi; açık bir kullanıcı belirtin',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: boş kullanıcı adı — PostgreSQL\'e tahmin edilen "postgres" kimliğiyle bağlanma reddedildi; açık bir kullanıcı belirtin',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: tanınmayan sslmode "{mode}"; beklenen: {allowed} — yazım hatasını sessizce atmak düz metne izin veren "prefer" düzeyine düşürür',
    'store.err_read' => 'Bağlantı dosyası okunamıyor: {path} (silin veya izinlerini düzeltin)',
    'store.err_json' => 'Bağlantı dosyası geçerli bir JSON değil ({error}): {path}',
    'store.err_shape' => 'Bağlantı dosyası "connections" eşlemesi içeren bir JSON nesnesi olmalıdır: {path}',
    'store.err_connections_type' => '"connections" değeri ad -> ayarlar biçiminde bir JSON nesnesi olmalıdır: {path}',
    'store.err_unknown' => 'Bilinmeyen bağlantı \'{name}\' — kayıtlı bağlantılar: {saved}',
    'store.none' => '(yok)',
    'store.err_pass_env_unset' => '\'{name}\' bağlantısı parolasını ${var} değişkeninden okur — bağlanmadan önce dışa aktarın',
    'store.err_mkdir' => 'Bağlantı dizini oluşturulamıyor: {dir}',
    'store.err_write' => 'Bağlantı dosyası yazılamıyor: {path}',
    'store.err_replace' => 'Bağlantı dosyası değiştirilemiyor: {path}',
    'store.err_entry_type' => '\'{name}\' bağlantısı bir ayarlar JSON nesnesine eşlenmelidir',
    'store.err_plaintext_pass' => '\'{name}\' bağlantısı düz metin \'pass\' saklıyor — connections.json asla gizli bilgi tutmaz: "pass-env": "VAR_NAME" ile değiştirin',
    'store.err_unknown_setting' => '\'{name}\' bağlantısında bilinmeyen ayar \'{field}\' var — izin verilenler: {allowed}',
    'store.err_setting_type' => '\'{name}\' bağlantısının \'{field}\' ayarı bir dize veya sayı olmalıdır',
    'store.err_setting_empty' => '\'{name}\' bağlantısının \'{field}\' ayarı boş olamaz',
    'store.err_needs_driver' => '\'{name}\' bağlantısı bir \'driver\' gerektirir',
    'store.err_driver' => '\'{name}\' bağlantısı desteklenmeyen \'{driver}\' sürücüsünü kullanıyor — şunlardan birini seçin: {allowed}',
    'store.err_port' => '\'{name}\' bağlantısının \'port\' ayarı bir sayı olmalıdır',
    'store.err_pass_env' => '\'{name}\' bağlantısının \'pass-env\' ayarı bir ortam değişkeni adı olmalıdır',
    'store.err_name' => 'Geçersiz bağlantı adı \'{name}\' — harf, rakam, nokta, tire veya alt çizgi kullanın (1-64 karakter)',
    'store.err_secure' => '{path} korunamıyor (mod {mode})',
    'db.err_dsn_semicolon' => 'Veritabanı sunucusu ve adı \';\' içermemelidir — PDO DSN seçenek ayırıcısıdır',
    'db.err_unknown_pg_ssl' => 'Bilinmeyen PostgreSQL ssl-mode: {mode}',
    'db.err_unknown_mysql_ssl' => 'Bilinmeyen MySQL ssl-mode: {mode}',
    'db.err_pg_driver' => 'PostgreSQL bağlayıcısıyla PostgreSQL olmayan bir sürücüye bağlanılamaz',
    'db.err_mysql_driver' => 'MySQL bağlayıcısı yalnızca mysql sürücüsünü kabul eder (\'{driver}\' alındı)',
    'db.err_reconnect' => 'Bağlantı hatasından sonra yeniden bağlanılamadı: {error}',
    'db.err_quote_no_connection' => 'Bağlantı olmadan alıntılanamaz',
    'dml.err_key_required' => 'satır anahtarı gerekli: birden fazla satırı etkileyebilecek ifade reddedildi',
    'dml.err_key_null' => 'anahtar sütunu {column} NULL: satır adreslenemiyor',
    'dml.err_empty_table' => 'tablo adı boş olamaz',
    'dml.err_empty_column' => 'sütun adı boş olamaz',
    'dml.err_empty_key_column' => 'anahtar sütun adı boş olamaz',
    'status.already_saved' => 'zaten kaydedildi ({count})',
    'status.not_in_favorites' => 'favorilerde yok ({count})',
    'status.gtid_mariadb' => 'GTID_MODE kullanılamıyor: MariaDB\'de gtid_mode değişkeni yok.',
    'status.gtid_version' => 'GTID_MODE, MySQL 5.7.6 veya daha yenisini gerektirir (sunucu {version}).',
    'caption.blocks_hit' => 'Blok isabetleri',
    'caption.blocks_read' => 'Okunan bloklar',
    'caption.buffer_pool_read_reqs' => 'Arabellek havuzu okuma istekleri',
    'caption.buffer_pool_usage' => 'Arabellek havuzu kullanımı',
    'caption.buffer_pool_write_reqs' => 'Arabellek havuzu yazma istekleri',
    'caption.bytes_in' => 'Gelen bayt',
    'caption.bytes_out' => 'Giden bayt',
    'caption.cache_hit_rate' => 'Önbellek isabet oranı',
    'caption.commits' => 'Commit\'ler',
    'caption.connections' => 'Bağlantılar',
    'caption.deadlocks' => 'Kilitlenmeler',
    'caption.disk_reads_not_from_pool' => 'Disk okumaları (havuzdan değil)',
    'caption.disk_write_requests' => 'Disk yazma istekleri',
    'caption.doublewrite_writes' => 'Doublewrite yazmaları',
    'caption.innodb_disk_reads' => 'InnoDB disk okumaları',
    'caption.innodb_disk_writes' => 'InnoDB disk yazmaları',
    'caption.insert_buffer' => 'Ekleme arabelleği',
    'caption.pages_created' => 'Oluşturulan sayfalar',
    'caption.pages_flushed' => 'Boşaltılan sayfalar',
    'caption.pages_read' => 'Okunan sayfalar',
    'caption.read_ahead' => 'Ön okuma',
    'caption.redo_log_bytes_written' => 'Yazılan redo log baytları',
    'caption.redo_log_writes' => 'Redo log yazmaları',
    'caption.rollbacks' => 'Geri almalar',
    'caption.row_lock_time' => 'Satır kilidi süresi',
    'caption.row_lock_waits' => 'Satır kilidi beklemeleri',
    'caption.shared_buffers' => 'Paylaşılan arabellekler',
    'caption.sql_statements' => 'SQL ifadeleri',
    'caption.table_open_cache' => 'Açık tablo önbelleği',
    'caption.temp_bytes' => 'Geçici baytlar',
    'caption.temp_files' => 'Geçici dosyalar',
    'caption.transactions' => 'İşlemler',
    'caption.tuples_fetched' => 'Getirilen demetler',
    'caption.tuples_returned' => 'Döndürülen demetler',
    'caption.traffic' => 'Trafik',
    'caption.key_efficiency' => 'Anahtar verimliliği',
    'caption.selects_per_second' => 'Saniyedeki SELECT',
    'caption.innodb_buffer_usage' => 'InnoDB arabellek kullanımı',
    'caption.innodb_reads_per_second' => 'InnoDB saniyedeki okumalar',
    'caption.innodb_writes_per_second' => 'InnoDB saniyedeki yazmalar',
    'widget.tip.bytes_in' => 'Gelen trafik: toplam {Bytes_received} bayt',
    'widget.tip.bytes_out' => 'Giden trafik: toplam {Bytes_sent} bayt',
    'widget.tip.client_connections' => 'İstemci bağlantıları: {Threads_connected}',
    'widget.tip.connections_level' => 'Bağlantı düzeyi ve max_connections',
    'widget.tip.table_open_cache' => 'Açık tablo önbelleği isabet oranı',
    'widget.tip.sql_statements' => 'SQL ifade hızları: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'InnoDB arabellek havuzu kullanım yüzdesi (bayt tabanlı)',
    'widget.tip.buffer_pool_read_reqs' => 'Saniyedeki InnoDB arabellek havuzu okuma istekleri',
    'widget.tip.buffer_pool_write_reqs' => 'Saniyedeki InnoDB arabellek havuzu yazma istekleri',
    'widget.tip.disk_reads_not_from_pool' => 'Saniyede diskten InnoDB arabellek havuzu okumaları',
    'widget.tip.innodb_disk_writes' => 'Saniyede diske yazılan InnoDB veri baytları',
    'widget.tip.disk_write_requests' => 'Saniyedeki InnoDB veri dosyası yazma istekleri',
    'widget.tip.innodb_disk_reads' => 'Saniyede diskten okunan InnoDB veri baytları',
    'widget.tip.row_lock_waits' => 'Saniyedeki InnoDB satır kilidi beklemeleri',
    'widget.tip.row_lock_time' => 'Saniyedeki InnoDB satır kilidi süresi (milisaniye)',
    'widget.tip.pages_flushed' => 'Saniyede boşaltılan InnoDB sayfaları',
    'widget.tip.pages_created' => 'Saniyede oluşturulan InnoDB sayfaları',
    'widget.tip.pages_read' => 'Saniyede okunan InnoDB sayfaları',
    'widget.tip.insert_buffer' => 'InnoDB ekleme arabelleği boyutu (sayfa)',
    'widget.tip.read_ahead' => 'Saniyedeki InnoDB arabellek havuzu ön okuması',
    'widget.tip.redo_log_bytes_written' => 'Saniyede yazılan InnoDB redo log baytları',
    'widget.tip.redo_log_writes' => 'Saniyedeki InnoDB redo log yazmaları',
    'widget.tip.doublewrite_writes' => 'Saniyedeki InnoDB doublewrite yazmaları',
    'widget.tip.alter_rate' => 'ALTER komut hızı (tüm Com_alter_*)',
    'widget.tip.create_rate' => 'CREATE komut hızı (tüm Com_create_*)',
    'widget.tip.drop_rate' => 'DROP komut hızı (tüm Com_drop_*)',
    'widget.tip.select_rate' => 'SELECT hızı',
    'widget.tip.insert_rate' => 'INSERT hızı',
    'widget.tip.update_rate' => 'UPDATE hızı',
    'widget.tip.delete_rate' => 'DELETE hızı',
    'widget.tip.tuples_fetched_total' => 'Veritabanından getirilen satırlar: toplam {tup_fetched}',
    'widget.tip.tuples_fetched_rate' => 'Satır getirme hızı',
    'widget.tip.tuples_returned_total' => 'İstemciye döndürülen satırlar: toplam {tup_returned}',
    'widget.tip.tuples_returned_rate' => 'Satır döndürme hızı',
    'widget.tip.blocks_read_total' => 'Okunan disk blokları: toplam {blks_read}',
    'widget.tip.blocks_hit_total' => 'Arabellek önbelleği isabetleri: toplam {blks_hit}',
    'widget.tip.active_backends' => 'Etkin arka uçlar: {numbackends}',
    'widget.tip.transaction_rates' => 'İşlem hızları: commit / geri alma',
    'widget.tip.commit_rate' => 'İşlem commit hızı',
    'widget.tip.rollback_rate' => 'İşlem geri alma hızı',
    'widget.tip.tuple_insert_rate' => 'Demet ekleme hızı',
    'widget.tip.tuple_update_rate' => 'Demet güncelleme hızı',
    'widget.tip.tuple_delete_rate' => 'Demet silme hızı',
    'widget.tip.deadlock_rate' => 'Kilitlenme hızı',
    'widget.tip.shared_buffers' => 'PostgreSQL shared_buffers yapılandırması (arabellek havuzu boyutu)',
    'widget.tip.cache_hit_rate' => 'Arabellek önbelleği isabet oranı: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => 'Geçici dosya oluşturma hızı',
    'widget.tip.temp_bytes' => 'Saniyede geçici dosyalara yazılan baytlar',
    'dash.data_written' => 'yazılan veri {kb} kB/sn',
    'dash.writes_ops' => 'yazmalar {n} işlem/sn',
    'dash.log_written' => 'yazılan günlük {kb} kB/sn',
    'dash.read_reqs' => 'okuma istekleri {n} sayfa/sn',
    'dash.write_reqs' => 'yazma istekleri {n} sayfa/sn',
    'dash.disk_reads' => 'disk okumaları {n} /sn',
    'metrics.busy_proxy' => 'meşguliyet vekili {pct}%',
    'metrics.cpu_load' => 'CPU yükü {load}',
    'metrics.no_samples' => 'henüz örnek yok',
    'metrics.connections_count' => '{count} bağlantı',
    'metrics.traffic_rate' => '{kb} kb/sn',
    'metrics.server_metrics' => 'Sunucu ölçümleri',
    'explain.query_block' => 'SORGU BLOĞU #{id} (maliyet: {cost})',
    'variables.footer_tree' => '[j/k] ağaç',
    'variables.footer_rows' => '[j/k] satırlar',
    'variables.footer_categories' => '[c] kategoriler',
    'variables.footer_edit' => '[Enter/e] düzenle (dinamik)',
    'variables.footer_rw_filter' => '[w] rw filtresi',
    'variables.footer_search' => '[s] ara',
    'variables.footer_back' => '[q] geri',
    'srv.title' => 'Sunucu durumu',
    'srv.features' => 'Özellikler',
    'srv.stored_programs' => 'Saklı programlar',
    'srv.directories' => 'Dizinler',
    'srv.data_directory' => 'Veri dizini',
    'srv.temp_directory' => 'Geçici dizin',
    'srv.log_directory' => 'Günlük dizini',
    'srv.pid_file' => 'PID dosyası',
    'srv.tls_active' => 'TLS etkin',
    'srv.tls_cipher' => 'TLS şifresi',
    'srv.tls_version' => 'TLS sürümü',
    'srv.ssl_support' => 'SSL desteği',
    'srv.ssl_ca' => 'SSL CA',
    'srv.ssl_cert' => 'SSL sertifikası',
    'srv.ssl_key' => 'SSL anahtarı',
    'srv.tls_secure' => 'TLS / güvenli bağlantı',
    'srv.replication' => 'Çoğaltma',
    'srv.repl_denied' => 'Yetersiz yetki (REPLICATION CLIENT)',
    'srv.repl_error' => 'Çoğaltma durumuna erişim hatası',
    'srv.not_configured' => 'Yapılandırılmamış',
    'srv.channel' => 'Kanal',
    'srv.source_host' => 'Kaynak sunucu',
    'srv.source_port' => 'Kaynak bağlantı noktası',
    'srv.replica_io' => 'Replika IO çalışıyor',
    'srv.replica_sql' => 'Replika SQL çalışıyor',
    'srv.seconds_behind' => 'Kaynağın gerisindeki saniye',
    'srv.relay_log_file' => 'Röle günlüğü dosyası',
    'srv.relay_pos' => 'Röle konumu',
    'srv.repl_channel_title' => 'Çoğaltma › {channel}',
    'srv.default' => 'Varsayılan',
    'srv.firewall' => 'Güvenlik duvarı',
    'srv.mysql_firewall' => 'MySQL güvenlik duvarı',
    'srv.caught_up' => '0 sn (yakalandı)',
    'srv.host' => 'Sunucu',
    'srv.socket' => 'Soket',
    'srv.port' => 'Bağlantı noktası',
    'srv.version' => 'Sürüm',
    'srv.uptime' => 'Çalışma süresi',
    'srv.running_since' => 'Çalışma başlangıcı',
    'srv.unknown' => 'Bilinmiyor',
    'srv.server_information' => 'Sunucu bilgileri',
    'perf.tab_easy_setup' => 'Kolay kurulum',
    'perf.tab_instruments' => 'Enstrümanlar',
    'perf.tab_consumers' => 'Tüketiciler',
    'perf.tab_actors' => 'Aktörler',
    'perf.tab_objects' => 'Nesneler',
    'perf.tab_threads' => 'İş parçacıkları',
    'perf.tab_options' => 'Seçenekler',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[SALT OKUNUR]',
    'perf.nothing_to_commit' => 'Uygulanacak bir şey yok',
    'perf.commit_planning_failed' => 'Uygulama planlaması başarısız: {error}',
    'perf.err_prepare' => 'İfade hazırlanamadı: {sql}',
    'perf.committed_one' => '{count} ifade uygulandı',
    'perf.committed_other' => '{count} ifade uygulandı',
    'perf.applied_one' => '{label} uygulandı — {count} ifade',
    'perf.applied_other' => '{label} uygulandı — {count} ifade',
    'perf.commit_failed' => '{label}, {total} ifadeden {position}. sırada başarısız oldu: {error} — önceki ifadeler uygulandı, sunucu durumu kısmi.',
    'perf.err_access_denied' => 'Erişim reddedildi: gerekli yetkiler eksik',
    'perf.err_not_enabled' => 'Performance Schema etkin değil',
    'perf.err_cannot_connect' => 'Veritabanına bağlanılamıyor',
    'perf.state_fully' => 'Tamamen etkin',
    'perf.state_default' => 'Varsayılan kurulum',
    'perf.state_custom' => 'Özel kurulum',
    'perf.state_disabled' => 'Devre dışı',
    'perf.state_unknown' => 'Bilinmiyor',
    'perf.current_state' => 'Geçerli durum: {state}',
    'perf.readonly_note' => '(Salt okunur mod - değiştirme yetkisi yok)',
    'perf.keys_hint' => '"[" tuşu Performance Schema\'yı tamamen etkinleştir   "]" tuşu Devre dışı bırak   "." tuşu Varsayılanlara sıfırla',
    'perf.default_instruments' => 'Varsayılan enstrümanlar: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'Varsayılan tüketiciler: events_statements_history, events_waits_history vb.',
    'perf.none_instruments' => '(kullanılabilir enstrüman yok)',
    'perf.none_consumers' => '(kullanılabilir tüketici yok)',
    'perf.none_actors' => '(yapılandırılmış aktör yok)',
    'perf.none_objects' => '(yapılandırılmış nesne kuralı yok)',
    'perf.none_threads' => '(kullanılabilir iş parçacığı yok)',
    'perf.total_instruments_one' => 'Toplam: {count} enstrüman',
    'perf.total_instruments_other' => 'Toplam: {count} enstrüman',
    'perf.total_consumers_one' => 'Toplam: {count} tüketici',
    'perf.total_consumers_other' => 'Toplam: {count} tüketici',
    'perf.total_actors_one' => 'Toplam: {count} aktör',
    'perf.total_actors_other' => 'Toplam: {count} aktör',
    'perf.total_objects_one' => 'Toplam: {count} nesne kuralı',
    'perf.total_objects_other' => 'Toplam: {count} nesne kuralı',
    'perf.total_threads_one' => 'Toplam: {count} iş parçacığı',
    'perf.total_threads_other' => 'Toplam: {count} iş parçacığı',
    'perf.col_thread_id' => 'İş parçacığı kimliği',
    'perf.col_name' => 'Ad',
    'perf.col_type' => 'Tür',
    'perf.col_user' => 'Kullanıcı',
    'perf.timer_options' => 'Zamanlayıcı seçenekleri',
    'perf.none_timers' => '(kullanılabilir zamanlayıcı yok - salt okunur)',
    'perf.col_timer_name' => 'Zamanlayıcı adı',
    'perf.col_implementation' => 'Uygulama',
    'perf.col_scale_factor' => 'Ölçek faktörü',
    'perf.timer_readonly_note' => 'Zamanlayıcı yapılandırması salt okunurdur (sunucu derlemesine göre belirlenir)',
    'perf.footer_nav' => '[j/k] gezin  [Space] değiştir',
    'perf.footer_actions' => '  [c] uygulamayı hazırla ([y] çalıştır)  [r] geri al',
    'perf.footer_back' => '  [q] geri',
    'perf.pending_one' => 'Bekleyen: {count} değişiklik',
    'perf.pending_other' => 'Bekleyen: {count} değişiklik',
    'cli.err_not_tty' => 'bu başlatıcı etkileşimli bir terminali yönetir ve ANSI\\\'yi bir boru hattına akıtmayı reddeder (çıkış 2) — bir pty içinde çalıştırın (ör. script -qec \\\'candy-sql app.sqlite\\\' /dev/null) veya kütüphane kodundan başsız olarak çizin (ResultTable::renderPlain()).',
    'cli.history_disabled' => 'geçmiş kaydı DEVRE DIŞI ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' doğrulanmış TLS gerektirir — ReactPHP yönetim aktarımı bunu karşılayamaz; bu yüzden yönetim canlı yenilemesi DEVRE DIŞI ve sorgular/tablo yüklemeleri TLS bağlantısı üzerinden eşzamanlı çalışır.',
    'cli.no_connection_selected' => 'bağlantı seçilmedi — bir yol, DSN veya --connect=<name> verin',
    'cli.usage_line' => 'KULLANIM: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # tam kullanım bilgisi için',
    'cli.tip_saved_connections' => '       İpucu: adlandırılmış bağlantıları {path} içine kaydedin ve --connect=<name> ile başlatın.',
    'help.summary' => 'candy-sql - SQLite, MySQL ve PostgreSQL için terminal SQL istemcisi',
    'help.usage' => 'KULLANIM
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => 'ARGÜMANLAR
  path-or-dsn
    SQLite veritabanı dosya yolu, örn.: app.sqlite
    Veya bir DSN dizgesi, örn.:  mysql://user:pass@localhost:3306/dbname

  Tamamen atlandığında: kayıtlı bağlantıları olan etkileşimli bir
  terminalde bir seçici bunları sunar; aksi halde kısa bir kullanım satırı
  yazdırılır ve süreç 2 durumuyla (hatalı çağrı) çıkar — bu tam ekran için
  --help çalıştırın.',
    'help.options' => 'SEÇENEKLER
  --help, -h
    Bu yardım ekranını gösterir.

  --version, -V
    Sürüm numarasını gösterir.

  --connect=<name>
    Kayıtlı bağlantılar dosyasındaki adı verilen girdiye bağlanır
    ($XDG_CONFIG_HOME/candy-sql/connections.json, varsayılan olarak
    $HOME/.config/candy-sql/connections.json). Açık bağlantı argümanlarıyla
    birleştirilemez. Parolalar asla diske yazılmaz: bir girdi sırrını
    "pass-env" ile adlandırır ve değişken bağlanma anında okunur. Aşağıdaki
    KAYITLI BAĞLANTILAR bölümüne bakın.',
    'help.option_dsn' => '  --dsn=<dsn>
    Tam DSN bağlantı dizgesi.
    Biçim: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    Örnekler:
      mysql://root:secret@localhost:3306/mydb?ssl-mode=require
      pgsql://postgres:pass@db.example.com:5432/production
      sqlite:///var/data/app.sqlite
      sqlite://:memory:',
    'help.option_params' => '  --driver=<driver>
  --db=<dbname>
  --host=<host>
  --port=<port>
  --user=<user>
  --pass=<pass>
  --ssl-mode=<mode>
    Tek tek bağlantı parametreleri (--driver ve --db dışındakilerin hepsi isteğe bağlıdır).
    --driver şunlardan biri olmalıdır: sqlite, mysql, pgsql
    --ssl-mode, MySQL için varsayılan olarak "prefer"dir. "require" ve
    "verify_*", sunucu sertifikası doğrulamalı TLS\'yi zorunlu kılar; CA
    güveni sunucu tarafında veya my.cnf ile yapılandırılmalıdır (README\'ye
    bakın). Bu doğrulanmış kiplerde düz metne izin verebilen ReactPHP
    aktarımı reddedilir — sorgular TLS bağlantısı üzerinden eşzamanlı
    çalışır ve yönetim canlı yenilemesi kapatılır (bir başlangıç bildirimi
    ve uygulamanın durum satırı bunu belirtir).
    Parolanın süreç komut satırında (ps ile görünür) kalmaması için --pass=
    yerine CANDY_SQL_PASS tercih edin.',
    'help.examples' => 'ÖRNEKLER
  # SQLite (varsayılan — yalın yol veya DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # DSN ile MySQL
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # Tek tek seçeneklerle MySQL
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => 'KAYITLI BAĞLANTILAR
  Adlandırılmış sunucuları bir kez listeleyin, sonra hep onlara bağlanın:

    $HOME/.config/candy-sql/connections.json  (dizin 0700, dosya 0600)',
    'help.saved_fields' => '  Alanlar: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env. Başka her alan reddedilir ve düz metin "pass" alanı her zaman
  reddedilir — bunun yerine pass-env\'i bir değişkene yönlendirin. Etkileşimli
  bir terminalde argümansız başlatma bu girdileri seçici olarak sunar;
  --connect=<name> doğrudan birine bağlanır.',
    'help.editing' => 'DÜZENLEME
  Bir TABLE önizlemesinin Rows bölmesi düzenlenebilir bir ızgaradır: h/l
  (veya ←/→) hücre imlecini taşır ve üç tuş dizisi veriyi değiştirir —
  her biri tam deyimi durum satırında gösterir ve Enter onaylayana kadar
  hiçbir şey yazmaz:

    e   imlecin altındaki hücreyi düzenle (Ctrl+N NULL yapar)
    o   satır ekle (Enter sütunlar arasında ilerler; boş girdi sütunu
        DEFAULT değerine bırakır, Ctrl+N NULL saklar)
    D   imlecin altındaki satırı sil

  Satırlara yalnızca tam PRIMARY KEY ile erişilir: anahtarı olmayan bir
  tablo, NULL/ikili bir anahtar, ikili bir hücre ve her sorgu sonucu adlı
  bir durum satırıyla reddedilir. Değerler bağlı parametrelerdir; her
  yazma, tam olarak bir satıra dokunulmadıkça geri alınan bir işlemde
  çalışır (zaten var olan değeri yazan bir UPDATE değişiklik yok olarak
  bildirilir). Esc açık bir düzenleme satırını iptal eder.',
    'help.editing_readonly' => '  --read-only
    Oturum için düzenleme tuşlarını kapatır. Bu bir sürçmeye karşı korur;
    Query bölmesindeki deyimleri süzmez — kesin güvence için salt okunur
    bir veritabanı kullanıcısıyla bağlanın.',
    'help.history' => 'GEÇMİŞ
  Varsayılan olarak her yönetim yoklama anlık görüntüsü, çevrimdışı bir
  denetim izi olarak $HOME/.candy-sql/history.db dosyasına (dizin 0700 /
  dosya 0600) kaydedilir. Uygulamadaki Dashboard ve Reports sayfaları bu
  dosyayı okumaz — canlı sunucuyu yoklar.',
    'help.history_flags' => '  --no-history
    Kaydı tamamen kapatır (diske hiçbir şey yazılmaz).

  --history=<path>
    Anlık görüntüleri varsayılan konum yerine <path> yoluna kaydeder.',
    'help.keyboard' => 'KLAVYE KISAYOLLARI (TUI)
  Tab          Odağı bölmeler arasında döndürür (Tables → Rows → Query → Admin)
  ↑ / ↓        Liste satırlarında gezinir (veya j/k) — Tables bölmesinde hareket
               yalnızca yereldir; asla önizleme getirmez
  /            (Tables bölmesi) tablo adları üzerinde canlı bir süzgeç satırı açar;
               harfler uzatır, ↑/↓ imleci taşır, Enter onaylar ve yükler, Backspace
               düzenler, Esc vazgeçer ve temizler
  Enter        Seçili tablonun satırlarını yükler
  Space        Seçili tablonun satırlarını yükler (Enter\'ın takma adı)
  PgDn / PgUp  (Tables/Rows bölmesi, tablo önizlemesinde) önizlemeyi 100 satırlık
               pencerelerle sayfalar — gezinme sınırı sayfa boyutu oldu
  Ctrl+Enter   Geçerli sorguyu çalıştırır
  Ctrl+R       Geçerli sorguyu çalıştırır
  Ctrl+E       Geçerli sorguyu çalıştırır
  EXPLAIN …    (Query düzenleyici, baştaki anahtar sözcük) yazılan bir EXPLAIN
               ExplainView üzerinden çalışır: Rows bölmesi düz sağlayıcı ızgarası yerine
               renk kodlu plan ağacını gösterir — ve her ANALYZE değiştiricisi
               ÇIKARILIR, denetleyici incelediğini asla çalıştırmaz
  e / o / D    (Rows bölmesi, tablo önizlemesi) hücreyi düzenle / satır ekle /
               satırı sil — bkz. DÜZENLEME; h/l hücre imlecini taşır
  Ctrl+Space   İmleç konumunda tamamlar (Query düzenleyici): SQL anahtar sözcükleri,
               FROM/JOIN/INTO/UPDATE sonrası tablo adları ve bağlamdaki sütunlar
               (WHERE/SELECT/ORDER BY, `alias.` / `table.` nitelikli, INSERT INTO t (...)
               listesi) yüklü şemadan. Tek aday tamamlanır, ortak önek ilerler, aksi
               halde durum satırı adayları listeler
  Ctrl+F       Geçerli sorguyu sık kullanılanlar listesine ekler (yalnızca oturum
               durumu — hiçbir bölme onu göstermez ve kalıcı değildir)
  Ctrl+Shift+F Geçerli sorguyu sık kullanılanlar listesinden çıkarır
  1-8          Bir yönetim bölmesine atlar (yalnızca Admin bölmesi odaktayken)
  q            Tables/Rows bölmelerinden çıkar; Admin\'den Tables\'a döner
  x            (Tables/Rows, canlı sonuç) geçerli satırları bir CSV yoluna dışa
               aktarır — Enter onaylayana kadar üzerine yazma reddi kurulu kalır
  i            (Tables/Rows) bir CSV dosyasını adlandırılmış bir tabloya aktarır
  Ctrl+S       Canlı sonucu/önizlemeyi her bölmeden CSV olarak dışa aktarır
  Esc          Tables/Rows bölmelerinden çıkar; açık bir Admin iletişim kutusunu iptal eder
  Backspace    İmlecin önündeki karakteri siler (Query düzenleyici)',
    'help.see_also' => 'AYRICA BAKIN
  https://github.com/sugarcraft/candy-sql',
];
