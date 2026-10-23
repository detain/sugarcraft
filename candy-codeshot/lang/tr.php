<?php

/**
 * Turkish translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: bilinmeyen bayrak '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: bilinmeyen tema '{name}'. Bilinenler: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: bilinmeyen pencere tarzı '{style}'. Bilinenler: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: bilinmeyen biçem '{format}'. Bilinenler: svg, png",
    'cli.gd_required'         => 'candy-codeshot: PNG çıktısı için ext-gd gerekli',
    'cli.font_not_found'      => "candy-codeshot: yazı tipi dosyası bulunamadı: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' geçerli bir yazı tipi dosyası değil (TTF, OTF, WOFF veya WOFF2 bekleniyordu)",
    'cli.bad_highlight'       => "candy-codeshot: geçersiz vurgu biçemi '{format}'. baş:bitiş (örn. 3:7) veya baş:bitiş:#renk (örn. 3:7:#fffbe6) kullanın",
    'cli.read_failed'          => 'candy-codeshot: girdi okunamadı',
    'cli.path_outside_cwd'    => "candy-codeshot: '{path}' geçerli çalışma dizininin dışında (izin vermek için --unsafe-paths kullanın)",
    'cli.write_failed'        => "candy-codeshot: yazılamadı '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} bir değer bekliyor",
    'cli.bad_number'          => "candy-codeshot: {option} negatif olmayan bir tam sayı bekliyor, alınan '{value}'",
    'cli.png_ignores'         => "candy-codeshot: PNG çıktısı SVG'ye-özel seçenekleri yoksayar: {options} (--format svg kullanın)",
    'cli.highlight_rejected'  => "candy-codeshot: '{spec}' vurgusu reddedildi: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: çıkış biçemi '{format}', '{path}' uzantısıyla eşleşmiyor",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - kodu veya terminal çıktısını SVG ya da PNG ekran görüntüsü olarak işler

Kullanım:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Bayrak değerleri ayrı argümanlardır: "--theme dracula", "--theme=dracula" değil
("=" biçimi bilinmeyen bayrak olarak reddedilir).

Seçenekler:
  --theme <ad>            Tema adı: dark, light, dracula, tokyo-night, nord (varsayılan: dark)
  --padding <n>           İçerik çevresindeki boşluk (varsayılan: 24)
  --no-window             Pencere süslemelerini gizle (trafik lambaları, başlık çubuğu)
  --no-shadow             Gölgeyi kapat
  --no-border             Çerçeveyi gizle
  --line-numbers          Satır numaralarını göster
  --border-radius <n>     Köşe yarıçapı (varsayılan: 8)
  --window-style <tarz>   Pencere tarzı: macos, windows-terminal, iterm, hyper, none (varsayılan: macos)
  --ligatures             Ligatürleri aç (font-variant-ligatures: normal; yalnız SVG)
  --font <yol>            SVG'ye yerleştirilecek TTF/OTF/WOFF/WOFF2 yazı tipi dosyası yolu (yalnız SVG)
  --unsafe-paths          girdi/--font/--output yollarının CWD dışına çıkmasına izin ver
  --highlight <baş:bitiş[:renk]>
                          baş:bitiş satırlarını isteğe bağlı renkle vurgula (varsayılan renk: #fffbe6; yalnız SVG)
  --format <svg|png>      Çıkış biçemi (varsayılan: svg; png, ext-gd gerektirir)
  -t, --type <dil>        Sözdizimi vurgulama dilini zorla (varsayılan: dosya adı, shebang, içerik)
  -o, --output <yol>      Çıktıyı stdout yerine dosyaya yaz
  -h, --help              Bu yardım mesajını göster

Notlar:
  Girdi ve çıktı yolları --unsafe-paths verilmedikçe geçerli çalışma dizininin
  içinde kalmalıdır. PNG çıktısı yukarıdaki yalnız-SVG seçenekleri yoksayar ve
  bıraktığı her birini stderr üzerinde duyurur.

Çıkış kodları:
  0  başarılı
  1  composer autoload eksik (kurulum sorunu)
  2  kullanım veya girdi hatası (geçersiz bayrak, okunamayan yol, reddedilen vurgu, ...)

Örnekler:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
