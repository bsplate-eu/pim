<?php

namespace App\Media\Exceptions\FileCannotBeAdded;

use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;

class FileIsTooBig extends FileCannotBeAdded
{
    /**
     * @param string $file
     * @param float $maxSize
     * @param string $collectionName
     * @return FileIsTooBig
     */
    public static function create(string $file, float $maxSize, string $collectionName): FileIsTooBig
    {
        $actualFileSize = filesize($file);

        return new static(___(
            'crafter',
            'File size is :actualFileSize, while max size of file in :collectionName is :maxSize',
            ['actualFileSize' => $actualFileSize, 'collectionName' => $collectionName, 'maxSize' => $maxSize]
        ));
    }
}
