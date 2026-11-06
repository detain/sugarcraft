<?php

/**
 * Arabic translations for candy-git-server.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'user.invalid_ssh_key'     => 'تنسيق مفتاح SSH العام غير صالح',
    'repo.create_dir_failed'   => 'فشل إنشاء دليل المستودع: {path}',
    'repo.git_init_failed'     => 'فشل git init: {output}',
    'repo.visibility_marker_unwritable' => 'فشل كتابة مؤشر الرؤية: {file}',
    'config.not_found'         => 'ملف التكوين غير موجود: {path}',
    'config.read_failed'       => 'فشل قراءة التكوين: {path}',
    'ssh.user_cannot_create'   => 'المستخدم {viewer} لا يمكنه إنشاء مستودعات',
    'ssh.invalid_repo_name'    => 'اسم مستودع غير صالح: {name}',
    'cli.banner'               => 'CandyGitServer v{version}',
    'cli.repos_header'         => 'المستودعات:',
    'cli.repo_listing_plain'   => '  {name}',
    'cli.repo_listing_public'  => '  {name} (عام)',
    'cli.already_initialized'  => 'مهيأ بالفعل: {path}',
    'cli.initializing'         => 'جاري تهيئة دليل بيانات CandyGitServer: {path}',
    'cli.done'                 => 'تم.',
    'cli.next_steps'           => 'الخطوات التالية:',
    'cli.next_step_1'          => '  1. أنشئ حسابًا: candy-git-server user add admin {path}',

    'cli.next_step_2'          => '  2. أنشئ مستودعًا: candy-git-server repo create <اسم> {path} (أضف --public للنشر للقراءة المجهولة)',

    'cli.next_step_3'          => '  3. قدّم عبر smart HTTP: candy-git-server serve --http --repo-root {path}/repositories',
    'cli.usage_user_root'      => 'الاستخدام: candy-git-server user add|key|list|token',
    'cli.usage_user_add'       => 'الاستخدام: candy-git-server user add <username> [data-path] [--password <pw>|-]',
    'cli.usage_user_token' => 'Usage: candy-git-server user token <username> [ttl-seconds] [data-path] إنشاء توقيع رمز الوكيل HTTP',
    'cli.usage_user_key'       => 'الاستخدام: candy-git-server user key <username> [key-file]',
    'cli.user_created'         => "تم إنشاء المستخدم '{username}' (admin: true)",
    'cli.user_password_stored' => 'تم حفظ كلمة المرور بصيغة تجزئة.',
    'cli.user_key_hint'        => "استخدم 'candy-git-server user key {username} <key-file|->' لإضافة مفتاح SSH العام.",
    'cli.user_key_read_failed' => 'فشل قراءة المفتاح من: {file}',
    'cli.user_key_added'       => "تمت إضافة المفتاح للمستخدم '{username}'",
    'cli.user_keys_header'     => 'المفاتيح المصرح بها:',
    'cli.user_key_entry'       => '  {prefix}...',
    'cli.user_list_empty'      => "المستخدمون:\n  (لا يوجد مستخدمون مسجلون. استخدم 'candy-git-server user add <username>')",
    'cli.user_list_header' => "المستخدمون ({path}):",
    'cli.usage_repo_root'      => 'الاستخدام: candy-git-server repo list|create|info',
    'cli.usage_repo_create'    => 'الاستخدام: candy-git-server repo create <name>',
    'cli.usage_repo_info'      => 'الاستخدام: candy-git-server repo info <name>',
    'cli.no_repos'             => 'لا توجد مستودعات حتى الآن.',
    'cli.repo_listing_none'    => '  (لا توجد مستودعات حتى الآن)',
    'cli.repo_invalid_name'    => 'اسم مستودع غير صالح: {name} (استخدم alphanumeric و punto و underscore و hyphen فقط, يجب ألا يبدأ بنقطة أو شرطة)',
    'cli.repo_created'         => "تم إنشاء المستودع '{name}' في {path}",
    'cli.repo_not_found'       => 'المستودع غير موجود: {name}',
    'cli.repo_info'            => "الاسم:         {name}\nالوصف:  {description}\nعام:       {is_public}\nخاص:      {is_private}\nالسماح بالدفع:   {allow_push}\nالمسار:         {path}\nالفروع:     {branches}\nالعلامات:         {tags}",
    'cli.bool_yes'             => 'نعم',
    'cli.bool_no'              => 'لا',
    'cli.none_value'           => '(لا شيء)',
    'cli.unknown_command'      => "أمر غير معروف: {cmd}\n  شغّل 'candy-git-server help' للمساعدة.",
    'cli.help'                 => "CandyGitServer — خادم Git ذاتي الاستضافة\n\nالاستخدام:\n  candy-git-server <أمر> [خيارات]\n\nالأوامر:\n  serve [--config path]    بدء خادم Git\n  init [data-path]         تهيئة دليل بيانات جديد\n  user add <username>      إنشاء مستخدم\n  user key <username> <file>  إضافة مفتاح SSH عام للمستخدم (استخدم - لـ stdin)\n  user list                سرد المستخدمين\n  repo list [data-path]    سرد المستودعات\n  repo create <name> [data-path]  إنشاء مستودع\n  repo info <name> [data-path]    عرض معلومات المستودع\n  help, --help, -h         عرض هذه المساعدة\n  version, --version, -v   عرض الإصدار\n\nمتغيرات البيئة:\n  CANDY_GIT_SERVER_DATA_PATH    دليل البيانات (الافتراضي: /tmp/candy-git-server)\n\nالأمثلة:\n  candy-git-server init ~/candy-git-server-data\n  candy-git-server serve --config ~/candy-git-server-data/config.yaml\n  candy-git-server repo create my-project\n  candy-git-server user key alice ~/.ssh/id_ed25519.pub",
    'user.ssh_key_too_short' => 'نواة المفتاح العام SSH قصيرة جدًا لـ {type}: {len} < {min} حرف base64',
    'config.parse_failed'   => 'تهيئة YAML غير صالحة: {error}',
    'config.invalid_user_trust_mode' => 'قيمة http.user_trust_mode "{mode}" غير صالحة: المتوقع off أو proxy أو token',
    'config.tls_not_supported' => 'تم ضبط http.tls_key_path/tls_cert_path لكن لم يُوصل أي مستمع HTTPS بعد؛ ضبطهما لا يُفعّل TLS. أزلها حتى يُدعم TLS.',
    'jobs.invalid_schedule' => 'جدولة مهمة غير صالحة "{schedule}": {reason}',
    'cli.option_needs_value' => 'الخيار {option} يتطلب قيمة',
    'cli.serve_http_needs_repo_root' => 'serve --http يتطلب --repo-root DIR (الدليل الذي تُقدَّم مؤشرراته الفرعية)',
    'cli.serve_repo_root_not_dir' => '--repo-root ليس دليلًا: {path}',
    'cli.serve_http_port_range' => '--http-port خارج المدى: {port}',
    'cli.serve_http_port_not_a_number' => 'serve --http: يجب أن يكون --http-port رقمًا، وقد ورد: {port}',
    'cli.serve_http_flag_unsupported' => 'الخيار {flag} غير مدعوم في serve --http (المستمع يعمل في المقدمة؛ استخدم مدير عمليات للتحول إلى خدمة خلفية).',
    'cli.serve_http_unknown_flag' => 'خيار غير معروف في serve --http: {flag}',
    'cli.port_deprecated'      => 'ملاحظة: --port مهجور؛ استخدم --http-port بدلاً منه (إبقاء الاسم المستعار للتوافق).',
    'cli.serve_http_banner' => 'مستمع smart-HTTP على {addr} ({repos} مستودعات، {published} منشورًا، {users} حسابات؛ القراءة المجهولة={anon}؛ الدفع مُقدَّم أيضًا — تُنقل رفض الخطافات داخل النطاق)',
];
