<?php

namespace App\Middleware;

use \App\Util;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;


class SendNotificationMiddleware implements MiddlewareInterface {
    private $message = null;
    
    function __construct(string $message) {
        $this->message = $message;
    }

    /**
     * If the response has status = 200, then send the requested notification to the logged in user.
     */
    public function process(Request $request, RequestHandler $handler): Response {
        $response = $handler->handle($request);

        if ($response->getStatusCode() == 200) {
            // Try to find the neighbor ID either in the request (put there by
            // the auth middeleware) or response (put there by login/register)
            $myNeighborId = $request->getAttribute(name: "neighborId");
            if ($myNeighborId == null) {
                if ($response->hasHeader("neighborId")) {
                    $myNeighborId = $response->getHeader("neighborId")[0];
                }
            }

            if ($myNeighborId != null) {
                $pdo = Util::getDbConnection();

                // Send a notification to the other dude that his friend request was accepted.
                $stmt = $pdo->prepare("
                    insert into notification (to_neighbor, type, message)
                    values (:me, 'system_message', :message)
                ");
                $stmt->execute(params: [
                    ":me" => $myNeighborId,
                    ":message" => $this->message,
                ]);
            }
        }

        return $response;
    }
}
