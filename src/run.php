<?php

use Symfony\Component\Yaml\Yaml;

require_once(dirname(__FILE__) . "/../vendor/autoload.php");

require_once "Keboola/ZuoraEx/Zuora.php";

$arguments = getopt("d::", array("data::"));
if (!isset($arguments["data"])) {
    print "Data folder not set.";
    exit(1);
}

$config = Yaml::parse(file_get_contents($arguments["data"] . "/config.yml"));

try {
    $zuora = new Zuora(
        $config['parameters'],
        $arguments["data"] . "/out/tables/"
    );

    $zuora->run();
} catch (Exception $e) {
    print $e->getMessage();
    exit(1);
}

exit(0);
