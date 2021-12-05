<?php

namespace Common;

class ListInputData extends InputData
{
    public function __construct(array $data = [])
    {
        $template = [
            'query' => 'varchar',
            'sort' => 'varchar',
            'sortBy' => 'varchar',
            'limit' => 'int',
            'offset' => 'int',
            'archived' => 'int',
            'searchFields' => 'varchar',
            'ExcludeIDs' => 'varchar'
        ];

        parent::__construct($data, $template);
    }
}