<?php

namespace Common\Services\OAuth;


class Hasher
{
    private static ?Hasher $instance = null;

    private ?PasswordHashInternal $hash;

    private function __construct()
    {
        $this->hash = (new PasswordHashInternal(8, false));
    }

    public static function getInstance(): Hasher
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function hashPassword($password): string
    {
        return $this->hash->HashPassword($password);
    }

    public function checkPassword($password, $hash): bool
    {
        return $this->hash->CheckPassword($password, $hash);
    }
}