<?php
/**
* @suppress PHP0413 
*/
namespace nuFileSystemSync;
require_once './vendor/autoload.php';
require_once 'context.php';
require_once 'synchronizer.php';
use Symfony\Component\Yaml\Yaml;

function main() {
    mb_internal_encoding('UTF-8');
    $config = Yaml::parseFile('/scripts/sync.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
    $start_time = microtime(true); 
    $context = new Context($config);
    $synchronyzer = new Synchronizer($context);
    $synchronyzer->sync();
    $end_time = microtime(true); 
    $execution_time = ($end_time - $start_time); 
    print("Execution time: ".$execution_time." sec\n");
} 

main();
