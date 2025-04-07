<?php

namespace Common;

use Carbon\Carbon;
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
        $dao->setDb($this->db);
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
                    $likeQuery .= sprintf(" %s LIKE '%%%s%%' OR ", $f, $this->escapeQueryParam($chunk));
                }
                $likeQuery = substr($likeQuery, 0, strlen($likeQuery) - 3);
                $queryParam .= sprintf("(%s) AND ", $likeQuery);
            }
            return substr($queryParam, 0, strlen($queryParam) - 4) . ")";
        }
        return $queryParam;
    }

    public function appendExcludeQueryForFields($queryParam, $fields, $query): string
    {
        if (!empty($query)) {
            $queryParam .= empty($queryParam) ? " (" : " AND (";
            $chunks = explode(' ', $query);
            foreach ($chunks as $chunk) {
                $likeQuery = "";
                foreach ($fields as $f) {
                    $likeQuery .= sprintf(" %s NOT LIKE '%%%s%%' AND ", $f, $this->escapeQueryParam($chunk));
                }
                $likeQuery = substr($likeQuery, 0, strlen($likeQuery) - 4);
                $queryParam .= sprintf("(%s) AND ", $likeQuery);
            }
            return substr($queryParam, 0, strlen($queryParam) - 5) . ")";
        }
        return $queryParam;
    }

    public function toFrontDateTime($date, $format = "m/d/Y H:i"): ?string
    {
        if ($date) {
            $dt = Carbon::parse($date);
            return $dt->format($format);
        }
        return null;
    }

    public function toFrontDate($date, $format = "m/d/Y"): ?string
    {
        if ($date) {
            $dt = Carbon::parse($date);
            return $dt->format($format);
        }
        return null;
    }

    public function memberOfGroupQuery($table, $query): string
    {
        $Contact = $this->user['Contact'];

        $memberQuery = '1=1';
        if (empty($Contact['AllowAccessToAll'])) {
            $memberQuery = sprintf($table . ".ContactGroupID" . " IN (SELECT tbl_ContactInGroup.ContactGroupID FROM tbl_ContactInGroup WHERE tbl_ContactInGroup.ContactID=%d)", $Contact['ContactID']);
        }

        return empty($query) ? $memberQuery : ' AND ' . $memberQuery;
    }

    protected function escapeQueryParam($input): string
    {
        // Replace single quotes and double quotes
        $input = str_replace("'", "''", $input);
        $input = str_replace('"', '""', $input);

        // Optionally escape other characters like semicolons if necessary
        return str_replace(";", "\\;", $input);
    }
}