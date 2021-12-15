<?php

namespace Common;

class ListInputData extends InputData
{
    public function __construct(array $data = [], array $template = [])
    {
        parent::__construct($data, array_merge($template, [
            'query' => 'varchar',
            'sort' => 'varchar',
            'sortBy' => 'varchar',
            'limit' => 'int',
            'offset' => 'int',
            'archived' => 'int',
            'searchFields' => 'json',
            'ExcludeIDs' => 'varchar'
        ]));
    }

    protected function cleanData($value, $type)
    {
        if ($type === 'json') {
            return json_decode($value, 1) ? $value : "{}";
        }

        return parent::cleanData($value, $type);
    }
}