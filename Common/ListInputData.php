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
            'searchFields' => 'varchar',
            'ExcludeIDs' => 'varchar'
        ]));
    }
}