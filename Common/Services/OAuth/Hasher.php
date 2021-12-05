<?php

namespace Common\Services\OAuth;

use Core\Auth\PasswordHash;

class Hasher
{
    private static ?Hasher $instance = null;

    private ?PasswordHash $hash;

    private function __construct()
    {
        $this->hash = (new PasswordHash(8, false));
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