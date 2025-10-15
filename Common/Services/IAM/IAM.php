<?php

namespace Common\Services\IAM;

use Common\Services\IAM\Interfaces\IAMInterface;
use Core\Container\ContainerAware;
use OTPHP\TOTP;

/**
 * Class IAM
 * Class assumes that container variable will hold 'user' variable with 'Contact' key that respects following structure:
 * ['ContactID' => 'int', 'CompanyID' => 'int|null', 'Email' => 'string', 'ArchivedDate' => 'string|null', 'role_ids' => 'array[int]', '' => '
 *
 * @package Common\Services\IAM
 */
class IAM extends ContainerAware implements IAMInterface
{
    public function __construct($container)
    {
        $this->container = $container;
    }

    /**
     * Function assumes there is a permissions map in the container.
     * Map must be in the following structure [ ['resource_name' => 1] ] where 1 denotes permission level.
     * @param string $resource
     * @param int $permission
     * @return bool
     */
    public function checkPermission(string $resource, int $permission): bool
    {
        $perms = $this->container['permissions'];
        return $this->permissionCheck($perms[$resource] ?? 0, $permission);
    }

    public function getCompanyID(): ?int
    {
        $value = null;
        try {
            $value = $this->user['Contact']['CompanyID'] ?? null;
        } catch (\Exception $e) {}
        return $value;
    }

    public function getContactID(): ?int
    {
        $value = null;
        try {
            $value = $this->user['Contact']['ContactID'] ?? null;
        } catch (\Exception $e) {}
        return $value;
    }

    public function getContactEmail(): ?string
    {
        $value = null;
        try {
            $value = $this->user['Contact']['Email'] ?? null;
        } catch (\Exception $e) {}
        return $value;
    }

    public function is2FAOn(): bool
    {
        $value = null;
        try {
            $value = $this->user['Contact']['UseTwoFactorAuth'] ?? null;
        } catch (\Exception $e) {}
        return !empty($value);
    }

    public function is2FAAuthOn(): bool
    {
        $value = null;
        try {
            $value = $this->user['Contact']['TwoFactorTOPTSecret'] ?? null;
        } catch (\Exception $e) {}
        return !empty($value);
    }

    public function verify2FAAuthCode(string $code): bool
    {
        $secret = null;
        try {
            $secret = $this->user['Contact']['TwoFactorTOPTSecret'] ?? null;
        } catch (\Exception $e) {
            return false;
        }

        return TOTP::createFromSecret($secret)->verify($code);
    }

    /**
     * Checks if user have permission to execute passed action.
     * PERMISSION_DENIED 0
     * PERMISSION_CREATE 2
     * PERMISSION_READ 1
     * PERMISSION_UPDATE 4
     * PERMISSION_DELETE 8
     * @param int $userLevel
     * @param int $permission
     * @return bool
     */
    public function permissionCheck(int $userLevel, int $permission): bool
    {
        $accessRights = $this->bitMask($userLevel);
        return in_array($permission, $accessRights);
    }

    /**
     * Correct the variables stored in array.
     *
     * @param integer
     * @return array
     */
    private function bitMask($mask = 0): array
    {
        if (!is_numeric($mask)) {
            return [];
        }
        $return = [];
        while ($mask > 0) {
            $end = null;
            for ($i = 0, $n = 0; $i <= $mask; $i = 1 * pow(2, $n), $n++) {
                $end = $i;
            }
            $return[] = $end;
            $mask = $mask - $end;
        }
        sort($return);
        return $return;
    }
}