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
                    $likeQuery .= sprintf(" ISNULL(CAST(%s AS NVARCHAR(MAX)),'') NOT LIKE '%%%s%%' AND ", $f, $this->escapeQueryParam($chunk));
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

    public function getBilledByDataForOffice(int $OfficeID, ?int $CompanyID = null, $isDispatch = false): array
    {
        // Billed by
        $result = $this->ResourceManager->findByID(new TblCompany(), $CompanyID ?? $this->IAM->getCompanyID());

        $Office = $this->ResourceManager->findByID(new TblOffice(), $OfficeID);
        $Division = !empty($Office) ? $this->ResourceManager->findByID(new TblDivision(), $Office['DivisionID']) : [];

        if (!empty($Office) &&
            (
                ($Office['AccountingDocumentName'] == 2 && empty($isDispatch))
                ||
                ($Office['DispatchDocumentName'] == 2 && !empty($isDispatch))
            )
        ) {
            $result['CompanyName'] = $Division['DivisionName'];
        }
        if (!empty($Office) &&
            (
                ($Office['AccountingDocumentAddress'] == 2 && empty($isDispatch))
                ||
                ($Office['DispatchDocumentAddress'] == 2 && !empty($isDispatch))
            )
        ) {
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
        if (!empty($Office) &&
            (
                ($Office['AccountingDocumentLogo'] == 2 && empty($isDispatch))
                ||
                ($Office['DispatchDocumentLogo'] == 2 && !empty($isDispatch))
            )
        ) {
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

    /**
     * The billed-by logo as a data: URI for PDF templates, so wkhtmltopdf does not
     * fetch it back through the public load balancer. Picks the same logo as
     * getBilledByDataForOffice (division logo when the office uses it, else the
     * company's), scoped to the caller's company. Returns null on any failure so
     * the caller keeps the URL. Needs $s3 and $logger on the using class.
     */
    public function getEmbeddedLogo(?int $OfficeID, bool $isDispatch = false): ?string
    {
        $CompanyID = $this->IAM->getCompanyID();
        if (empty($CompanyID) || !isset($this->s3)) {
            return null;
        }

        try {
            $Office = null;
            if (!empty($OfficeID)) {
                $Office = $this->getDaoForObject(TblOffice::class)
                    ->select('DivisionID, AccountingDocumentLogo, DispatchDocumentLogo')
                    ->where(['OfficeID' => $OfficeID, 'CompanyID' => $CompanyID])
                    ->getOne();
                if (empty($Office)) {
                    return null;
                }
            }

            $useDivision = !empty($Office) && $Office[$isDispatch ? 'DispatchDocumentLogo' : 'AccountingDocumentLogo'] == 2;
            if ($useDivision) {
                $Logo = $this->getDaoForObject(TblDivision::class)
                    ->select('ImagePath')
                    ->where(['DivisionID' => $Office['DivisionID'], 'CompanyID' => $CompanyID])
                    ->getOne();
            } else {
                $Logo = $this->getDaoForObject(TblCompany::class)
                    ->select('ImagePath')
                    ->where(['CompanyID' => $CompanyID])
                    ->getOne();
            }
            if (empty($Logo['ImagePath'])) {
                return null;
            }

            // S3::get prefixes the key with the caller's CompanyID
            $bytes = (string)$this->s3->get($Logo['ImagePath'], DOCUMENTS_BUCKET)['Body'];
            $mime = $bytes === '' ? false : (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if ($mime === false || strpos($mime, 'image/') !== 0) {
                return null;
            }

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (\Throwable $e) {
            $this->logger->warning('PDF logo not embedded: ' . $e->getMessage(), ['OfficeID' => $OfficeID]);
            return null;
        }
    }
}