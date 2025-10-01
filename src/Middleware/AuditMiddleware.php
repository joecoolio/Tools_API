<?php

namespace App\Middleware;

use \App\Models\BaseModel;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;

use \App\Util;

/**
 * Description of ValidateMiddleware
 *
 * @author mike
 */
class AuditMiddleware extends BaseModel {
    /**
     * Record every call in the audit table.
     */
    public function __invoke(Request $request, RequestHandler $handler): Response {
        // Before execution
        $startTimeUs = microtime(true);
        
        // Do the execution
        $response = $handler->handle($request);

        // After execution
        // $routeContext = RouteContext::fromRequest($request);
        // $route = $routeContext->getRoute();
        // $apiCalled = $route->getName() != null ? $route->getName() : $route->getPattern();
        $apiCalled = $request->getUri()->getPath();
        $ipAddress = $request->getAttribute('ip_address');

        // Try to find the neighbor ID either in the request (put there by
        // the auth middeleware) or response (put there by login/register)
        $myUserId = $request->getAttribute(name: "userId");
        if ($myUserId == null) {
            if ($response->hasHeader("userId")) {
                $myUserId = $response->getHeader("userId")[0];
            }
        }

        // After execution, record the api invocation
        $endTimeUs = microtime(true);
        $duration = round(($endTimeUs - $startTimeUs) * 1000, 3);

        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("insert into audit_log (userid, api, source_ip, exec_time_ms) values (:userid, :api, :source_ip, :exec_time_ms)");
        $stmt->execute(params: [
            ':userid' => $myUserId,
            ':api' => $apiCalled,
            ':source_ip' => $ipAddress,
            ':exec_time_ms' => $duration
        ]);

        return $response;
    }
}
