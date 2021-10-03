<?php

namespace Common;

class CommandData implements \ArrayAccess
{
    private array $data = [];

    public function __construct(array $data = [], array $template = [])
    {
        $data = $this->cleanData($data, $template);

        $this->data = $data;
    }

    public function cleanData(array $data = [], array $template = []): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                $data[$key] = $this->clean($value, $template[$key] ?? "");
            } else {
                $data[$key] = $this->cleanData($value, $template[$key]);
            }
        }

        return $data;
    }

    public function __get($key)
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    /**
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        if (isset($this->data[$offset])) {
            return $this->data[$offset];
        }
        return null;
    }

    /**
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        $this->data[$offset] = $value;
    }

    /**
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }

    protected function filterVar($value, $filter, $option = null)
    {
        if ($filter == FILTER_SANITIZE_DATE) {
            return $this->sanitizeDate($value);
        } else if ($filter == FILTER_SANITIZE_NUMBER_FLOAT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_SANITIZE_NUMBER_INT && !is_numeric($value)) {
            return null;
        } else if ($filter == FILTER_VALIDATE_EMAIL) {
            $value = trim($value);
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

    protected function clean($value, $type)
    {
        if (strpos($type, 'int') === 0) {
            $value = $this->filterVar($value, FILTER_SANITIZE_NUMBER_INT);
        } else if (strpos($type, 'decimal') === 0) {
            $value = $this->filterVar($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        } else if (strpos($type, 'datetime') === 0) {
            $value = $this->filterVar($value, FILTER_SANITIZE_DATE);
        } else {
            $value = $this->filterVar($value, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        }

        // TODO specific filters, throw exception if not valid

        return $value;
    }
}