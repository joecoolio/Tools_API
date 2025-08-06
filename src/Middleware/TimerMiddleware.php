<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class TimerMiddleware {
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
        $startTime = hrtime(true);
        
        $response = $handler->handle($request);

        return $response->withAddedHeader("Access-Control-Expose-Headers", "ExecutionTime")
            ->withAddedHeader("ExecutionTime", (hrtime(true) - $startTime) / 1e+6); // Convert to ms

    }
}
