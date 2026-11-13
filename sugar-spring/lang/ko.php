<?php

/**
 * Korean translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps는 > 0이어야 합니다; 받음: {fps}',
    'spring.fps_rate_too_small' => 'fps는 1/fps가 유한하려면 충분히 커야 합니다; 받음: {fps}',
    'spring.inputs_finite' => 'Spring 입력은 유한해야 합니다; 받음: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime은 > 0이어야 합니다; 받음: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency는 1e-9 이상이어야 합니다. 진동할 수 없는 스프링은 절대 안정되지 않습니다. 입력값: {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio는 0보다 커야 합니다. 감쇠 없는 스프링은 영원히 진동하며 안정되지 않습니다. 입력값: {dampingRatio}',
    'spring.coeffs_finite' => '스프링 계수가 유한하지 않은 값으로 오버플로되었습니다. 입력값: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => '스프링 갱신 입력은 유한해야 합니다. 입력값: pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'SpringConfig 입력은 유한해야 합니다. 입력값: tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'SpringConfig의 mass는 0보다 커야 합니다. 입력값: {mass}',
    'spring.config_tension' => 'SpringConfig의 tension은 0보다 커야 합니다. 장력 없는 스프링은 절대 안정되지 않습니다. 입력값: {tension}',
    'spring.config_friction' => 'SpringConfig의 friction은 0보다 커야 합니다. 마찰 없는 스프링은 영원히 진동하며 안정되지 않습니다. 입력값: {friction}',
    'spring.config_derived' => 'SpringConfig가 수렴하지 않는 스프링을 파생했습니다. 입력값: tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'SpringChain 단계 {index}는 [Spring, float, float, float]여야 합니다',
    'spring.chain_active_index' => 'SpringChain의 activeIndex는 0과 단계 수 사이여야 합니다. {count}개 단계에서 입력값: {activeIndex}',
    'spring.collection_bad_spring' => 'SpringCollection에는 ID "{id}"에 대한 Spring 인스턴스가 필요합니다',
    'spring.collection_bad_value' => 'SpringCollection에서 ID "{id}"의 {plane} 값은 유한한 수여야 합니다',
    'spring.collection_misaligned' => 'SpringCollection에 스프링 "{id}"의 {plane} 데이터가 없습니다',
    'spring.collection_orphan' => 'SpringCollection의 {plane}에 알 수 없는 스프링 ID "{id}"가 있습니다',
    'spring.unknown_id' => 'SpringCollection에 ID "{id}"를 가진 스프링이 없습니다',
    'vector.components_finite' => 'Vector 성분은 유한해야 합니다. 입력값: x={x}, y={y}, z={z}',
    'wire.arity' => '{class}::fromArray은 jsonSerialize가 내보내는 순서 기반 [x, y, z] 목록을 기대합니다. {n}개의 항목을 받았습니다',
    'wire.member' => '{class}::fromArray은 int 또는 float 항목을 기대합니다. 항목 {index}의 유형: {type}',
    'vector.normalize_zero' => '길이가 0인 벡터는 정규화할 수 없습니다',
    'vector.normalize_overflow' => '길이가 무한대로 오버플로된 벡터는 정규화할 수 없습니다. 입력값: length={length}',
    'point.components_finite' => 'Point 성분은 유한해야 합니다. 입력값: x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'cubic-bezier 제어점은 유한해야 합니다. 입력값: [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'CubicBezier 제어점의 x 값은 [0, 1] 범위여야 합니다. 입력값: x1={x1}, x2={x2}',
    'bezier.t_finite' => 't는 유한해야 합니다. 입력값: {t}',
    'bezier.unknown_static' => 'CubicBezier에 정적 팩토리 {name}이(가) 없습니다',
    'projectile.dt_finite' => 'deltaTime은 유한해야 합니다. 입력값: {deltaTime}',
    'projectile.negative_dt' => 'deltaTime은 0 이상이어야 합니다. 입력값: {deltaTime}',
    'projectile.components_finite' => 'Projectile {name} 구성 요소는 유한해야 합니다',
];
