<?php

/**
 * Turkish translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "kayıt eklendi: {project} ({language}) +{duration}s",
    'cli.usage' => "Kullanım: candy-codetime [--backend=file|sqlite] <command> [args]\n\nKomutlar:\n  dashboard              Kalp atışı kaydet\n  push                   <project> <language> <file> [duration]  Kilometre taşlarını yönet (yalnızca sqlite)\n  milestone              <add|list> [name] [description]  Kalp atışlarını dışa aktar (varsayılan 7 gün)\n  export                 <csv|json|ics> [days]  300 saniyeyi aşan izlenmeyen boşlukları göster (varsayılan 7 gün)\n  gaps                   [days]  30 günden eski veri dosyalarını arşivle (yalnızca kopyalar; hiçbir zaman silmez)\n  backup                 Bu kullanımı göster\n  help, --help           Sürümü yazdır\n  --version, -v          varsayılan: file\n\nSeçenekler:\n  --backend=file|sqlite  push/export/gaps/dashboard için depolama arka ucu (varsayılan: file)\n  CANDY_CODETIME_DIR     Veri dizini (varsayılan: \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Kullanım: candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push: zorunlu argümanlar eksik (project, language, file)",
    'cli.push_ignored' => "push: {file} .sugartrackignore ile eşleşti — atlandı",
    'cli.push_invalid_duration' => "push: süre, saniye cinsinden negatif olmayan bir tam sayı olmalı, alınan: \"{value}\"",
    'cli.backend_invalid' => "geçersiz arka uç (file veya sqlite kullanın)",
    'cli.backend_unavailable' => "sqlite arka ucu kullanılamıyor: PHP sqlite3 uzantısı yüklü değil",
    'cli.milestone_usage' => "Kullanım: candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add: zorunlu argüman eksik (name)",
    'cli.milestone_added' => "kilometre taşı eklendi: {name}",
    'cli.milestone_list_header' => "Kilometre taşları:",
    'cli.milestone_list_empty' => "Kayıtlı kilometre taşı yok.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Kullanım: candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export: geçersiz biçim (csv, json veya ics kullanın)",
    'cli.gaps_no_data' => "Boşluk bulunamadı.",
    'cli.gaps_header' => "İzlenmeyen zaman boşlukları:",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup: {count} dosya arşivlendi",
    'cli.backup_none' => "backup: arşivlenecek yeni bir şey yok",
    'cli.unknown_command' => "candy-codetime: bilinmeyen komut: {command}",
    'cli.legacy_env_deprecated' => "candy-codetime: {variable} kullanımdan kaldırıldı; yerine CANDY_CODETIME_DIR ayarlayın.",
    'cli.migration_notice' => "candy-codetime: {notice}",
];
