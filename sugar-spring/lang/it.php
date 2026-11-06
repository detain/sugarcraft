<?php

/**
 * Italian translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps deve essere > 0; ottenuto: {fps}',
    'spring.inputs_finite' => 'Gli input di Spring devono essere finiti; ottenuto: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime deve essere > 0; ottenuto: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency deve essere >= 1e-9; una molla che non può oscillare non si stabilizza mai; ottenuto {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio deve essere > 0; una molla non smorzata oscilla per sempre e non si stabilizza mai; ottenuto {dampingRatio}',
    'spring.coeffs_finite' => 'i coefficienti della molla sono andati in overflow verso valori non finiti; ottenuto deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'i valori di aggiornamento della molla devono essere finiti; ottenuto pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'i valori di SpringConfig devono essere finiti; ottenuto tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'mass di SpringConfig deve essere > 0; ottenuto {mass}',
    'spring.config_tension' => 'tension di SpringConfig deve essere > 0; una molla senza tensione non si stabilizza mai; ottenuto {tension}',
    'spring.config_friction' => 'friction di SpringConfig deve essere > 0; una molla senza attrito oscilla per sempre e non si stabilizza mai; ottenuto {friction}',
    'spring.config_derived' => 'SpringConfig ha derivato una molla non convergente; ottenuto tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'la fase {index} di SpringChain deve essere [Spring, float, float, float]',
    'spring.chain_active_index' => 'activeIndex di SpringChain deve essere compreso tra 0 e il numero di fasi; ottenuto {activeIndex} con {count} fasi',
    'spring.collection_bad_spring' => 'SpringCollection necessita di un’istanza Spring per l’id "{id}"',
    'spring.collection_bad_value' => 'il valore {plane} per l’id "{id}" deve essere un numero finito in SpringCollection',
    'spring.collection_misaligned' => 'a SpringCollection mancano dati {plane} per la molla "{id}"',
    'spring.collection_orphan' => 'SpringCollection contiene in {plane} l’id molla sconosciuto "{id}"',
    'spring.unknown_id' => 'SpringCollection non contiene alcuna molla con l’id "{id}"',
    'vector.components_finite' => 'le componenti di Vector devono essere finite; ottenuto x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'impossibile normalizzare un vettore di lunghezza zero',
    'vector.normalize_overflow' => 'impossibile normalizzare un vettore la cui lunghezza è andata in overflow verso l’infinito; ottenuto length={length}',
    'point.components_finite' => 'le componenti di Point devono essere finite; ottenuto x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'i punti di controllo cubic-bezier devono essere finiti, ottenuto [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'i valori x dei punti di controllo di CubicBezier devono essere in [0, 1]; ottenuto x1={x1}, x2={x2}',
    'bezier.t_finite' => 't deve essere finito; ottenuto {t}',
    'projectile.dt_finite' => 'deltaTime deve essere finita; ottenuto {deltaTime}',
    'projectile.negative_dt' => 'deltaTime deve essere >= 0; ottenuto {deltaTime}',
    'projectile.components_finite' => 'I componenti di {name} del proiettile devono essere finiti',
];
