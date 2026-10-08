<?php

/**
 * English (default) translations for honey-bounce.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'spring.fps_positive' => 'fps must be > 0; got {fps}',
    'spring.inputs_finite' => 'Spring inputs must be finite; got deltaTime={deltaTime}, angularFrequency={angularFrequency}, dampingRatio={dampingRatio}',
    'spring.negative_dt' => 'deltaTime must be >= 0; got {deltaTime}',
];
