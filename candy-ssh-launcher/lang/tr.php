<?php

/**
 * Turkish translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => '{bin} çalıştırılamadı',
    'config.not_found'         => 'wishlist yapılandırması bulunamadı: {path}',
    'config.json_top_level'    => 'wishlist json: üst düzey değer bir dizi olmalıdır',
    'config.json_entry_object' => 'wishlist json: her giriş bir nesne olmalıdır',
    'config.yaml_continuation' => "wishlist yaml: herhangi bir '- name:' bloğundan önce Devamlılık ayarlanamaz",
    'config.yaml_unparseable'  => 'wishlist yaml: ayrıştırılamayan satır: {line}',
    'config.entry_missing_field' => 'eksik zorunlu alana sahip wishlist girişi: name veya host',
    'config.field_not_scalar'  => 'wishlist girdisi: [{field}] alanı skaler olmalı, alınan tür: {type}',
    'config.unreadable'       => 'wishlist yapılandırması okunamadı: {path}',
    'config.home_unset'       => '~ $HOME olmadan genişletilemiyor: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: satır içi akış koleksiyonu desteklenmiyor: {value} (iç içe liste kullanın)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist uç noktası: [{field}] alanı başında tire olan güvensiz bir değer içeriyor [{value}]',
    'endpoint.port_invalid'     => 'wishlist uç noktası [{host}]: geçersiz bağlantı noktası [{port}] — 1 ile 65535 arasında bir tam sayı olmalı',

    'cli.usage'         => 'Kullanım: candy-ssh-launcher [--config <yol>] [--ssh <ssh-ikili>] [--list] [-h|--help]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: bilinmeyen argüman: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: yapılandırma bulunamadı. --config <yol> iletin.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: iptal edildi.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh ikilisi bulunamadı veya çalıştırılabilir değil: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} bir değer gerektirir',
    'cli.no_endpoints'   => 'candy-ssh-launcher: {path} içinde endpoint yok',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtre: ',
    'picker.no_matches'  => '  (eşleşme yok)',
    'picker.help'        => '  ↑/↓ seç · Enter bağlan · Esc çık · filtrelemek için yaz',
    'picker.count'       => '  ({total} içinden {shown})',
];
