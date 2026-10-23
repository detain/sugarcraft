<?php

/**
 * Simplified Chinese translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot：无法识别的标志 '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot：未知的主题 '{name}'。已知：dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot：未知的窗口样式 '{style}'。已知：macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot：未知的格式 '{format}'。已知：svg, png",
    'cli.gd_required'         => 'candy-codeshot：PNG 输出需要 ext-gd',
    'cli.font_not_found'      => "candy-codeshot：找不到字体文件：'{path}'",
    'cli.font_invalid'        => "candy-codeshot：'{path}' 不是有效的字体文件（期望 TTF、OTF、WOFF 或 WOFF2）",
    'cli.bad_highlight'       => "candy-codeshot：无效的高亮格式 '{format}'。请使用 开始:结束（例如 3:7）或 开始:结束:#颜色（例如 3:7:#fffbe6）",
    'cli.read_failed'          => 'candy-codeshot：读取输入失败',
    'cli.path_outside_cwd'    => "candy-codeshot：路径 '{path}' 位于当前工作目录之外（使用 --unsafe-paths 允许）",
    'cli.write_failed'        => "candy-codeshot：写入失败 '{path}'",
    'cli.missing_value'       => "candy-codeshot：{option} 需要一个值",
    'cli.bad_number'          => "candy-codeshot：{option} 期望非负整数，收到 '{value}'",
    'cli.png_ignores'         => "candy-codeshot：PNG 输出会忽略仅限 SVG 的选项：{options}（请使用 --format svg）",
    'cli.highlight_rejected'  => "candy-codeshot：高亮 '{spec}' 被拒绝：{reason}",
    'cli.format_mismatch'     => "candy-codeshot：输出格式 '{format}' 与 '{path}' 的扩展名不匹配",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - 将代码或终端输出渲染为 SVG 或 PNG 截图

用法:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

标志的值是独立参数："--theme dracula"，而不是 "--theme=dracula"
（"=" 形式会被当作无法识别的标志拒绝）。

选项:
  --theme <name>          主题名称：dark, light, dracula, tokyo-night, nord（默认：dark）
  --padding <n>           内容周围的留白（默认：24）
  --no-window             隐藏窗口装饰（红绿灯、标题栏）
  --no-shadow             禁用阴影
  --no-border             隐藏边框
  --line-numbers          显示行号
  --border-radius <n>     圆角半径（默认：8）
  --window-style <style>  窗口样式：macos, windows-terminal, iterm, hyper, none（默认：macos）
  --ligatures             启用连字（font-variant-ligatures: normal；仅限 SVG）
  --font <path>           要嵌入 SVG 的 TTF/OTF/WOFF/WOFF2 字体文件路径（仅限 SVG）
  --unsafe-paths          允许输入/--font/--output 路径位于当前目录之外
  --highlight <start:end[:color]>
                          以可选颜色高亮行 start:end（默认颜色：#fffbe6；仅限 SVG）
  --format <svg|png>      输出格式（默认：svg；png 需要 ext-gd）
  -t, --type <lang>       强制指定语法高亮语言（默认：文件名、shebang、内容）
  -o, --output <path>     将输出写入文件而非 stdout
  -h, --help              显示此帮助信息

注意:
  除非提供 --unsafe-paths，输入和输出路径必须保持在当前工作目录内。
  PNG 输出会忽略上述仅限 SVG 的选项，并在 stderr 上逐一宣布被丢弃的选项。

退出码:
  0  成功
  1  缺少 composer autoload（安装问题）
  2  用法或输入错误（无效标志、不可读路径、被拒绝的高亮等）

示例:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
