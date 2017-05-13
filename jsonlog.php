<?php

// Move this file to document root.

require 'vendor/autoload.php';


use \SimpleComplex\JsonLog\JsonLog;


$logger = new JsonLog();

$committable = $logger->committable(true, true);
var_dump($committable);
echo "\n";

$logger->log(4, 'Does it {work}? Code is {code}.', array('work' => 'actually work', 'code' => 117));
