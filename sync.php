<?php
/**
* @suppress PHP0413 
*/
namespace nuFileSystemSync;
require_once './vendor/autoload.php';
require_once 'context.php';
require_once 'synchronizer.php';
use Symfony\Component\Yaml\Yaml;

$log_file = '/scripts/log.txt';

function main() {
    global $log_file;
    mb_internal_encoding('UTF-8');
    $config = Yaml::parseFile('/scripts/sync.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
    $start_time = microtime(true); 
    $context = new Context($config);
    $context->loadControls('/scripts');
    if ($context->control->stop && !$context->control->once) {
        return;
    }
    @file_put_contents($log_file, "Sync ". date('d.m.Y H:i:s', time()) . PHP_EOL);
    $synchronyzer = new Synchronizer($context);
    $synchronyzer->sync();
    $end_time = microtime(true); 
    $execution_time = ($end_time - $start_time); 
    Synchronizer::_console("Execution time: ".$execution_time." sec\n");
} 

main();
