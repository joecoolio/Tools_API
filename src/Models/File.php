<?php

namespace App\Models;

use \PDO;
use \App\Util;

class File extends BaseModel {
    // Get all the tools that I currently own
    public function uploadFile($directory, $uploadedFile) {
        $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
        $basename = Util::uuidv4();
        $filename = sprintf('%s.%0.8s', $basename, $extension);

        $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
    
        return $filename;
    }

}