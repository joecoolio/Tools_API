<?php
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;

// // Middleware classes
use App\Middleware\JSONBodyMiddleware;
use App\Middleware\MultiPartBodyMiddleware;
use App\Middleware\TimerMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\AuthMiddlewareNonMandatory;
use App\Middleware\ValidateMiddleware;
use App\Middleware\MultiPartValidateMiddleware;
use App\Middleware\AuditMiddleware;

// // Auth classes
use App\Auth\AuthUserLogin;
use App\Auth\AuthUserRegister;
use App\Auth\AuthRefreshToken;

// // User clases
use App\Util;
use App\Models\Neighbor;
use App\Models\Tool;
use App\Models\User;
use App\Models\File;
use App\Models\PushNotification;

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
$app->post('/v1/auth/register', function (Request $request, Response $response, array $args) {
    try {
        $userid = $request->getParam('userid');
        $password = $request->getParam('password');
        $name = $request->getParam('name');
        $nickname = $request->getParam('nickname');
        $address = $request->getParam('address');
        $uploadedFile = $request->getUploadedFiles()['photo'];
        $directory = 'images';
        $ipaddress = $request->getAttribute('ip_address');

        $response = (new AuthUserRegister())->register($userid, $password, $name, $nickname, $address, $ipaddress, $uploadedFile, $directory, $response);
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Add headers per RFC 6749
->add(
    function (Request $request, RequestHandler $handler) {
        $response = $handler->handle($request);
        return $response
            ->withAddedHeader("Cache-Control", "no-store")
            ->withAddedHeader("Pragma", "no-cache");
    }
)
// Make sure the proper fields are in the request
->add( new MultiPartValidateMiddleware([
    'userid' => 'required',
    'password' => 'required',
    'name' => 'required',
    'nickname' => 'required',
    'address' => 'required',
    'photo' => 'uploaded_file:0,1500K,png,jpeg'
]) )
// Make sure the body is multipart/form-data formatted
->add( new MultiPartBodyMiddleware() )
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

// Validate an address
$app->post('/v1/validateaddress', function (Request $request, Response $response, array $args) {
    try {
        $bodyArray = $request->getParsedBody();
        $address = $bodyArray["address"];

        $retval = (new User())->validateAddress($address);
        return $response->withJson([ "result" => $retval ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'address' => 'required'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddlewareNonMandatory() )
// Time each request
->add( new TimerMiddleware() )
;

// Update my info
$app->post('/v1/updateinfo', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");

        $name = $request->getParam('name');
        $nickname = $request->getParam('nickname');
        $password = $request->getParam('password');
        $address = $request->getParam('address');
        $uploadedFile = $request->getParam('photo');

        $directory = 'images';

        // Handle no photo upload
        if ($uploadedFile == "null") $uploadedFile = null;

        (new User())->updateInfo($neighborId, $name, $nickname, $password, $address, $uploadedFile, $directory);
        return $response->withJson([ "result" => "success" ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the proper fields are in the request
->add( new MultiPartValidateMiddleware([
    'name' => 'required',
    'nickname' => 'required',
    'password' => '',
    'address' => 'required',
    'photo' => 'uploaded_file:0,1500K,png,jpeg'
]) )
// Make sure the body is multipart/form-data formatted
->add( new MultiPartBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;

// Discard stored friend list in redis.
// The next call to /friends will reload from the db.
$app->post('/v1/expirefriends', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        Util::expireFriends($neighborId);
        return $response->withJson(data: [ "result" => "success" ]);
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

// Get all of my friends.
$app->post('/v1/friends', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $depth = $bodyArray["depth"];

        $retval = (new User())->getFriends($neighborId, $depth);
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
    'depth' => 'required|numeric'
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


/////
// Neighbors
/////


// Get all neighbors
$app->post('/v1/getneighbors', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $radiusMiles = $bodyArray["radius_miles"];
        
        $retval = (new Neighbor())->listAllNeighbors($neighborId, $radiusMiles);
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
    'radius_miles' => 'required|numeric'
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

// Create a friendship request
$app->post('/v1/requestfriendship', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $neighborId = $bodyArray["neighborId"];
        $message = $bodyArray["message"];

        (new Neighbor())->requestFriendship($myNeighborId, $neighborId, $message);
        $response->getBody()->write('{ "result": "success" }');
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'neighborId' => 'required|integer',
    'message' => 'required'
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

// Delete a friendship request
$app->post('/v1/deletefriendshiprequest', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $neighborId = $bodyArray["neighborId"];

        (new Neighbor())->deleteFriendshipRequest($myNeighborId, $neighborId);
        $response->getBody()->write('{ "result": "success" }');
        return $response;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'neighborId' => 'required|integer',
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


/////
// Manage friends
/////


// Create a friendship / accept a request
$app->post('/v1/createfriendship', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $neighborId = $bodyArray["neighborId"];

        // I am the target, the other neighbor is the source
        (new User())->createFriendship($myNeighborId, $neighborId);
        $response->getBody()->write('{ "result": "success" }');
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

// Delete a friendship
$app->post('/v1/removefriendship', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $neighborId = $bodyArray["neighborId"];

        (new User())->removeFriendship($myNeighborId, $neighborId);
        $response->getBody()->write('{ "result": "success" }');
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


/////
// Manage my tools
/////


// List tool categories
$app->post('/v1/gettoolcategories', function (Request $request, Response $response, array $args) {
    try {
        $retval = (new Tool())->getCategories();
        return $response->withJson($retval);
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

// Create a new tool
$app->post('/v1/createtool', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");

        $shortName = $request->getParam('short_name');
        $brand = $request->getParam('brand');
        $name = $request->getParam('name');
        $productUrl = $request->getParam('product_url');
        $replacementCost = $request->getParam('replacement_cost');
        $categoryId = $request->getParam('category');
        // $uploadedFile = $request->getUploadedFiles()['photo'];
        $uploadedFile = $request->getParam('photo');
        $directory = 'images';

        (new Tool())->createTool(
            $neighborId,
            $shortName,
            $brand,
            $name,
            $productUrl,
            $replacementCost,
            $categoryId,
            $uploadedFile,
            $directory
        );
        return $response->withJson(data: [ "result" => "success" ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the proper fields are in the request
->add( new MultiPartValidateMiddleware([
    'short_name' => 'required',
    'brand' => 'required',
    'name' => 'required',
    'product_url' => 'required',
    'replacement_cost' => 'required|numeric',
    'photo' => 'uploaded_file:0,1500K,png,jpeg'
]) )
// Make sure the body is multipart/form-data formatted
->add( new MultiPartBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;

// Update a tool
$app->post('/v1/updatetool', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");

        $toolId = $request->getParam('id');
        $shortName = $request->getParam('short_name');
        $brand = $request->getParam('brand');
        $name = $request->getParam('name');
        $productUrl = $request->getParam('product_url');
        $replacementCost = $request->getParam('replacement_cost');
        $categoryId = $request->getParam('category');
        // $uploadedFile = $request->getUploadedFiles()['photo'];
        $uploadedFile = $request->getParam('photo');
        $directory = 'images';

        (new Tool())->updateTool(
            $toolId,
            $neighborId,
            $shortName,
            $brand,
            $name,
            $productUrl,
            $replacementCost,
            $categoryId,
            $uploadedFile,
            $directory
        );
        return $response->withJson([ "result" => "success" ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the proper fields are in the request
->add( new MultiPartValidateMiddleware([
    'id' => 'required|integer',
    'short_name' => 'required',
    'brand' => 'required',
    'name' => 'required',
    'product_url' => 'required',
    'replacement_cost' => 'required|numeric',
    'photo' => 'uploaded_file:0,1500K,png,jpeg'
]) )
// Make sure the body is multipart/form-data formatted
->add( new MultiPartBodyMiddleware() )
// Audit
->add( new AuditMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
// Time each request
->add( new TimerMiddleware() )
;


/////
// Tools
/////


// List all tools available to me
$app->post('/v1/getalltools', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $radiusMiles = $bodyArray["radius_miles"];

        $retval = (new Tool())->listAllTools($neighborId, $radiusMiles);
        return $response->withJson($retval);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'radius_miles' => 'required|numeric'
]) )
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
        return $response->withJson($retval);
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

// Create a tool borrow request
$app->post('/v1/requestborrow', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $toolId = $bodyArray["toolId"];
        $message = $bodyArray["message"];

        (new Tool())->requestBorrowTool($myNeighborId, $toolId, $message);
        return $response->withJson([ "result" => "success" ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'toolId' => 'required|integer',
    'message' => 'required'
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

// Delete a borrow request
$app->post('/v1/deleteborrowrequest', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $toolId = $bodyArray["toolId"];

        (new Tool())->deleteBorrowRequest($myNeighborId, $toolId);
        return $response->withJson([ "result" => "success" ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'toolId' => 'required|integer',
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

// Accept a tool borrow request
$app->post('/v1/acceptborrow', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $toolId = $bodyArray["toolId"];
        $notificationId = $bodyArray["notificationId"];
        $message = $bodyArray["message"];

        (new Tool())->acceptBorrowRequest($myNeighborId, $toolId, $notificationId, $message);
        return $response->withJson([ "result" => "success" ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'toolId' => 'required|integer',
    'notificationId' => 'required|integer',
    'message' => 'required',
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

// Reject a tool borrow request
$app->post('/v1/rejectborrow', function (Request $request, Response $response, array $args) {
    try {
        $myNeighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $toolId = $bodyArray["toolId"];
        $notificationId = $bodyArray["notificationId"];

        (new Tool())->rejectBorrowRequest($myNeighborId, $toolId, $notificationId);
        return $response->withJson([ "result" => "success" ]);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'toolId' => 'required|integer',
    'notificationId' => 'required|integer',
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


/////
// Notifications
/////


// Get all notifications
$app->post('/v1/getnotifications', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $retval = (new User())->listNotifications($neighborId);
        return $response->withJson($retval);
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

// Resolve a notification
$app->post('/v1/resolvenotification', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        
        $bodyArray = $request->getParsedBody();
        $notificationId = $bodyArray["id"];
        
        (new User())->resolveNotifications($notificationId);
        return $response->withJson([ "result" => "success" ]);
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




// Test push notification
$app->post('/v1/testpush', function (Request $request, Response $response, array $args) {
    try {
        (new PushNotification())->sendWebPush();
        return $response->withJson([ "result" => "success" ]);
    } catch (Exception $e) {
error_log($e);
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
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
