<?php

namespace App\Models;

use \PDO;
use \App\Util;
use Slim\Psr7\UploadedFile;

class File extends BaseModel {
    protected static $mimeMap = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
    ];

    // Upload a file and return the cretaed filename
    public function uploadFile($directory, $uploadedFile) {
        if ($uploadedFile instanceof UploadedFile) {
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            if ($extension == "") {
                $extension = File::$mimeMap[$uploadedFile->getClientMediaType()];
            }
            $basename = Util::uuidv4();
            $filename = sprintf('%s.%0.8s', $basename, $extension);

            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
        
            return $filename;
        } else {
            // Handle base64 encoded strings
            return $this->saveBase64ToFile($directory, $uploadedFile);
        }
    }

    // Upload a base64 encoded file and return the cretaed filename
    protected function saveBase64ToFile($directory, string $base64String): string {
        // Extract Base64 data and MIME type
        $parts = explode(',', $base64String);
        $encodedData = end($parts);
        $mimeType = null;
        if (isset($parts[0]) && strpos($parts[0], ';base64') !== false) {
            preg_match('/data:(.*?);/', $parts[0], $matches);
            if (isset($matches[1])) {
                $mimeType = $matches[1];
            }
        }

        // Decode the Base64 string
        $decodedData = base64_decode($encodedData);

        // Determine file details (example: generate a unique name and assume PNG)
        if (!$mimeType) {
            $mimeType = 'image/png'; // Fallback if MIME type not in data URI
        }
        $ext = File::$mimeMap[$mimeType];
      
        // Create the file and dump the contents into it
        $basename = Util::uuidv4();
        $filename = sprintf('%s.%0.8s', $basename, $ext);
        file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $decodedData);

        return $filename;
    }
}