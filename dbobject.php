<?php
namespace nuFileSystemSync;

require_once 'context.php';
require_once 'synchronizer.php';

class DBObject
{
    private $context;
    public $table;
    private $objectDescriptor;
    public $tableName;
    public $folderName;
    public function __construct($objectDescriptor, &$context)
    {
        $this->context = $context;
        $this->objectDescriptor = $objectDescriptor;
        $this->tableName = Context::getName($objectDescriptor);
        $this->table = $this->context->tables[$this->tableName];
        $this->folderName = merge_paths($this->context->folders->target->database, $this->tableName);
    }

    public function load($id)
    {
        if (array_key_exists($id, $this->table->records)) {
            $record = $this->table->records[$id];
            $record->processed = true;
            $objectData = array_merge($record->data, $this->addRelations($this->objectDescriptor, $id, $record));
            return $objectData;
        } else return null;
    }

    public static function getPrimaryKey($tableName, &$recordData) {
        $pk = new \stdClass();
        $pk->name = $tableName.'_id';
        $pk->value = $recordData[$pk->name];
        return $pk;
    }

    private function addRelations(&$objectDescriptor, $pk, $record)
    {
        $section = [];
        if (property_exists($objectDescriptor, 'one-many')) {
            $section['one-many'] = [];
            foreach ($objectDescriptor->{'one-many'} as $relation) {
                $section['one-many'][Context::getName($relation)] = $this->constructObject($relation, $pk, $relation->fk);
            }
        }
        if (property_exists($objectDescriptor, 'many-one')) {
            $section['many-one'] = [];
            foreach ($objectDescriptor->{'many-one'} as $relation) {
                $relation_id = $record->data[$relation->fk];
                $tableName = Context::getName($relation);
                $pk_name = $tableName . '_id';
                $section['one-many'][$tableName] = $this->constructObject($relation, $relation_id, $pk_name);
            }
        }
        return $section;
    }
    private function constructObject(&$objectDescriptor, $pk_value, $fk_name = NULL)
    {
        if (preg_match('/(.*)\[(.*)\]/', $fk_name, $match)) {
            $fk_name = $match[1];
            $rg = "/^" . preg_quote($pk_value, '/') . $match[2] . "/";
        }
        $dump_rows = [];
        $table = $this->context->tables[Context::getName($objectDescriptor)];
        foreach ($table->records as $pk => $record) {
            if ($fk_name) {
                $value = $record->data[$fk_name];
                $m = (isset($rg)) ? preg_match($rg, $value) : $value == $pk_value;
                if (!$m) {
                    continue;
                }
            }
            $single_row = $record->data;
            $record->processed = true;
            $dump_rows[] = array_merge($single_row, $this->addRelations($objectDescriptor, $pk, $record));
        }
        return $dump_rows;
    }
}