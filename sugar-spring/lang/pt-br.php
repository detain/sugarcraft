<?php

/**
 * Brazilian Portuguese translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps deve ser > 0; obtido: {fps}',
    'spring.inputs_finite' => 'As entradas do Spring devem ser finitas; obtido: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime deve ser > 0; obtido: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency deve ser >= 1e-9; uma mola que não pode oscilar nunca se estabiliza; obtido {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio deve ser > 0; uma mola sem amortecimento oscila para sempre e nunca se estabiliza; obtido {dampingRatio}',
    'spring.coeffs_finite' => 'os coeficientes da mola estouraram para valores não finitos; obtido deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'os valores de atualização da mola devem ser finitos; obtido pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'os valores de SpringConfig devem ser finitos; obtido tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'mass de SpringConfig deve ser > 0; obtido {mass}',
    'spring.config_tension' => 'tension de SpringConfig deve ser > 0; uma mola sem tensão nunca se estabiliza; obtido {tension}',
    'spring.config_friction' => 'friction de SpringConfig deve ser > 0; uma mola sem atrito oscila para sempre e nunca se estabiliza; obtido {friction}',
    'spring.config_derived' => 'SpringConfig derivou uma mola que não converge; obtido tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'a etapa {index} de SpringChain deve ser [Spring, float, float, float]',
    'spring.chain_active_index' => 'activeIndex de SpringChain deve estar entre 0 e o número de etapas; obtido {activeIndex} com {count} etapas',
    'spring.collection_bad_spring' => 'SpringCollection precisa de uma instância Spring para o id "{id}"',
    'spring.collection_bad_value' => 'o valor {plane} para o id "{id}" deve ser um número finito em SpringCollection',
    'spring.collection_misaligned' => 'SpringCollection não tem dados {plane} para a mola "{id}"',
    'spring.collection_orphan' => 'SpringCollection contém em {plane} o id de mola desconhecido "{id}"',
    'spring.unknown_id' => 'SpringCollection não tem nenhuma mola com o id "{id}"',
    'vector.components_finite' => 'os componentes de Vector devem ser finitos; obtido x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'não é possível normalizar um vetor de comprimento zero',
    'vector.normalize_overflow' => 'não é possível normalizar um vetor cujo comprimento estourou para infinito; obtido length={length}',
    'point.components_finite' => 'os componentes de Point devem ser finitos; obtido x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'os pontos de controle cubic-bezier devem ser finitos, obtido [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'os valores de x dos pontos de controle de CubicBezier devem estar em [0, 1]; obtido x1={x1}, x2={x2}',
    'bezier.t_finite' => 't deve ser finito; obtido {t}',
    'bezier.unknown_static' => 'CubicBezier não possui uma factory estática {name}',
    'projectile.dt_finite' => 'deltaTime deve ser finito; obtido {deltaTime}',
    'projectile.negative_dt' => 'deltaTime deve ser >= 0; obtido {deltaTime}',
    'projectile.components_finite' => 'Os componentes de {name} do projétil devem ser finitos',
];
