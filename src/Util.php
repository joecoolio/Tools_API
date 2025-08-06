<?php

namespace App;

use PDO;
use Predis\Client;

abstract class Util {
    public static function var_error_log( $object=null ){
        ob_start();                    // start buffer capture
        var_dump( $object );           // dump the values
        $contents = ob_get_contents(); // put the buffer into a variable
        ob_end_clean();                // end capture
        error_log( $contents );        // log contents of the result of var_dump( $object )
    }

    // Create a UUIDv4
    public static function uuidv4() : string {
        // Generate 16 bytes (128 bits) of random data or use the data passed into the function.
        $data = random_bytes(16);
        assert(strlen($data) == 16);

        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Output the 36 character UUID.
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function getDbConnection() : PDO {
        $dbInfo = sprintf("pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $_ENV['DATABASE_HOST'],
            $_ENV['DATABASE_PORT'],
            $_ENV['DATABASE_NAME'],
            $_ENV['DATABASE_USER'],
            $_ENV['DATABASE_PASS']
        );
        $pdo = new PDO($dbInfo);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    public static function getRedisConnection() : Client {
        $redis = new Client([
            'host' => $_ENV['REDIS_HOST'],
            'port' => $_ENV['REDIS_PORT']
        ]);
        return $redis;
    }
}