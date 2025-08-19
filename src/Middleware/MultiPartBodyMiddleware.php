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
class MultiPartBodyMiddleware {
    /**
     * Ensure that the request has content-type = multipart/form-data.
     */
    public function __invoke(Request $request, RequestHandler $handler): Response {
        $multipart = str_starts_with($request->getHeaderLine('Content-Type'), "multipart/form-data");

        if (! $multipart) {
            $badresponse = new \GuzzleHttp\Psr7\Response();
            return $badresponse->withStatus(500, "Body of request was not formatted as multipart/form-data");
        }

        // If you get here, everything is fine
        return $handler->handle($request);
    }

}
