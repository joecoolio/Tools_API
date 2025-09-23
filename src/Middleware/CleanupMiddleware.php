<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;


class CleanupMiddleware implements MiddlewareInterface {
    private $headerNameArray = null;
    
    function __construct(array $headerNameArray) {
        $this->headerNameArray = $headerNameArray;
    }

    /**
     * Remove info from the response that I don't want exposed to the user.
     */
    public function process(Request $request, RequestHandler $handler): Response {
        $response = $handler->handle($request);

        foreach($this->headerNameArray as $headerName) {
            $response = $response->withoutHeader($headerName);
        }

        return $response;
    }
}
