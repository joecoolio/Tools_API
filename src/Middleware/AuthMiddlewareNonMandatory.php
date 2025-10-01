<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

use App\Util;

/**
 * Middleware to validate a JWT if provided.  If not provided, ignore and keep going.
 * Class AuthMiddleware is used if the existence of authentication is mandatory.
 */
class AuthMiddlewareNonMandatory {
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
        $userid = null;
        
        // If available, get the userid from the auth token
        if ($request->hasHeader('Authorization')) {

            // Get the JWT header
            $auth = $request->getHeaderLine('Authorization');
            $matches = [];
            // Verify that the JWT exists
            if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {

                // Found a JWT, grab it
                $jwt = $matches[1];

                $token = Util::validateJWT($jwt, false);
        
                if (count($token) != 0 && !array_key_exists("error_code", $token)) {
                    // If you get here, the JWT is valid
                    $userid = $token['userid'];
                }
            }
        }

        // If provided, add the userid to the request and send it onward
        if ($userid != null) {
            // Add the userid from the access token to the request
            $response = $handler->handle($request->withAttribute("userid", $token['userid'])->withAttribute("neighborId", $token['neighborId']));

            // Add the username from the access token to the response too
            return $response
                ->withHeader("userId", $token['userid'])
                ->withHeader("neighborId", (string) $token['neighborId']);
        // If not provided, just keep going
        } else {
            return $handler->handle($request);
        }
    }
}
