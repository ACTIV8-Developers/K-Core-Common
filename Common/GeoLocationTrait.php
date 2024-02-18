<?php

namespace Common;

use App\Models\TblCountry;
use App\Models\TblLocations;
use App\Models\TblState;

trait GeoLocationTrait
{
    protected function getLatLonFromAddressLine($defaults = [])
    {
        if (empty($defaults)) {
            $defaults = $this->data();
        }
        $country = $this->getDaoForObject(TblCountry::class)->where(['CountryID' => $defaults['CountryID']])->getOne();
        if ($country) {
            $country = $country['CountryName'];
        } else {
            $country = "";
        }

        $state = null;
        if (!empty($defaults['StateID'])) {
            $state = $this->getDaoForObject(TblState::class)->where(['StateID' => $defaults['StateID']])->getOne();
        }
        if ($state) {
            $state = $state['State'];
        } else {
            $state = "";
        }

        $Postal = $defaults['PostalCode'];
        if (($country === "USA") || ($country === "Canada")) {
            $Postal .= " " . $state;
        }

        $addr = $defaults['AddressName'];

        $addr = $addr . "," . $defaults['CityName'] . "," . $Postal . "," . $country;

        $key = getenv('GOOGLE_MAPS_GEO_KEY');
        $url = sprintf("https://maps.googleapis.com/maps/api/geocode/json?address=%s&key=%s", rawurlencode($addr), $key);
        $data = json_decode(file_get_contents($url), true);

        return $data['results'][0]['geometry']['location'] ?? [
            'lat' => 0,
            'lng' => 0
        ];
    }

    protected function getAddressFromLatLon($defaults = []): array
    {
        if (empty($defaults)) {
            $defaults = $this->data();
        }

        $Latitude = $defaults['Latitude'];
        $Longitude = $defaults['Longitude'];

        $key = getenv('GOOGLE_MAPS_GEO_KEY');
        $url = sprintf("https://maps.googleapis.com/maps/api/geocode/json?latlng=%s,%s&key=%s", $Latitude, $Longitude, $key);
        $data = json_decode(file_get_contents($url), true);
        $addressNumber = '';
        $address = '';
        $CityName = '';
        $StateID = null;
        $CountryID = null;
        $PostalCode = '';
        foreach ($data['results'][0]['address_components'] as $key => $value) {
            if (in_array("street_number", $value['types'])) {
                $addressNumber = isset($value['long_name']) ? $value['long_name'] . " " : '';
            }
            if (in_array("route", $value['types'])) {
                $address = isset($value['long_name']) ? $value['long_name'] : "";
            }
            if (in_array("locality", $value['types'])) {
                $CityName = isset($value['long_name']) ? $value['long_name'] : "";
            }
            if (in_array("administrative_area_level_1", $value['types'])) {
                $StateID = $this->getDaoForObject(TblState::class)
                    ->select('StateID')
                    ->where(sprintf("StateAbbreviation='%s'", $value['short_name']))
                    ->getOne();
            }
            if (in_array("country", $value['types'])) {
                $CountryID = $this->getDaoForObject(TblCountry::class)
                    ->select('CountryID')
                    ->where(sprintf("Abbreviation='%s'", $value['short_name']))
                    ->getOne();
            }
            if (in_array("postal_code", $value['types'])) {
                $PostalCode = isset($value['long_name']) ? $value['long_name'] : "";
            }
        }
        return [
            'AddressName' => $addressNumber . $address,
            'CountryID' => $CountryID ? $CountryID['CountryID'] : null,
            'PostalCode' => $PostalCode,
            'StateID' => $StateID ? $StateID['StateID'] : null,
            'CityName' => $CityName
        ];
    }

    private function getLatLonFromDatabase($defaults): array
    {
        if (empty($defaults)) {
            $defaults = $this->data();
        }
        // TODO take defaults first

        $where = '';
        $CountryID = $this->data('CountryID', FILTER_SANITIZE_NUMBER_INT);
        if (!empty($CountryID)) {
            $where .= (empty($where) ? "" : " AND ") . sprintf('CountryID=%d', $CountryID);
        }
        $StateID = $this->data('StateID', FILTER_SANITIZE_NUMBER_INT);
        if (!empty($StateID)) {
            $where .= (empty($where) ? "" : " AND ") . sprintf('StateID=%d', $StateID);
        }
        $AddressName = $this->data('AddressName', FILTER_SANITIZE_INPUT_STRING);
        if (!empty($AddressName)) {
            $where .= (empty($where) ? "" : " AND ") . sprintf("AddressName='%s'", $AddressName);
        }
        $CityName = $this->data('CityName', FILTER_SANITIZE_INPUT_STRING);
        if (!empty($CityName)) {
            $where .= (empty($where) ? "" : " AND ") . sprintf("CityName='%s'", $CityName);
        }
        $PostalCode = $this->data('PostalCode', FILTER_SANITIZE_INPUT_STRING);
        if (!empty($PostalCode)) {
            $where .= (empty($where) ? "" : " AND ") . sprintf('PostalCode=%d', $PostalCode);
        }
        $location = $this->getDaoForObject(TblLocations::class)
            ->select('Latitude as lat, Longitude as lng')
            ->where($where)
            ->getOne();
        if (empty($location['Latitude'])) {
            return [
                'lat' => false,
                'lng' => false
            ];
        } else {
            return $location;
        }
    }
}