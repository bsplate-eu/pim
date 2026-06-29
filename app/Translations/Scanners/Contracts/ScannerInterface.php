<?php

namespace App\Translations\Scanners\Contracts;

interface ScannerInterface
{
    public function scanAndSaveTranslations(): void;

    public function addScannedPaths(array $scannedPaths): self;
}
