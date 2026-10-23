<?php

/**
 * Arabic translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps يجب أن يكون > 0; تم الحصول على: {fps}',
    'spring.inputs_finite' => 'يجب أن تكون مدخلات Spring منتهية؛ تم الحصول على: deltaTime={deltaTime}، angularFrequency={angularFrequency}، dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'يجب أن يكون deltaTime >= 0؛ تم الحصول على: {deltaTime}',
    'projectile.dt_finite' => 'يجب أن تكون deltaTime منتهية؛ القيمة المستلمة: {deltaTime}',
    'projectile.negative_dt' => 'يجب أن تكون deltaTime أكبر من أو تساوي 0؛ القيمة المستلمة: {deltaTime}',
    'projectile.components_finite' => 'يجب أن تكون مكونات {name} للمقذوف منتهية',
];
