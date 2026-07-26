<?php

declare(strict_types=1);

use MailPanel\Bootstrap\ApplicationFactory;
use MailPanel\Bootstrap\Environment;

require_once __DIR__ . '/../vendor/autoload.php';
Environment::load(dirname(__DIR__));

return ApplicationFactory::create(dirname(__DIR__));
