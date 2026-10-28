<?php
declare(strict_types=1);
trait A { public const K = 42; }
trait B { use A; public function readTraitConst(): int { return A::K; } }
final class Host { use B; public function read(): int { return $this->readTraitConst(); } }
$h = new Host();
echo $h->read(), PHP_EOL;
echo \A::K, PHP_EOL;
