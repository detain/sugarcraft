<?php

/**
 * Arabic translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps يجب أن يكون > 0; تم الحصول على: {fps}',
    'spring.fps_rate_too_small' => 'fps يجب أن يكون كبيرًا بما يكفي ليكون 1/fps نهائيًا؛ تم الحصول على: {fps}',
    'spring.inputs_finite' => 'يجب أن تكون مدخلات Spring منتهية؛ تم الحصول على: deltaTime={deltaTime}، angularFrequency={angularFrequency}، dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'يجب أن يكون deltaTime > 0؛ تم الحصول على: {deltaTime}',
    'spring.angular_too_small' => 'يجب أن تكون angularFrequency أكبر من أو تساوي 1e-9؛ النابض الذي لا يستطيع التذبذب لا يهدأ أبدًا؛ المُدخل {angularFrequency}',
    'spring.damping_positive' => 'يجب أن تكون dampingRatio أكبر من 0؛ النابض غير المخمَّد يتذبذب إلى الأبد ولا يهدأ أبدًا؛ المُدخل {dampingRatio}',
    'spring.coeffs_finite' => 'فاضت معاملات النابض إلى قيم غير منتهية؛ المُدخل deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'يجب أن تكون مُدخلات تحديث النابض منتهية؛ المُدخل pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'يجب أن تكون مُدخلات SpringConfig منتهية؛ المُدخل tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'يجب أن تكون mass في SpringConfig أكبر من 0؛ المُدخل {mass}',
    'spring.config_tension' => 'يجب أن تكون tension في SpringConfig أكبر من 0؛ نابض بلا شد لا يهدأ أبدًا؛ المُدخل {tension}',
    'spring.config_friction' => 'يجب أن تكون friction في SpringConfig أكبر من 0؛ نابض بلا احتكاك يتذبذب إلى الأبد ولا يهدأ أبدًا؛ المُدخل {friction}',
    'spring.config_derived' => 'استنتجت SpringConfig نابضًا غير متقارب؛ المُدخل tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'يجب أن تكون مرحلة SpringChain رقم {index} على الشكل [Spring, float, float, float]',
    'spring.chain_active_index' => 'يجب أن يكون activeIndex في SpringChain بين 0 وعدد المراحل؛ المُدخل {activeIndex} مع {count} مرحلة',
    'spring.collection_bad_spring' => 'تحتاج SpringCollection إلى نسخة Spring للمُعرِّف "{id}"',
    'spring.collection_bad_value' => 'يجب أن تكون قيمة {plane} للمُعرِّف "{id}" في SpringCollection عددًا منتهيًا',
    'spring.collection_misaligned' => 'تفتقر SpringCollection إلى بيانات {plane} للنابض "{id}"',
    'spring.collection_orphan' => 'تحتوي SpringCollection في {plane} على مُعرِّف نابض غير معروف "{id}"',
    'spring.unknown_id' => 'لا تحتوي SpringCollection على نابض بالمُعرِّف "{id}"',
    'vector.components_finite' => 'يجب أن تكون مركبات Vector منتهية؛ المُدخل x={x}, y={y}, z={z}',
    'wire.arity' => 'يتوقع {class}::fromArray القائمة الموقعية [x, y, z] التي يصدرها jsonSerialize؛ عدد العناصر {n}',
    'wire.member' => '{class}::fromArray يحتاج عناصر عددية (int أو float)؛ العنصر {index} من النوع {type}',
    'vector.normalize_zero' => 'لا يمكن تطبيع متجه بطول صفر',
    'vector.normalize_overflow' => 'لا يمكن تطبيع متجه فاض طوله إلى اللانهاية؛ المُدخل length={length}',
    'point.components_finite' => 'يجب أن تكون مركبات Point منتهية؛ المُدخل x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'يجب أن تكون نقاط تحكم cubic-bezier منتهية، المُدخل [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'يجب أن تكون قيم x لنقاط تحكم CubicBezier ضمن [0, 1]؛ المُدخل x1={x1}, x2={x2}',
    'bezier.t_finite' => 'يجب أن تكون t محدودة؛ المُدخل {t}',
    'bezier.unknown_static' => 'CubicBezier لا يحتوي على مصنع ثابت {name}',
    'projectile.dt_finite' => 'يجب أن تكون deltaTime منتهية؛ القيمة المستلمة: {deltaTime}',
    'projectile.negative_dt' => 'يجب أن تكون deltaTime أكبر من أو تساوي 0؛ القيمة المستلمة: {deltaTime}',
    'projectile.components_finite' => 'يجب أن تكون مكونات {name} للمقذوف منتهية',
];
