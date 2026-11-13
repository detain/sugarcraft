<?php

/**
 * Simplified Chinese translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps 必须 > 0；实际：{fps}',
    'spring.fps_rate_too_small' => 'fps 必须足够大以使 1/fps 有限；实际：{fps}',
    'spring.inputs_finite' => 'Spring 输入必须为有限值；实际：deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime 必须 > 0；实际：{deltaTime}',
    'spring.angular_too_small' => 'angularFrequency 必须 >= 1e-9；无法振荡的弹簧永远不会稳定；得到 {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio 必须 > 0；无阻尼弹簧永远振荡且永不稳定；得到 {dampingRatio}',
    'spring.coeffs_finite' => '弹簧系数溢出为非有限值；得到 deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => '弹簧更新输入必须为有限值；得到 pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'SpringConfig 输入必须为有限值；得到 tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'SpringConfig 的 mass 必须 > 0；得到 {mass}',
    'spring.config_tension' => 'SpringConfig 的 tension 必须 > 0；无张力的弹簧永远不会稳定；得到 {tension}',
    'spring.config_friction' => 'SpringConfig 的 friction 必须 > 0；无摩擦弹簧永远振荡且永不稳定；得到 {friction}',
    'spring.config_derived' => 'SpringConfig 导出了不收敛的弹簧；得到 tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'SpringChain 阶段 {index} 必须为 [Spring, float, float, float]',
    'spring.chain_active_index' => 'SpringChain 的 activeIndex 必须介于 0 和阶段数之间；在 {count} 个阶段时得到 {activeIndex}',
    'spring.collection_bad_spring' => 'SpringCollection 需要为 id "{id}" 提供 Spring 实例',
    'spring.collection_bad_value' => 'SpringCollection 中 id "{id}" 的 {plane} 值必须是有限数',
    'spring.collection_misaligned' => 'SpringCollection 缺少弹簧 "{id}" 的 {plane} 数据',
    'spring.collection_orphan' => 'SpringCollection 的 {plane} 中包含未知的弹簧 id "{id}"',
    'spring.unknown_id' => 'SpringCollection 没有 id 为 "{id}" 的弹簧',
    'vector.components_finite' => 'Vector 分量必须为有限值；得到 x={x}, y={y}, z={z}',
    'vector.normalize_zero' => '无法归一化长度为零的向量',
    'vector.normalize_overflow' => '无法归一化长度溢出为无穷大的向量；得到 length={length}',
    'point.components_finite' => 'Point 分量必须为有限值；得到 x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'cubic-bezier 控制点必须为有限值，得到 [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'CubicBezier 控制点的 x 值必须在 [0, 1] 内；得到 x1={x1}, x2={x2}',
    'bezier.t_finite' => 't 必须有限；得到 {t}',
    'bezier.unknown_static' => 'CubicBezier 没有名为 {name} 的静态工厂',
    'projectile.dt_finite' => 'deltaTime 必须为有限值；得到 {deltaTime}',
    'projectile.negative_dt' => 'deltaTime 必须 >= 0；得到 {deltaTime}',
    'projectile.components_finite' => '抛射体 {name} 分量必须为有限值',
];
