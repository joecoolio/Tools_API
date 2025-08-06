<?php

namespace App\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthRefreshToken extends AuthUser {
    /** Refresh an access token.
     * If grant type is wrong, return 400.
     */
    public function process(Request $request, Response $response): Response {
        $bodyArray = $request->getParsedBody();
        
        // Grant type must = "refresh_token" (per RFC6749)
        if ($bodyArray['grant_type'] != "refresh_token") {
            $badresponse = new \GuzzleHttp\Psr7\Response();
            $badresponse->getBody()->write('{ "error": "invalid_request" }');
            return $badresponse->withStatus(400, "Bad Request");
        }
        
        // Refresh token is required
        if ($bodyArray['refresh_token'] == null) {
            $badresponse = new \GuzzleHttp\Psr7\Response();
            $badresponse->getBody()->write('{ "error": "invalid_request" }');
            return $badresponse->withStatus(400, "Bad Request");
        }
        
        // Validate the refresh token
        $jwt = $this->validateToken($bodyArray['refresh_token'], isRefreshToken: true);
        // Check for validation error
        if (array_key_exists("error_code", $jwt)) {
            $code = $jwt["error_code"];
            $message = $jwt["error_msg"];
            error_log("AuthRefreshToken: validate failed with code $code and message $message");
            
            $badresponse = new \GuzzleHttp\Psr7\Response();
            if ($code == 401) {
                $badresponse = $badresponse->withAddedHeader("WWW-Authenticate", 'Bearer realm="wordgame.mikebillings.com",error="invalid_token",error_description="The refresh token is invalid');
            }
            $badresponse->getBody()->write('{ "error": "$message" }');
            return $badresponse->withStatus($code, "Bad Request");
        }
        
        // Issue new tokens
        $userid = $jwt['userid'];
        $neighborId = $jwt['neighborId'];
        $tokens = $this->createTokens($userid, $neighborId);
        $response->getBody()->write(json_encode($tokens));
        error_log("AuthRefreshToken: tokens refreshed for $userid");

        // Revoke the old refresh token
        $this->revokeToken($bodyArray['refresh_token']);

        // Return
        return $response;
    }
}
