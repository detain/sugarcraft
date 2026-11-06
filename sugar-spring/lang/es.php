<?php

/**
 * Spanish translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps debe ser > 0; obtenido: {fps}',
    'spring.inputs_finite' => 'Las entradas de Spring deben ser finitas; obtenido: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime debe ser > 0; obtenido: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency debe ser >= 1e-9; un resorte que no puede oscilar nunca se estabiliza; se obtuvo {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio debe ser > 0; un resorte sin amortiguación oscila para siempre y nunca se estabiliza; se obtuvo {dampingRatio}',
    'spring.coeffs_finite' => 'los coeficientes del resorte desbordaron a valores no finitos; se obtuvo deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'los valores de actualización del resorte deben ser finitos; se obtuvo pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'los valores de SpringConfig deben ser finitos; se obtuvo tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'mass de SpringConfig debe ser > 0; se obtuvo {mass}',
    'spring.config_tension' => 'tension de SpringConfig debe ser > 0; un resorte sin tensión nunca se estabiliza; se obtuvo {tension}',
    'spring.config_friction' => 'friction de SpringConfig debe ser > 0; un resorte sin fricción oscila para siempre y nunca se estabiliza; se obtuvo {friction}',
    'spring.config_derived' => 'SpringConfig derivó un resorte que no converge; se obtuvo tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'la etapa {index} de SpringChain debe ser [Spring, float, float, float]',
    'spring.chain_active_index' => 'activeIndex de SpringChain debe estar entre 0 y el número de etapas; se obtuvo {activeIndex} con {count} etapas',
    'spring.collection_bad_spring' => 'SpringCollection necesita una instancia de Spring para el id "{id}"',
    'spring.collection_bad_value' => 'el valor {plane} para el id "{id}" debe ser un número finito en SpringCollection',
    'spring.collection_misaligned' => 'a SpringCollection le faltan datos {plane} para el resorte "{id}"',
    'spring.collection_orphan' => 'SpringCollection contiene en {plane} el id de resorte desconocido "{id}"',
    'spring.unknown_id' => 'SpringCollection no tiene ningún resorte con el id "{id}"',
    'vector.components_finite' => 'los componentes de Vector deben ser finitos; se obtuvo x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'no se puede normalizar un vector de longitud cero',
    'vector.normalize_overflow' => 'no se puede normalizar un vector cuya longitud desbordó a infinito; se obtuvo length={length}',
    'point.components_finite' => 'los componentes de Point deben ser finitos; se obtuvo x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'los puntos de control cubic-bezier deben ser finitos, se obtuvo [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'los valores x de los puntos de control de CubicBezier deben estar en [0, 1]; se obtuvo x1={x1}, x2={x2}',
    'bezier.t_finite' => 't debe ser finito; se obtuvo {t}',
    'projectile.dt_finite' => 'deltaTime debe ser finito; se obtuvo {deltaTime}',
    'projectile.negative_dt' => 'deltaTime debe ser >= 0; se obtuvo {deltaTime}',
    'projectile.components_finite' => 'Los componentes del {name} del proyectil deben ser finitos',
];
