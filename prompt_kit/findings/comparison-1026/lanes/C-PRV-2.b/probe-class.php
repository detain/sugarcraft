<?php
trait C { public const K = 'cls'; }
class User { use C; }
echo User::K;
