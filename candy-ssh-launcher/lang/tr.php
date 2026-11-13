<?php

/**
 * Turkish translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => '{bin} çalıştırılamadı',
    'launcher.transport_not_executable' => 'taşıma ikilisi bulunamadı veya çalıştırılabilir değil: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: uç nokta {endpoint}: özel taşıma {legs} öğelerini yok sayar',
    'config.not_found'         => 'wishlist yapılandırması bulunamadı: {path}',
    'config.json_top_level'    => 'wishlist json: üst düzey değer bir dizi olmalıdır',
    'config.json_entry_object' => 'wishlist json: her giriş bir nesne olmalıdır',
    'config.yaml_continuation' => "wishlist yaml: herhangi bir '- name:' bloğundan önce bir devam satırı var",
    'config.yaml_unparseable'  => 'wishlist yaml: ayrıştırılamayan satır {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: {line}. satırdaki `{field}:` altında blok eşlemesi: {found} — bu alan bir `- value` öğeleri listesi olmalıdır',
    'config.yaml_unterminated_quote' => 'wishlist yaml: değerde kapatılmamış tırnak işareti: {found}',
    'config.entry_missing_field' => 'wishlist girdisi #{index}: gerekli alan eksik: {field}',
    'config.unknown_key' => 'bilinmeyen alan `{key}` yoksayıldı — belgelenmiş anahtar değil',
    'config.unknown_key_nearmiss' => 'bilinmeyen alan `{key}` yoksayıldı — `{field}` mi demek istediniz?',
    'config.duplicate_name' => 'yinelenen endpoint adı `{name}` — tüm satırlar yüklenir; adlar tanımlayıcıdır',
    'config.field_not_scalar'  => 'wishlist girdisi: [{field}] alanı skaler olmalı, alınan tür: {type}',
    'config.too_large'          => 'wishlist yapılandırması {limit} bayt sınırını aşıyor: {path}',
    'config.list_too_long'      => 'wishlist girişi: [{field}] alanı, yükleyicinin izin verdiği {limit} öğeden fazlasını içeriyor',
    'config.field_not_list'       => 'wishlist girişi: [{field}] alanı bir anahtar-değer eşlemesi değil, bir değer listesi olmalı',
    'config.encoding_utf16'       => '{path} UTF-16 kodlamalı — yapılandırmayı UTF-8 olarak yeniden kaydedin',
    'config.unreadable'       => 'wishlist yapılandırması okunamadı: {path}',
    'config.home_unset'       => '~ $HOME olmadan genişletilemiyor: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: satır içi akış koleksiyonu desteklenmiyor: {value} (iç içe liste kullanın)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist uç noktası: [{field}] alanı başında tire olan güvensiz bir değer içeriyor [{value}]',
    'endpoint.host_empty' => '`{name}` uç noktasının ana makinesi boş — seçilebilir bir satır bir ana makine adına ihtiyaç duyar',
    'endpoint.name_empty' => 'endpoint adı boş — seçilebilir bir satır bir ad gerektirir',
    'endpoint.option_empty' => '`{name}` uç noktasında boş bir seçenek girdisi var — boş bir -o değeri reddedilir',
    'endpoint.port_invalid'     => 'wishlist uç noktası [{host}]: geçersiz bağlantı noktası [{port}] — 1 ile 65535 arasında bir tam sayı olmalı',

    'cli.usage'         => 'Kullanım: candy-ssh-launcher [--config <yol>] [--ssh <ssh-ikili>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: bilinmeyen argüman: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: yapılandırma bulunamadı. ~/.config/wishlist.yml dosyasını oluşturun (README içindeki hızlı başlangıca bakın) veya --config <yol> iletin.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: iptal edildi.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh ikilisi bulunamadı veya çalıştırılabilir değil: {bin} — ssh başka bir konumdaysa --ssh <yol> iletin',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} bir değer gerektirir',
    'cli.no_endpoints'   => 'candy-ssh-launcher: {path} içinde uç nokta yok',
    'cli.not_a_terminal' => 'seçici etkileşimli bir terminal gerektirir — stdin ve stdout birer TTY olmalıdır. Makinece okunabilir çıktı için --list kullanın.',
    'cli.config_unowned'   => 'candy-ssh-launcher: başka bir kullanıcının sahip olduğu yapılandırma reddedildi: {path} — yapılandırmalar güvenilen girdidir; README içindeki Güvenlik bölümüne bakın.',
    'cli.config_writable'  => 'candy-ssh-launcher: grup ya da herkes tarafından yazılabilen yapılandırma reddedildi: {path} — 0644 veya 0600 olarak sıkılaştırın ya da --config ile açıkça iletin.',
    'cli.project_local'    => 'candy-ssh-launcher: proje yerel wishlist kullanımda: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: uç nokta -o {option} istiyor — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => 'ana makine anahtarı denetimi düşürüldü — sunucu anahtarı doğrulanmadan kalabilir',
    'cli.unsafe_option_effect_user_known_hosts_file' => 'kullanıcının bilinen ana makine güven veritabanı yönlendirildi',
    'cli.unsafe_option_effect_global_known_hosts_file' => 'sistem geneli güven veritabanı yönlendirildi',
    'cli.unsafe_option_effect_log_level' => 'ssh uyarıları ve bildirimleri gizleniyor',
    'cli.unsafe_option_effect_identity_agent' => 'kimlik doğrulama başka bir ajan soketine devredildi',
    'cli.unsafe_option_effect_certificate_file' => 'ek bir kimlik doğrulama sertifikası sunuluyor',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtre: ',
    'picker.no_matches'  => '  (eşleşme yok — Backspace veya Esc)',
    'picker.help'        => '↑/↓ seç · Enter bağlan · Esc çık · filtrelemek için yaz',
    'picker.help_keys'   => '⌫ sil · Ctrl+U/W temizle · PgUp/PgDn sayfa · Home/End atla',
    'picker.count'       => '  ({total} içinden {shown})',
];
