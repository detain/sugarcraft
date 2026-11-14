<?php

/**
 * Japanese translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite ファイルが見つかりません：{path}',
    'complete.in_literal' => '文字列やコメントの中では補完できません',
    'complete.nothing' => '補完するものがありません — キーワードの先頭を入力してください',
    'complete.none_keyword' => '"{word}" を補完するキーワードはありません',
    'complete.none_table' => '"{word}" を補完するテーブルはありません',
    'complete.none_column' => '"{word}" を補完する列はありません',
    'complete.none_qualified' => '{qualifier} に "{word}" を補完する列はありません',
    'complete.list' => '"{word}" の補完候補 {count} 件: {list}{more}',
    'complete.done' => '補完しました {word} → {insert}',
    'complete.inserted' => '挿入しました {insert}',
    'edit.refuse_readonly' => '読み取り専用セッションです — 編集は無効です',
    'edit.refuse_no_table' => '先にテーブルのプレビューを開いてください — クエリ結果はその場で編集できません',
    'edit.refuse_no_rows' => 'カーソル位置に行がありません',
    'edit.refuse_no_pk' => 'このテーブルには主キーがありません — 行を安全に特定できないため、クエリペインで文を書いてください',
    'edit.refuse_pk_hidden' => 'この行の主キーは NULL かバイナリのプレースホルダーです — 特定できません',
    'edit.refuse_binary' => 'バイナリや大きなセルはここでは編集できません — クエリペインで文を使ってください',
    'edit.refuse_schema' => '{table} のスキーマを読み取れません — 編集を拒否しました',
    'edit.prompt_cell' => '{table}.{column} を編集 = {value}  (Enter でプレビュー · Ctrl+N で NULL · Esc で中止)',
    'edit.prompt_insert' => '{table} に新しい行 — {column} ({type}) [{n}/{total}] = {value}  (Enter で次へ · 空 = DEFAULT · Ctrl+N で NULL · Esc で中止)',
    'edit.prompt_confirm' => '{sql}  (Enter で確定 · Esc で中止)',
    'edit.cancelled' => '編集を中止しました',
    'edit.unchanged' => '値は変わっていません — 何も書き込みませんでした',
    'edit.done_update' => '{table} の 1 行を更新しました',
    'edit.done_insert' => '{table} に 1 行を挿入しました',
    'edit.done_delete' => '{table} から 1 行を削除しました',
    'edit.none_changed' => '変更された行はありません — 値が同じか行が存在しません。プレビューを更新しました',
    'edit.failed' => '書き込みに失敗しました — ロールバック済み、変更はありません: {reason}',
    'edit.too_many' => '拒否しました: この文は {count} 行に影響します — ロールバック済み、変更はありません',
    'ui.title.tables' => ' テーブル ',
    'ui.title.rows' => ' 行 ',
    'ui.title.query' => ' クエリ ',
    'ui.title.admin' => ' 管理 ',
    'ui.title.explain' => ' 実行計画 ',
    'ui.help.query' => 'tab  ペイン切替  ·  ctrl+r / ctrl+e  実行  ·  enter  改行  ·  ↑/↓  履歴  ·  ctrl+c  終了',
    'ui.help.rows' => 'tab  ペイン切替  ·  h/l  セル  ·  e  編集  ·  o  新規行  ·  D  削除  ·  x  エクスポート  ·  q / esc  終了',
    'ui.help.browse' => 'tab  ペイン切替  ·  enter  テーブル読込  ·  x  エクスポート  ·  i  インポート  ·  q / esc  終了',
    'ui.help.admin' => '1-{count}  ページ選択  ·  j/k  移動  ·  p  一時停止  ·  r  リセット  ·  tab  ペイン切替  ·  q  戻る  ·  esc  ダイアログを閉じる',
    'ui.no_tables' => '(テーブルなし)',
    'ui.no_match' => '(一致なし)',
    'ui.loading' => '読み込み中…',
    'ui.empty' => '(空)',
    'ui.admin_unavailable' => '管理ページを利用できません: {reason}',
    'ui.error' => 'エラー: {message}',
    'io.prompt_export' => 'CSV へ → {path}  (Enter で書き込み · Esc で中止)',
    'io.prompt_replace' => '{path} を置換しますか?  (Enter で確定 · Esc で中止)',
    'io.prompt_import_path' => 'CSV をインポート ← {path}  (Enter · Esc で中止)',
    'io.prompt_import_table' => '{source} をインポート → テーブル {table}  (Enter · Esc で中止)',
    'bar.tables' => 'テーブル: {count}',
    'bar.cycle' => 'Tab:切替',
    'bar.navigate' => '↑↓:移動',
    'bar.load' => 'Enter:読込',
    'bar.run' => 'Ctrl+R:実行',
    'bar.select' => '1-{count}:選択',
    'bar.nav' => 'j/k:移動',
    'bar.quit' => 'q:終了',
    'bar.paused' => '[一時停止中]',
    'bar.tx' => '[TX]',
    'picker.title' => '保存済み接続',
    'picker.help' => '[↑↓/j/k] 選択   [Enter] 接続   [q] キャンセル',
    'result.empty' => '(結果セットは空です)',
    'explain.no_plan' => '(クエリプランなし — クエリは行を返しませんでした)',
    'explain.title' => ' クエリプラン ',
    'status.live_refresh_off' => '管理のライブ更新は無効 — TLS 検証済み ssl-mode (同期トランスポートのみ)',
    'status.async_failed' => '非同期コマンドが失敗しました: {message}',
    'status.file_cancelled' => 'ファイル入力を中止しました',
    'status.path_required' => 'パスが必要です — Esc で中止',
    'status.no_such_file' => 'ファイルがありません — 入力を続けるか Esc で中止',
    'status.table_required' => '対象テーブル名を入力 — Esc で中止',
    'status.export_failed' => 'エクスポートに失敗しました: {message}',
    'status.exported' => '{count} 行を {path} にエクスポートしました',
    'status.import_failed' => 'インポートに失敗しました: {message}',
    'status.imported' => '{path} を {table} にインポートしました',
    'status.history' => '履歴 {pos}/{count}',
    'status.conn_lost' => '接続が切れました。再試行してください',
    'status.destructive' => '⚠ 破壊的な文です — 確定するには変更せず ctrl+r をもう一度',
    'status.running' => 'クエリを実行中…',
    'status.explain_failed' => 'EXPLAIN に失敗しました: {message}',
    'status.explain_plan' => '実行計画 — クエリ実行やテーブルプレビューで置き換わります',
    'status.end_of_table' => 'テーブルの終わり — 次のページはありません',
    'status.first_page' => 'すでに最初のページです',
    'status.nothing_to_save' => '保存するものがありません',
    'status.saved' => '保存しました ({count})',
    'status.removed' => '削除しました ({count})',
    'status.row_one' => '1 行',
    'status.rows' => '{count} 行',
    'status.page' => 'ページ {page} (行 {first}–{last})',
    'editor.placeholder' => '-- SQL を入力、ctrl+r で実行 --',
    'err.async_query_unsupported' => 'このドライバーは非同期クエリに対応していません',
    'err.async_browse_unsupported' => 'このドライバーは非同期ブラウズに対応していません',
    'dash.title' => 'パフォーマンスダッシュボード',
    'dash.fetching' => 'サーバーメトリクスを取得中...',
    'dash.back_hint' => '[q] でテーブル表示に戻ります',
    'dash.recent_queries' => '最近のクエリ',
    'dash.no_queries' => '(クエリはまだありません)',
    'dash.header_pg' => 'パフォーマンスダッシュボード{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => 'パフォーマンスダッシュボード{status} | MySQL {version} | 稼働時間: {uptime}',
    'dash.uptime_segment' => ' | 稼働時間: {uptime}',
    'pane.process_list' => 'プロセス一覧',
    'pane.variables' => '変数',
    'pane.status' => 'ステータス',
    'pane.query_stats' => 'クエリ統計',
    'pane.dashboard' => 'ダッシュボード',
    'pane.table_stats' => 'テーブル統計',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => 'デバッグ',
    'page.no_data' => 'まだデータがありません。',
    'page.error' => 'エラー: {message}',
    'page.loading' => '読み込み中…',
    'page.fetching' => 'サーバーからデータを取得中です。',
    'debug.title' => 'デバッグログ',
    'debug.entries' => ' ({count} 件)',
    'debug.errors_only' => '[エラーのみ]',
    'debug.empty' => '(ログエントリなし)',
    'debug.more' => '↑ さらに {count} 件',
    'debug.help' => '[j/k] 移動  [f] エラーのみ  [c] クリア  [q] 戻る',
    'reports.cannot_access' => 'パフォーマンスレポートにアクセスできません: {message}',
    'reports.title' => 'パフォーマンスレポート',
    'reports.categories' => 'カテゴリ',
    'reports.no_data' => '(データなし)',
    'reports.loading' => 'レポートを読み込み中…',
    'reports.loading_one' => '{caption} を読み込み中…',
    'reports.no_data_for' => '{caption} のデータはありません',
    'reports.help' => '[j/k] 行移動  [h/l] カテゴリ  [[]/[]] レポート  [,/.] 列  [c] 単位  [r] 更新  [x] エクスポート  [q] 戻る',
    'reports.unavailable' => 'パフォーマンスレポートは利用できません',
    'reports.ensure' => 'MySQL 5.6.6 以降で performance_schema=enabled、かつ sys スキーマがインストールされていることを確認してください。',
    'conn.kill_cancelled' => 'Kill を中止しました',
    'conn.none_selected' => '接続が選択されていません',
    'conn.refuse_background' => 'バックグラウンドスレッドの終了は拒否されました',
    'conn.verb_kill_query' => 'クエリを Kill',
    'conn.verb_kill' => 'Kill',
    'conn.sent' => '{verb} を接続 {id} に送信しました',
    'conn.failed' => '接続 {id} への {verb} に失敗しました',
    'conn.confirm_verb_query' => '実行中のクエリをキャンセル:',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} 接続 {id} ({user}@{host})?  [y] 確定   [その他のキー] キャンセル',
    'conn.help_empty' => '[j/k] 移動  [f] フィルタ  [r] 更新',
    'conn.help_full' => '[j/k] 移動  [K] kill  [X] クエリ kill  [f] フィルタ  [r] 更新',
    'conn.vars_fail' => 'サーバー変数を取得できません',
    'vars.load_fail' => '変数を読み込めません: {message}',
    'vars.unknown_error' => '不明なエラー',
    'vars.not_dynamic' => 'エラー 1238: \'{name}\' は動的ではありません — [p] で PERSIST_ONLY を使用',
    'vars.new_value' => '新しい値 > ',
    'vars.edit_head' => '編集 ({mode}) {name} (現在: {current})',
    'vars.scope_explain' => 'GLOBAL = 実行時のみ (再起動で失われる) · PERSIST = 実行時 + 設定ファイル · PERSIST_ONLY = 設定ファイルのみ (次回の再起動時に適用)',
    'vars.edit_help' => '新しい値を入力、[p] モード切替、[Enter] 確定、[Esc] キャンセル',
    'reports.back' => '[q] 戻る',
    'err.dsn_empty' => 'DSN を空にすることはできません',
    'err.dsn_no_separator' => '無効な DSN: 区切り "://" がありません',
    'err.driver_unsupported' => 'サポートされていないドライバー: {driver}',
    'err.dsn_sqlite_invalid' => '無効な SQLite DSN',
    'err.dsn_parse_failed' => '無効な DSN: parse_url() が失敗しました',
    'err.dsn_no_host' => '無効な DSN: ホストがありません',
    'err.driver_choose' => 'サポートされていないドライバー: {driver} — {list} から選択してください',
    'err.args_missing' => '必須引数がありません: --driver と --db の両方を指定してください (または --dsn を使用)',
    'err.opt_needs_value' => 'オプション --{name} には値が必要です',
    'err.opt_unknown' => '不明なオプション: --{name}',
    'err.csv_not_found' => 'CSV ファイルが見つかりません: {path}',
    'err.csv_unreadable' => 'CSV ファイル \'{path}\' を読み取れません: 存在し読み取り可能か確認してください',
    'err.csv_no_headers' => 'CSV ヘッダーを読み取れません: {path}',
    'err.csv_no_columns' => 'CSV ファイルに列がありません: {path}',
    'err.dest_unwritable' => '出力先 \'{path}\' を書き込み用に開けません: ディレクトリが存在し書き込み可能か確認してください',
    'err.export_buffer' => 'エクスポートバッファを作成できませんでした',
    'err.csv_partial' => '{table} の CSV エクスポートは {expected} 行中 {written} 行を書き込みました — エクスポート中にテーブルが変更されたか、ドライバーがページング範囲を無視しました。不完全なファイルを完全なものとして提示することを拒否します',
    'err.sql_partial' => '{table} の SQL ダンプは {expected} 行中 {written} 行を書き込みました — ダンプ中にテーブルが変更されたか、ドライバーがページング範囲を無視しました。不完全なファイルを完全なものとして提示することを拒否します',
    'err.table_unreadable' => 'テーブルが見つからないか読み取れません: {table} ({message})',
    'err.dml_no_prepare' => 'この接続ではプリペアドステートメントを実行できません',
    'err.dml_refused' => 'サーバーがステートメントを拒否しました',
    'alert.connection_critical' => '接続使用率が危険なほど高い',
    'alert.connection_elevated' => '接続使用率が高い',
    'alert.aborted_elevated' => '中断された接続の割合が高い',
    'alert.thread_elevated' => '実行中スレッド数が多い',
    'alert.slow_query_time' => 'スロークエリ時間は {seconds} 秒に設定されています',
    'alert.connections_at' => '接続数は最大の {pct}% ({connected}/{max})',
    'alert.connection_error_one' => '接続エラーを {count} 件検出',
    'alert.connection_error_other' => '接続エラーを {count} 件検出',
    'alert.threshold_ratio_connection_warning' => '接続の警告しきい値は 0.0 から 1.0 の間である必要があります',
    'alert.threshold_ratio_connection_critical' => '接続の危険しきい値は 0.0 から 1.0 の間である必要があります',
    'alert.threshold_ratio_thread_running' => '実行中スレッドのしきい値は 0.0 から 1.0 の間である必要があります',
    'alert.threshold_aborted_rate' => '中断率のしきい値は 0.0 以上である必要があります',
    'alert.threshold_slow_query' => 'スロークエリのしきい値は 0.0 以上である必要があります',
    'alert.threshold_connection_errors' => '接続エラーのしきい値は 0 以上である必要があります',
    'history.err_mkdir' => '履歴ディレクトリを作成できません: {dir}',
    'history.err_chmod' => '履歴ストアファイルを保護できません: {path} (モード {mode})',
    'history.err_unusable' => '履歴ストアを使用できません: {error} — 履歴ファイルを削除するか権限を修正して復旧してください。',
    'query.cancelled' => '非同期クエリがキャンセルされました',
    'tx.unknown' => '不明なコマンド — :tx はトランザクションを開始・確定・ロールバックします',
    'tx.async_refusal' => ':tx は同期セッションのみ対応です。非同期トランスポートにはまだ調整済みのトランザクション列がないため、BEGIN/COMMIT を明示的に実行するか同期パスを利用してださい',
    'tx.already_open' => 'トランザクションは既に開いています — :tx commit または :tx rollback',
    'tx.no_tx' => '開いているトランザクションはありません — :tx で開始します',
    'tx.opened' => 'トランザクションを開始しました — :tx commit または :tx rollback まで文が束ねられます',
    'tx.committed' => 'トランザクションを確定しました',
    'tx.rolled_back' => 'トランザクションをロールバックしました',
    'tx.arm_rollback' => 'ROLLBACK は BEGIN 以降の全ての変更を破棄します — 確定するには実行キーをもう一度押してください',
    'tx.failed' => 'トランザクションコマンドが失敗しました: {message}',
    'history.none' => '表示できるデータなし — ステータス履歴は MySQL 系セッションのみ記録します (--history で有効化)',
    'history.empty' => '直近 {minutes} 分のサンプルがありません — トレンドには少なくとも 2 件必要です',
    'history.unreadable' => '履歴ストアを読み取れません: {message}',
    'history.status' => '履歴トレンド — 直近 {minutes} 分から {samples} 行',
    'query.timed_out' => '非同期クエリが {seconds} 秒後にタイムアウトしました',
    'reports.err_meta_missing' => 'レポートのメタデータファイルが見つかりません: {path}',
    'reports.err_meta_read' => 'レポートのメタデータファイルを読み取れませんでした: {path}',
    'reports.err_not_in_catalog' => 'カタログにレポートが見つかりません: {name}',
    'reports.err_view_unavailable' => 'このサーバーではビューを利用できません: {name}',
    'reports.err_loading' => 'レポートの読み込みエラー: {error}',
    'variables.err_meta_missing' => '変数のメタデータファイルが見つかりません: {path}',
    'variables.err_meta_read' => '変数のメタデータファイルを読み取れませんでした: {path}',
    'resilience.statement_timeout_default' => 'ステートメントの実行がタイムアウトしました',
    'resilience.statement_timeout_after' => 'ステートメントの実行が {seconds} 秒後にタイムアウトしました',
    'variables.err_prepare' => 'この接続でステートメントを準備できませんでした',
    'variables.err_not_editable' => '変数は編集できません',
    'variables.err_persist_mariadb' => '永続化には MariaDB 11.1 以上が必要です — このサーバーは SET PERSIST を実装していません。',
    'variables.err_persist_mysql' => '永続化には MySQL 8.0.16 以上が必要です — このサーバーは SET PERSIST を実装していません。',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection: ユーザー名が空です — 推測した "root" として MySQL に接続することを拒否します。ユーザーを明示してください',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection: ユーザー名が空です — 推測した "postgres" として PostgreSQL に接続することを拒否します。ユーザーを明示してください',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection: sslmode "{mode}" は認識できません。次のいずれかを指定してください: {allowed} — 誤字を黙って破棄すると平文を許容する "prefer" に格下げされます',
    'store.err_read' => '接続ファイルを読み取れません: {path}（削除するか権限を修正して復旧してください）',
    'store.err_json' => '接続ファイルは有効な JSON ではありません ({error}): {path}',
    'store.err_shape' => '接続ファイルは "connections" マップを持つ JSON オブジェクトである必要があります: {path}',
    'store.err_connections_type' => '"connections" の値は 名前 -> 設定 の JSON オブジェクトである必要があります: {path}',
    'store.err_unknown' => '不明な接続 \'{name}\' — 保存済みの接続: {saved}',
    'store.none' => '(なし)',
    'store.err_pass_env_unset' => '接続 \'{name}\' はパスワードを ${var} から読み取ります — 接続前にエクスポートしてください',
    'store.err_mkdir' => '接続ディレクトリを作成できません: {dir}',
    'store.err_write' => '接続ファイルを書き込めません: {path}',
    'store.err_replace' => '接続ファイルを置き換えられません: {path}',
    'store.err_entry_type' => '接続 \'{name}\' は設定の JSON オブジェクトである必要があります',
    'store.err_plaintext_pass' => '接続 \'{name}\' は平文の \'pass\' を保存しています — connections.json に秘密情報は保存しません。"pass-env": "VAR_NAME" に置き換えてください',
    'store.err_unknown_setting' => '接続 \'{name}\' に不明な設定 \'{field}\' があります — 使用可能: {allowed}',
    'store.err_setting_type' => '接続 \'{name}\' の設定 \'{field}\' は文字列または数値である必要があります',
    'store.err_setting_empty' => '接続 \'{name}\' の設定 \'{field}\' は空にできません',
    'store.err_needs_driver' => '接続 \'{name}\' には \'driver\' が必要です',
    'store.err_driver' => '接続 \'{name}\' はサポートされていないドライバー \'{driver}\' を使用しています — 次から選択してください: {allowed}',
    'store.err_port' => '接続 \'{name}\' の設定 \'port\' は数値である必要があります',
    'store.err_pass_env' => '接続 \'{name}\' の設定 \'pass-env\' は環境変数名である必要があります',
    'store.err_name' => '接続名 \'{name}\' が無効です — 英数字、ドット、ハイフン、アンダースコアを使用してください (1〜64 文字)',
    'store.err_secure' => '{path} を保護できません (モード {mode})',
    'db.err_dsn_semicolon' => 'データベースのホスト名と名前に \';\' を含めることはできません — PDO DSN のオプション区切りです',
    'db.err_unknown_pg_ssl' => '不明な PostgreSQL ssl-mode: {mode}',
    'db.err_unknown_mysql_ssl' => '不明な MySQL ssl-mode: {mode}',
    'db.err_pg_driver' => 'PostgreSQL コネクタで PostgreSQL 以外のドライバーには接続できません',
    'db.err_mysql_driver' => 'MySQL コネクタは mysql ドライバーのみ受け付けます (\'{driver}\' を受信)',
    'db.err_reconnect' => '接続エラー後の再接続に失敗しました: {error}',
    'db.err_quote_no_connection' => '接続なしではクォートできません',
    'dml.err_key_required' => '行キーが必要です: 複数行に影響しうるステートメントを拒否します',
    'dml.err_key_null' => 'キー列 {column} が NULL です: 行を特定できません',
    'dml.err_empty_table' => 'テーブル名は空にできません',
    'dml.err_empty_column' => '列名は空にできません',
    'dml.err_empty_key_column' => 'キー列名は空にできません',
    'status.already_saved' => '保存済み ({count})',
    'status.not_in_favorites' => 'お気に入りにありません ({count})',
    'status.gtid_mariadb' => 'GTID_MODE は利用できません: MariaDB に gtid_mode 変数はありません。',
    'status.gtid_version' => 'GTID_MODE には MySQL 5.7.6 以降が必要です (サーバーは {version})。',
    'caption.blocks_hit' => 'ブロックヒット',
    'caption.blocks_read' => 'ブロック読み取り',
    'caption.buffer_pool_read_reqs' => 'バッファプール読み取り要求',
    'caption.buffer_pool_usage' => 'バッファプール使用率',
    'caption.buffer_pool_write_reqs' => 'バッファプール書き込み要求',
    'caption.bytes_in' => '受信バイト',
    'caption.bytes_out' => '送信バイト',
    'caption.cache_hit_rate' => 'キャッシュヒット率',
    'caption.commits' => 'コミット',
    'caption.connections' => '接続',
    'caption.deadlocks' => 'デッドロック',
    'caption.disk_reads_not_from_pool' => 'ディスク読み取り (プール外)',
    'caption.disk_write_requests' => 'ディスク書き込み要求',
    'caption.doublewrite_writes' => 'ダブルライト書き込み',
    'caption.innodb_disk_reads' => 'InnoDB ディスク読み取り',
    'caption.innodb_disk_writes' => 'InnoDB ディスク書き込み',
    'caption.insert_buffer' => '挿入バッファ',
    'caption.pages_created' => '作成ページ',
    'caption.pages_flushed' => 'フラッシュページ',
    'caption.pages_read' => '読み取りページ',
    'caption.read_ahead' => '先読み',
    'caption.redo_log_bytes_written' => 'REDO ログ書き込みバイト',
    'caption.redo_log_writes' => 'REDO ログ書き込み',
    'caption.rollbacks' => 'ロールバック',
    'caption.row_lock_time' => '行ロック時間',
    'caption.row_lock_waits' => '行ロック待機',
    'caption.shared_buffers' => '共有バッファ',
    'caption.sql_statements' => 'SQL ステートメント',
    'caption.table_open_cache' => 'テーブルオープンキャッシュ',
    'caption.temp_bytes' => '一時バイト',
    'caption.temp_files' => '一時ファイル',
    'caption.transactions' => 'トランザクション',
    'caption.tuples_fetched' => 'フェッチされたタプル',
    'caption.tuples_returned' => '返されたタプル',
    'caption.traffic' => 'トラフィック',
    'caption.key_efficiency' => 'キー効率',
    'caption.selects_per_second' => '毎秒 SELECT 数',
    'caption.innodb_buffer_usage' => 'InnoDB バッファ使用率',
    'caption.innodb_reads_per_second' => 'InnoDB 毎秒読み取り',
    'caption.innodb_writes_per_second' => 'InnoDB 毎秒書き込み',
    'widget.tip.bytes_in' => '受信トラフィック: 合計 {Bytes_received} バイト',
    'widget.tip.bytes_out' => '送信トラフィック: 合計 {Bytes_sent} バイト',
    'widget.tip.client_connections' => 'クライアント接続: {Threads_connected}',
    'widget.tip.connections_level' => '接続レベルと max_connections の比較',
    'widget.tip.table_open_cache' => 'テーブルオープンキャッシュのヒット率',
    'widget.tip.sql_statements' => 'SQL ステートメントレート: select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'InnoDB バッファプール使用率 (バイト基準)',
    'widget.tip.buffer_pool_read_reqs' => 'InnoDB バッファプール読み取り要求 (毎秒)',
    'widget.tip.buffer_pool_write_reqs' => 'InnoDB バッファプール書き込み要求 (毎秒)',
    'widget.tip.disk_reads_not_from_pool' => 'InnoDB バッファプールのディスク読み取り (毎秒)',
    'widget.tip.innodb_disk_writes' => 'InnoDB データのディスク書き込みバイト (毎秒)',
    'widget.tip.disk_write_requests' => 'InnoDB データファイル書き込み要求 (毎秒)',
    'widget.tip.innodb_disk_reads' => 'InnoDB データのディスク読み取りバイト (毎秒)',
    'widget.tip.row_lock_waits' => 'InnoDB 行ロック待機 (毎秒)',
    'widget.tip.row_lock_time' => 'InnoDB 行ロック時間 (毎秒、ミリ秒)',
    'widget.tip.pages_flushed' => 'InnoDB フラッシュページ (毎秒)',
    'widget.tip.pages_created' => 'InnoDB 作成ページ (毎秒)',
    'widget.tip.pages_read' => 'InnoDB 読み取りページ (毎秒)',
    'widget.tip.insert_buffer' => 'InnoDB 挿入バッファサイズ (ページ)',
    'widget.tip.read_ahead' => 'InnoDB バッファプール先読み (毎秒)',
    'widget.tip.redo_log_bytes_written' => 'InnoDB REDO ログ書き込みバイト (毎秒)',
    'widget.tip.redo_log_writes' => 'InnoDB REDO ログ書き込み (毎秒)',
    'widget.tip.doublewrite_writes' => 'InnoDB ダブルライト書き込み (毎秒)',
    'widget.tip.alter_rate' => 'ALTER コマンドレート (すべての Com_alter_*)',
    'widget.tip.create_rate' => 'CREATE コマンドレート (すべての Com_create_*)',
    'widget.tip.drop_rate' => 'DROP コマンドレート (すべての Com_drop_*)',
    'widget.tip.select_rate' => 'SELECT レート',
    'widget.tip.insert_rate' => 'INSERT レート',
    'widget.tip.update_rate' => 'UPDATE レート',
    'widget.tip.delete_rate' => 'DELETE レート',
    'widget.tip.tuples_fetched_total' => 'DB からフェッチした行: 合計 {tup_fetched}',
    'widget.tip.tuples_fetched_rate' => '行フェッチレート',
    'widget.tip.tuples_returned_total' => 'クライアントに返した行: 合計 {tup_returned}',
    'widget.tip.tuples_returned_rate' => '行返却レート',
    'widget.tip.blocks_read_total' => '読み取ったディスクブロック: 合計 {blks_read}',
    'widget.tip.blocks_hit_total' => 'バッファキャッシュヒット: 合計 {blks_hit}',
    'widget.tip.active_backends' => 'アクティブなバックエンド: {numbackends}',
    'widget.tip.transaction_rates' => 'トランザクションレート: コミット / ロールバック',
    'widget.tip.commit_rate' => 'トランザクションコミットレート',
    'widget.tip.rollback_rate' => 'トランザクションロールバックレート',
    'widget.tip.tuple_insert_rate' => 'タプル挿入レート',
    'widget.tip.tuple_update_rate' => 'タプル更新レート',
    'widget.tip.tuple_delete_rate' => 'タプル削除レート',
    'widget.tip.deadlock_rate' => 'デッドロックレート',
    'widget.tip.shared_buffers' => 'PostgreSQL の shared_buffers 設定 (バッファプールサイズ)',
    'widget.tip.cache_hit_rate' => 'バッファキャッシュヒット率: blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => '一時ファイル作成レート',
    'widget.tip.temp_bytes' => '一時ファイルへの書き込みバイト (毎秒)',
    'dash.data_written' => 'データ書き込み {kb} kB/s',
    'dash.writes_ops' => '書き込み {n} ops/s',
    'dash.log_written' => 'ログ書き込み {kb} kB/s',
    'dash.read_reqs' => '読み取り要求 {n} ページ/s',
    'dash.write_reqs' => '書き込み要求 {n} ページ/s',
    'dash.disk_reads' => 'ディスク読み取り {n} /s',
    'metrics.busy_proxy' => 'ビジー指標 {pct}%',
    'metrics.cpu_load' => 'CPU 負荷 {load}',
    'metrics.no_samples' => 'サンプルはまだありません',
    'metrics.connections_count' => '{count} 件の接続',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => 'サーバーメトリクス',
    'explain.query_block' => 'クエリブロック #{id} (コスト: {cost})',
    'variables.footer_tree' => '[j/k] ツリー',
    'variables.footer_rows' => '[j/k] 行',
    'variables.footer_categories' => '[c] カテゴリ',
    'variables.footer_edit' => '[Enter/e] 編集 (動的)',
    'variables.footer_rw_filter' => '[w] rw フィルタ',
    'variables.footer_search' => '[s] 検索',
    'variables.footer_back' => '[q] 戻る',
    'srv.title' => 'サーバーステータス',
    'srv.features' => '機能',
    'srv.stored_programs' => 'ストアドプログラム',
    'srv.directories' => 'ディレクトリ',
    'srv.data_directory' => 'データディレクトリ',
    'srv.temp_directory' => '一時ディレクトリ',
    'srv.log_directory' => 'ログディレクトリ',
    'srv.pid_file' => 'PID ファイル',
    'srv.tls_active' => 'TLS 有効',
    'srv.tls_cipher' => 'TLS 暗号',
    'srv.tls_version' => 'TLS バージョン',
    'srv.ssl_support' => 'SSL サポート',
    'srv.ssl_ca' => 'SSL CA',
    'srv.ssl_cert' => 'SSL 証明書',
    'srv.ssl_key' => 'SSL 鍵',
    'srv.tls_secure' => 'TLS / セキュア接続',
    'srv.replication' => 'レプリケーション',
    'srv.repl_denied' => '権限が不足しています (REPLICATION CLIENT)',
    'srv.repl_error' => 'レプリケーションステータスへのアクセスエラー',
    'srv.not_configured' => '未設定',
    'srv.channel' => 'チャネル',
    'srv.source_host' => 'ソースホスト',
    'srv.source_port' => 'ソースポート',
    'srv.replica_io' => 'レプリカ IO 実行中',
    'srv.replica_sql' => 'レプリカ SQL 実行中',
    'srv.seconds_behind' => 'ソースからの遅延秒数',
    'srv.relay_log_file' => 'リレーログファイル',
    'srv.relay_pos' => 'リレー位置',
    'srv.repl_channel_title' => 'レプリケーション › {channel}',
    'srv.default' => 'デフォルト',
    'srv.firewall' => 'ファイアウォール',
    'srv.mysql_firewall' => 'MySQL ファイアウォール',
    'srv.caught_up' => '0秒 (同期済み)',
    'srv.host' => 'ホスト',
    'srv.socket' => 'ソケット',
    'srv.port' => 'ポート',
    'srv.version' => 'バージョン',
    'srv.uptime' => '稼働時間',
    'srv.running_since' => '起動日時',
    'srv.unknown' => '不明',
    'srv.server_information' => 'サーバー情報',
    'perf.tab_easy_setup' => '簡単セットアップ',
    'perf.tab_instruments' => 'インストゥルメント',
    'perf.tab_consumers' => 'コンシューマ',
    'perf.tab_actors' => 'アクター',
    'perf.tab_objects' => 'オブジェクト',
    'perf.tab_threads' => 'スレッド',
    'perf.tab_options' => 'オプション',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[読み取り専用]',
    'perf.nothing_to_commit' => 'コミットする内容がありません',
    'perf.commit_planning_failed' => 'コミット計画に失敗しました: {error}',
    'perf.err_prepare' => 'ステートメントの準備に失敗しました: {sql}',
    'perf.committed_one' => '{count} 件のステートメントをコミットしました',
    'perf.committed_other' => '{count} 件のステートメントをコミットしました',
    'perf.applied_one' => '{label} を適用しました — {count} 件のステートメント',
    'perf.applied_other' => '{label} を適用しました — {count} 件のステートメント',
    'perf.commit_failed' => '{label} は {total} 件中 {position} 件目のステートメントで失敗しました: {error} — それ以前のステートメントは適用済みで、サーバーの状態は部分的です。',
    'perf.err_access_denied' => 'アクセス拒否: 必要な権限がありません',
    'perf.err_not_enabled' => 'Performance Schema は有効ではありません',
    'perf.err_cannot_connect' => 'データベースに接続できません',
    'perf.state_fully' => '完全に有効',
    'perf.state_default' => 'デフォルト設定',
    'perf.state_custom' => 'カスタム設定',
    'perf.state_disabled' => '無効',
    'perf.state_unknown' => '不明',
    'perf.current_state' => '現在の状態: {state}',
    'perf.readonly_note' => '(読み取り専用モード - 変更する権限がありません)',
    'perf.keys_hint' => 'キー "[" Performance Schema を完全に有効化   キー "]" 無効化   キー "." デフォルトに戻す',
    'perf.default_instruments' => 'デフォルトのインストゥルメント: stage/%, statement/%, wait/%',
    'perf.default_consumers' => 'デフォルトのコンシューマ: events_statements_history、events_waits_history など',
    'perf.none_instruments' => '(利用可能なインストゥルメントはありません)',
    'perf.none_consumers' => '(利用可能なコンシューマはありません)',
    'perf.none_actors' => '(設定されたアクターはありません)',
    'perf.none_objects' => '(設定されたオブジェクトルールはありません)',
    'perf.none_threads' => '(利用可能なスレッドはありません)',
    'perf.total_instruments_one' => '合計: {count} 件のインストゥルメント',
    'perf.total_instruments_other' => '合計: {count} 件のインストゥルメント',
    'perf.total_consumers_one' => '合計: {count} 件のコンシューマ',
    'perf.total_consumers_other' => '合計: {count} 件のコンシューマ',
    'perf.total_actors_one' => '合計: {count} 件のアクター',
    'perf.total_actors_other' => '合計: {count} 件のアクター',
    'perf.total_objects_one' => '合計: {count} 件のオブジェクトルール',
    'perf.total_objects_other' => '合計: {count} 件のオブジェクトルール',
    'perf.total_threads_one' => '合計: {count} 件のスレッド',
    'perf.total_threads_other' => '合計: {count} 件のスレッド',
    'perf.col_thread_id' => 'スレッド ID',
    'perf.col_name' => '名前',
    'perf.col_type' => '種類',
    'perf.col_user' => 'ユーザー',
    'perf.timer_options' => 'タイマーオプション',
    'perf.none_timers' => '(利用可能なタイマーはありません - 読み取り専用)',
    'perf.col_timer_name' => 'タイマー名',
    'perf.col_implementation' => '実装',
    'perf.col_scale_factor' => 'スケール係数',
    'perf.timer_readonly_note' => 'タイマー設定は読み取り専用です (サーバービルドにより決定)',
    'perf.footer_nav' => '[j/k] 移動  [Space] 切替',
    'perf.footer_actions' => '  [c] コミットを準備 ([y] で実行)  [r] 元に戻す',
    'perf.footer_back' => '  [q] 戻る',
    'perf.pending_one' => '保留中: {count} 件の変更',
    'perf.pending_other' => '保留中: {count} 件の変更',
    'cli.err_not_tty' => 'このランチャーは対話型端末を操作するため、パイプへの ANSI 出力を拒否します (終了コード 2) — pty 内で実行するか (例: script -qec \'candy-sql app.sqlite\' /dev/null)、ライブラリコードからヘッドレスで描画してください (ResultTable::renderPlain())。',
    'cli.history_disabled' => '履歴の記録は無効です ({path}): {error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' は検証済み TLS を要求します — ReactPHP の管理用トランスポートは対応できないため、管理画面のライブ更新は無効になり、クエリとテーブル読み込みは TLS 接続上で同期的に実行されます。',
    'cli.no_connection_selected' => '接続が選択されていません — パス、DSN、または --connect=<name> を指定してください',
    'cli.usage_line' => '使い方: candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # 詳細な使い方を表示',
    'cli.tip_saved_connections' => '       ヒント: 名前付き接続を {path} に保存し、--connect=<name> で起動できます。',
    'help.summary' => 'candy-sql - SQLite、MySQL、PostgreSQL 向けのターミナル SQL クライアント',
    'help.usage' => '使い方
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => '引数
  path-or-dsn
    SQLite データベースファイルのパス。例: app.sqlite
    または DSN 文字列。例:  mysql://user:pass@localhost:3306/dbname

  すべて省略した場合: 保存済み接続がある対話端末ではピッカーが
  それらを提示します。それ以外は短い使用法の行を出力し、ステータス 2
  (不正な呼び出し)で終了します。この完全な画面は --help で表示できます。',
    'help.options' => 'オプション
  --help, -h
    このヘルプ画面を表示します。

  --version, -V
    バージョン番号を表示します。

  --connect=<name>
    保存済み接続ファイルから指定した名前のエントリへ接続します
    ($XDG_CONFIG_HOME/candy-sql/connections.json。既定は
    $HOME/.config/candy-sql/connections.json)。明示的な接続引数とは
    併用できません。パスワードはディスクに保存されません。エントリは
    "pass-env" で秘密の変数名を指定し、その変数は接続時に読み取られます。
    下の「保存済み接続」(SAVED CONNECTIONS)を参照してください。',
    'help.option_dsn' => '  --dsn=<dsn>
    完全な DSN 接続文字列。
    形式: <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    例:
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
    個別の接続パラメーター(--driver と --db 以外はすべて任意)。
    --driver は sqlite、mysql、pgsql のいずれか
    --ssl-mode は MySQL では既定で "prefer"。"require" と "verify_*" は
    サーバー証明書の検証付き TLS を強制します。CA の信頼はサーバー側または
    my.cnf で設定する必要があります(README 参照)。これらの検証モードでは
    平文を許す ReactPHP トランスポートは拒否され、クエリは TLS 接続上で
    同期的に実行され、管理画面のライブ更新は無効になります(起動時の通知と
    アプリのステータス行がそれを知らせます)。
    パスワードがプロセスのコマンドライン(ps で見える)に残らないよう、
    --pass= より CANDY_SQL_PASS を使ってください。',
    'help.examples' => '使用例
  # SQLite(既定 — パスのみ、または DSN)
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # DSN で MySQL
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # 個別のフラグで MySQL
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => '保存済み接続
  名前付きサーバーを一度登録すれば、以後はすぐに接続できます:

    $HOME/.config/candy-sql/connections.json  (ディレクトリ 0700、ファイル 0600)',
    'help.saved_fields' => '  フィールド: driver (sqlite|mysql|pgsql), host, port, user, db, ssl-mode,
  pass-env。それ以外のフィールドは拒否され、平文の "pass" フィールドは
  常に拒否されます。代わりに pass-env で変数を指定してください。対話端末で
  引数なしに起動すると、これらのエントリがピッカーとして表示されます。
  --connect=<name> で直接接続できます。',
    'help.editing' => '編集
  TABLE プレビューの Rows ペインは編集可能なグリッドです。h/l(または ←/→)で
  セルカーソルを動かし、3 つのキーでデータを変更します。いずれも正確な
  ステートメントをステータス行に表示し、Enter で確定するまで何も書き込みません:

    e   カーソル位置のセルを編集(Ctrl+N で NULL)
    o   行を挿入(Enter で列を順に進む。空入力はその列を DEFAULT のままに、
        Ctrl+N は NULL を格納)
    D   カーソル位置の行を削除

  行は完全な PRIMARY KEY だけで特定されます。主キーのないテーブル、
  NULL/バイナリのキー、バイナリのセル、およびクエリ結果は、名前付きの
  ステータス行で拒否されます。値はバインドパラメーターで、各書き込みは
  ちょうど 1 行が対象でない限りロールバックされるトランザクション内で
  実行されます(既に同じ値を書く UPDATE は変更なしと報告されます)。
  Esc で開いている編集行を取り消します。',
    'help.editing_readonly' => '  --read-only
    このセッションの編集キーを無効にします。うっかり操作を防ぐものであり、
    Query ペインのステートメントはフィルターしません。確実に防ぐには
    読み取り専用のデータベースユーザーで接続してください。',
    'help.history' => '履歴
  既定では、管理画面のポーリングのスナップショットがすべて、オフラインの
  監査証跡として $HOME/.candy-sql/history.db(ディレクトリ 0700 / ファイル
  0600)に記録されます。アプリ内の Dashboard と Reports ページはこの
  ファイルを読まず、稼働中のサーバーに問い合わせます。',
    'help.history_flags' => '  --no-history
    記録を完全に無効にします(ディスクには何も書き込まれません)。

  --history=<path>
    スナップショットを既定の場所ではなく <path> に保存します。',
    'help.keyboard' => 'キーボードショートカット (TUI)
  Tab          ペイン間でフォーカスを循環(Tables → Rows → Query → Admin)
  ↑ / ↓        リスト行を移動(または j/k)。Tables ペインでの移動はローカルのみで、
               プレビューは取得しません
  /            (Tables ペイン)テーブル名のライブフィルター行を開く。文字で追加、
               ↑/↓ でカーソル移動、Enter で確定して読み込み、Backspace で編集、
               Esc で破棄してクリア
  Enter        選択したテーブルの行を読み込む
  Space        選択したテーブルの行を読み込む(Enter の別名)
  PgDn / PgUp  (Tables/Rows ペイン、テーブルプレビュー時)プレビューを 100 行単位で
               ページ送り。閲覧上限はページサイズになりました
  Ctrl+Enter   現在のクエリを実行
  Ctrl+R       現在のクエリを実行
  Ctrl+E       現在のクエリを実行
  EXPLAIN …    (Query エディター、先頭のキーワード)入力した EXPLAIN は ExplainView を
               通ります。Rows ペインはフラットなプロバイダーのグリッドではなく、
               色分けされたプランツリーを表示し、ANALYZE 修飾子は除去されます。
               インスペクターは調査対象を実行しません
  e / o / D    (Rows ペイン、テーブルプレビュー)セルの編集 / 行の挿入 /
               行の削除 — 「編集」(EDITING)を参照。h/l でセルカーソル移動
  Ctrl+Space   キャレット位置で補完(Query エディター): SQL キーワード、
               FROM/JOIN/INTO/UPDATE の後のテーブル名、文脈に応じた列
               (WHERE/SELECT/ORDER BY、`alias.` / `table.` 修飾、INSERT INTO t (...)
               の列リスト)を読み込み済みスキーマから補完します。候補が 1 つなら
               補完、共通接頭辞があれば前進、それ以外はステータス行に候補を表示
  Ctrl+F       現在のクエリをお気に入りリストに追加(セッション内のみ。
               どのペインにも表示されず、保存もされません)
  Ctrl+Shift+F 現在のクエリをお気に入りリストから削除
  1-8          管理ペインへ移動(Admin ペインにフォーカスがあるときのみ)
  q            Tables/Rows ペインから終了。Admin からは Tables に戻る
  x            (Tables/Rows、ライブ結果)現在の行を CSV パスへエクスポート。
               Enter で確定するまで上書き拒否が有効
  i            (Tables/Rows)CSV ファイルを指定したテーブルへインポート
  Ctrl+S       どのペインからでもライブ結果/プレビューを CSV にエクスポート
  Esc          Tables/Rows ペインから終了。開いている Admin ダイアログを取り消す
  Backspace    カーソルの前の文字を削除(Query エディター)',
    'help.see_also' => '関連項目
  https://github.com/sugarcraft/candy-sql',
];
