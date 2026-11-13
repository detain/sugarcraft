<?php

/**
 * Arabic translations for sugar-charts.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'braillegrid.dim_positive' => 'أعمدة/صفوف BrailleGrid يجب أن تكون > 0',
    'sparkline.width_nonneg'   => 'عرض خط الرسم يجب أن يكون >= 0',
    'barchart.dim_nonneg'      => 'عرض/ارتفاع الرسم البياني الشريطي يجب أن يكون >= 0',
    'barchart.bar_width_min'   => 'barWidth يجب أن يكون >= 1',
    'barchart.bar_gap_nonneg'  => 'barGap يجب أن يكون >= 0',
    'barchart.color_return'   => 'يجب أن تُرجع دالة ألوان الرسم البياني الشريطي {expected} أو null، تم تمرير {given}',
    'heatmap.dim_nonneg'       => 'عرض/ارتفاع خريطة الحرارة يجب أن يكون >= 0',
    'heatmap.coords_nonneg'    => 'إحداثيات نقطة الحرارة يجب أن تكون >= 0',
    'heatmap.palette_min'      => 'لوحة الألوان تتطلب لونين على الأقل (أو فارغة للتعطيل)',
    'linechart.dim_nonneg'     => 'عرض/ارتفاع الرسم البياني الخطي يجب أن يكون >= 0',
    'linechart.color_return'  => 'يجب أن تُرجع دالة ألوان سلاسل الرسم البياني الخطي {expected} أو null، تم تمرير {given}',
    'waveline.dim_nonneg'      => 'عرض/ارتفاع خط الموجة يجب أن يكون >= 0',
    'ohlc.dim_nonneg'          => 'عرض/ارتفاع مخطط OHLC يجب أن يكون >= 0',
    'scatter.dim_nonneg'       => 'عرض/ارتفاع الرسم البياني المبعثر يجب أن يكون >= 0',
    'chart.dim_nonneg'         => 'يجب أن يكون عرض/ارتفاع الرسم >= 0',
    'finite.non_finite'        => 'يجب أن تكون قيمة الرسم نهائية، وقد حصل على {value}',
// Support/Rune.php
    'rune.empty'               => 'يجب أن يكون {name} خانة واحدة مرئية، لا فارغة',
    'rune.width_one'           => 'يجب أن يشغل {name} خانة واحدة بالضبط، تم تقديم {cells} خانات',

    // LineChart/TimeSeries.php
    'timeseries.point_shape'   => 'يجب أن تكون نقاط TimeSeries أزواجًا [DateTimeImmutable، رقمي]، تم تقديم {given}',

    // OHLC/OHLCChart.php
    'ohlc.bar_type'            => 'يجب أن تحتوي قائمة أعمدة OHLC على نسخ Bar فقط، تم تقديم {given}',
// Chart/Chart.php + ChartExtras compositor doors
    'chart.title_side'         => 'عناوين {side} لا مكان لها في التركيب الأفقي؛ استخدم Position::Top أو Position::Bottom',

    // LineChart/LineChart.php
    'chart.datalabels_unsupported' => 'لا يمكن لـ LineChart طباعة تسميات القيم؛ استخدم BarChart::withDataLabels() أو اعرض الأرقام من بياناتك',

    // LineChart/TimeSeries.php withTimeFormat door
    'timeseries.time_format' => 'TimeSeries يرفض تنسيقات الوقت التي لا يستطيع وقت التشغيل عرضها: {fmt}',

    // Canvas/Canvas.php off-switch closure (CH-08)
    'canvas.dim_positive' => 'يجب أن يكون عرض ولوحة كل منهما >= 1',
];
