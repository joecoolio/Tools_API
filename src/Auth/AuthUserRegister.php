<?php

namespace App\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthUserRegister extends AuthUser {
    
    /** Register.
     * If user doesn't exist already, create a user record in the db and put an app & refresh token in the response.
     * If user does exist with the same password, return tokens.
     * If user exists with a different password, return a 401 (Unauthorized) error.
     */
    public function process(Request $request, Response $response): Response {
        $bodyArray = $request->getParsedBody();
        $userid = $bodyArray['userid'];
        $name = $bodyArray['name'];
        $address = $bodyArray['address'];
        $password = $bodyArray['password'];
        
        // Test user existance by calling login
        $loginResult = $this->login($userid, $password);
        if ($loginResult['result'] == LoginResult::Success) {
            // User already exists and the password is correct, no need to do anything else
            $tokens = $this->createTokens($userid, $loginResult['neighborId']);
            $response->getBody()->write(json_encode($tokens));
            return $response;
        } elseif ($$loginResult['result'] == LoginResult::WrongPassword) {
            // User already exists but the password doesn't match
            $badresponse = new \GuzzleHttp\Psr7\Response();
            $badresponse->getBody()->write('{ "error": "incorrect password" )');
            return $badresponse->withStatus(401, "Unauthorized");
        } else {
            // User does not exist, create it
            $neighborId = $this->register(
                $userid,
                $name,
                $address,
                $password,
                $request->getAttribute('ip_address')
            );
            $tokens = $this->createTokens($userid, $neighborId);
            $response->getBody()->write(json_encode($tokens));
            return $response->withHeader("www_username", $userid);
        }
    }
}
