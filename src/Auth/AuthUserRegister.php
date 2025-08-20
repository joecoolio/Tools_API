<?php

namespace App\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use \App\Models\File;
use \App\Util;

class AuthUserRegister extends AuthUser {
    
    /** Register.
     * If user doesn't exist already, create a user record in the db and put an app & refresh token in the response.
     * If user does exist with the same password, return tokens.
     * If user exists with a different password, return a 401 (Unauthorized) error.
     */
    public function register(
        string $userid, 
        string $password, 
        string $name, 
        string $nickname, 
        string $address,
        string $ipaddress,
        $photoFile,
        $uploadDirectory,
        $response,
    ): Response {
        // Test user existance by calling login
        $loginResult = $this->login($userid, $password);
        if ($loginResult['result'] == LoginResult::Success) {
            // User already exists and the password is correct, no need to do anything else
            $tokens = $this->createTokens($userid, $loginResult['neighborId']);
            $response->getBody()->write(json_encode($tokens));
            return $response;
        } elseif ($loginResult['result'] == LoginResult::WrongPassword) {
            // User already exists but the password doesn't match
            $badresponse = new \GuzzleHttp\Psr7\Response();
            $badresponse->getBody()->write('{ "error": "incorrect password" )');
            return $badresponse->withStatus(401, "Unauthorized");
        } else {
            // User does not exist, create it

            // Upload the file first and get the returned filename
            $filename = null;
            if ($photoFile != null) {
                $filename = (new File())->uploadFile($uploadDirectory, $photoFile);
            }

            // Reset the password next
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Update all the data
            $data = [
                'userid' => $userid,
                'password_hash' => $hashedPassword,
                'name' => $name,
                'nickname' => $nickname,
                'home_address' => $address,
                'photo_link' => $filename,
                'created' => (new \DateTimeImmutable())->format(\DateTime::ISO8601),
                'created_by_ip' => $ipaddress
            ];

            // Prep fields & placeholders
            $fields = array_keys($data);
            $placeholders = array_map(fn($f) => ":$f", $fields);

            // Run an update and get the created ID
            $pdo = Util::getDbConnection();
            $pdo->beginTransaction();
            try {
                // Insert into neighbor table
                $sql =
                    "insert into neighbor(" .
                    implode(', ', $fields) . ") " .
                    "values (" . 
                    implode(', ', $placeholders) . ") " .
                    "returning id";
                $stmt = $pdo->prepare($sql);
                foreach ($data as $key => $value) {
                    $stmt->bindValue(':' . $key, $value);
                }
                $stmt->execute($data);
                $neighborId = $stmt->fetchColumn();

                // Update the address point
                $stmt = $pdo->prepare("
                    UPDATE neighbor
                        SET home_address_point = (
                            SELECT ST_SetSRID(ST_SnapToGrid((g).geomout, 0.00001), 4326)
                            FROM geocode(home_address, 1) AS g
                        )
                    WHERE
                        home_address IS NOT NULL
                        and id = :id
                ");
                $stmt->execute(params: [ ':id' => $neighborId ]);

                $pdo->commit();
            } catch (\PDOException $e) {
                $pdo->rollBack();
                throw $e;
            }

            $tokens = $this->createTokens($userid, $neighborId);
            $response->getBody()->write(json_encode($tokens));
            return $response->withHeader("www_username", $userid);
        }
    }


    // Not used
    public function process(Request $request, Response $response): Response {
        return $response;
    }
}
