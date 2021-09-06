<?php

namespace KCoreCommon\Controllers\Common;

trait FunctionalTrait
{
    /**
     * @param array $data
     * @param callable $callback
     */
    public function each(array $data, callable $callback)
    {
        $i = 0;
        foreach ($data as $d) {
            $callback($d, $i++);
        }
    }

    /**
     * @param array $data
     * @param callable $callback
     * @return array
     */
    public function map(array $data, callable $callback): array
    {
        $result = [];
        $i = 0;
        foreach ($data as $k => $d) {
            $result[] = $callback($d, $i++, $k);
        }

        return $result;
    }

    /**
     * @param array $data
     * @param callable $callback
     * @return array
     */
    public function filter(array $data, callable $callback): array
    {
        $result = [];
        $i = 0;
        foreach ($data as $k => $d) {
            if ($callback($d, $i++, $k)) {
                $result[] = $d;
            }
        }

        return $result;
    }

    /**
     * @param array $data
     * @param callable $callback
     * @param $memo
     * @return mixed
     */
    public function reduce(array $data, callable $callback, $memo)
    {
        $i = 0;
        foreach ($data as $d) {
            $memo = $callback($memo, $d, $i++);
        }

        return $memo;
    }

    public function pluck(array $data, $field): array
    {
        $result = [];
        foreach ($data as $d) {
            $result[] = $d[$field];
        }

        return $result;
    }
}