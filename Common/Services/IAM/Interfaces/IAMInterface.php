<?php

namespace Common\Services\IAM\Interfaces;

interface IAMInterface
{
    const PERMISSION_DENIED = 0;
    const PERMISSION_CREATE = 1;
    const PERMISSION_READ = 2;
    const PERMISSION_UPDATE = 4;
    const PERMISSION_DELETE = 8;

    /**
     * @param string $resource Name of the resource permission is checked against.
     * @param int $permission One of permission levels to check against, see PERMISSION_* constants.
     * @return bool
     */
    public function checkPermission(string $resource, int $permission): bool;

    /**
     * Will return integer greater than 0 if there is a logged user attached to company, or null otherwise.
     * Function is meant to be used with multi-tenant systems, function will return null even if user is logged if it
     * is not attached to a company.
     * @return int|null
     */
    public function getCompanyID(): ?int;

    /**
     * Will return integer greater than 0 if there is a logged user, or null otherwise.
     * @return int|null
     */
    public function getContactID(): ?int;

    /**
     * Will return string in email format if there is a logged user, or null otherwise.
     * @return string|null
     */
    public function getContactEmail(): ?string;

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
    public function permissionCheck(int $userLevel, int $permission): bool;

    public function is2FAOn(): bool;

    public function is2FAAuthOn(): bool;
}