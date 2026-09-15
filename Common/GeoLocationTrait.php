<?php

namespace Common;

use App\Models\TblCountry;
use App\Models\TblLocations;
use App\Models\TblState;
use App\Services\Geo\GeoLocator;
use DateTimeZone;

trait GeoLocationTrait
{
    /**
     * Resolves an address to coordinates via the company's configured geo
     * provider. Returns lat/lng 0 when the address cannot be resolved, which is
     * the contract callers have always relied on.
     */
    protected function getLatLonFromAddressLine($defaults = [])
    {
        if (empty($defaults)) {
            $defaults = $this->data();
        }

        $country = $this->getDaoForObject(TblCountry::class)
            ->where(['CountryID' => $defaults['CountryID'] ?? null])
            ->getOne();

        $state = null;
        if (!empty($defaults['StateID'])) {
            $state = $this->getDaoForObject(TblState::class)
                ->where(['StateID' => $defaults['StateID']])
                ->getOne();
        }

        return GeoLocator::service()->latLonFromAddress([
            'AddressName' => $defaults['AddressName'] ?? '',
            'CityName' => $defaults['CityName'] ?? '',
            'PostalCode' => $defaults['PostalCode'] ?? '',
            'State' => $state['State'] ?? '',
            'StateAbbreviation' => $state['StateAbbreviation'] ?? '',
            'Country' => $country['CountryName'] ?? '',
        ]);
    }

    /**
     * Reverse geocodes to the address shape callers expect, resolving the
     * provider's state/country abbreviations to Accur8 IDs.
     */
    protected function getAddressFromLatLon($defaults = []): array
    {
        if (empty($defaults)) {
            $defaults = $this->data();
        }

        $resolved = GeoLocator::service()->addressFromLatLon(
            (float)($defaults['Latitude'] ?? 0),
            (float)($defaults['Longitude'] ?? 0)
        );

        if (empty($resolved)) {
            return [
                'AddressName' => '',
                'CountryID' => null,
                'Country' => null,
                'PostalCode' => '',
                'StateID' => null,
                'State' => null,
                'CityName' => '',
                'FormatedAddress' => null,
            ];
        }

        $state = null;
        if (!empty($resolved['StateAbbreviation'])) {
            $state = $this->getDaoForObject(TblState::class)
                ->select('StateID, StateName')
                ->where(sprintf("StateAbbreviation='%s'", $resolved['StateAbbreviation']))
                ->getOne();
        }

        $country = null;
        if (!empty($resolved['CountryAbbreviation'])) {
            $country = $this->getDaoForObject(TblCountry::class)
                ->select('CountryID')
                ->where(sprintf("Abbreviation='%s'", $resolved['CountryAbbreviation']))
                ->getOne();
        }

        return [
            'AddressName' => $resolved['AddressName'],
            'CountryID' => $country['CountryID'] ?? null,
            'Country' => $country['CountryID'] ?? null,
            'PostalCode' => $resolved['PostalCode'],
            'StateID' => $state['StateID'] ?? null,
            'State' => $state['StateName'] ?? null,
            'CityName' => $resolved['CityName'],
            'FormatedAddress' => $resolved['FormatedAddress'] ?: null,
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

    /**
     * Google-shaped timezone payload. The key casing (timeZoneId) is load-bearing
     * — the frontend reads it directly.
     */
    protected function getTimeZoneFromLatLon($defaults = []): array
    {
        if (empty($defaults)) {
            $defaults = $this->data();
        }

        return GeoLocator::service()->timeZoneFromLatLon(
            (float)($defaults['Latitude'] ?? 0),
            (float)($defaults['Longitude'] ?? 0),
            $defaults['Timestamp'] ?? time()
        );
    }
}