<?php

namespace Common;

use App\Models\TblCountry;
use App\Models\TblLocations;
use App\Models\TblState;
use DateTimeZone;

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
        $State = null;
        $CountryID = null;
        $Country = null;
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
                    ->select('StateID, StateName')
                    ->where(sprintf("StateAbbreviation='%s'", $value['short_name']))
                    ->getOne();
                $State = $StateID ? $StateID['StateName'] : null;
            }
            if (in_array("country", $value['types'])) {
                $CountryID = $this->getDaoForObject(TblCountry::class)
                    ->select('CountryID')
                    ->where(sprintf("Abbreviation='%s'", $value['short_name']))
                    ->getOne();
                $Country = $CountryID ? $CountryID['CountryID'] : null;
            }
            if (in_array("postal_code", $value['types'])) {
                $PostalCode = isset($value['long_name']) ? $value['long_name'] : "";
            }
        }
        return [
            'AddressName' => $addressNumber . $address,
            'CountryID' => $CountryID ? $CountryID['CountryID'] : null,
            'Country' => $Country ?? null,
            'PostalCode' => $PostalCode,
            'StateID' => $StateID ? $StateID['StateID'] : null,
            'State' => $State ??  null,
            'CityName' => $CityName,
            'FormatedAddress' => isset($data['results'][0]) ? $data['results'][0]['formatted_address'] : null,
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

    protected function getNearestTimezone($cur_lat, $cur_long, $country_code = '') {
        $timezone_ids = ($country_code) ? DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country_code)
            : DateTimeZone::listIdentifiers();

        if ($timezone_ids && isset($timezone_ids[0])) {

            $time_zone = '';
            $tz_distance = 0;

            //only one identifier?
            if (count($timezone_ids) == 1) {
                $time_zone = $timezone_ids[0];
            } else {
                foreach ($timezone_ids as $timezone_id) {
                    $timezone = new DateTimeZone($timezone_id);
                    $location = $timezone->getLocation();
                    $tz_lat   = $location['latitude'];
                    $tz_long  = $location['longitude'];

                    $theta    = $cur_long - $tz_long;
                    $distance = (sin(deg2rad($cur_lat)) * sin(deg2rad($tz_lat)))
                        + (cos(deg2rad($cur_lat)) * cos(deg2rad($tz_lat)) * cos(deg2rad($theta)));
                    $distance = acos($distance);
                    $distance = abs(rad2deg($distance));
                    // echo '<br />'.$timezone_id.' '.$distance;

                    if (!$time_zone || $tz_distance > $distance) {
                        $time_zone   = $timezone_id;
                        $tz_distance = $distance;
                    }

                }
            }
            return  $time_zone;
        }
        return 'unknown';
    }

    /**
     * Calculates the distance in miles between two points specified by latitude and longitude.
     *
     * @param float $lat1 Latitude of the first point
     * @param float $lon1 Longitude of the first point
     * @param float $lat2 Latitude of the second point
     * @param float $lon2 Longitude of the second point
     * @return float Distance in miles
     */
    public function calculateDistanceFromLatLon(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 3959.0; // Radius of the earth in miles

        $dLat = deg2rad($lat2 - $lat1);  // Convert degrees to radians
        $dLon = deg2rad($lon2 - $lon1);  // Convert degrees to radians

        $a = sin($dLat/2) * sin($dLat/2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon/2) * sin($dLon/2);

        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        // Distance in miles

        return $earthRadius * $c;
    }

    protected function getTimeZoneFromLatLon($defaults = []): array
    {
        if (empty($defaults)) {
            $defaults = $this->data();
        }

        $Latitude = $defaults['Latitude'];
        $Longitude = $defaults['Longitude'];
        $Timestamp = $defaults['Timestamp'];

        $key = getenv('GOOGLE_MAPS_GEO_KEY');
        $url = sprintf("https://maps.googleapis.com/maps/api/timezone/json?location=%s,%s&key=%s&timestamp=%s", $Latitude, $Longitude, $key, $Timestamp);
        return json_decode(file_get_contents($url), true);
    }
}