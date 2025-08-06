<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Description of JSONBodyMiddleware
 *
 * @author mike
 */
class JSONBodyMiddleware {
    /**
     * Add an execution time to each response.
     *
     * @param  \Psr\Http\Message\ServerRequestInterface $request  PSR7 request
     * @param  \Psr\Http\Message\ResponseInterface      $response PSR7 response
     * @param  callable                                 $next     Next middleware
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function __invoke(Request $request, RequestHandler $handler): Response {
        // Allow an empty body
        if ($request->getBody()->getSize() == 0) {
            return $handler->handle($request);
        }
        
        // Convert json body to array
        $bodyArray = $request->getParsedBody();
        if (!$bodyArray) {
            $badresponse = new \GuzzleHttp\Psr7\Response();
            return $badresponse->withStatus(500, "Body of request was not formatted as JSON");
        }

        // If you get here, everything is fine
        return $handler->handle($request);
    }
    
    


}
