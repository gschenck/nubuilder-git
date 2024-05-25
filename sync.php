<?php
/**
* @suppress PHP0413 
*/
namespace nuFileSystemSync;
require_once 'logger.php';
require_once './vendor/autoload.php';
require_once 'context.php';
require_once 'synchronizer.php';
require_once 'file_utils.php';
use Symfony\Component\Yaml\Yaml;

$log = new Logger();
$log->addTarget("/scripts/log.txt", true);
$log->addTarget("php://stdout");

function main() {
    global $argc, $argv;
    mb_internal_encoding('UTF-8');
    
    $config = Yaml::parseFile('/scripts/sync.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
    $start_time = microtime(true); 
    $context = new Context($config);
    $context->loadControls('/scripts');
    $synchronyzer = new Synchronizer($context);
    // Check command line parameters
    $params = [];
    if($argc > 1) {
        parse_str(implode('&',array_slice($argv, 1)), $params);
    }
    $paramNames = array_keys($params);
    $init = preg_grep('/(-)?init/i', $paramNames);  
    if ((count($init) > 0) || $context->control->init) {
        // Initialize the Sync folder and the git_sync table
        $GLOBALS['log']->print('Initialize syncronization data '. date('d.m.Y H:i:s', time()));
        empty_dir($context->folders->target->database);
        empty_dir($context->folders->target->code);
        @file_put_contents(merge_paths($context->folders->target->root, "version"), $context->version);
        $synchronyzer->init(true);
    } else {
        // check config file version
        if (!$context->checkVersion()) {
            $GLOBALS['log']->print("Versions of sync.yaml and folder {$context->folders->target->root} are not the same.\nPlease run with -init parameter");
            exit(1);
        }
    }
    if ($context->control->stop && !$context->control->once) {
        exit(0);
    }
    $GLOBALS['log']->print("Sync ". date('d.m.Y H:i:s', time()));
    $synchronyzer->init(false);
    $synchronyzer->sync();
    $end_time = microtime(true); 
    $execution_time = ($end_time - $start_time); 
    $GLOBALS['log']->print("Execution time: ".$execution_time." sec");
} 

main();
