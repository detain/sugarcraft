<?php

/**
 * Traditional Chinese (zh-TW) translations for candy-scriptui.
 *
 * LOCALES.md names zh-cn vs zh_TW as a divergent pair; this catalog is
 * written for Taiwan usage (檔案/範本/載入/程序), not merely re-spaced
 * Simplified glyphs.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => '顏色為空。',
    'style.unrecognised_color' => '無法辨識的顏色：{value} — 允許：{allowed}。',
    'style.hex_digit_count' => "十六進位顏色必須有 3 或 6 個十六進位數字（例如 '#f80' 或 '#ff8800'）；取得：'{value}'。",
    'style.padding_token_int' => "Padding/margin 標記必須是整數；實際：'{token}'。",
    'style.padding_count'     => 'Padding/margin 需要 1、2 或 4 個整數；實際：{count}。',
    'style.bad_entry'         => "--style 條目必須是 'key=value' 或 'element.prop=value'；實際：'{raw}'。",
    'style.unknown_prop'      => "未知樣式屬性：'{prop}'。",
    'process.spawn_failed'    => '啟動子程序失敗',
    'border.unknown'          => '未知邊框樣式：{name}。',
    'log.unknown_level'       => '未知日誌級別：{name}。',
    'log.encode_failed' => '將日誌條目編碼為 json 失敗。',
    'log.bad_format' => '無效的 --format 字串：{format}。',
    'spinner.unknown_style'   => '未知微調器樣式：{name}。',
    'spinner.windows_unsupported' => 'Spin 無法在原生 Windows 上執行命令：該平台的程序後端不受支援——請改用 WSL。',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => '未知主題：{name}。',
    'io.read_failed' => '無法讀取檔案：{path}。',
    'io.write_failed' => '無法寫入檔案：{path}。',
    'runtime.no_terminal' => '{command} 需要互動式終端機；沒有可用的控制終端機。',
    'runtime.bad_timeout' => '--timeout 值「{value}」無效——需要非負秒數或形如 5s/250ms/1m30s 的時長（0 表示不限）。',
    'runtime.bad_number' => "{flag} 值 '{value}' 無效 — 需要非負整數。",
    'runtime.pcntl_missing' => '未載入 ext-pcntl：SIGINT/SIGTERM 將在沒有 130/143 退出碼對應的情況下結束本程序。',
    'runtime.unknown_choice' => '{option} 的值「{value}」無效。允許：{allowed}。',
    'hints.choose' => '↑/↓ 移動 · / 篩選 · Enter 選擇 · Esc 取消',
    'hints.choose.multi' => '↑/↓ 移動 · 空格 切換 · / 篩選 · Enter 選擇 · Esc 取消',
    'hints.filter' => '輸入以篩選 · ↑/↓ 移動 · tab 切換（多選） · enter 選擇 · esc 取消',
    'hints.pager' => '↑/↓ j/k 移動 · ←/→ h/l 橫向 · PgUp/b PgDn/空格 翻頁 · Ctrl+U/D 半頁',
    'hints.pager.more' => 'g/G 或 Home/End 首/尾 · q/Esc 退出',
    'format.template_unresolved' => '範本：{count} 個佔位符已展開為空：{names} — 必須設定變數並透過 --allow-env 允許。',
    'runtime.empty_input' => '輸入沒有產生任何行；不執行操作。',
    'runtime.empty_input_filter' => '沒有可過濾的內容；標準輸入未產生任何行。',
    'runtime.empty_input_pager' => '沒有可分頁的內容；標準輸入未產生任何行。',
    'runtime.conflicting_flags' => '不能將 {first} 與 {second} 以不同的值同時使用。',
    'runtime.empty_selection' => '未選擇任何條目——請在按 Enter 前用空格鍵勾選至少一個條目。',
];
