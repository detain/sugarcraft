<?php

/**
 * Russian translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps должен быть > 0; получено: {fps}',
    'spring.inputs_finite' => 'Входные данные Spring должны быть конечными; получено: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime должен быть > 0; получено: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency должен быть >= 1e-9; пружина, не способная колебаться, никогда не успокоится; получено {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio должен быть > 0; незатухающая пружина колеблется вечно и никогда не успокаивается; получено {dampingRatio}',
    'spring.coeffs_finite' => 'коэффициенты пружины переполнились до бесконечных значений; получено deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'данные обновления пружины должны быть конечными; получено pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'параметры SpringConfig должны быть конечными; получено tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'mass в SpringConfig должен быть > 0; получено {mass}',
    'spring.config_tension' => 'tension в SpringConfig должен быть > 0; пружина без натяжения никогда не успокоится; получено {tension}',
    'spring.config_friction' => 'friction в SpringConfig должен быть > 0; пружина без трения колеблется вечно и никогда не успокаивается; получено {friction}',
    'spring.config_derived' => 'SpringConfig вывел расходящуюся пружину; получено tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'этап {index} SpringChain должен быть [Spring, float, float, float]',
    'spring.chain_active_index' => 'activeIndex SpringChain должен быть от 0 до числа этапов; получено {activeIndex} при {count} этапах',
    'spring.collection_bad_spring' => 'SpringCollection требует экземпляр Spring для id "{id}"',
    'spring.collection_bad_value' => 'значение {plane} для id "{id}" должно быть конечным числом в SpringCollection',
    'spring.collection_misaligned' => 'в SpringCollection нет данных {plane} для пружины "{id}"',
    'spring.collection_orphan' => 'SpringCollection содержит в {plane} неизвестный id пружины "{id}"',
    'spring.unknown_id' => 'в SpringCollection нет пружины с id "{id}"',
    'vector.components_finite' => 'компоненты Vector должны быть конечными; получено x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'невозможно нормализовать вектор нулевой длины',
    'vector.normalize_overflow' => 'невозможно нормализовать вектор, длина которого переполнилась до бесконечности; получено length={length}',
    'point.components_finite' => 'компоненты Point должны быть конечными; получено x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'опорные точки cubic-bezier должны быть конечными, получено [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'значения x опорных точек CubicBezier должны быть в [0, 1]; получено x1={x1}, x2={x2}',
    'projectile.dt_finite' => 'deltaTime должно быть конечным; получено {deltaTime}',
    'projectile.negative_dt' => 'deltaTime должно быть >= 0; получено {deltaTime}',
    'projectile.components_finite' => 'Компоненты {name} снаряда должны быть конечными',
];
