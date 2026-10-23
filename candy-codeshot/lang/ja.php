<?php

/**
 * Japanese translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot：認識できないフラグ '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot：不明なテーマ '{name}'。既知：dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot：不明なウィンドウスタイル '{style}'。既知：macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot：不明な形式 '{format}'。既知：svg, png",
    'cli.gd_required'         => 'candy-codeshot：PNG出力にはext-gdが必要です',
    'cli.font_not_found'      => "candy-codeshot：フォントファイルが見つかりません：'{path}'",
    'cli.font_invalid'        => "candy-codeshot：'{path}'は有効なフォントファイルではありません（TTF、OTF、WOFFまたはWOFF2が必要）",
    'cli.bad_highlight'       => "candy-codeshot：不正なハイライト形式 '{format}'。開始:終了（例 3:7）または開始:終了:#色（例 3:7:#fffbe6）を使用してください",
    'cli.read_failed'          => 'candy-codeshot：入力の読み取りに失敗',
    'cli.path_outside_cwd'    => "candy-codeshot：パス '{path}' は現在の作業ディレクトリの外にあります（許可するには --unsafe-paths を使用）",
    'cli.write_failed'        => "candy-codeshot：書き込みに失敗 '{path}'",
    'cli.missing_value'       => "candy-codeshot：{option} には値が必要です",
    'cli.bad_number'          => "candy-codeshot：{option} は非負の整数を期待していますが '{value}' を受け取りました",
    'cli.png_ignores'         => "candy-codeshot：PNG出力はSVG専用オプションを無視します：{options}（--format svg を使用してください）",
    'cli.highlight_rejected'  => "candy-codeshot：ハイライト '{spec}' を拒否しました：{reason}",
    'cli.format_mismatch'     => "candy-codeshot：出力形式 '{format}' が '{path}' の拡張子と一致しません",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - コードやターミナル出力をSVGまたはPNGのスクリーンショットにレンダリング

使用法:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

フラグの値は個別の引数です: "--theme dracula"（"--theme=dracula" ではない）
（"=" 形式は認識できないフラグとして拒否されます）。

オプション:
  --theme <name>          テーマ名: dark, light, dracula, tokyo-night, nord（既定: dark）
  --padding <n>           内容の余白（既定: 24）
  --no-window             ウィンドウ装飾を非表示（トラフィックライト、タイトルバー）
  --no-shadow             ドロップシャドウを無効化
  --no-border             枠線を非表示
  --line-numbers          行番号を表示
  --border-radius <n>     角の半径（既定: 8）
  --window-style <style>  ウィンドウスタイル: macos, windows-terminal, iterm, hyper, none（既定: macos）
  --ligatures             リガチャを有効化（font-variant-ligatures: normal; SVGのみ）
  --font <path>           SVGに埋め込むTTF/OTF/WOFF/WOFF2フォントファイルのパス（SVGのみ）
  --unsafe-paths          入力/--font/--outputパスのCWD外へのアクセスを許可
  --highlight <start:end[:color]>
                          指定行 start:end を任意の色でハイライト（既定色: #fffbe6; SVGのみ）
  --format <svg|png>      出力形式（既定: svg; pngにはext-gdが必要）
  -t, --type <lang>       構文ハイライトの言語を強制（既定: ファイル名、シェバン、内容）
  -o, --output <path>     stdoutの代わりにファイルへ出力
  -h, --help              このヘルプを表示

注意:
  入力・出力パスは --unsafe-paths を指定しない限り現在の作業ディレクトリ内に
  限られます。PNG出力は上記のSVG専用オプションを無視し、破棄した各オプションを
  stderrに通知します。

終了コード:
  0  成功
  1  composer autoloadが見つからない（インストールの問題）
  2  使い方または入力のエラー（不正なフラグ、読めないパス、拒否されたハイライトなど）

例:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
