<?php
declare(strict_types=1);
require '/home/sites/prompt-step-P3.CLOSE-r1/sugar-crush/vendor/autoload.php';
require '/home/sites/prompt-step-P3.CLOSE-r1/sugar-crush/tests/RuntimeTest.php';
$m = new ReflectionMethod(\SugarCraft\Crush\Tests\RuntimeTest::class, 'writePrimitivesCalledIn');
$m->setAccessible(true);
foreach (array_slice($argv, 1) as $f) {
    $r = $m->invoke(null, $f);
    echo basename($f), ' => ', ($r === [] ? '[]' : json_encode(array_keys($r))), "\n";
}
