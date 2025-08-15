<?php
use App\Models\Neighbor;
use App\Models\Tool;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;

// // Middleware classes
use App\Middleware\JSONBodyMiddleware;
use App\Middleware\TimerMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\AuthMiddlewareNonMandatory;
use App\Middleware\ValidateMiddleware;
use App\Middleware\AuditMiddleware;

// // Auth classes
use App\Auth\AuthUserLogin;
use App\Auth\AuthUserRegister;
use App\Auth\AuthRefreshToken;

// // User clases
// use App\Models\LakeLevel;
use App\Models\User;

require 'vendor/autoload.php';

// Read .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$config = require 'config.php';

//$config = [];
//$config['displayErrorDetails'] = true;

$app = AppFactory::create();
// $app->setBasePath($_ENV['WEB_BASE_PATH']);
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

$checkProxyHeaders = true;
$trustedProxies = [];
$app->add(new RKA\Middleware\IpAddress($checkProxyHeaders, $trustedProxies));

/////
// Authorization
/////

// Register (user, pass) => (access token, refresh token) - same as login + create user
$app->post('/v1/auth/register', [new AuthUserRegister(), 'process'])
// Add headers per RFC 6749
->add(
    function (Request $request, RequestHandler $handler) {
        $response = $handler->handle($request);
        return $response
            ->withAddedHeader("Cache-Control", "no-store")
            ->withAddedHeader("Pragma", "no-cache");
    }
)
// Make sure the user/pass values are in the body JSON
->add( new ValidateMiddleware([
    'userid' => 'required',
    'name' => 'required',
    'password' => 'required',
    'address' => 'required'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Non-mandatory auth
->add( new AuthMiddlewareNonMandatory() )
// Time each request
->add( new TimerMiddleware() )
;

// Login (user, pass) => (access token, refresh token)
$app->post('/v1/auth/login', [new AuthUserLogin(), 'process'])
// Add headers per RFC 6749
->add(
    function (Request $request, RequestHandler $handler) {
        $response = $handler->handle($request);
        return $response
            ->withAddedHeader("Cache-Control", "no-store")
            ->withAddedHeader("Pragma", "no-cache");
    }
)
// Make sure the user/pass values are in the body JSON
->add( new ValidateMiddleware([ 'userid' => 'required', 'password' => 'required' ]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Non-mandatory auth
->add( new AuthMiddlewareNonMandatory() )
// Time each request
->add( new TimerMiddleware() )
;

// Refresh token (refresh token)
$app->post('/v1/auth/refresh', [new AuthRefreshToken(), 'process'])
// Add headers per RFC 6749
->add(
    function (Request $request, RequestHandler $handler) {
        $response = $handler->handle($request);
        return $response
            ->withAddedHeader("Cache-Control", "no-store")
            ->withAddedHeader("Pragma", "no-cache");
    }
)
// Make sure the user/pass values are in the body JSON
->add( new ValidateMiddleware([
    'grant_type' => 'required',
    'refresh_token' => 'required'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Non-mandatory auth
->add( new AuthMiddlewareNonMandatory() )
// Time each request
->add( new TimerMiddleware() )
;


/////
// User
/////

// Get my info (including location)
$app->post('/v1/myinfo', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $retval = (new User())->getInfo($neighborId);
        $response->getBody()->write($retval);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;


// Refresh friend list in redis
$app->post('/v1/reloadfriends', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $retval = (new User())->reloadFriends($neighborId);
        $response->getBody()->write('{ "result": "success" }');
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;

// Get all of my friends (using the cached list)
$app->post('/v1/friends', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $retval = (new User())->getFriends($neighborId);
        $response->getBody()->write($retval);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;

// Get all neighbors
$app->post('/v1/getneighbors', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $retval = (new Neighbor())->listAllNeighbors($neighborId);
        $response->getBody()->write($retval);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;

// Get a single neighbor
$app->post('/v1/getneighbor', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $neighborId = $bodyArray["neighborId"];

        $retval = (new Neighbor())->getNeighbor($neighborId, $myNeighborId);
        $response->getBody()->write($retval);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'neighborId' => 'required|integer'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;

// Get the photo for a neighbor
$app->post('/v1/getImage', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $photo_id = $bodyArray["photo_id"];

        $response = (new Neighbor())->getPhoto($photo_id, $response, /*$myNeighborId**/);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'photo_id' => 'required'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;


// List all of my tools
$app->post('/v1/getmytools', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $retval = (new Tool())->listMyTools($neighborId);
        $response->getBody()->write($retval);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;


// List all tools available to me
$app->post('/v1/getalltools', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $retval = (new Tool())->listAllTools($neighborId);
        $response->getBody()->write($retval);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;

// List all tools available to me
$app->post('/v1/gettool', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $bodyArray = $request->getParsedBody();
        $toolId = $bodyArray["id"];
        
        $retval = (new Tool())->getTool($toolId, $neighborId);
        $response->getBody()->write($retval);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'id' => 'required|integer'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;










$app->options('/{routes:.+}', function ($request, $response, $args) {
    return $response;
});

$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

$app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
    throw new \Slim\Exception\HttpNotFoundException($request);
});

$app->run();
