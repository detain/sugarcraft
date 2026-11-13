<?php

/**
 * Polish translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps musi być > 0; otrzymano: {fps}',
    'spring.fps_rate_too_small' => 'fps musi być wystarczająco duże, aby 1/fps było skończone; otrzymano: {fps}',
    'spring.inputs_finite' => 'Dane wejściowe Spring muszą być skończone; otrzymano: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime musi być > 0; otrzymano: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency musi wynosić >= 1e-9; sprężyna, która nie może drgać, nigdy się nie wyciszy; otrzymano {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio musi wynosić > 0; sprężyna bez tłumienia drży wiecznie i nigdy się nie wycisza; otrzymano {dampingRatio}',
    'spring.coeffs_finite' => 'współczynniki sprężyny przebrały się do wartości nieskończonych; otrzymano deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'dane aktualizacji sprężyny muszą być skończone; otrzymano pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'dane SpringConfig muszą być skończone; otrzymano tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'mass w SpringConfig musi być > 0; otrzymano {mass}',
    'spring.config_tension' => 'tension w SpringConfig musi być > 0; sprężyna bez napięcia nigdy się nie wyciszy; otrzymano {tension}',
    'spring.config_friction' => 'friction w SpringConfig musi być > 0; sprężyna bez tarcia drży wiecznie i nigdy się nie wycisza; otrzymano {friction}',
    'spring.config_derived' => 'SpringConfig wywiódł sprężynę niezbieżną; otrzymano tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'etap {index} SpringChain musi być [Spring, float, float, float]',
    'spring.chain_active_index' => 'activeIndex SpringChain musi być między 0 a liczbą etapów; otrzymano {activeIndex} przy {count} etapach',
    'spring.collection_bad_spring' => 'SpringCollection wymaga instancji Spring dla identyfikatora "{id}"',
    'spring.collection_bad_value' => 'wartość {plane} dla identyfikatora "{id}" musi być liczbą skończoną w SpringCollection',
    'spring.collection_misaligned' => 'SpringCollection nie ma danych {plane} dla sprężyny "{id}"',
    'spring.collection_orphan' => 'SpringCollection zawiera w {plane} nieznany identyfikator sprężyny "{id}"',
    'spring.unknown_id' => 'SpringCollection nie ma sprężyny o identyfikatorze "{id}"',
    'vector.components_finite' => 'składowe Vector muszą być skończone; otrzymano x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'nie można znormalizować wektora o długości zero',
    'vector.normalize_overflow' => 'nie można znormalizować wektora, którego długość przebrała się do nieskończoności; otrzymano length={length}',
    'point.components_finite' => 'składowe Point muszą być skończone; otrzymano x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'punkty kontrolne cubic-bezier muszą być skończone, otrzymano [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'wartości x punktów kontrolnych CubicBezier muszą być w [0, 1]; otrzymano x1={x1}, x2={x2}',
    'bezier.t_finite' => 't musi być skończone; otrzymano {t}',
    'bezier.unknown_static' => 'CubicBezier nie ma statycznej fabryki {name}',
    'projectile.dt_finite' => 'deltaTime musi być skończone; otrzymano {deltaTime}',
    'projectile.negative_dt' => 'deltaTime musi być >= 0; otrzymano {deltaTime}',
    'projectile.components_finite' => 'Składowe {name} pocisku muszą być skończone',
];
