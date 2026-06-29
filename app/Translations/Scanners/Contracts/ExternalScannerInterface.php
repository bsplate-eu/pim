<?php

namespace App\Translations\Scanners\Contracts;

interface ExternalScannerInterface extends ScannerInterface
{
    public function setGroup(string $group): self;
}
