<?php

/**
 * Czech translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps musí být > 0; obdrženo: {fps}',
    'spring.inputs_finite' => 'Vstupy Spring musí být konečné; obdrženo: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime musí být > 0; obdrženo: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency musí být >= 1e-9; pružina, která nemůže kmitat, se nikdy neustálí; obdrženo {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio musí být > 0; netlumená pružina kmitá věčně a nikdy se neustálí; obdrženo {dampingRatio}',
    'spring.coeffs_finite' => 'koeficienty pružiny přetekly do nekonečných hodnot; obdrženo deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'vstupy aktualizace pružiny musí být konečné; obdrženo pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'vstupy SpringConfig musí být konečné; obdrženo tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'mass v SpringConfig musí být > 0; obdrženo {mass}',
    'spring.config_tension' => 'tension v SpringConfig musí být > 0; pružina bez napětí se nikdy neustálí; obdrženo {tension}',
    'spring.config_friction' => 'friction v SpringConfig musí být > 0; pružina bez tření kmitá věčně a nikdy se neustálí; obdrženo {friction}',
    'spring.config_derived' => 'SpringConfig odvodil nekonvergující pružinu; obdrženo tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'fáze {index} SpringChain musí být [Spring, float, float, float]',
    'spring.chain_active_index' => 'activeIndex SpringChain musí být mezi 0 a počtem fází; obdrženo {activeIndex} při {count} fázích',
    'spring.collection_bad_spring' => 'SpringCollection potřebuje instanci Spring pro id "{id}"',
    'spring.collection_bad_value' => 'hodnota {plane} pro id "{id}" musí být v SpringCollection konečné číslo',
    'spring.collection_misaligned' => 'SpringCollection postrádá data {plane} pro pružinu "{id}"',
    'spring.collection_orphan' => 'SpringCollection obsahuje v {plane} neznámé id pružiny "{id}"',
    'spring.unknown_id' => 'SpringCollection nemá žádnou pružinu s id "{id}"',
    'vector.components_finite' => 'souřadnice Vector musí být konečné; obdrženo x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'nelze normalizovat vektor o nulové délce',
    'vector.normalize_overflow' => 'nelze normalizovat vektor, jehož délka přetekla do nekonečna; obdrženo length={length}',
    'point.components_finite' => 'souřadnice Point musí být konečné; obdrženo x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'řídicí body cubic-bezier musí být konečné, obdrženo [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'hodnoty x řídicích bodů CubicBezier musí být v [0, 1]; obdrženo x1={x1}, x2={x2}',
    'bezier.t_finite' => 't musí být konečné; obdrženo {t}',
    'bezier.unknown_static' => 'CubicBezier nemá statickou továrnu {name}',
    'projectile.dt_finite' => 'deltaTime musí být konečné; získáno {deltaTime}',
    'projectile.negative_dt' => 'deltaTime musí být >= 0; získáno {deltaTime}',
    'projectile.components_finite' => 'Komponenty {name} projektilu musí být konečné',
];
