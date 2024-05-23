<?php
namespace nuFileSystemSync;
require_once 'file_utils.php';
require_once 'logger.php';
class Context {
    public $folders;
    public $database;
    public $sync;
    public $scheme;
    public $objects;
    public $tables;
    public $files;
    public $control;
    public function __construct($config) {
        $this->folders = $config->folders;
        $servername =   getenv('DB_HOST');
        $scheme =       getenv('DB_NAME');
        $username =     getenv('DB_USER');
        $password =     getenv('DB_PASS');
        $this->database = new \PDO("mysql:host=$servername;dbname=$scheme", $username, $password);
        $this->sync = new \PDO("mysql:host=$servername;dbname=$scheme", $username, $password);
        $this->scheme = $scheme;
        $this->objects = $config->tables;
        $this->tables = $this->createTablesList($this->objects);   
        $this->control = new \StdClass();
        $this->init();
    }

    public static function getName(&$objectDescriptor) {
        if (is_object($objectDescriptor)) {
            return array_keys((array)$objectDescriptor)[0];
        } else {
            return $objectDescriptor;
        }
    }

    public function loadControls($dir) {
        global $log;
        $this->control->stop = file_exists(merge_paths($dir, 'stop'));
        $once = merge_paths($dir, 'once');
        $this->control->once = file_exists($once);
        @unlink($once);
        $this->control->test = file_exists(merge_paths($dir, 'test'));
        if (file_exists(merge_paths($dir, 'info'))) {
            $log->setLevel(Logger::INFO);
        }
        if (file_exists(merge_paths($dir, 'debug'))) {
            $log->setLevel(Logger::DEBUG);
        }
    }

    private function init() {
        // Create folders if needed
        ensure_exists($this->folders->target->database);
        ensure_exists($this->folders->target->code);

        // load Tables in memory
        foreach ($this->tables as $table => &$value) {
            $value = $this->loadTable($table);
        }
        
        //load file structure for DB objecrts
        $this->files = $this->loadYamlFiles($this->folders->target->database);
        $this->webCode = $this->loadSourceFiles($this->folders->source->root);
        $this->gitCode = $this->loadSourceFiles($this->folders->target->code);
    }

    private function createTablesList($startingPoint) {
        $list = []; 
        foreach ($startingPoint as $objectDescriptor) {
            $tablename = $this->getName($objectDescriptor);
            $list[$tablename] = null;
            if (property_exists($objectDescriptor, 'one-many')) {
                $list = array_merge($list, $this->createTablesList($objectDescriptor->{'one-many'}));
            }
            if (property_exists($objectDescriptor, 'many-one')) {
                $list = array_merge($list, $this->createTablesList($objectDescriptor->{'many-one'}));
            }
        }
        return $list;
    }

    private function loadTable($name) {
        $result = new \StdClass();
        // load columns
        $s = $this->database->prepare("DESCRIBE {$name}");
        $s->execute();
        $result->columns = [];
        while ($row = $s->fetchObject()) {
            $result->columns[] = $row->Field;
        }
        // load data
        $data = [];
        $s = $this->database->prepare("SELECT * FROM {$name} order by {$name}_id");
        $s->execute();
        while ($row = $s->fetch(\PDO::FETCH_ASSOC)) {
            $data_row = new \StdClass();
            $data_row->processed = false;
            $data_row->data = [];
            foreach ($result->columns as $col) {
                $value = $row[$col];
                $data_row->data[$col] = $value;
            }
            $data[$row["{$name}_id"]] = $data_row;
        }
        $result->records = $data;
        return $result;
    }

    private function loadYamlFiles($dir) {
        $result = [];
        $dirList = array_diff(scandir($dir), array('.', '..'));
        foreach ($dirList as &$item) {
            $item = merge_paths($dir, $item);
            $pi = pathinfo($item);
            if (key_exists('extension', $pi) && $pi['extension'] == 'yaml') {
                $result[$item] = false;
            }
        }

        foreach ($dirList as $item) {
            if (is_dir($item)) {
                $result = array_merge($result, $this->loadYamlFiles($item));
            }
        }
        return $result;
      }

      private function loadSourceFiles($root) {
        $result = [];
        foreach ($this->folders->source->items as $wildcard) {
            $fw = merge_paths($root, $wildcard);
            foreach (glob($fw) as $fileName) {
                $f = new \stdClass();
                $f->time = $this->getFileTime($fileName);
                $f->processed = false;
                if (strpos($fileName, $root) == 0) {
                    $fileName = substr($fileName, strlen($root) + 1);
                }
                $result[$fileName] = $f;
            }
        }
        return $result;
      }

      public function getFileTime($fileName)
      {
          clearstatcache(true, $fileName);
          $ft = @filemtime($fileName);
          if ($ft !== false) {
              $d = date('d-m-Y H:i:s e', $ft);
              $t = new \DateTime($d);
              return $t;
          }
      }

      public function getDescriptorByTableName($tableName, $startingPoint = null) {
        if (is_null($startingPoint)) {
            $startingPoint = $this->objects;
        }
        foreach ($startingPoint as $objectDescriptor) {
            $name = $this->getName($objectDescriptor);
            if ($name === $tableName) {
                return $objectDescriptor;
            }
            if (property_exists($objectDescriptor, 'one-many')) {
                $od = $this->getDescriptorByTableName($tableName, $objectDescriptor->{'one-many'});
                if (! is_null($od)) {
                    return $od;
                }
            }
            if (property_exists($objectDescriptor, 'many-one')) {
                $od = $this->getDescriptorByTableName($tableName, $objectDescriptor->{'many-one'});
                if (! is_null($od)) {
                    return $od;
                }
            }
        }
      }
}
