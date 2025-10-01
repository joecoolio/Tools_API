<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

use App\Util;

/**
 * Middleware to handle validation of JWT.
 */
class AuthMiddleware {
    /**
     * Validate a valid JWT token is on the request.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface $request  PSR7 request
     * @param  \Psr\Http\Message\ResponseInterface      $response PSR7 response
     * @param  callable                                 $next     Next middleware
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function __invoke(Request $request, RequestHandler $handler): Response {
        // Get the auth header
        if (!$request->hasHeader('Authorization')) {
            // Auth header is missing
            $badresponse = new \GuzzleHttp\Psr7\Response();
            return $badresponse->withStatus(400, "Authorization header not found in request");
        }

        // Get the JWT header
        $auth = $request->getHeaderLine('Authorization');
        $matches = [];
        // Verify that the JWT exists
        if (! preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            // Wrong kind of header
            $badresponse = new \GuzzleHttp\Psr7\Response();
            return $badresponse->withStatus(400, "JWT Token not found in request");
        }

        // Found a JWT, grab it
        $jwt = $matches[1];

        $token = Util::validateJWT($jwt, false);
        
        if (count($token) == 0) {
            $badresponse = new \GuzzleHttp\Psr7\Response();
            $badresponse->getBody()->write('{ "error": "invalid_grant" }');
            return $badresponse->withStatus(400, "Bad Request, validate returned empty");
        }

        if (array_key_exists("error_code", $token)) {
            $code = $token["error_code"];
            $message = $token["error_msg"];
            
            // There was an error, return it
            $badresponse = new \GuzzleHttp\Psr7\Response();
            $badresponse->getBody()->write('{ "error": "$message" }');
            return $badresponse->withStatus($code, $message);
        } else {
            // If you get here, the JWT is valid

            // Add the username from the access token to the request
            $response = $handler->handle($request->withAttribute("userid", $token['userid'])->withAttribute("neighborId", $token['neighborId']));
        }
        
        // Add the username from the access token to the response too
        return $response
            ->withHeader("userId", $token['userid'])
            ->withHeader("neighborId", (string) $token['neighborId']);
    }
}
