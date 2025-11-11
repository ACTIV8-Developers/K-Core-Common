<?php

namespace Common;

use App\Models\TblCompany;
use App\Models\TblDivision;
use App\Models\TblOffice;
use App\Models\TblState;
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

    public function appendQueryForFieldsNoChunking($queryParam, $fields, $query): string
    {
        if (!empty($query)) {
            $queryParam .= empty($queryParam) ? " (" : " AND (";
            $likeQuery = "";
            foreach ($fields as $f) {
                $likeQuery .= sprintf(" %s LIKE '%%%s%%' OR ", $f, $this->escapeQueryParam($query));
            }
            $likeQuery = substr($likeQuery, 0, strlen($likeQuery) - 3);
            $queryParam .= sprintf("(%s) AND ", $likeQuery);
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

    public function escapeQueryParam($input)
    {
        // Replace single quotes and double quotes
        $input = str_replace("'", "''", $input);
        $input = str_replace('"', '""', $input);

        // Optionally escape other characters like semicolons if necessary
        return str_replace(";", "\\;", $input);
    }

    public function getBilledByDataForOffice(int $OfficeID, ?int $CompanyID = null): array
    {
        // Billed by
        $result = $this->ResourceManager->findByID(new TblCompany(), $CompanyID ?? $this->IAM->getCompanyID());

        $Office = $this->ResourceManager->findByID(new TblOffice(), $OfficeID);
        $Division = !empty($Office) ? $this->ResourceManager->findByID(new TblDivision(), $Office['DivisionID']) : [];

        if (!empty($Office) && $Office['AccountingDocumentName'] == 2) {
            $result['CompanyName'] = $Division['DivisionName'];
        }
        if (!empty($Office) && $Office['AccountingDocumentAddress'] == 2) {
            $result['AddressName'] = $Division['AddressName'];
            $result['AddressName2'] = $Division['AddressName2'];
            $result['CityName'] = $Division['CityName'];
            $result['State'] = $Division['State'];
            $result['StateID'] = $Division['StateID'];
            $result['Country'] = $Division['Country'];
            $result['CountryID'] = $Division['CountryID'];
            $result['PostalCode'] = $Division['PostalCode'];
            $result['AreaCode'] = $Division['AreaCode'];
            $result['PhoneNumber'] = $Division['PhoneNumber'];
            $result['PhoneExtension'] = $Division['PhoneExtension'];
            $result['MCNumber'] = $Division['MC'] ?? "";
            $result['FederalID'] = $Division['FederalID'] ?? "";
        }
        if (!empty($Office) && $Office['AccountingDocumentLogo'] == 2) {
            $result['ServerImagePath'] = $this->TemplatesManagerInterface->getDivisionLogoUrl($Office['DivisionID']);
            $result['ImagePath'] = $this->TemplatesManagerInterface->getDivisionLogoUrl($Office['DivisionID']);
        } else {
            $result['ServerImagePath'] = $this->TemplatesManagerInterface->getCompanyLogoUrl($CompanyID);
            $result['ImagePath'] = $this->TemplatesManagerInterface->getCompanyLogoUrl($CompanyID);
        }

        $State = $this->ResourceManager->findByID(new TblState(), $result['StateID'] ?? 0);

        if (!empty($State['StateAbbreviation'])) {
            $result['StateAbbreviation'] = $State['StateAbbreviation'];
        } else {
            $result['StateAbbreviation'] = '';
        }
        return $result;
    }
}