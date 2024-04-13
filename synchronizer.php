<?php
namespace nuFileSystemSync;
/**
* @suppress PHP0413 
*/
require_once './vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

require_once 'context.php';
require_once 'dbobject.php';


class Synchronizer
{
    const TO_FS = 0;
    const TO_NU = 1;
    const DELETE_NU = 2;
    const DELETE_FS = 3;
    private $context;
    public function __construct($context)
    {
        $this->context = $context;
        $this->init();
    }

    private function init()
    {
        $s = <<<SQL
            CREATE TABLE IF NOT EXISTS git_sync (
                path VARCHAR(255) NOT NULL,
                this_turn TINYINT NULL,
                ts TIMESTAMP NOT NULL,
                PRIMARY KEY (path)
            )
        SQL;
        $this->context->database->exec($s);
    }

    public function sync()
    {
        $s = "UPDATE git_sync SET this_turn = 0";
        $this->context->database->exec($s);
        // [DATABASE section]
        // Normal objects
        foreach ($this->context->objects as $objectDescriptor) {
            $object = new DBObject($objectDescriptor, $this->context);
            $folderName = $object->folderName;
            $tableName = $object->tableName;
            ensure_exists($folderName);
            $table = $object->table;
            foreach ($table->records as $pk => $record) {
                $objectData = $object->load($pk);
                $this->syncObject($folderName, $pk, $tableName, $objectData);
            }
        }
        // orphan records - these tables usually processed from "one-many" & "many-one" sections of objects,
        // but some records not related to others
        foreach ($this->context->tables as $name => $table) {
            $folderName = merge_paths($this->context->folders->target->database, $name);
            $first_time = true;
            foreach ($table->records as $pk => $record) {
                if (!$record->processed) {
                    if ($first_time) {
                        ensure_exists($folderName);
                        $first_time = false;
                    }
                    $this->syncObject($folderName, $pk, $name, $record->data);
                }
            }
        }
        // files yaml, which does not exist in DB
        foreach ($this->context->files as $fileName=>$processed) {
            if ($processed === false) {
                $this->syncFile($fileName);
            }
        }

        // [ADDITIONAL FILES (mostly code) section]
        // 1. from web to git
        foreach ($this->context->webCode as $fileName=>$fileDetails) {
            $nuTime = $fileDetails->time;
            $fileDetails->processed = true;
            $syncTime = $this->getSyncTime($fileName);
            if (array_key_exists($fileName, $this->context->gitCode)) {
                $gitFileDetails = $this->context->gitCode[$fileName];
                $gitFileDetails->processed = true;
                $gitTime = $gitFileDetails->time;
            }
            $direction = null;
            if (isset($gitTime)) {
                if ($nuTime < $gitTime) {
                    $direction = Synchronizer::TO_NU;
                }
                if ($nuTime > $gitTime) {
                    $direction = Synchronizer::TO_FS;
                }
            } else {
                if (isset($syncTime)) {
                    $direction = Synchronizer::DELETE_NU;
                } else {
                    $direction = Synchronizer::TO_FS;
                }
            }
            switch ($direction) {
                case Synchronizer::TO_NU:
                    $fileTime = $this->copyFile($fileName, $this->context->folders->target->code, $this->context->folders->source->root);
                    $this->mark_synchonized($fileName, $fileTime);
                    break;
                case Synchronizer::TO_FS:
                    $fileTime = $this->copyFile($fileName, $this->context->folders->source->root, $this->context->folders->target->code);
                    $this->mark_synchonized($fileName, $fileTime);
                    break;
                case Synchronizer::DELETE_NU:
                    @unlink(merge_paths($this->context->folders->source->root, $fileName));
                    $this->delete_synchonization($fileName);
                    break;
            }
            $this->console($fileName, "", $direction);
        }
        // 2. from git to web
        foreach ($this->context->gitCode as $fileName=>$fileDetails) {
            if ($fileDetails->processed) {
                continue;
            }
            $gitTime = $fileDetails->time;
            $fileDetails->processed = true;
            $syncTime = $this->getSyncTime($fileName);
            $direction = null;

            if (isset($syncTime)) {
                if ($syncTime < $gitTime) {
                    $direction = Synchronizer::TO_NU;
                } else {
                    $direction = Synchronizer::DELETE_FS;
                }
            } else {
                $direction = Synchronizer::TO_NU;
            }
            switch ($direction) {
                case Synchronizer::TO_NU:
                    $fileTime = $this->copyFile($fileName, $this->context->folders->target->code, $this->context->folders->source->root);
                    $this->mark_synchonized($fileName, $fileTime);
                    break;
                case Synchronizer::DELETE_FS:
                    @unlink(merge_paths($this->context->folders->target->code, $fileName));
                    $this->delete_synchonization($fileName);
                    break;
            }
            $this->console($fileName, "", $direction);
        }
        $s = "DELETE FROM git_sync WHERE this_turn = 0";
        $this->context->database->exec($s);
    }

    private function copyFile($filename, $from, $to) {
        $from = merge_paths($from, $filename);
        $to = merge_paths($to, $filename);
        $pi = pathinfo($to);
        ensure_exists($pi['dirname']);
        if (copy($from, $to)) {
            chmod($to, 0666);
            $ft = @filemtime($from);
            @touch($to, $ft);
            $ts = $this->getFileTime($to);
            return $ts;
        }
    }

    /**
     * Syncronises object data loaded from DB with file system file, probably existing. 
     * Possible actions, depending on last modified time of file and last sync time:
     * - save file to db
     * - save db to file
     * - delete from db
     * @param string $folderName
     * @param string $pk
     * @param string $tableName
     * @param array $objectData 
     * 
     */
    private function syncObject($folderName, $pk, $tableName, $objectData) {
        $dbYaml = Yaml::dump($objectData, 10, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $path = merge_paths($folderName, $pk) . ".yaml";
        $fsYaml = @file_get_contents($path);
        if (($fsYaml === false) || ($dbYaml != $fsYaml)) {
            //who is older - sync time or file modification time?
            $syncTime = $this->getSyncTime($path);
            $fileTime = $this->getFileTime($path);
            $direction = Synchronizer::TO_FS;
            if (isset($syncTime)) {
                if (isset($fileTime)) {
                    if ($syncTime < $fileTime) {
                        $direction = Synchronizer::TO_NU;
                    } else {
                        $direction = Synchronizer::TO_FS;
                    }
                } else {
                    $direction = Synchronizer::DELETE_NU;
                }
            } else {
                if (isset($fileTime)) {
                    $direction = Synchronizer::TO_FS;
                } else {
                    $direction = Synchronizer::TO_FS;
                }
            }
            if ($direction == Synchronizer::TO_FS) {
                $fileTime = $this->objectToFile($folderName, $pk, $dbYaml);
                $this->mark_synchonized($path, $fileTime);
            };
            if ($direction == Synchronizer::TO_NU) {
                $this->objectToDatabase($tableName, $fsYaml);
                $this->mark_synchonized($path, $fileTime);
            }
            if ($direction == Synchronizer::DELETE_NU) {
                $this->objectDeleteFromDatabase($tableName, $objectData);
                $this->delete_synchonization($path);
            };
            $this->console($tableName, $pk, $direction);
        } else {
            $fileTime = $this->getFileTime($path);
            $this->mark_synchonized($path, $fileTime);
        }
        $this->context->files[$path] = true;
    }

    
    /**
     * Creates records in database or deletes file depending of modification and last sync time
     * @param string $fileName
     */
    private function syncFile($fileName) {
        $fi = pathinfo($fileName);
        $tableName = substr($fi['dirname'], strlen($this->context->folders->target->database));
        $pk = $fi['filename'];
        $syncTime = $this->getSyncTime($fileName);
        $fileTime = $this->getFileTime($fileName);
        $direction = Synchronizer::TO_NU;
        if (isset($syncTime)) {
            if ($syncTime < $fileTime) {
                $direction = Synchronizer::TO_NU;
            } else {
                $direction = Synchronizer::DELETE_FS;
            }
        } else {
            $direction = Synchronizer::TO_NU;
        }
        $fsYaml = @file_get_contents($fileName);
        if ($direction == Synchronizer::TO_NU) {
            $this->objectToDatabase($tableName, $fsYaml);
            $this->mark_synchonized($fileName, $fileTime);
        }
        if ($direction == Synchronizer::DELETE_FS) {
            @unlink($fileName);
            $this->delete_synchonization($fileName);
        };
        $this->console($tableName, $pk, $direction);
        $this->context->files[$fileName] = true;
    }

    

    /**
     * Saves yaml content to file
     * @param string $folderName - where the file will be placed
     * @param string $fileName - without extention
     * @param string $yaml - file content
     * @return \DateTime - file creation date
     */
    private function objectToFile($folderName, $fileName, $yaml)
    {
        $path = merge_paths($folderName, $fileName) . ".yaml";
        file_put_contents($path, $yaml);
        chmod($path, 0666);
        $ts = $this->getFileTime($path);
        return $ts;
    }

    private function objectToDatabase($tableName, $fsYaml) {
        try {
            $fsObject =  Yaml::parse($fsYaml);
        } catch (\Exception $exception) {
            printf('Unable to parse the YAML string: %s', $exception->getMessage());
            return false;
        }
        // for deletion we should recreate object based on DB data instead of FS data, because we have to delete all new subrecords, 
        // probably not existing in FS
        $objecDEsctiptor = $this->context->getDescriptorByTableName($tableName);
        $dbObjectLoader = new DBObject($objecDEsctiptor, $this->context);
        $pk = DBObject::getPrimaryKey($tableName, $fsObject);
        $dbObject = $dbObjectLoader->load($pk->value);
        try {
            $this->context->database->beginTransaction();
            if ($dbObject) {
                $this->objectWalker($dbObject, $tableName, [$this, 'deleteRecordCallback']);
            }
            $this->objectWalker($fsObject, $tableName, [$this, 'addRecordCallback']);
            $this->context->database->commit();
        }
        catch (\Exception $exception) {
            print_r($exception->getMessage());
            $this->context->database->rollBack();
        }
    }

    /**
     * Deletes records in hierarchical @objectData
     * @param string $tableName - where record will be stored
     * @param array $objectData - single record and relation records
     */
    private function objectDeleteFromDatabase($tableName, &$objectData) {
        try {
            $this->context->database->beginTransaction();
            $this->objectWalker($objectData, $tableName, [$this, 'deleteRecordCallback']);
            $this->context->database->commit();
        }
        catch (\Exception $exception) {
            print_r($exception->getMessage());
            $this->context->database->rollBack();
        }
    }

    /**
     * Callback function for objectWalker
     * Deletes records in hierarchical @objectData
     * @param string $tableName - where record will be stored
     * @param array $record - single record as array of [column=>value]
     */
    private function deleteRecordCallback($tableName, $record) {
        $pk = $record[$tableName.'_id'];
        $s = "DELETE FROM {$tableName} WHERE `{$tableName}_id` = ?";
        $st = $this->context->database->prepare($s);
        $st->execute([$pk]);

    }

   /**
     * Callback function for objectWalker
     * should update single record 
     * but as this record may does not exist is db, the solution is to delete probably previous version and insert new one
     * @param string $tableName - where record will be stored
     * @param array $record - single record as array of [column=>value]
     */
    private function addRecordCallback($tableName, $record) {
        $pkName = $tableName.'_id';
        $pk = $record[$pkName];
        $s = "DELETE FROM {$tableName} WHERE `{$pkName}` = ?";
        $st = $this->context->database->prepare($s);
        $st->execute([$pk]);

        $s = "INSERT INTO {$tableName} (";
        $values = [];
        $first = true;
        foreach ($record as $column=>$value) {
            if (! $first) {
                $s.= ',';
            }
            $first = false;
            $s.="`$column`";
            $values[] = $value; 
        }
        $s.=") VALUES (".join(',',array_map(fn($x)=>'?',$values)).")";      
        $st = $this->context->database->prepare($s);
        $st->execute($values);  
    }

    /**
     * Recursively walks thru the record itself and one-many and many-one relations of objectData
     * calling callback function on each record
     * @param array $objectData - single record and relation records
     * @param string $tableName - table name where tompost (current) record belongs to
     * @param callable $callback - the callback function called on each record with paramerters ($tableName, $record)
     */
    private function objectWalker($objectData, $tableName, callable $callback) {
        $record = array_filter($objectData, fn($name)=> ($name!=='many-one') && ($name!=='one-many'),ARRAY_FILTER_USE_KEY);
        call_user_func($callback, $tableName, $record);
        if (array_key_exists('one-many', $objectData)) {
            $one_many = $objectData['one-many'];
            foreach ($one_many as $relationTableName=>$table) {
                foreach ($table as $record) {
                    $this->objectWalker($record, $relationTableName, $callback);
                }
            }
        }
        if (array_key_exists('many-one', $objectData)) {
            $one_many = $objectData['many-one'];
            foreach ($one_many as $relationTableName=>$table) {
                foreach ($table as $record) {
                    $this->objectWalker($record, $relationTableName, $callback);
                }
            }
        }
    }
 
    /**
     * returns file modification time in configured timezone
     * @param string $fileName - full path
     * @return mixed - DateTime or null if file does not exist
     */
    private function getFileTime($fileName)
    {
        return $this->context->getFileTime($fileName);
    }

    /**
     * returns last synchronized time for table and primary key
     * @param string $tableName
     * @param string $pk 
     * @return mixed -  DateTime or null if there was no any synchronizations
     */
    private function getSyncTime($path)
    {
        $st = $this->context->database->prepare('SELECT ts FROM git_sync WHERE path = ?');
        $st->execute([$path]);
        $row = $st->fetchObject();
        if ($row) {
            return new \DateTime($row->ts, $this->context->tz);
        }
    }

    public static function console($tableName, $pk, $direction) {
        static $strDirection = [
            Synchronizer::TO_NU =>     "DB <- FS",
            Synchronizer::TO_FS =>     "DB -> FS",
            Synchronizer::DELETE_NU => "DB DELETE",
            Synchronizer::DELETE_FS => "FS DELETE",
        ];
        if (is_null($direction)) {
            return;
        }
        if (gettype($direction) == 'string') {
            $reason = $direction;
        } else {
            $reason = $strDirection[$direction];
        }
        print(str_pad($tableName,50).str_pad($pk, 26).$reason."\n");
    }

    /** 
     * Marks database item as synchronized for table and primary key
     * @param string $tableName
     * @param string $pk
     * @param \DateTime $syncTime
     */
    private function mark_synchonized($path, $syncTime)
    {
        $moment = $syncTime->format('Y-m-d H:i:s');

        $s = 'DELETE FROM git_sync WHERE path = ?';
        $st = $this->context->database->prepare($s);
        $st->execute([$path]);

        $s = 'INSERT INTO git_sync (path, this_turn, ts) VALUES (?, 1, ?)';
        $st = $this->context->database->prepare($s);
        $st->execute([$path, $moment]);
    }

    private function delete_synchonization($path) {
        // Do nothing:
        // All records of the git_sync are set to 0 on the beginning of synchronization
        // and all zeros will be deleted on the last step of synchronization.
        // So, if we do nothisng with synchronization record, it will be removed on the last step
    }
}
