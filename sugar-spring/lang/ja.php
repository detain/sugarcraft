<?php

/**
 * Japanese translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps は > 0 である必要があります；取得：{fps}',
    'spring.fps_rate_too_small' => 'fps は 1/fps が有限になる十分大きな値である必要があります；取得：{fps}',
    'spring.inputs_finite' => 'Spring の入力は有限である必要があります；取得：deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime は > 0 である必要があります；取得：{deltaTime}',
    'spring.angular_too_small' => 'angularFrequency は 1e-9 以上である必要があります。振動できないばねは決して安定しません。取得値: {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio は 0 より大である必要があります。減衰のないばねは永遠に振動し、決して安定しません。取得値: {dampingRatio}',
    'spring.coeffs_finite' => 'ばねの係数が非有限値にオーバーフローしました。取得値: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'ばねの更新入力は有限値でなければなりません。取得値: pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'SpringConfig の入力は有限値でなければなりません。取得値: tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'SpringConfig の mass は 0 より大である必要があります。取得値: {mass}',
    'spring.config_tension' => 'SpringConfig の tension は 0 より大である必要があります。張力のないばねは決して安定しません。取得値: {tension}',
    'spring.config_friction' => 'SpringConfig の friction は 0 より大である必要があります。摩擦のないばねは永遠に振動し、決して安定しません。取得値: {friction}',
    'spring.config_derived' => 'SpringConfig は発散するばねを導出しました。取得値: tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'SpringChain のステージ {index} は [Spring, float, float, float] でなければなりません',
    'spring.chain_active_index' => 'SpringChain の activeIndex は 0 とステージ数の間である必要があります。{count} ステージで取得値: {activeIndex}',
    'spring.collection_bad_spring' => 'SpringCollection は ID "{id}" に対して Spring インスタンスを必要とします',
    'spring.collection_bad_value' => 'SpringCollection の ID "{id}" の {plane} 値は有限数でなければなりません',
    'spring.collection_misaligned' => 'SpringCollection にはばね "{id}" の {plane} データがありません',
    'spring.collection_orphan' => 'SpringCollection の {plane} に不明なばね ID "{id}" が含まれています',
    'spring.unknown_id' => 'SpringCollection に ID "{id}" のばねはありません',
    'vector.components_finite' => 'Vector の成分は有限値でなければなりません。取得値: x={x}, y={y}, z={z}',
    'wire.arity' => '{class}::fromArray は jsonSerialize が出力する位置指定の [x, y, z] リストを期待していますが、{n} 個の要素を受け取りました',
    'wire.member' => '{class}::fromArray には int または float の要素が必要です。要素 {index} の型は {type} です',
    'vector.normalize_zero' => '長さがゼロのベクトルは正規化できません',
    'vector.normalize_overflow' => '長さが無限大にオーバーフローしたベクトルは正規化できません。取得値: length={length}',
    'point.components_finite' => 'Point の成分は有限値でなければなりません。取得値: x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'cubic-bezier の制御点は有限値でなければなりません。取得値: [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'CubicBezier の制御点の x 値は [0, 1] 内である必要があります。取得値: x1={x1}, x2={x2}',
    'bezier.t_finite' => 't は有限である必要があります。取得値: {t}',
    'bezier.unknown_static' => 'CubicBezier に static ファクトリー {name} はありません',
    'projectile.dt_finite' => 'deltaTime は有限である必要があります。取得値: {deltaTime}',
    'projectile.negative_dt' => 'deltaTime は 0 以上である必要があります。取得値: {deltaTime}',
    'projectile.components_finite' => 'Projectile の {name} コンポーネントは有限である必要があります',
];
