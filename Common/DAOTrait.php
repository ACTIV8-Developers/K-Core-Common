<?php

namespace Common;

use Common\Models\BaseDAO;

trait DAOTrait
{
    public function currentDateTime(): ?string
    {
        return date('Y-m-d H:i:s');
    }

    protected function getDaoForObject($class): BaseDAO
    {
        $dao = (new BaseDAO(new $class));
        $dao->setContainer($this->container);
        return $dao;
    }

    public function appendQueryForFields($queryParam, $fields, $query): string
    {
        if (!empty($query)) {
            $queryParam .= empty($queryParam) ? " (" : " AND (";
            $chunks = explode(' ', $query);
            foreach ($chunks as $chunk) {
                $likeQuery = "";
                foreach ($fields as $f) {
                    $likeQuery .= sprintf(" %s LIKE '%%%s%%' OR ", $f, $chunk);
                }
                $likeQuery = substr($likeQuery, 0, strlen($likeQuery) - 3);
                $queryParam .= sprintf("(%s) AND ", $likeQuery);
            }
            return substr($queryParam, 0, strlen($queryParam) - 4) . ")";
        }
        return $queryParam;
    }
}