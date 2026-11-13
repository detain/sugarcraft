<?php

/**
 * Dutch translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps moet > 0 zijn; gekregen: {fps}',
    'spring.fps_rate_too_small' => 'fps moet groot genoeg zijn opdat 1/fps eindig is; gekregen: {fps}',
    'spring.inputs_finite' => 'Spring-invoer moet eindig zijn; gekregen: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime moet > 0 zijn; gekregen: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency moet >= 1e-9 zijn; een veer die niet kan oscilleren komt nooit tot rust; gekregen {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio moet > 0 zijn; een ongedempte veer oscilleert eeuwig en komt nooit tot rust; gekregen {dampingRatio}',
    'spring.coeffs_finite' => 'veercoëfficiënten zijn geoverload naar niet-eindige waarden; gekregen deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'invoer voor de veerupdate moet eindig zijn; gekregen pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'invoer van SpringConfig moet eindig zijn; gekregen tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'mass van SpringConfig moet > 0 zijn; gekregen {mass}',
    'spring.config_tension' => 'tension van SpringConfig moet > 0 zijn; een veer zonder spanning komt nooit tot rust; gekregen {tension}',
    'spring.config_friction' => 'friction van SpringConfig moet > 0 zijn; een wrijvingsloze veer oscilleert eeuwig en komt nooit tot rust; gekregen {friction}',
    'spring.config_derived' => 'SpringConfig heeft een niet-convergerende veer afgeleid; gekregen tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'fase {index} van SpringChain moet [Spring, float, float, float] zijn',
    'spring.chain_active_index' => 'activeIndex van SpringChain moet tussen 0 en het aantal fasen liggen; gekregen {activeIndex} met {count} fasen',
    'spring.collection_bad_spring' => 'SpringCollection heeft een Spring-instantie nodig voor id "{id}"',
    'spring.collection_bad_value' => 'de {plane}-waarde voor id "{id}" moet een eindig getal zijn in SpringCollection',
    'spring.collection_misaligned' => 'SpringCollection mist {plane}-gegevens voor veer "{id}"',
    'spring.collection_orphan' => 'SpringCollection bevat in {plane} de onbekende veer-id "{id}"',
    'spring.unknown_id' => 'SpringCollection heeft geen veer met id "{id}"',
    'vector.components_finite' => 'componenten van Vector moeten eindig zijn; gekregen x={x}, y={y}, z={z}',
    'wire.arity' => '{class}::fromArray verwacht de positionele [x, y, z]-lijst van jsonSerialize; {n} items ontvangen',
    'wire.member' => '{class}::fromArray heeft int- of float-items nodig; item {index} is van type {type}',
    'vector.normalize_zero' => 'kan een vector met lengte nul niet normaliseren',
    'vector.normalize_overflow' => 'kan een vector wiens lengte naar oneindig is geoverload niet normaliseren; gekregen length={length}',
    'point.components_finite' => 'componenten van Point moeten eindig zijn; gekregen x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'cubic-bezier-controlepunten moeten eindig zijn, gekregen [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'de x-waarden van CubicBezier-controlepunten moeten in [0, 1] liggen; gekregen x1={x1}, x2={x2}',
    'bezier.t_finite' => 't moet eindig zijn; gekregen {t}',
    'bezier.unknown_static' => 'CubicBezier heeft geen statische factory {name}',
    'projectile.dt_finite' => 'deltaTime moet eindig zijn; gekregen {deltaTime}',
    'projectile.negative_dt' => 'deltaTime moet >= 0 zijn; gekregen {deltaTime}',
    'projectile.components_finite' => 'De {name}-componenten van het projectiel moeten eindig zijn',
];
