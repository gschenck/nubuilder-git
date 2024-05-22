<?php
/**
* @suppress PHP0413 
*/
namespace nuFileSystemSync;
require_once 'logger.php';
require_once './vendor/autoload.php';
require_once 'context.php';
require_once 'synchronizer.php';
use Symfony\Component\Yaml\Yaml;

$log = new Logger();
$log->addTarget("/scripts/log.txt", true);
$log->addTarget("php://stdout");

function main() {
    global $log;
    mb_internal_encoding('UTF-8');
    $config = Yaml::parseFile('/scripts/sync.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
    $start_time = microtime(true); 
    $context = new Context($config);
    $context->loadControls('/scripts');
    if ($context->control->stop && !$context->control->once) {
        return;
    }
    $log->print("Sync ". date('d.m.Y H:i:s', time()));
    $synchronyzer = new Synchronizer($context);
    $synchronyzer->sync();
    $end_time = microtime(true); 
    $execution_time = ($end_time - $start_time); 
    $log->print("Execution time: ".$execution_time." sec");
} 

main();
