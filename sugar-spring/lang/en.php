<?php

/**
 * English (default) translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps must be > 0; got {fps}',
    'spring.fps_rate_too_small' => 'fps must be large enough for 1/fps to be finite; got {fps}',
    'spring.inputs_finite' => 'Spring inputs must be finite; got deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime must be > 0; got {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency must be >= 1e-9; a spring that cannot oscillate never settles; got {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio must be > 0; an undamped spring oscillates forever and never settles; got {dampingRatio}',
    'spring.coeffs_finite' => 'Spring coefficients overflowed to non-finite values; got deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'Spring update inputs must be finite; got pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'SpringConfig inputs must be finite; got tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'SpringConfig mass must be > 0; got {mass}',
    'spring.config_tension' => 'SpringConfig tension must be > 0; a spring with no tension never settles; got {tension}',
    'spring.config_friction' => 'SpringConfig friction must be > 0; a frictionless spring oscillates forever and never settles; got {friction}',
    'spring.config_derived' => 'SpringConfig derived a non-converging spring; got tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'SpringChain stage {index} must be [Spring, float, float, float]',
    'spring.chain_active_index' => 'SpringChain activeIndex must be between 0 and the stage count; got {activeIndex} with {count} stages',
    'spring.collection_bad_spring' => 'SpringCollection needs a Spring instance for id "{id}"',
    'spring.collection_bad_value' => 'SpringCollection {plane} value for id "{id}" must be a finite number',
    'spring.collection_misaligned' => 'SpringCollection is missing {plane} data for spring "{id}"',
    'spring.collection_orphan' => 'SpringCollection {plane} carries unknown spring id "{id}"',
    'spring.unknown_id' => 'SpringCollection has no spring with id "{id}"',
    'vector.components_finite' => 'Vector components must be finite; got x={x}, y={y}, z={z}',
    'wire.arity' => '{class}::fromArray expects the positional [x, y, z] list jsonSerialize emits; got {n} entries',
    'wire.member' => '{class}::fromArray needs int or float entries; entry {index} is {type}',
    'vector.normalize_zero' => 'Cannot normalize a zero-length vector',
    'vector.normalize_overflow' => 'Cannot normalize a vector whose length overflowed to infinity; got length={length}',
    'point.components_finite' => 'Point components must be finite; got x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'CubicBezier control points must be finite; got [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'CubicBezier control points must have x-values in [0, 1]; got x1={x1}, x2={x2}',
    'bezier.t_finite' => 't must be finite; got {t}',
    'bezier.unknown_static' => 'CubicBezier has no static factory {name}',
    'projectile.dt_finite' => 'deltaTime must be finite; got {deltaTime}',
    'projectile.negative_dt' => 'deltaTime must be >= 0; got {deltaTime}',
    'projectile.components_finite' => 'Projectile {name} components must be finite',
];
