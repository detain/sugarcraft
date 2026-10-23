<?php

/**
 * Russian translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: неизвестный флаг '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: неизвестная тема '{name}'. Известные: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: неизвестный стиль окна '{style}'. Известные: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: неизвестный формат '{format}'. Известные: svg, png",
    'cli.gd_required'         => 'candy-codeshot: ext-gd требуется для вывода PNG',
    'cli.font_not_found'      => "candy-codeshot: файл шрифта не найден: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' не является допустимым файлом шрифта (ожидались TTF, OTF, WOFF или WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: неверный формат подсветки '{format}'. Используйте начало:конец (например, 3:7) или начало:конец:#цвет (например, 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: не удалось прочитать ввод',
    'cli.path_outside_cwd'    => "candy-codeshot: путь '{path}' находится вне текущего рабочего каталога (используйте --unsafe-paths, чтобы разрешить)",
    'cli.write_failed'        => "candy-codeshot: не удалось записать '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} требует значение",
    'cli.bad_number'          => "candy-codeshot: {option} ожидает неотрицательное целое число, получено '{value}'",
    'cli.png_ignores'         => "candy-codeshot: вывод PNG игнорирует только-SVG параметры: {options} (используйте --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: подсветка '{spec}' отклонена: {reason}",
    'cli.format_mismatch'     => "candy-codeshot: формат вывода '{format}' не соответствует расширению '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - рендерит код или вывод терминала в SVG или PNG скриншот

Использование:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Значения флагов — отдельные аргументы: "--theme dracula", а не "--theme=dracula"
(форма "=" отвергается как неизвестный флаг).

Параметры:
  --theme <имя>           Название темы: dark, light, dracula, tokyo-night, nord (по умолчанию: dark)
  --padding <n>           Отступ вокруг содержимого (по умолчанию: 24)
  --no-window             Скрыть оформление окна (светофор, заголовок)
  --no-shadow             Отключить тень
  --no-border             Скрыть рамку
  --line-numbers          Показать номера строк
  --border-radius <n>     Радиус скругления углов (по умолчанию: 8)
  --window-style <стиль>  Стиль окна: macos, windows-terminal, iterm, hyper, none (по умолчанию: macos)
  --ligatures             Включить лигатуры (font-variant-ligatures: normal; только SVG)
  --font <путь>           Путь к файлу шрифта TTF/OTF/WOFF/WOFF2 для встраивания в SVG (только SVG)
  --unsafe-paths          Разрешить пути ввода/--font/--output вне текущего каталога
  --highlight <начало:конец[:цвет]>
                          Подсветить строки начало:конец с необязательным цветом (цвет по умолчанию: #fffbe6; только SVG)
  --format <svg|png>      Формат вывода (по умолчанию: svg; png требует ext-gd)
  -t, --type <язык>       Принудительный язык подсветки синтаксиса (по умолчанию: имя файла, shebang, содержимое)
  -o, --output <путь>     Записать вывод в файл вместо stdout
  -h, --help              Показать это сообщение помощи

Примечания:
  Пути ввода и вывода должны оставаться в текущем рабочем каталоге, если не указан
  --unsafe-paths. Вывод PNG игнорирует перечисленные только-SVG параметры и сообщает
  о каждом отброшенном в stderr.

Коды выхода:
  0  успех
  1  отсутствует composer autoload (проблема установки)
  2  ошибка использования или ввода (неверный флаг, нечитаемый путь, отклонённая подсветка, ...)

Примеры:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
