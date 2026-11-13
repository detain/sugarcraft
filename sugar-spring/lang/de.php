<?php

/**
 * German translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps muss > 0 sein; erhalten: {fps}',
    'spring.inputs_finite' => 'Spring-Eingaben müssen endlich sein; erhalten: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime muss > 0 sein; erhalten: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency muss >= 1e-9 sein; eine Feder, die nicht schwingen kann, setzt sich nie; erhalten: {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio muss > 0 sein; eine ungedämpfte Feder schwingt ewig und setzt sich nie; erhalten: {dampingRatio}',
    'spring.coeffs_finite' => 'Federkoeffizienten sind zu nicht-endlichen Werten übergelaufen; erhalten: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'Eingaben der Feder-Aktualisierung müssen endlich sein; erhalten: pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'SpringConfig-Eingaben müssen endlich sein; erhalten: tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'SpringConfig mass muss > 0 sein; erhalten: {mass}',
    'spring.config_tension' => 'SpringConfig tension muss > 0 sein; eine Feder ohne Spannung setzt sich nie; erhalten: {tension}',
    'spring.config_friction' => 'SpringConfig friction muss > 0 sein; eine reibungsfreie Feder schwingt ewig und setzt sich nie; erhalten: {friction}',
    'spring.config_derived' => 'SpringConfig hat eine nicht konvergierende Feder abgeleitet; erhalten: tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'SpringChain-Stufe {index} muss [Spring, float, float, float] sein',
    'spring.chain_active_index' => 'SpringChain activeIndex muss zwischen 0 und der Stufenzahl liegen; erhalten: {activeIndex} bei {count} Stufen',
    'spring.collection_bad_spring' => 'SpringCollection benötigt eine Spring-Instanz für die ID "{id}"',
    'spring.collection_bad_value' => 'Der {plane}-Wert für die ID "{id}" muss in SpringCollection eine endliche Zahl sein',
    'spring.collection_misaligned' => 'SpringCollection fehlen {plane}-Daten für die Feder "{id}"',
    'spring.collection_orphan' => 'SpringCollection enthält in {plane} die unbekannte Feder-ID "{id}"',
    'spring.unknown_id' => 'SpringCollection hat keine Feder mit der ID "{id}"',
    'vector.components_finite' => 'Vector-Komponenten müssen endlich sein; erhalten: x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'Ein Vektor der Länge Null kann nicht normalisiert werden',
    'vector.normalize_overflow' => 'Ein Vektor, dessen Länge zu Unendlich übergelaufen ist, kann nicht normalisiert werden; erhalten: length={length}',
    'point.components_finite' => 'Point-Komponenten müssen endlich sein; erhalten: x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'cubic-bezier-Stützpunkte müssen endlich sein, erhalten: [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'Die x-Werte der CubicBezier-Stützpunkte müssen in [0, 1] liegen; erhalten: x1={x1}, x2={x2}',
    'bezier.t_finite' => 't muss endlich sein; erhalten: {t}',
    'bezier.unknown_static' => 'CubicBezier hat keine statische Factory {name}',
    'projectile.dt_finite' => 'deltaTime muss endlich sein; erhalten {deltaTime}',
    'projectile.negative_dt' => 'deltaTime muss >= 0 sein; erhalten {deltaTime}',
    'projectile.components_finite' => 'Komponenten des Projektil-{name} müssen endlich sein',
];
