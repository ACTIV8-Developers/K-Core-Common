<?php

namespace Common;

class InputData implements \ArrayAccess, \Countable, \IteratorAggregate
{
    private array $data = [];
    private array $template = [];

    public function __construct(array $data = [], array $template = [])
    {
        $data = $this->clean($data, $template, !empty($template));

        $this->data = $data;

        $this->template = $template;
    }

    public function clean(array $data = [], $template = [], $excludeNonTemplate = false): array
    {
        $cleanedData = [];
        foreach ($data as $key => $value) {
            if ($excludeNonTemplate && !isset($template[$key])) {
                continue;
            }
            if (!is_array($value)) {
                $cleanedData[$key] = $this->cleanData($value, $template[$key] ?? "");
            } else {
                $cleanedData[$key] = $this->clean($value, $template[$key] ?? []);
            }
        }

        return $cleanedData;
    }

    public function validate(): bool
    {
        if (empty($this->template)) {
            return true;
        }

        foreach ($this->template as $k => $v) {
            if (!isset($this->data[$k]) && (strpos($v, "NULL") === false)) {
                return false;
            }
        }

        return true;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function __get($key)
    {
        return $this->data[$key] ?? null;
    }

    protected function filterVar($value, int $filter, ?int $option = 0): mixed
    {
        if ($filter == FILTER_SANITIZE_DATE) {
            return $this->sanitizeDate($value);
        } else if ($filter == FILTER_SANITIZE_NUMBER_FLOAT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_SANITIZE_NUMBER_INT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_VALIDATE_EMAIL) {
            $value = trim($value);
        } else if ($filter == FILTER_SANITIZE_INPUT_STRING) {
            return trim($value);
        }

        return filter_var($value, $filter, $option);
    }

    protected function sanitizeDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        $format = 'Y-m-d H:i:s';
        $d = \DateTime::createFromFormat($format, $value);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        return ($d && $d->format($format) === $value) ? $value : null;
    }

    protected function cleanData($value, $type)
    {
        if ($value !== 0 && !$value && !$type) {
            return null;
        }

        if (str_starts_with($type, 'int')) {
            $value = $this->filterVar($value, FILTER_SANITIZE_NUMBER_INT);
        } else if (str_starts_with($type, 'decimal')) {
            $value = $this->filterVar($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } else if (str_starts_with($type, 'datetime')) {
            $value = $this->filterVar($value, FILTER_SANITIZE_DATE);
        } else {
            // TODO specific filters, throw exception if not valid
        }

        return $value;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        if (isset($this->data[$offset])) {
            return $this->data[$offset];
        }
        return null;
    }

    public function offsetSet($offset, $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}