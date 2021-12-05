<?php

namespace Common\Services\IAM\Interfaces;

interface IAMInterface
{
    public function getCompanyID(): ?int;

    public function getContactID(): ?int;
}