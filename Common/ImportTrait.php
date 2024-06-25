<?php

namespace Common;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

trait ImportTrait
{
    protected function validateExcelEntry(string $value, array $valuesMap): array
    {
        if (isset($valuesMap[$value])) {
            return [
                "name" => $value,
                "value" => $valuesMap[$value]
            ];
        } else {
            return [
                "value" => $value,
                "error" => "NOT_FOUND"
            ];
        }
    }

    protected function validateExcelAmount(mixed $value): array
    {
        if (is_numeric($value)) {
            return [
                "name" => $value,
                "value" => $value
            ];
        } else {
            return [
                "value" => $value,
                "error" => "INVALID_FORMAT"
            ];
        }
    }

    protected function validateExcelDate(mixed $value): array
    {
        try {
            if (is_int($value)) {
                return [
                    "name" => $this->excelIntTimeToDate($value, DEFAULT_SQL_DATE_FORMAT),
                    "value" => $value
                ];
            }
            $date = Carbon::parse($value);
            return [
                "name" => $date->format(DEFAULT_SQL_DATE_FORMAT),
                "value" => $value
            ];
        } catch (\Exception $e) {
            return [
                "value" => $value,
                "error" => "INVALID_FORMAT"
            ];
        }
    }

    protected function validateString(mixed $value): array
    {
        return [
            "name" => $value,
            "value" => $value
        ];
    }

    protected function excelIntTimeToDate($date_value, $format): string
    {
        /**
         * Number of days between the beginning of serial date-time (1900-Jan-0)
         * used by Excel and the beginning of UNIX Epoch time (1970-Jan-1).
         */
        $days_since_1900 = 25569;

        if ($date_value < 60) {
            --$days_since_1900;
        }

        /**
         * Values greater than 1 contain both a date and time while values lesser
         * than 1 contain only a fraction of a 24-hour day, and thus only time.
         */
        if ($date_value >= 1) {
            $utc_days = $date_value - $days_since_1900;
            $timestamp = round($utc_days * 86400);

            if (($timestamp <= PHP_INT_MAX) && ($timestamp >= -PHP_INT_MAX)) {
                $timestamp = (integer) $timestamp;
            }
        } else {
            $hours = round($date_value * 24);
            $mins = round($date_value * 1440) - round($hours * 60);
            $secs = round($date_value * 86400) - round($hours * 3600) - round($mins * 60);
            $timestamp = (integer) gmmktime($hours, $mins, $secs);
        }

        return Carbon::createFromTimestamp($timestamp)->format($format);
    }
}