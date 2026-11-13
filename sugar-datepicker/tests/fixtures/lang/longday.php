<?php

/**
 * Synthetic bad-shape locale fixture (dp-14): day names that blow the 3-column
 * cell budget. This file deliberately lives OUTSIDE lang/ so the shipped
 * locale glob never validates it; it exists to prove (a) the validator bites
 * and (b) the renderer clamps per slot.
 *
 * @return array<string, string>
 */

return [
    'day.1' => 'Montag',
    'day.5' => 'Freitag',
] + require __DIR__ . '/../../../lang/en.php';
