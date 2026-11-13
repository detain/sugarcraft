<?php

/**
 * Arabic translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => "فشل تنفيذ \u{2066}{bin}\u{2069}",
    'launcher.transport_not_executable' => "ثنائي النقل غير موجود أو غير قابل للتنفيذ: \u{2066}{bin}\u{2069}",
    'launcher.transport_dropped' => "candy-ssh-launcher: نقطة النهاية \u{2066}{endpoint}\u{2069}: النقل المخصص يتجاهل {legs}",
    'config.not_found'         => "تكوين wishlist غير موجود: \u{2066}{path}\u{2069}",
    'config.json_top_level'    => 'wishlist json: القيمة عالية المستوى يجب أن تكون مصفوفة',
    'config.json_entry_object' => 'wishlist json: كل إدخال يجب أن يكون كائنًا',
    'config.yaml_continuation' => "wishlist yaml: يوجد سطر متابعة قبل أي كتلة \u{2066}'- name:'\u{2069}",
    'config.yaml_unparseable'  => "wishlist yaml: سطر غير قابل للتحليل {line}: \u{2066}{found}\u{2069}",
    'config.yaml_block_mapping' => "wishlist yaml: تعيين كتلة تحت `\u{2066}{field}\u{2069}:` في السطر {line}: \u{2066}{found}\u{2069} — يجب أن يكون هذا الحقل قائمة عناصر `\u{2066}- value\u{2069}`",
    'config.yaml_unterminated_quote' => "wishlist yaml: علامة اقتباس غير مغلقة في القيمة: \u{2066}{found}\u{2069}",
    'config.entry_missing_field' => "عنصر wishlist رقم {index}: يفتقد حقلاً إلزامياً: \u{2066}{field}\u{2069}",
    'config.unknown_key' => "تم تجاهل الحقل غير المعروف `\u{2066}{key}\u{2069}` — ليس مفتاحاً موثقاً",
    'config.unknown_key_nearmiss' => "تم تجاهل الحقل غير المعروف `\u{2066}{key}\u{2069}` — هل قصدت `\u{2066}{field}\u{2069}`؟",
    'config.duplicate_name' => "اسم نقطة اتصال مكرر `\u{2066}{name}\u{2069}` — تُحمَّل كل الصفوف؛ الأسماء معرِّفات",
    'config.field_not_scalar'  => "إدخال wishlist: الحقل [\u{2066}{field}\u{2069}] يجب أن يكون قياسياً، والنوع \u{2066}{type}\u{2069}",
    'config.too_large'          => "ملف قائمة الأمنيات أكبر من حد {limit} بايت: \u{2066}{path}\u{2069}",
    'config.list_too_long'      => "إدخال قائمة الأمنيات: الحقل [\u{2066}{field}\u{2069}] يحمل أكثر من {limit} عنصرًا يسمح به المحمل",
    'config.field_not_list'       => "‏إدخال قائمة الأمنيات: الحقل [\u{2066}{field}\u{2069}] يجب أن يكون قائمة قيم لا خريطة مفاتيح",
    'config.encoding_utf16'       => "\u{2066}{path}\u{2069} ترميزه UTF-16 — أعد حفظ الملف بترميز UTF-8",
    'config.unreadable'       => "تعذر قراءة تكوين wishlist: \u{2066}{path}\u{2069}",
    'config.home_unset'       => "لا يمكن توسيع \u{2066}~\u{2069} بدون \u{2066}\$HOME\u{2069}: \u{2066}{path}\u{2069}",
    'config.yaml_inline_flow' => "wishlist yaml: مجموعة التدفق المضمّنة غير مدعومة: \u{2066}{value}\u{2069} (استخدم قائمة متداخلة)",

    // endpoint security
    'endpoint.option_injection' => "نقطة نهاية wishlist: الحقل [\u{2066}{field}\u{2069}] يحتوي قيمة غير آمنة تبدأ بشرطة [\u{2066}{value}\u{2069}]",
    'endpoint.host_empty' => "النقطة `\u{2066}{name}\u{2069}` تحتوي مضيفًا فارغًا — يحتاج الصف القابل للاختيار إلى اسم مضيف",
    'endpoint.name_empty' => 'اسم نقطة الاتصال فارغ — الصف القابل للتحديد يحتاج اسماً',
    'endpoint.option_empty' => "النقطة `\u{2066}{name}\u{2069}` تحتوي مدخل خيار فارغًا — قيمة `\u{2066}-o\u{2069}` الفارغة مرفوضة",
    'endpoint.port_invalid'     => "نقطة نهاية wishlist [\u{2066}{host}\u{2069}]: منفذ غير صالح [\u{2066}{port}\u{2069}] — يجب أن يكون عدداً صحيحاً من 1 إلى 65535",

    'cli.usage'         => "الاستخدام: \u{2066}candy-ssh-launcher [--config <مسار>] [--ssh <ssh-ثنائي>] [--list] [-h|--help] [-v|--version]\u{2069}",
    'cli.unknown_arg'   => "candy-ssh-launcher: وسيطة غير معروفة: \u{2066}{arg}\u{2069}",
    'cli.no_config'     => "candy-ssh-launcher: لم يتم العثور على تكوين. أنشئ \u{2066}~/.config/wishlist.yml\u{2069} (راجع البدء السريع في README) أو مرر \u{2066}--config <مسار>\u{2069}.",
    'cli.error'         => "candy-ssh-launcher: \u{2066}{message}\u{2069}",
    'cli.cancelled'     => 'candy-ssh-launcher: تم الإلغاء.',
    'cli.ssh_not_executable' => "candy-ssh-launcher: ثنائي ssh غير موجود أو غير قابل للتنفيذ: \u{2066}{bin}\u{2069} — مرّر \u{2066}--ssh <مسار>\u{2069} لتحديد ثنائي ssh",
    'cli.missing_value'  => "candy-ssh-launcher: \u{2066}{arg}\u{2069} يتطلب قيمة",
    'cli.no_endpoints'   => "candy-ssh-launcher: لا توجد نقاط نهاية في \u{2066}{path}\u{2069}",
    'cli.not_a_terminal' => 'يحتاج المحدد إلى طرفية تفاعلية؛ يجب أن يكون stdin وstdout من نوع TTY. استخدم --list للحصول على مخرج قابل للقراءة آليًا.',
    'cli.config_unowned'   => "candy-ssh-launcher: رفض تكوين يملكه مستخدم آخر: \u{2066}{path}\u{2069} — التكوينات مصدر موثوق؛ انظر Security في README.",
    'cli.config_writable'  => "candy-ssh-launcher: رفض تكوين قابل للكتابة من المجموعة أو العالم: \u{2066}{path}\u{2069} — شدد صلاحياته إلى 0644 أو 0600 أو مرره صراحة عبر \u{2066}--config\u{2069}.",
    'cli.project_local'    => "candy-ssh-launcher: يُستخدم wishlist المحلي للمشروع: \u{2066}{path}\u{2069}",
    'cli.unsafe_option_notice' => "candy-ssh-launcher: نقطة النهاية تطلب -o \u{2066}{option}\u{2069} — \u{2066}{effect}\u{2069}",
    'cli.unsafe_option_effect_strict_host_key_checking' => "يتم تخفيض التحقق من مفتاح المضيف — قد يبقى مفتاح الخادم دون تحقق",
    'cli.unsafe_option_effect_user_known_hosts_file' => "يتم تحويل قاعدة بيانات ثقة المضيفين المعروفين للمستخدم",
    'cli.unsafe_option_effect_global_known_hosts_file' => "يتم تحويل قاعدة بيانات الثقة على مستوى النظام",
    'cli.unsafe_option_effect_log_level' => "يتم إخفاء تحذيرات ssh وإشعاراتها",
    'cli.unsafe_option_effect_identity_agent' => "يتم تفويض المصادقة إلى مقبس وكيل آخر",
    'cli.unsafe_option_effect_certificate_file' => "يتم تقديم شهادة مصادقة إضافية",
    'cli.ssh_config_source'  => "candy-ssh-launcher: يتم استيراد نقاط النهاية من تكوين ssh: \u{2066}{path}\u{2069}",
    'cli.ssh_config_missing' => "candy-ssh-launcher: لا يوجد تكوين ssh في \u{2066}{path}\u{2069} — مرّر \u{2066}--config <path>\u{2069} مع \u{2066}--from-ssh-config\u{2069} لتسمية ملف آخر",
    'cli.config_is_ssh_config' => "candy-ssh-launcher: يبدو \u{2066}{path}\u{2069} تكوين OpenSSH وليس ملف wishlist — استورده عبر \u{2066}--from-ssh-config\u{2069} (اختياريا مع \u{2066}--config <path>\u{2069})",

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'تصفية: ',
    'picker.no_matches'  => '  (لا تطابقات — Backspace أو Esc)',
    'picker.help'        => '↑/↓ تحديد · Enter اتصال · Esc خروج · اكتب للتصفية',
    'picker.help_keys'   => '⌫ حذف · Ctrl+U/W مسح · PgUp/PgDn صفحة · Home/End انتقال',
    'picker.count'       => '  ({shown} من {total})',
];
