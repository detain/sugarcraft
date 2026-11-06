<?php

/**
 * French translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps doit être > 0 ; reçu : {fps}',
    'spring.inputs_finite' => 'Les entrées de Spring doivent être finies ; reçu : deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime doit être > 0 ; reçu : {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency doit être >= 1e-9 ; un ressort qui ne peut pas osciller ne se stabilise jamais ; obtenu : {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio doit être > 0 ; un ressort non amorti oscille indéfiniment et ne se stabilise jamais ; obtenu : {dampingRatio}',
    'spring.coeffs_finite' => 'les coefficients du ressort ont débordé vers des valeurs non finies ; obtenu : deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'les entrées de la mise à jour du ressort doivent être finies ; obtenu : pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'les entrées de SpringConfig doivent être finies ; obtenu : tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'la mass de SpringConfig doit être > 0 ; obtenu : {mass}',
    'spring.config_tension' => 'la tension de SpringConfig doit être > 0 ; un ressort sans tension ne se stabilise jamais ; obtenu : {tension}',
    'spring.config_friction' => 'la friction de SpringConfig doit être > 0 ; un ressort sans friction oscille indéfiniment et ne se stabilise jamais ; obtenu : {friction}',
    'spring.config_derived' => 'SpringConfig a dérivé un ressort non convergent ; obtenu : tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'l’étape {index} de SpringChain doit être [Spring, float, float, float]',
    'spring.chain_active_index' => 'activeIndex de SpringChain doit être compris entre 0 et le nombre d’étapes ; obtenu : {activeIndex} avec {count} étapes',
    'spring.collection_bad_spring' => 'SpringCollection a besoin d’une instance Spring pour l’identifiant « {id} »',
    'spring.collection_bad_value' => 'la valeur {plane} pour l’identifiant « {id} » doit être un nombre fini dans SpringCollection',
    'spring.collection_misaligned' => 'SpringCollection ne contient pas de données {plane} pour le ressort « {id} »',
    'spring.collection_orphan' => 'SpringCollection porte dans {plane} l’identifiant de ressort inconnu « {id} »',
    'spring.unknown_id' => 'SpringCollection ne contient aucun ressort avec l’identifiant « {id} »',
    'vector.components_finite' => 'les composantes de Vector doivent être finies ; obtenu : x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'impossible de normaliser un vecteur de longueur nulle',
    'vector.normalize_overflow' => 'impossible de normaliser un vecteur dont la longueur a débordé vers l’infini ; obtenu : length={length}',
    'point.components_finite' => 'les composantes de Point doivent être finies ; obtenu : x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'les points de contrôle cubic-bezier doivent être finis, obtenu : [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'les valeurs x des points de contrôle de CubicBezier doivent être dans [0, 1] ; obtenu : x1={x1}, x2={x2}',
    'bezier.t_finite' => 't doit être fini ; obtenu : {t}',
    'projectile.dt_finite' => 'deltaTime doit être finie ; reçu : {deltaTime}',
    'projectile.negative_dt' => 'deltaTime doit être >= 0 ; reçu : {deltaTime}',
    'projectile.components_finite' => 'Les composants {name} du projectile doivent être finis',
];
