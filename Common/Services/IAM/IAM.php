<?php

namespace Common\Services\IAM;

use Common\Services\IAM\Interfaces\IAMInterface;
use Core\Container\ContainerAware;

class IAM extends ContainerAware implements IAMInterface
{
    public function __construct($container)
    {
        $this->container = $container;
    }

    public function checkPermission($resource, $permission)
    {
        // TODO
    }

    public function getCompanyID(): ?int
    {
        $value = null;
        try {
            $value = $this->user['Contact']['CompanyID'];
        } catch (\Exception $e) {}
        return $value;
    }

    public function getContactID(): ?int
    {
        $value = null;
        try {
            $value = $this->user['Contact']['ContactID'];
        } catch (\Exception $e) {}
        return $value;
    }

    public function getContactEmail(): ?string
    {
        $value = null;
        try {
            $value = $this->user['Contact']['Email'];
        } catch (\Exception $e) {}
        return $value;
    }
}