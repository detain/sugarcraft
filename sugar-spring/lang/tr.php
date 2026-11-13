<?php

/**
 * Turkish translations for sugar-spring.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps > 0 olmalıdır; alınan: {fps}',
    'spring.fps_rate_too_small' => 'fps, 1/fps sonlu kalacak kadar büyük olmalıdır; alınan: {fps}',
    'spring.inputs_finite' => 'Spring girdileri sonlu olmalıdır; alınan: deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.dt_positive' => 'deltaTime > 0 olmalıdır; alınan: {deltaTime}',
    'spring.angular_too_small' => 'angularFrequency >= 1e-9 olmalı; salınamayan bir yay asla yatışmaz; alınan {angularFrequency}',
    'spring.damping_positive' => 'dampingRatio > 0 olmalı; sönümsüz yay sonsuza dek salınır ve asla yatışmaz; alınan {dampingRatio}',
    'spring.coeffs_finite' => 'yay katsayıları sonlu olmayan değerlere taştı; alınan deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.update_finite' => 'yay güncelleme girdileri sonlu olmalı; alınan pos={pos}, vel={vel}, target={target}',
    'spring.config_inputs_finite' => 'SpringConfig girdileri sonlu olmalı; alınan tension={tension}, friction={friction}, mass={mass}',
    'spring.config_mass' => 'SpringConfig içinde mass > 0 olmalı; alınan {mass}',
    'spring.config_tension' => 'SpringConfig içinde tension > 0 olmalı; gerilimsiz yay asla yatışmaz; alınan {tension}',
    'spring.config_friction' => 'SpringConfig içinde friction > 0 olmalı; sürtünmesiz yay sonsuza dek salınır ve asla yatışmaz; alınan {friction}',
    'spring.config_derived' => 'SpringConfig yakınsamayan bir yay türetti; alınan tension={tension}, friction={friction}, mass={mass}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.chain_bad_stage' => 'SpringChain aşaması {index} [Spring, float, float, float] olmalı',
    'spring.chain_active_index' => 'SpringChain activeIndex 0 ile aşama sayısı arasında olmalı; {count} aşamada alınan {activeIndex}',
    'spring.collection_bad_spring' => 'SpringCollection, "{id}" kimliği için bir Spring örneğine ihtiyaç duyar',
    'spring.collection_bad_value' => 'SpringCollection içinde "{id}" kimliği için {plane} değeri sonlu bir sayı olmalı',
    'spring.collection_misaligned' => 'SpringCollection, "{id}" yayı için {plane} verilerini içermiyor',
    'spring.collection_orphan' => 'SpringCollection, {plane} içinde bilinmeyen yay kimliği "{id}" taşıyor',
    'spring.unknown_id' => 'SpringCollection, "{id}" kimliğine sahip bir yay içermiyor',
    'vector.components_finite' => 'Vector bileşenleri sonlu olmalı; alınan x={x}, y={y}, z={z}',
    'vector.normalize_zero' => 'uzunluğu sıfır olan vektör normalize edilemez',
    'vector.normalize_overflow' => 'uzunluğu sonsuzluğa taşan vektör normalize edilemez; alınan length={length}',
    'point.components_finite' => 'Point bileşenleri sonlu olmalı; alınan x={x}, y={y}, z={z}',
    'bezier.points_finite' => 'cubic-bezier kontrol noktaları sonlu olmalı, alınan [{x1}, {y1}, {x2}, {y2}]',
    'bezier.x_range' => 'CubicBezier kontrol noktalarının x değerleri [0, 1] aralığında olmalı; alınan x1={x1}, x2={x2}',
    'bezier.t_finite' => 't sonlu olmalıdır; alınan {t}',
    'bezier.unknown_static' => 'CubicBezier içinde {name} adlı statik fabrika yok',
    'projectile.dt_finite' => 'deltaTime sonlu olmalı; alınan {deltaTime}',
    'projectile.negative_dt' => 'deltaTime >= 0 olmalı; alınan {deltaTime}',
    'projectile.components_finite' => 'Mermi {name} bileşenleri sonlu olmalı',
];
