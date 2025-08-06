<?php

namespace App\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthUserLogin extends AuthUser{
    
    /** Login.  If successful, put an app & refresh token in the response.
     * If unsuccessful, return a 401 (Unauthorized) error.
     */
    public function process(Request $request, Response $response): Response {
        $bodyArray = $request->getParsedBody();

        $loginResult = $this->login($bodyArray['userid'], $bodyArray['password']);
        if ($loginResult["result"] == LoginResult::Success) {
            // Login successful, build tokens and return them
            $tokens = $this->createTokens($bodyArray['userid'], $loginResult['neighborId']);
            $response->getBody()->write(json_encode($tokens));
            return $response->withHeader("www_username", $bodyArray['userid']);;
        } else {
            // Login failed, return 401
            $badresponse = new \GuzzleHttp\Psr7\Response();
            return $badresponse->withStatus(401, "Unauthorized");
        }
    }
}

