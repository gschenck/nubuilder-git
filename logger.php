<?php
namespace nuFileSystemSync;
class Logger {
    const DEBUG = 0;
    const INFO = 1;
    const WARNING = 2;
    const ERROR = 3;
    private $level = Logger::WARNING;
    private $targets = [];
    private $format = 'd.m.Y H:i:s';

    public function addTarget($target, $reset = false) {
        $this->targets[] = $target;
        if ($reset) {
            $result = @file_put_contents($target, "");
            if ($result === false) {
                print_r("Error initialize {$target}");
            }
        }
    }

    public function getLevel($level) {
        return $this->level;
    }    
    public function setLevel($level) {
        $this->level = $level;
    }

    public function debug($message) {
        if ($this->level <= Logger::DEBUG) {
            $this->log(date($this->format)." ".$this->getFunction()." [DEBUG] ".$message);
        }
    }
    public function info($message) {
        if ($this->level <= Logger::INFO) {
            $this->log(date($this->format)." ".$this->getFunction()." [INFO] ".$message);
        }
    }
    public function warning($message) {
        if ($this->level <= Logger::WARNING) {
            $this->log(date($this->format)." ".$this->getFunction()." [WARNING] ".$message);
        }
    }
    public function error($message) {
        if ($this->level <= Logger::ERROR) {
            $this->log(date($this->format)." ".$this->getFunction()." [ERROR] ".$message);
        }
    }

    private function getFunction() {
        $trace = debug_backtrace();
        if (count($trace) > 2) {
            $prev = $trace[2];
            return $prev["class"].".".$prev["function"];
        }
        return "{main}";
    }

    public function print($message) {
        $this->log($message);
    }

    private function log($message) {
        foreach ($this->targets as $target) {
            $result = file_put_contents($target, $message . PHP_EOL, FILE_APPEND);
            if ($result === false) {
                print_r("Error log to {$target}");
            }
        }
    }

}