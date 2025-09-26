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
}
