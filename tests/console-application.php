<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel('dev', true);
$kernel->boot();

return new Application($kernel);
