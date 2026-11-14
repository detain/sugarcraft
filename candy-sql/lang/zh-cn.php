<?php

/**
 * Simplified Chinese translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'SQLite 文件不存在：{path}',
    'complete.in_literal' => '字符串或注释内无可补全内容',
    'complete.nothing' => '无可补全内容 — 请输入关键字的开头',
    'complete.none_keyword' => '没有可补全 "{word}" 的关键字',
    'complete.none_table' => '没有可补全 "{word}" 的表',
    'complete.none_column' => '没有可补全 "{word}" 的列',
    'complete.none_qualified' => '{qualifier} 中没有可补全 "{word}" 的列',
    'complete.list' => '"{word}" 有 {count} 个补全项：{list}{more}',
    'complete.done' => '已补全 {word} → {insert}',
    'complete.inserted' => '已插入 {insert}',
    'edit.refuse_readonly' => '只读会话 — 编辑已禁用',
    'edit.refuse_no_table' => '请先打开表预览 — 查询结果无法就地编辑',
    'edit.refuse_no_rows' => '光标下没有行',
    'edit.refuse_no_pk' => '该表没有主键 — 无法安全定位行；请在查询窗格中编写语句',
    'edit.refuse_pk_hidden' => '该行主键为 NULL 或二进制占位符 — 无法定位',
    'edit.refuse_binary' => '二进制或大型单元格无法在此编辑 — 请在查询窗格中使用语句',
    'edit.refuse_schema' => '无法读取 {table} 的结构 — 已拒绝编辑',
    'edit.prompt_cell' => '编辑 {table}.{column} = {value}  (回车预览 · Ctrl+N 设为 NULL · Esc 取消)',
    'edit.prompt_insert' => '{table} 新行 — {column} ({type}) [{n}/{total}] = {value}  (回车下一项 · 留空 = DEFAULT · Ctrl+N 设为 NULL · Esc 取消)',
    'edit.prompt_confirm' => '{sql}  (回车提交 · Esc 取消)',
    'edit.cancelled' => '已取消编辑',
    'edit.unchanged' => '值未改变 — 未写入任何内容',
    'edit.done_update' => '已更新 {table} 中的 1 行',
    'edit.done_insert' => '已向 {table} 插入 1 行',
    'edit.done_delete' => '已从 {table} 删除 1 行',
    'edit.none_changed' => '没有行被更改 — 值已相同或该行已不存在；预览已刷新',
    'edit.failed' => '写入失败 — 已回滚，未做任何更改：{reason}',
    'edit.too_many' => '已拒绝：该语句将影响 {count} 行 — 已回滚，未做任何更改',
    'ui.title.tables' => ' 表 ',
    'ui.title.rows' => ' 行 ',
    'ui.title.query' => ' 查询 ',
    'ui.title.admin' => ' 管理 ',
    'ui.title.explain' => ' 执行计划 ',
    'ui.help.query' => 'tab  切换窗格  ·  ctrl+r / ctrl+e / ctrl+enter  运行  ·  enter  换行  ·  ↑/↓  历史  ·  esc / ctrl+c  取消运行中查询  ·  ctrl+c  空闲时退出',
    'ui.help.rows' => 'tab  切换窗格  ·  j/k  行  ·  h/l  单元格  ·  e  编辑  ·  o  新行  ·  D  删除  ·  y  复制  ·  x  导出  ·  q / esc  退出',
    'ui.help.browse' => 'tab  切换窗格  ·  enter  加载表  ·  x  导出  ·  i  导入  ·  q / esc  退出',
    'ui.help.empty' => 'tab  切换窗格  ·  q / esc  退出',
    'param.prompt' => '占位符 {index}/{total} ({label}) - 输入值，Enter 绑定（留空 = NULL），Esc 取消运行',
    'param.line' => '参数 {label} ({index}/{total}) = {value} · 空 = NULL · Enter 下一个 · Esc 取消',
    'param.cancel' => '参数输入已取消 - 查询未运行',
    'param.mixed' => '不支持混用 ? 与 :name 占位符 - 请统一使用一种风格',
    'yank.refuse_no_cell' => '光标下没有单元格',
    'yank.osc52' => '已复制单元格到剪贴板 - 终端可能忽略 OSC 52',
    'yank.plain' => '此终端不支持 OSC 52 - 单元格文本：{text}',
    'yank.plain_null' => '此终端不支持 OSC 52 - 单元格为 NULL',
    'yank.too_big' => '单元格为 {bytes} 字节 - 超出 OSC 52 上限',
    'ui.help.result' => 'tab  切换窗格  ·  j/k  行  ·  h/l  单元格  ·  y  复制  ·  x  导出  ·  q / esc  退出',
    'ui.help.admin' => '1-{count}  选择页面  ·  j/k  导航  ·  p  暂停  ·  r  重置  ·  tab  切换窗格  ·  q  返回  ·  esc  取消对话框或运行中查询',
    'ui.no_tables' => '(无表)',
    'ui.no_match' => '(无匹配)',
    'ui.loading' => '加载中…',
    'ui.empty' => '(空)',
    'ui.admin_unavailable' => '管理页面不可用：{reason}',
    'ui.error' => '错误：{message}',
    'io.prompt_export' => '导出 CSV → {path}  (回车写入 · Esc 取消)',
    'io.prompt_replace' => '替换 {path}？  (回车确认 · Esc 取消)',
    'io.prompt_import_path' => '导入 CSV ← {path}  (回车 · Esc 取消)',
    'io.prompt_import_table' => '导入 {source} → 表 {table}  (回车 · Esc 取消)',
    'bar.tables' => '表：{count}',
    'bar.cycle' => 'Tab:切换',
    'bar.navigate' => '↑↓:导航',
    'bar.load' => 'Enter:加载',
    'bar.run' => 'Ctrl+R:运行',
    'bar.select' => '1-{count}:选择',
    'bar.nav' => 'j/k:移动',
    'bar.quit' => 'q:退出',
    'bar.paused' => '[已暂停]',
    'bar.tx' => '[TX]',
    'picker.title' => '已保存的连接',
    'picker.help' => '[↑↓/j/k] 选择   [Enter] 连接   [q] 取消',
    'result.empty' => '(结果集为空)',
    'explain.no_plan' => '(无查询计划 — 查询未返回行)',
    'explain.title' => ' 查询计划 ',
    'status.live_refresh_off' => '管理实时刷新已禁用 — 经 TLS 验证的 ssl-mode（仅同步传输）',
    'status.async_failed' => '异步命令失败：{message}',
    'status.file_cancelled' => '已取消文件输入',
    'status.path_required' => '需要路径 — Esc 取消',
    'status.no_such_file' => '文件不存在 — 继续输入或按 Esc 取消',
    'status.table_required' => '请输入目标表名 — Esc 取消',
    'status.export_failed' => '导出失败：{message}',
    'status.exported' => '已导出 {count} 行到 {path}',
    'status.import_failed' => '导入失败：{message}',
    'status.imported' => '已将 {path} 导入 {table}',
    'status.history' => '历史 {pos}/{count}',
    'status.conn_lost' => '连接已断开；请重试',
    'status.destructive' => '⚠ 破坏性语句 — 请再次按 ctrl+r（不要修改）以确认',
    'status.running' => '正在运行查询…',
    'status.explain_failed' => 'EXPLAIN 失败：{message}',
    'status.explain_plan' => '执行计划 — 任何查询运行或表预览都会将其替换',
    'status.end_of_table' => '表末尾 — 没有下一页',
    'status.first_page' => '已在第一页',
    'status.nothing_to_save' => '没有可保存的内容',
    'status.saved' => '已保存 ({count})',
    'status.removed' => '已移除 ({count})',
    'status.row_one' => '1 行',
    'status.rows' => '{count} 行',
    'status.page' => '第 {page} 页（行 {first}–{last}）',
    'editor.placeholder' => '-- 输入 SQL，按 ctrl+r 运行 --',
    'err.async_query_unsupported' => '此驱动不支持异步查询',
    'err.async_browse_unsupported' => '此驱动不支持异步浏览',
    'dash.title' => '性能仪表板',
    'dash.fetching' => '正在获取服务器指标...',
    'dash.back_hint' => '按 [q] 返回表视图',
    'dash.recent_queries' => '最近的查询',
    'dash.no_queries' => '(暂无查询)',
    'dash.header_pg' => '性能仪表板{status} | PostgreSQL {version}{uptime}',
    'dash.header_mysql' => '性能仪表板{status} | MySQL {version} | 运行时间：{uptime}',
    'dash.uptime_segment' => ' | 运行时间：{uptime}',
    'pane.process_list' => '进程列表',
    'pane.variables' => '变量',
    'pane.status' => '状态',
    'pane.query_stats' => '查询统计',
    'pane.dashboard' => '仪表板',
    'pane.table_stats' => '表统计',
    'pane.perf_schema' => 'Performance Schema',
    'pane.debug' => '调试',
    'page.no_data' => '暂无数据。',
    'page.error' => '错误：{message}',
    'page.loading' => '加载中…',
    'page.fetching' => '正在从服务器获取数据。',
    'debug.title' => '调试日志',
    'debug.entries' => ' ({count} 条)',
    'debug.errors_only' => '[仅错误]',
    'debug.empty' => '(无日志条目)',
    'debug.more' => '↑ 还有 {count} 条',
    'debug.help' => '[j/k] 导航  [f] 仅错误  [c] 清除  [q] 返回',
    'reports.cannot_access' => '无法访问性能报告：{message}',
    'reports.title' => '性能报告',
    'reports.categories' => '类别',
    'reports.no_data' => '(无数据)',
    'reports.loading' => '正在加载报告…',
    'reports.loading_one' => '正在加载 {caption}…',
    'reports.no_data_for' => '{caption} 没有可用数据',
    'reports.help' => '[j/k] 行导航  [h/l] 类别  [[]/[]] 报告  [,/.] 列  [c] 单位  [r] 刷新  [x] 导出  [q] 返回',
    'reports.unavailable' => '性能报告不可用',
    'reports.ensure' => '请确保使用 MySQL 5.6.6+，已启用 performance_schema 并安装了 sys 架构。',
    'conn.kill_cancelled' => '已取消 Kill',
    'conn.none_selected' => '未选择连接',
    'conn.refuse_background' => '拒绝终止后台线程',
    'conn.verb_kill_query' => '终止查询',
    'conn.verb_kill' => '终止',
    'conn.sent' => '已向连接 {id} 发送 {verb}',
    'conn.failed' => '连接 {id} 的 {verb} 失败',
    'conn.confirm_verb_query' => '取消正在运行的查询：',
    'conn.confirm_verb_kill' => 'KILL',
    'conn.confirm' => '{verb} 连接 {id} ({user}@{host})？  [y] 确认   [其他键] 取消',
    'conn.help_empty' => '[j/k] 导航  [f] 过滤  [r] 刷新',
    'conn.help_full' => '[j/k] 导航  [K] kill  [X] kill 查询  [f] 过滤  [r] 刷新',
    'conn.vars_fail' => '无法获取服务器变量',
    'vars.load_fail' => '无法加载变量：{message}',
    'vars.unknown_error' => '未知错误',
    'vars.not_dynamic' => '错误 1238：\'{name}\' 不是动态变量 — 按 [p] 使用 PERSIST_ONLY',
    'vars.new_value' => '新值 > ',
    'vars.edit_head' => '编辑 ({mode}) {name}（当前：{current}）',
    'vars.scope_explain' => 'GLOBAL = 仅运行时（重启后丢失）· PERSIST = 运行时 + 配置文件 · PERSIST_ONLY = 仅配置文件（下次重启时生效）',
    'vars.edit_help' => '输入新值，[p] 切换模式，[Enter] 确认，[Esc] 取消',
    'reports.back' => '[q] 返回',
    'err.dsn_empty' => 'DSN 不能为空',
    'err.dsn_no_separator' => '无效的 DSN：缺少 "://" 分隔符',
    'err.driver_unsupported' => '不支持的驱动：{driver}',
    'err.dsn_sqlite_invalid' => '无效的 SQLite DSN',
    'err.dsn_parse_failed' => '无效的 DSN：parse_url() 失败',
    'err.dsn_no_host' => '无效的 DSN：缺少主机',
    'err.driver_choose' => '不支持的驱动：{driver} — 请从 {list} 中选择',
    'err.args_missing' => '缺少必需参数：请同时提供 --driver 和 --db（或使用 --dsn）',
    'err.opt_needs_value' => '选项 --{name} 需要一个值',
    'err.opt_unknown' => '未知选项：--{name}',
    'err.csv_not_found' => '未找到 CSV 文件：{path}',
    'err.csv_unreadable' => '无法读取 CSV 文件 \'{path}\'：请检查其是否存在且可读',
    'err.csv_no_headers' => '无法从以下位置读取 CSV 标题：{path}',
    'err.csv_no_columns' => 'CSV 文件没有列：{path}',
    'err.dest_unwritable' => '无法打开目标 \'{path}\' 进行写入：请检查目录是否存在且可写',
    'err.export_buffer' => '无法创建导出缓冲区',
    'err.csv_partial' => '{table} 的 CSV 导出仅写入了 {expected} 行中的 {written} 行 — 表在导出期间发生变化，或驱动忽略了分页窗口；拒绝将不完整的文件当作完整文件',
    'err.sql_partial' => '{table} 的 SQL 转储仅写入了 {expected} 行中的 {written} 行 — 表在转储期间发生变化，或驱动忽略了分页窗口；拒绝将不完整的文件当作完整文件',
    'err.table_unreadable' => '未找到表或无法读取：{table}（{message}）',
    'err.dml_no_prepare' => '此连接无法执行预处理语句',
    'err.dml_refused' => '服务器拒绝了该语句',
    'alert.connection_critical' => '连接使用率严重偏高',
    'alert.connection_elevated' => '连接使用率偏高',
    'alert.aborted_elevated' => '中止连接比率偏高',
    'alert.thread_elevated' => '运行线程数偏高',
    'alert.slow_query_time' => '慢查询时间设置为 {seconds} 秒',
    'alert.connections_at' => '连接数达到上限的 {pct}% ({connected}/{max})',
    'alert.connection_error_one' => '检测到 {count} 个连接错误',
    'alert.connection_error_other' => '检测到 {count} 个连接错误',
    'alert.threshold_ratio_connection_warning' => '连接警告阈值必须介于 0.0 和 1.0 之间',
    'alert.threshold_ratio_connection_critical' => '连接严重阈值必须介于 0.0 和 1.0 之间',
    'alert.threshold_ratio_thread_running' => '运行线程阈值必须介于 0.0 和 1.0 之间',
    'alert.threshold_aborted_rate' => '中止率阈值必须 >= 0.0',
    'alert.threshold_slow_query' => '慢查询阈值必须 >= 0.0',
    'alert.threshold_connection_errors' => '连接错误阈值必须 >= 0',
    'history.err_mkdir' => '无法创建历史目录：{dir}',
    'history.err_chmod' => '无法保护历史存储文件：{path}（模式 {mode}）',
    'history.err_unusable' => '历史存储不可用：{error} — 请删除历史文件或修复其权限以恢复。',
    'query.cancelled' => '异步查询已取消',
    'tx.unknown' => '未知命令——:tx 用于开始、提交或回滚事务',
    'tx.async_refusal' => ':tx 仅覆盖同步会话；异步传输尚无协调事务通路——请显式执行 BEGIN/COMMIT 或使用同步路径',
    'tx.already_open' => '已有打开的事务——:tx commit 或 :tx rollback',
    'tx.no_tx' => '没有打开的事务——:tx 将开始一个',
    'tx.opened' => '事务已开始——语句将并入直至 :tx commit 或 :tx rollback',
    'tx.committed' => '事务已提交',
    'tx.rolled_back' => '事务已回滚',
    'tx.arm_rollback' => 'ROLLBACK 将丢弃 BEGIN 之后的全部更改——再次按下执行键以确认',
    'tx.failed' => '事务命令失败：{message}',
    'history.none' => '无可读取内容——状态历史仅记录 MySQL 系列会话（用 --history 启用）',
    'history.empty' => '最近 {minutes} 分钟内无样本——趋势至少需要两个',
    'history.unreadable' => '历史存储无法读取：{message}',
    'history.status' => '历史趋势——最近 {minutes} 分钟共 {samples} 行',
    'query.timed_out' => '异步查询在 {seconds} 秒后超时',
    'reports.err_meta_missing' => '未找到报表元数据文件：{path}',
    'reports.err_meta_read' => '读取报表元数据文件失败：{path}',
    'reports.err_not_in_catalog' => '目录中未找到报表：{name}',
    'reports.err_view_unavailable' => '此服务器上不可用的视图：{name}',
    'reports.err_loading' => '加载报表出错：{error}',
    'variables.err_meta_missing' => '未找到变量元数据文件：{path}',
    'variables.err_meta_read' => '读取变量元数据文件失败：{path}',
    'resilience.statement_timeout_default' => '语句执行超时',
    'resilience.statement_timeout_after' => '语句执行在 {seconds} 秒后超时',
    'variables.err_prepare' => '无法在此连接上准备语句',
    'variables.err_not_editable' => '变量不可编辑',
    'variables.err_persist_mariadb' => '持久化需要 MariaDB 11.1+ — 此服务器未实现 SET PERSIST。',
    'variables.err_persist_mysql' => '持久化需要 MySQL 8.0.16+ — 此服务器未实现 SET PERSIST。',
    'conn.err_empty_user_mysql' => 'ReactMysqlConnection：用户名为空 — 拒绝以猜测的 "root" 身份连接 MySQL；请显式指定用户',
    'conn.err_empty_user_pg' => 'ReactPostgresConnection：用户名为空 — 拒绝以猜测的 "postgres" 身份连接 PostgreSQL；请显式指定用户',
    'conn.err_sslmode_unrecognised' => 'ReactPostgresConnection：无法识别的 sslmode "{mode}"；应为以下之一：{allowed} — 静默丢弃拼写错误会降级为允许明文的 "prefer"',
    'store.err_read' => '无法读取连接文件：{path}（请删除它或修复其权限以恢复）',
    'store.err_json' => '连接文件不是有效的 JSON（{error}）：{path}',
    'store.err_shape' => '连接文件必须是包含 "connections" 映射的 JSON 对象：{path}',
    'store.err_connections_type' => '"connections" 的值必须是 名称 -> 设置 的 JSON 对象：{path}',
    'store.err_unknown' => '未知连接 \'{name}\' — 已保存的连接：{saved}',
    'store.none' => '（无）',
    'store.err_pass_env_unset' => '连接 \'{name}\' 从 ${var} 读取密码 — 请在连接前导出它',
    'store.err_mkdir' => '无法创建连接目录：{dir}',
    'store.err_write' => '无法写入连接文件：{path}',
    'store.err_replace' => '无法替换连接文件：{path}',
    'store.err_entry_type' => '连接 \'{name}\' 必须映射到设置的 JSON 对象',
    'store.err_plaintext_pass' => '连接 \'{name}\' 以明文存储了 \'pass\' — connections.json 从不保存机密：请改用 "pass-env": "VAR_NAME"',
    'store.err_unknown_setting' => '连接 \'{name}\' 含有未知设置 \'{field}\' — 允许的设置：{allowed}',
    'store.err_setting_type' => '连接 \'{name}\' 的设置 \'{field}\' 必须是字符串或数字',
    'store.err_setting_empty' => '连接 \'{name}\' 的设置 \'{field}\' 不能为空',
    'store.err_needs_driver' => '连接 \'{name}\' 需要 \'driver\'',
    'store.err_driver' => '连接 \'{name}\' 使用了不受支持的驱动 \'{driver}\' — 请选择：{allowed}',
    'store.err_port' => '连接 \'{name}\' 的设置 \'port\' 必须是数字',
    'store.err_pass_env' => '连接 \'{name}\' 的设置 \'pass-env\' 必须是环境变量名',
    'store.err_name' => '连接名 \'{name}\' 无效 — 请使用字母、数字、点、短横线或下划线（1-64 个字符）',
    'store.err_secure' => '无法保护 {path}（模式 {mode}）',
    'db.err_dsn_semicolon' => '数据库主机和名称不得包含 \';\' — 它是 PDO DSN 的选项分隔符',
    'db.err_unknown_pg_ssl' => '未知的 PostgreSQL ssl-mode：{mode}',
    'db.err_unknown_mysql_ssl' => '未知的 MySQL ssl-mode：{mode}',
    'db.err_pg_driver' => '无法使用 PostgreSQL 连接器连接非 PostgreSQL 驱动',
    'db.err_mysql_driver' => 'MySQL 连接器仅接受 mysql 驱动（收到 \'{driver}\'）',
    'db.err_reconnect' => '连接错误后重新连接失败：{error}',
    'db.err_quote_no_connection' => '没有连接无法加引号',
    'dml.err_key_required' => '需要行键：拒绝可能影响多行的语句',
    'dml.err_key_null' => '键列 {column} 为 NULL：无法定位该行',
    'dml.err_empty_table' => '表名不能为空',
    'dml.err_empty_column' => '列名不能为空',
    'dml.err_empty_key_column' => '键列名不能为空',
    'status.already_saved' => '已保存 ({count})',
    'status.not_in_favorites' => '不在收藏中 ({count})',
    'status.gtid_mariadb' => 'GTID_MODE 不可用：MariaDB 没有 gtid_mode 变量。',
    'status.gtid_version' => 'GTID_MODE 需要 MySQL 5.7.6 或更高版本（服务器为 {version}）。',
    'caption.blocks_hit' => '块命中',
    'caption.blocks_read' => '块读取',
    'caption.buffer_pool_read_reqs' => '缓冲池读请求',
    'caption.buffer_pool_usage' => '缓冲池使用率',
    'caption.buffer_pool_write_reqs' => '缓冲池写请求',
    'caption.bytes_in' => '入站字节',
    'caption.bytes_out' => '出站字节',
    'caption.cache_hit_rate' => '缓存命中率',
    'caption.commits' => '提交',
    'caption.connections' => '连接',
    'caption.deadlocks' => '死锁',
    'caption.disk_reads_not_from_pool' => '磁盘读取（非来自缓冲池）',
    'caption.disk_write_requests' => '磁盘写请求',
    'caption.doublewrite_writes' => '双写写入',
    'caption.innodb_disk_reads' => 'InnoDB 磁盘读取',
    'caption.innodb_disk_writes' => 'InnoDB 磁盘写入',
    'caption.insert_buffer' => '插入缓冲',
    'caption.pages_created' => '已创建页',
    'caption.pages_flushed' => '已刷新页',
    'caption.pages_read' => '已读页',
    'caption.read_ahead' => '预读',
    'caption.redo_log_bytes_written' => 'Redo 日志写入字节',
    'caption.redo_log_writes' => 'Redo 日志写入',
    'caption.rollbacks' => '回滚',
    'caption.row_lock_time' => '行锁时间',
    'caption.row_lock_waits' => '行锁等待',
    'caption.shared_buffers' => '共享缓冲区',
    'caption.sql_statements' => 'SQL 语句',
    'caption.table_open_cache' => '表打开缓存',
    'caption.temp_bytes' => '临时字节',
    'caption.temp_files' => '临时文件',
    'caption.transactions' => '事务',
    'caption.tuples_fetched' => '已获取元组',
    'caption.tuples_returned' => '已返回元组',
    'caption.traffic' => '流量',
    'caption.key_efficiency' => '键效率',
    'caption.selects_per_second' => '每秒 SELECT 数',
    'caption.innodb_buffer_usage' => 'InnoDB 缓冲使用率',
    'caption.innodb_reads_per_second' => 'InnoDB 每秒读取',
    'caption.innodb_writes_per_second' => 'InnoDB 每秒写入',
    'widget.tip.bytes_in' => '入站流量：共 {Bytes_received} 字节',
    'widget.tip.bytes_out' => '出站流量：共 {Bytes_sent} 字节',
    'widget.tip.client_connections' => '客户端连接：{Threads_connected}',
    'widget.tip.connections_level' => '连接水平与 max_connections 对比',
    'widget.tip.table_open_cache' => '表打开缓存命中率',
    'widget.tip.sql_statements' => 'SQL 语句速率：select / insert / update / delete / create / alter / drop',
    'widget.tip.buffer_pool_usage' => 'InnoDB 缓冲池使用百分比（基于字节）',
    'widget.tip.buffer_pool_read_reqs' => 'InnoDB 缓冲池每秒读请求',
    'widget.tip.buffer_pool_write_reqs' => 'InnoDB 缓冲池每秒写请求',
    'widget.tip.disk_reads_not_from_pool' => 'InnoDB 缓冲池每秒磁盘读取',
    'widget.tip.innodb_disk_writes' => 'InnoDB 每秒写入磁盘的数据字节',
    'widget.tip.disk_write_requests' => 'InnoDB 数据文件每秒写请求',
    'widget.tip.innodb_disk_reads' => 'InnoDB 每秒从磁盘读取的数据字节',
    'widget.tip.row_lock_waits' => 'InnoDB 每秒行锁等待',
    'widget.tip.row_lock_time' => 'InnoDB 每秒行锁时间（毫秒）',
    'widget.tip.pages_flushed' => 'InnoDB 每秒刷新页数',
    'widget.tip.pages_created' => 'InnoDB 每秒创建页数',
    'widget.tip.pages_read' => 'InnoDB 每秒读取页数',
    'widget.tip.insert_buffer' => 'InnoDB 插入缓冲大小（页）',
    'widget.tip.read_ahead' => 'InnoDB 缓冲池每秒预读',
    'widget.tip.redo_log_bytes_written' => 'InnoDB 每秒写入 Redo 日志字节',
    'widget.tip.redo_log_writes' => 'InnoDB 每秒 Redo 日志写入',
    'widget.tip.doublewrite_writes' => 'InnoDB 每秒双写写入',
    'widget.tip.alter_rate' => 'ALTER 命令速率（所有 Com_alter_*）',
    'widget.tip.create_rate' => 'CREATE 命令速率（所有 Com_create_*）',
    'widget.tip.drop_rate' => 'DROP 命令速率（所有 Com_drop_*）',
    'widget.tip.select_rate' => 'SELECT 速率',
    'widget.tip.insert_rate' => 'INSERT 速率',
    'widget.tip.update_rate' => 'UPDATE 速率',
    'widget.tip.delete_rate' => 'DELETE 速率',
    'widget.tip.tuples_fetched_total' => '从数据库获取的行：共 {tup_fetched}',
    'widget.tip.tuples_fetched_rate' => '行获取速率',
    'widget.tip.tuples_returned_total' => '返回给客户端的行：共 {tup_returned}',
    'widget.tip.tuples_returned_rate' => '行返回速率',
    'widget.tip.blocks_read_total' => '已读取的磁盘块：共 {blks_read}',
    'widget.tip.blocks_hit_total' => '缓冲缓存命中：共 {blks_hit}',
    'widget.tip.active_backends' => '活动后端：{numbackends}',
    'widget.tip.transaction_rates' => '事务速率：提交 / 回滚',
    'widget.tip.commit_rate' => '事务提交速率',
    'widget.tip.rollback_rate' => '事务回滚速率',
    'widget.tip.tuple_insert_rate' => '元组插入速率',
    'widget.tip.tuple_update_rate' => '元组更新速率',
    'widget.tip.tuple_delete_rate' => '元组删除速率',
    'widget.tip.deadlock_rate' => '死锁速率',
    'widget.tip.shared_buffers' => 'PostgreSQL shared_buffers 配置（缓冲池大小）',
    'widget.tip.cache_hit_rate' => '缓冲缓存命中率：blks_hit / (blks_hit + blks_read)',
    'widget.tip.temp_files' => '临时文件创建速率',
    'widget.tip.temp_bytes' => '每秒写入临时文件的字节数',
    'dash.data_written' => '数据写入 {kb} kB/s',
    'dash.writes_ops' => '写入 {n} 次/秒',
    'dash.log_written' => '日志写入 {kb} kB/s',
    'dash.read_reqs' => '读请求 {n} 页/秒',
    'dash.write_reqs' => '写请求 {n} 页/秒',
    'dash.disk_reads' => '磁盘读取 {n} /秒',
    'metrics.busy_proxy' => '繁忙代理 {pct}%',
    'metrics.cpu_load' => 'CPU 负载 {load}',
    'metrics.no_samples' => '暂无样本',
    'metrics.connections_count' => '{count} 个连接',
    'metrics.traffic_rate' => '{kb} kb/s',
    'metrics.server_metrics' => '服务器指标',
    'explain.query_block' => '查询块 #{id}（成本：{cost}）',
    'variables.footer_tree' => '[j/k] 树',
    'variables.footer_rows' => '[j/k] 行',
    'variables.footer_categories' => '[c] 类别',
    'variables.footer_edit' => '[Enter/e] 编辑（动态）',
    'variables.footer_rw_filter' => '[w] rw 过滤',
    'variables.footer_search' => '[s] 搜索',
    'variables.footer_back' => '[q] 返回',
    'srv.title' => '服务器状态',
    'srv.features' => '功能',
    'srv.stored_programs' => '存储程序',
    'srv.directories' => '目录',
    'srv.data_directory' => '数据目录',
    'srv.temp_directory' => '临时目录',
    'srv.log_directory' => '日志目录',
    'srv.pid_file' => 'PID 文件',
    'srv.tls_active' => 'TLS 已启用',
    'srv.tls_cipher' => 'TLS 加密套件',
    'srv.tls_version' => 'TLS 版本',
    'srv.ssl_support' => 'SSL 支持',
    'srv.ssl_ca' => 'SSL CA',
    'srv.ssl_cert' => 'SSL 证书',
    'srv.ssl_key' => 'SSL 密钥',
    'srv.tls_secure' => 'TLS / 安全连接',
    'srv.replication' => '复制',
    'srv.repl_denied' => '权限不足 (REPLICATION CLIENT)',
    'srv.repl_error' => '访问复制状态出错',
    'srv.not_configured' => '未配置',
    'srv.channel' => '通道',
    'srv.source_host' => '源主机',
    'srv.source_port' => '源端口',
    'srv.replica_io' => '副本 IO 运行中',
    'srv.replica_sql' => '副本 SQL 运行中',
    'srv.seconds_behind' => '落后源的秒数',
    'srv.relay_log_file' => '中继日志文件',
    'srv.relay_pos' => '中继位置',
    'srv.repl_channel_title' => '复制 › {channel}',
    'srv.default' => '默认',
    'srv.firewall' => '防火墙',
    'srv.mysql_firewall' => 'MySQL 防火墙',
    'srv.caught_up' => '0 秒（已追上）',
    'srv.host' => '主机',
    'srv.socket' => '套接字',
    'srv.port' => '端口',
    'srv.version' => '版本',
    'srv.uptime' => '运行时间',
    'srv.running_since' => '运行起始',
    'srv.unknown' => '未知',
    'srv.server_information' => '服务器信息',
    'perf.tab_easy_setup' => '简易设置',
    'perf.tab_instruments' => '采集点',
    'perf.tab_consumers' => '消费者',
    'perf.tab_actors' => '参与者',
    'perf.tab_objects' => '对象',
    'perf.tab_threads' => '线程',
    'perf.tab_options' => '选项',
    'perf.title' => 'Performance Schema',
    'perf.read_only' => '[只读]',
    'perf.nothing_to_commit' => '没有可提交的内容',
    'perf.commit_planning_failed' => '提交规划失败：{error}',
    'perf.err_prepare' => '准备语句失败：{sql}',
    'perf.committed_one' => '已提交 {count} 条语句',
    'perf.committed_other' => '已提交 {count} 条语句',
    'perf.applied_one' => '已应用 {label} — {count} 条语句',
    'perf.applied_other' => '已应用 {label} — {count} 条语句',
    'perf.commit_failed' => '{label} 在第 {position}/{total} 条语句失败：{error} — 之前的语句已应用，服务器状态不完整。',
    'perf.err_access_denied' => '访问被拒绝：缺少所需权限',
    'perf.err_not_enabled' => 'Performance Schema 未启用',
    'perf.err_cannot_connect' => '无法连接到数据库',
    'perf.state_fully' => '完全启用',
    'perf.state_default' => '默认设置',
    'perf.state_custom' => '自定义设置',
    'perf.state_disabled' => '已禁用',
    'perf.state_unknown' => '未知',
    'perf.current_state' => '当前状态：{state}',
    'perf.readonly_note' => '（只读模式 - 无修改权限）',
    'perf.keys_hint' => '按键 "[" 完全启用 Performance Schema   按键 "]" 禁用   按键 "." 重置为默认值',
    'perf.default_instruments' => '默认采集点：stage/%, statement/%, wait/%',
    'perf.default_consumers' => '默认消费者：events_statements_history、events_waits_history 等',
    'perf.none_instruments' => '（无可用采集点）',
    'perf.none_consumers' => '（无可用消费者）',
    'perf.none_actors' => '（未配置参与者）',
    'perf.none_objects' => '（未配置对象规则）',
    'perf.none_threads' => '（无可用线程）',
    'perf.total_instruments_one' => '共 {count} 个采集点',
    'perf.total_instruments_other' => '共 {count} 个采集点',
    'perf.total_consumers_one' => '共 {count} 个消费者',
    'perf.total_consumers_other' => '共 {count} 个消费者',
    'perf.total_actors_one' => '共 {count} 个参与者',
    'perf.total_actors_other' => '共 {count} 个参与者',
    'perf.total_objects_one' => '共 {count} 条对象规则',
    'perf.total_objects_other' => '共 {count} 条对象规则',
    'perf.total_threads_one' => '共 {count} 个线程',
    'perf.total_threads_other' => '共 {count} 个线程',
    'perf.col_thread_id' => '线程 ID',
    'perf.col_name' => '名称',
    'perf.col_type' => '类型',
    'perf.col_user' => '用户',
    'perf.timer_options' => '计时器选项',
    'perf.none_timers' => '（无可用计时器 - 只读）',
    'perf.col_timer_name' => '计时器名称',
    'perf.col_implementation' => '实现',
    'perf.col_scale_factor' => '比例因子',
    'perf.timer_readonly_note' => '计时器配置为只读（由服务器构建决定）',
    'perf.footer_nav' => '[j/k] 导航  [Space] 切换',
    'perf.footer_actions' => '  [c] 暂存提交（[y] 执行）  [r] 还原',
    'perf.footer_back' => '  [q] 返回',
    'perf.pending_one' => '待处理：{count} 项更改',
    'perf.pending_other' => '待处理：{count} 项更改',
    'cli.err_not_tty' => '此启动器驱动交互式终端，拒绝向管道输出 ANSI（退出码 2）——请在 pty 中运行（例如 script -qec \'candy-sql app.sqlite\' /dev/null），或从库代码以无界面方式渲染（ResultTable::renderPlain()）。',
    'cli.history_disabled' => '历史记录已禁用（{path}）：{error}',
    'cli.tls_sync' => 'ssl-mode \'{mode}\' 要求经过验证的 TLS——ReactPHP 管理传输无法满足，因此管理实时刷新已禁用，查询和表加载将通过 TLS 连接同步运行。',
    'cli.no_connection_selected' => '未选择连接——请传入路径、DSN 或 --connect=<name>',
    'cli.usage_line' => '用法：candy-sql [OPTIONS] [path-or-dsn]',
    'cli.usage_help_line' => '       candy-sql --help   # 查看完整用法信息',
    'cli.tip_saved_connections' => '       提示：将命名连接保存在 {path} 中，并使用 --connect=<name> 启动。',
    'help.summary' => 'candy-sql - 适用于 SQLite、MySQL 和 PostgreSQL 的终端 SQL 客户端',
    'help.usage' => '用法
  candy-sql [OPTIONS] [path-or-dsn]',
    'help.arguments' => '参数
  path-or-dsn
    SQLite 数据库文件路径，例如：app.sqlite
    或 DSN 字符串，例如：  mysql://user:pass@localhost:3306/dbname

  完全省略时：在有已保存连接的交互式终端中，选择器会列出它们；
  否则打印一行简短用法并以状态 2（调用错误）退出——运行 --help
  可查看此完整屏幕。',
    'help.options' => '选项
  --help, -h
    显示此帮助屏幕。

  --version, -V
    显示版本号。

  --connect=<name>
    拨入已保存连接文件中指定名称的条目
    ($XDG_CONFIG_HOME/candy-sql/connections.json，默认为
    $HOME/.config/candy-sql/connections.json)。不能与显式连接参数
    同时使用。密码从不存储在磁盘上：条目通过 "pass-env" 指明其密钥
    变量，连接时才读取该变量。参见下方的已保存连接（SAVED CONNECTIONS）。',
    'help.option_dsn' => '  --dsn=<dsn>
    完整的 DSN 连接字符串。
    格式： <driver>://<user>:<pass>@<host>:<port>/<dbname>[?ssl-mode=<mode>]
    示例：
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
    各个连接参数（除 --driver 和 --db 外均为可选）。
    --driver 必须是以下之一：sqlite、mysql、pgsql
    MySQL 的 --ssl-mode 默认为 "prefer"。"require" 和 "verify_*" 会强制
    使用带服务器证书校验的 TLS；CA 信任必须在服务器端或通过 my.cnf
    配置（见 README）。在这些校验模式下，允许明文的 ReactPHP 传输会被
    拒绝——查询通过 TLS 连接同步运行，管理页面的实时刷新被禁用
    （启动提示和应用状态栏都会说明）。
    建议使用 CANDY_SQL_PASS 而非 --pass=，以免密码出现在进程命令行
    （ps 可见）中。',
    'help.examples' => '示例
  # SQLite（默认——纯路径或 DSN）
  candy-sql app.sqlite
  candy-sql sqlite:///var/data/app.sqlite
  candy-sql sqlite://:memory:

  # 通过 DSN 连接 MySQL
  candy-sql mysql://root:secret@localhost:3306/mydb
  candy-sql --dsn=mysql://root:secret@localhost:3306/mydb

  # 通过单独标志连接 MySQL
  CANDY_SQL_PASS=secret candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

  # PostgreSQL
  candy-sql pgsql://postgres:pass@db.example.com:5432/mydb
  candy-sql --driver=pgsql --host=db.example.com --port=5432 --user=postgres --pass=pass --db=mydb',
    'help.saved_intro' => '已保存连接
  只需列出一次命名服务器，之后即可随时拨入：

    $HOME/.config/candy-sql/connections.json  （目录 0700，文件 0600）',
    'help.saved_fields' => '  字段：driver (sqlite|mysql|pgsql)、host、port、user、db、ssl-mode、
  pass-env。其他任何字段都会被拒绝，明文 "pass" 字段始终被拒绝——
  请改用 pass-env 指向一个变量。在交互式终端中无参数启动时，这些条目
  会以选择器形式呈现；--connect=<name> 可直接拨入其中一个。',
    'help.editing' => '编辑
  TABLE 预览的 Rows 窗格是可编辑网格：h/l（或 ←/→）移动单元格光标，
  三个组合键可修改数据——每个都会在状态栏显示确切的语句，在按 Enter
  确认之前不会写入任何内容：

    e   编辑光标所在的单元格（Ctrl+N 设为 NULL）
    o   插入一行（Enter 逐列前进；空输入让该列取其 DEFAULT，
        Ctrl+N 存为 NULL）
    D   删除光标所在的行

  行只能通过完整的 PRIMARY KEY 定位：没有主键的表、NULL/二进制键、
  二进制单元格以及任何查询结果都会被拒绝，并给出具名状态行。值是绑定
  参数；每次写入都在事务中运行，除非恰好只影响一行，否则回滚（写入
  已有相同值的 UPDATE 会报告为无变化）。Esc 取消已打开的编辑行。',
    'help.editing_readonly' => '  --read-only
    在本次会话中关闭编辑组合键。这可防止误操作；它不会过滤 Query 窗格中
    的语句——如需硬性保证，请以只读数据库用户身份连接。',
    'help.history' => '历史记录
  默认情况下，每个管理轮询快照都会记录到 $HOME/.candy-sql/history.db
  （目录 0700 / 文件 0600），作为离线审计轨迹。应用内的 Dashboard 和
  Reports 页面不读取此文件——它们轮询实时服务器。',
    'help.history_flags' => '  --no-history
    完全禁用记录（不向磁盘写入任何内容）。

  --history=<path>
    将快照存储到 <path>，而不是默认位置。',
    'help.keyboard' => '键盘快捷键 (TUI)
  Tab          在窗格之间循环切换焦点（Tables → Rows → Query → Admin）
  ↑ / ↓        浏览列表行（或 j/k）——在 Tables 窗格中移动仅限本地；
               从不获取预览
  /            （Tables 窗格）打开表名的实时过滤行；字母会延长它，↑/↓ 移动光标，
               Enter 确认并加载，Backspace 编辑，Esc 放弃并清除
  Enter        加载所选表的行
  Space        加载所选表的行（Enter 的别名）
  PgDn / PgUp  （Tables/Rows 窗格，在表预览上）以 100 行为窗口分页预览——
               浏览上限已变为页大小
  Ctrl+Enter   执行当前查询
  Ctrl+R       执行当前查询
  Ctrl+E       执行当前查询
  EXPLAIN …    （Query 编辑器，开头关键字）键入的 EXPLAIN 经由 ExplainView 运行：
               Rows 窗格显示彩色编码的计划树，而不是扁平的提供者网格——并且任何
               ANALYZE 修饰符都会被去除，检查器绝不执行它所检查的内容
  e / o / D    （Rows 窗格，表预览）编辑单元格 / 插入行 /
               删除行——参见编辑（EDITING）；h/l 移动单元格光标
  Ctrl+Space   在光标处补全（Query 编辑器）：SQL 关键字、FROM/JOIN/INTO/UPDATE
               之后的表名，以及依上下文的列（WHERE/SELECT/ORDER BY、`alias.` /
               `table.` 限定、INSERT INTO t (...) 列表），均来自已加载的架构。
               只有一个候选时直接补全，共同前缀则前移，否则状态栏列出候选
  Ctrl+F       将当前查询加入收藏列表（仅会话状态——没有窗格浏览它，也不会持久化）
  Ctrl+Shift+F 将当前查询从收藏列表中移除
  1-8          跳转到某个管理窗格（仅当 Admin 窗格获得焦点时）
  q            从 Tables/Rows 窗格退出；在 Admin 中则返回 Tables
  x            （Tables/Rows，实时结果）将当前行导出到 CSV 路径——
               在按 Enter 确认之前，拒绝覆盖保持生效
  i            （Tables/Rows）将 CSV 文件导入到指定名称的表
  Ctrl+S       从任意窗格将实时结果/预览导出为 CSV
  Esc          从 Tables/Rows 窗格退出；取消已打开的 Admin 对话框
  Backspace    删除光标前的字符（Query 编辑器）',
    'help.see_also' => '另请参阅
  https://github.com/sugarcraft/candy-sql',
];
