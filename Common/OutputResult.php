<?php

namespace Common;

class OutputResult
{
    private int $success;

    private string $message;

    private array $data = [];

    public function __construct(bool $success = true, string $message = 'OK', array $data = [])
    {
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
    }

    public static function successWithData($data = []): OutputResult
    {
        return new OutputResult(true, 'OK', $data);
    }

    public static function error(string $errorMsg, array $errorData = []): OutputResult
    {
        return new OutputResult(false, $errorMsg, $errorData);
    }

    public static function successWithID(int $id): OutputResult
    {
        return new OutputResult(true, 'OK', [
            'id' => $id
        ]);
    }

    /**
     * @return int
     */
    public function getSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @param bool $success
     */
    public function setSuccess(bool $success): void
    {
        $this->success = $success;
    }

    /**
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @param string $message
     */
    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function isOk(): bool
    {
        return !!$this->getSuccess();
    }
}