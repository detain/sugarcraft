<?php

/**
 * Arabic translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot: خيار غير معروف '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot: سمة غير معروفة '{name}'. المعروفة: dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot: نمط نافذة غير معروف '{style}'. المعروفة: macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot: صيغة غير معروفة '{format}'. المعروفة: svg, png",
    'cli.gd_required'         => 'candy-codeshot: امتداد gd مطلوب لإخراج PNG',
    'cli.font_not_found'      => "candy-codeshot: لم يتم العثور على ملف الخط: '{path}'",
    'cli.font_invalid'        => "candy-codeshot: '{path}' ليس ملف خط صالح (المتوقع TTF أو OTF أو WOFF أو WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot: صيغة تظليل غير صالحة '{format}'. استخدم البداية:النهاية (مثل 3:7) أو البداية:النهاية:#اللون (مثل 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot: تعذر قراءة الإدخال',
    'cli.path_outside_cwd'    => "candy-codeshot: المسار '{path}' يقع خارج مجلد العمل الحالي (استخدم --unsafe-paths للسماح)",
    'cli.write_failed'        => "candy-codeshot: تعذر الكتابة إلى '{path}'",
    'cli.missing_value'       => "candy-codeshot: {option} يتطلب قيمة",
    'cli.bad_number'          => "candy-codeshot: {option} يتوقع عددًا صحيحًا غير سالب، وقد وصل '{value}'",
    'cli.png_ignores'         => "candy-codeshot: إخراج PNG يتجاهل خيارات SVG فقط: {options} (استخدم --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot: رفض التظليل '{spec}': {reason}",
    'cli.format_mismatch'     => "candy-codeshot: صيغة الإخراج '{format}' لا تطابق امتداد '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - يعرض الشفرة أو مخرجات الطرفية كلقطة SVG أو PNG

طريقة الاستخدام:
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

قيم الخيارات هي وسائط منفصلة: "--theme dracula" وليس "--theme=dracula"
(صيغة "=" تُرفض كخيار غير معروف).

الخيارات:
  --theme <name>          اسم السمة: dark, light, dracula, tokyo-night, nord (الافتراضي: dark)
  --padding <n>           الحشوة حول المحتوى (الافتراضي: 24)
  --no-window             إخفاء زخرفة النافذة (أضواء المرور، شريط العنوان)
  --no-shadow             تعطيل الظل
  --no-border             إخفاء الإطار
  --line-numbers          إظهار أرقام الأسطر
  --border-radius <n>     نصف قطر الزوايا (الافتراضي: 8)
  --window-style <style>  نمط النافذة: macos, windows-terminal, iterm, hyper, none (الافتراضي: macos)
  --ligatures             تفعيل الوصلات الخطية (font-variant-ligatures: normal؛ SVG فقط)
  --font <path>           مسار ملف خط TTF/OTF/WOFF/WOFF2 لتضمينه في SVG (SVG فقط)
  --unsafe-paths          السماح بمسارات الإدخال/--font/--output خارج مجلد العمل
  --highlight <start:end[:color]>
                          تظليل الأسطر start:end بلون اختياري (اللون الافتراضي: #fffbe6؛ SVG فقط)
  --format <svg|png>      صيغة الإخراج (الافتراضي: svg؛ png تتطلب ext-gd)
  -t, --type <lang>       فرض لغة تظليل الصيغة (الافتراضي: اسم الملف، shebang، المحتوى)
  -o, --output <path>     كتابة الإخراج إلى ملف بدلًا من stdout
  -h, --help              عرض رسالة المساعدة هذه

ملاحظات:
  يجب أن تبقى مسارات الإدخال والإخراج داخل مجلد العمل الحالي ما لم تُعطَ
  --unsafe-paths. إخراج PNG يتجاهل خيارات SVG فقط أعلاه ويعلن عن كل خيار متروك
  على stderr.

رموز الخروج:
  0  نجاح
  1  autoload المفقود من composer (مشكلة تثبيت)
  2  خطأ في الاستخدام أو الإدخال (خيار غير صالح، مسار غير قابل للقراءة، تظليل مرفوض، ...)

أمثلة:
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
