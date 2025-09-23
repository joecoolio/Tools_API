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
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $apiCalled = $route->getName() != null ? $route->getName() : $route->getPattern();
        $executionDate = (new \DateTimeImmutable());
        $startTimeUs = microtime(true);
        $userName = $request->getAttribute("userid");
        
        // Do the execution
        $response = $handler->handle($request);
    
        // After execution, record the api invocation
        if ($userName == null && $response->hasHeader("userId")) {
            // The validate email token process starts with no user but adds this header
            $userName = $response->getHeader("userId")[0];
        }
        $endTimeUs = microtime(true);
        $duration = round(($endTimeUs - $startTimeUs) * 1000, 3);


        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("insert into audit_log (userid, api, source_ip, exec_time_ms) values (:userid, :api, :source_ip, :exec_time_ms)");
        $stmt->execute(params: [
            ':userid' => $userName,
            ':api' => $apiCalled,
            ':source_ip' => $request->getAttribute('ip_address'),
            ':exec_time_ms' => $duration
        ]);

        return $response;
    }
}
