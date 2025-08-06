<?php

namespace App\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

use \App\Util;

use \PDO;

enum LoginResult {
    case Success;
    case WrongPassword;
    case UserDoesNotExist;
}

/**
 * Description of User
 *
 * @author mike
 */
abstract class AuthUser {
    /** Processing call.  Override this.
     */
    abstract public function process(Request $request, Response $response): Response;

    /** Create a JWT access token.
     */
    protected function createTokens(string $userid, int $neighborId) : array {
        // $privateKey = file_get_contents('jwt.pem');
        $privateKey = file_get_contents($_ENV['JWT_PRIVATE_FILENAME']);
                
        $now = new \DateTimeImmutable();

        $accessToken = JWT::encode([
            "iss" => $_ENV['SERVER_FQDN'],
            "iat" => $now->getTimestamp(),
            "nbf" => $now->getTimestamp(),
            "exp" => $now->add(new \DateInterval("P30D"))->getTimestamp(), // 30 days
//            "exp" => $now->add(new \DateInterval("PT1M"))->getTimestamp(), // 1 minute for testing
            "userid" => $userid,
            "neighborId" => $neighborId,
            "role" => 'user_access'
        ], $privateKey, 'RS256');

        $refreshTokenID = Util::uuidv4();
//        $refreshTokenExp = $now->add(new \DateInterval("P1D"))->getTimestamp(); // 1 day
        $refreshTokenExp = $now->add(new \DateInterval("P180D")); // 365 days
//        $refreshTokenExp = $now->add(new \DateInterval("PT3M"))->getTimestamp(); // 3 minutes for testing
        
        $refreshToken = JWT::encode([
            "iss" => $_ENV['AUTH_SERVER_FQDN'],
            "iat" => $now->getTimestamp(),
            "nbf" => $now->getTimestamp(),
            "exp" => $refreshTokenExp->getTimestamp(),
            "userid" => $userid,
            "neighborId" => $neighborId,
            "role" => 'refresh_token',
            "revocationid" => $refreshTokenID
        ], $privateKey, 'RS256');

        // Send the refresh token to the db
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("insert into token (id, created, expire, neighbor_id) values (?, ?, ?, ?)");
        $stmt->execute([ $refreshTokenID, $now->format(\DateTime::ISO8601), $refreshTokenExp->format(\DateTime::ISO8601), $neighborId ]);
        
        return [
            "access_token" => $accessToken,
            "token_type" => "Bearer",
            "expires_in" => 86400, // 1 day as seconds
            "refresh_token" => $refreshToken
        ];
    }
    
    /** Validate that a token is good.  Checks the contents and the db.
     * 
     * @param string encrypted JWT string
     * @param bool if the token to be validated is a refresh token
     * @return decoded JWT if valid; null otherwise
     */
    public static function validateToken(string $token, bool $isRefreshToken = false) : array {
        // $publicKey = file_get_contents('jwt.pub');
        $publicKey = file_get_contents($_ENV['JWT_PUBLIC_FILENAME']);
        $jwt = null;

        if ($token == null) {
            error_log("Request to validate null token");
            return ["error_code" => "400", "error_msg" => "null token provided"];
        }
        
        try {
            $jwt = JWT::decode($token, new Key($publicKey, 'RS256'));
        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log("Expired token");
            return ["error_code" => "401", "error_msg" => "expired token"];
        } catch (\LogicException $e) {
            // errors having to do with environmental setup or malformed JWT Keys
            error_log("Logic Exception: $e");
            return ["error_code" => "400", "error_msg" => "logic exception $e"];
        } catch (\UnexpectedValueException $e) {
            // errors having to do with JWT signature and claims
            error_log("Unexpected Value Exception: $e");
            return ["error_code" => "400", "error_msg" => "unexpected value exception $e"];
        }
        
        // Validate server name
        if ($isRefreshToken && $jwt->iss != $_ENV['AUTH_SERVER_FQDN']) {
            error_log("Token has wrong FQDN: {$jwt->iss}");
            return ["error_code" => "400", "error_msg" => "Refresh token has wrong FQDN: {$jwt->iss}"];
        }
        if (!$isRefreshToken && $jwt->iss != $_ENV['SERVER_FQDN']) {
            error_log("Token has wrong FQDN: {$jwt->iss}");
            return ["error_code" => "400", "error_msg" => "Access token has wrong FQDN: {$jwt->iss}"];
        }

        // Validate role
        if ($isRefreshToken && $jwt->role != 'refresh_token') {
            error_log("Refresh token has wrong role: {$jwt->role}");
            return ["error_code" => "400", "error_msg" => "Refresh token has wrong role: {$jwt->role}"];
        }
        if (!$isRefreshToken && $jwt->role != 'user_access') {
            error_log("Access token has wrong role: {$jwt->role}");
            return ["error_code" => "400", "error_msg" => "Access token has wrong role: {$jwt->role}"];
        }
        
        // If it's a refresh token, check that it exists in the db
        if ($isRefreshToken) {
            $pdo = Util::getDbConnection();
            $stmt = $pdo->prepare("select id from token where id = :id");
            $stmt->execute([ ':id' => $jwt->revocationid ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result === null) {
                error_log("Refresh token is not in the database: {$jwt->revocationid}");
                return ["error_code" => "400", "error_msg" => "Refresh token is not in the database"];
            }
        }
        
        return (array) $jwt;
    }
    
    /** Revoke a refresh token.
     * 
     * @param string $jwt
     */
    protected function revokeToken(string $token) {
        try {
            // $publicKey = file_get_contents('jwt.pub');
            $publicKey = file_get_contents($_ENV['JWT_PUBLIC_FILENAME']);
            $jwt = JWT::decode($token, new Key($publicKey, 'RS256'));

            if ($jwt->revocationid != null) {
                $pdo = Util::getDbConnection();
                $stmt = $pdo->prepare("delete from token where id = :id");
                $stmt->bindParam(':id', $jwt->revocationid, PDO::PARAM_STR);
                $stmt->execute();
                error_log("Deleted revoked refresh token: $jwt->revocationid");
            } else {
                error_log("Cannot delete token because it has no revocation id");
            }
        } catch (\Exception $e) {
            error_log("Cannot delete token because it doesn't decode: $e");
        }
    }

    /** Login.  Return true if successful.
     * 
     * @param string $email email address
     * @param string $password password
     * @return true if successful; false otherwise
     */
    public function login(string $userid, string $password) : array {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("select id, password_hash from neighbor where userid = :userid");
        $stmt->execute([ ':userid' => $userid ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result !== false) {
            $neighborId = $result["id"];
            $stored_password_hash = $result["password_hash"];

            // Found the user's record, check the password
            if (password_verify($password, $stored_password_hash)) {
                error_log("Login for $userid succeeded");
                return [ "result" => LoginResult::Success, "neighborId" => $neighborId ];
            } else {
                error_log("Login for $userid failed: incorrect password");
                return [ "result" => LoginResult::WrongPassword, "neighborId" => 0 ];
            }
        } else {
            error_log("Login for $userid failed: record doesn't exist in DB");
            return [ "result" => LoginResult::UserDoesNotExist, "neighborId" => 0 ];
        }
    }
    
    

    /** Create a new user with the given user & password.
     * This does not check (or care) if the user already exists - do that beforehand.
     */
    protected function register(string $userid, string $name, string $address, string $password, string $ipaddress, bool $notifications_enabled = false) : int {
        // Hash the password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // If the user doesn't activate, the user will delete automatically
        $now = (new \DateTimeImmutable());
        $expires = $now->add(new \DateInterval("P2D"));  // 2 days

        // Create the user record in the db
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("insert into neighbor (userid, name, home_address, password_hash, created, created_by_ip) values (:userid, :name, :address, :password_hash, :created, :created_by_ip)");
        $stmt->execute(params: [
            ':userid' => $userid,
            ':name' => $name,
            ':address' => $address,
            ':password_hash' => $hashedPassword,
            ':created' => $now->format(\DateTime::ISO8601),
            ':created_by_ip' => $ipaddress
        ]);
        $neighborId = $pdo->lastInsertId();
        
        error_log("Created new user: $neighborId");

        // Execute geocode lookup on the address
        if (!$this->geocodeAddress($neighborId)) {
            error_log("Failed to execute geo lookup for the address: $address");
        }

        return $neighborId;
    }

    // Do a geocode lookup of the address of a neighbor.
    // Run this on register or whenever the address changes.
    protected function geocodeAddress(int $neighborId) : bool {
        $pdo = Util::getDbConnection();
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
        return $stmt->rowCount() == 1;
    }

}
