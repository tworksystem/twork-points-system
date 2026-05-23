<?php

namespace TWorkPointsSystem\Tests;

class WPDBStub
{
    public string $prefix = 'wp_';
    public array $queries = [];
    public array $insertLog = [];
    public array $updateLog = [];
    public array $getVarQueue = [];
    public $rowResult;
    public int $insert_id = 0;

    public function query($sql)
    {
        $this->queries[] = $sql;
        return true;
    }

    public function prepare($query, ...$args)
    {
        // Basic sprintf-style substitution suitable for tests
        return vsprintf($query, $args);
    }

    public function get_var($query)
    {
        $this->queries[] = $query;
        if (!empty($this->getVarQueue)) {
            return array_shift($this->getVarQueue);
        }
        return null;
    }

    public function enqueueGetVarResult($value): void
    {
        $this->getVarQueue[] = $value;
    }

    public function insert($table, $data, $format)
    {
        $this->insertLog[] = [
            'table' => $table,
            'data' => $data,
            'format' => $format,
        ];
        $this->insert_id = count($this->insertLog);
        return true;
    }

    public function get_row($query)
    {
        $this->queries[] = $query;
        return $this->rowResult;
    }

    public function setRowResult($row): void
    {
        $this->rowResult = $row;
    }

    public function update($table, $data, $where, $format, $whereFormat)
    {
        $this->updateLog[] = [
            'table' => $table,
            'data' => $data,
            'where' => $where,
            'format' => $format,
            'where_format' => $whereFormat,
        ];
        return true;
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->insertLog = [];
        $this->updateLog = [];
        $this->getVarQueue = [];
        $this->rowResult = null;
        $this->insert_id = 0;
    }
}

