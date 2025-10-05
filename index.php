<?php

use App\Models\GemeniAI;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

use Slim\Factory\AppFactory;

// Middleware classes
use App\Middleware\JSONBodyMiddleware;
use App\Middleware\MultiPartBodyMiddleware;
use App\Middleware\TimerMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\AuthMiddlewareNonMandatory;
use App\Middleware\ValidateMiddleware;
use App\Middleware\MultiPartValidateMiddleware;
use App\Middleware\AuditMiddleware;
use App\Middleware\SendNotificationMiddleware;
use App\Middleware\CleanupMiddleware;

// Auth classes
use App\Auth\AuthUserLogin;
use App\Auth\AuthUserRegister;
use App\Auth\AuthRefreshToken;

// User clases
use App\Util;
use App\Models\Neighbor;
use App\Models\Tool;
use App\Models\User;
use App\Models\News;
use App\Models\PushNotification;

require 'vendor/autoload.php';

// Read .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$config = require 'config.php';

//$config = [];
//$config['displayErrorDetails'] = true;

$app = AppFactory::create();
$app->setBasePath($_ENV['WEB_BASE_PATH']);
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

$checkProxyHeaders = true;
$trustedProxies = [];

/////
// Authorization
/////

// Check a userid to see if it's already in use
$app->post('/v1/auth/useridavailable', function (Request $request, Response $response, array $args) {
    try {
        $userid = $request->getParam('userid');

        $retval = ! (new User())->userIdExists($userid);
        return $response->withJson([ "result" => $retval ]);;
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the proper fields are in the request
->add( new ValidateMiddleware([
    'userid' => 'required',
]) )
// Make sure the body is multipart/form-data formatted
->add( new JSONBodyMiddleware() )
// Non-mandatory auth
->add( new AuthMiddlewareNonMandatory() )
;

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
// Send a welcome notification
->add(new SendNotificationMiddleware("Welcome to the system!  Cool ain't it?!!!!"))
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
->add( new JsonBodyMiddleware() )
// Non-mandatory auth
->add( new AuthMiddlewareNonMandatory() )
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
// Non-mandatory auth
->add( new AuthMiddlewareNonMandatory() )
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
// Non-mandatory auth
->add( new AuthMiddlewareNonMandatory() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddlewareNonMandatory() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
;

// Get all of my friends.
$app->post('/v1/friends', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $depth = $bodyArray["depth"];
        $radiusMiles = $bodyArray["radius_miles"];
        $searchTerms = array_key_exists("search_terms", $bodyArray) ? $bodyArray["search_terms"] : [];
        $searchWithAnd = array_key_exists("search_with_and", $bodyArray) ? $bodyArray["search_with_and"] : false;

        $retval = (new User())->getFriends($neighborId, $depth, $radiusMiles, $searchTerms, $searchWithAnd);
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
    'depth' => 'required|numeric',
    'radius_miles' => 'required|numeric',
    'search_terms' => 'array',
    'search_with_and' => 'required_with:search_terms'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
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
        $searchTerms = array_key_exists("search_terms", $bodyArray) ? $bodyArray["search_terms"] : [];
        $searchWithAnd = array_key_exists("search_with_and", $bodyArray) ? $bodyArray["search_with_and"] : false;
        
        $retval = (new Neighbor())->listAllNeighbors($neighborId, $radiusMiles, $searchTerms, $searchWithAnd);
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
    'radius_miles' => 'required|numeric',
    'search_terms' => 'array',
    'search_with_and' => 'required_with:search_terms'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
        $searchTerms = $request->getParam('search_terms');
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
            $searchTerms,
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
    'category' => 'required|numeric',
    'search_terms' => 'required|array',
    'photo' => 'uploaded_file:0,1500K,png,jpeg'
]) )
// Make sure the body is multipart/form-data formatted
->add( new MultiPartBodyMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
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
        $searchTerms = $request->getParam('search_terms');
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
            $searchTerms,
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
    'category' => 'required|numeric',
    'search_terms' => 'required|array',
    'photo' => 'uploaded_file:0,1500K,png,jpeg'
]) )
// Make sure the body is multipart/form-data formatted
->add( new MultiPartBodyMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
;


/////
// Tools
/////


// Get category for tool description
$app->post('/v1/toolcategory', function (Request $request, Response $response, array $args) {
    try {
        $bodyArray = $request->getParsedBody();
        $tooldescription = $bodyArray["tooldescription"];
        
        return $response->withJson((new GemeniAI())->getCategoryForTool($tooldescription));
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'tooldescription' => 'required'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
;

// Get keywords for tool description
$app->post('/v1/toolkeywords', function (Request $request, Response $response, array $args) {
    try {
        $bodyArray = $request->getParsedBody();
        $tooldescription = $bodyArray["tooldescription"];
        
        return $response->withJson((new GemeniAI())->getKeywordsForTool($tooldescription));
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'tooldescription' => 'required'
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
;

// List all tools available to me
$app->post('/v1/getalltools', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $radiusMiles = $bodyArray["radius_miles"];
        $searchTerms = array_key_exists("search_terms", $bodyArray) ? $bodyArray["search_terms"] : [];
        $searchWithAnd = array_key_exists("search_with_and", $bodyArray) ? $bodyArray["search_with_and"] : false;

        $retval = (new Tool())->listAllTools($neighborId, $radiusMiles, $searchTerms, $searchWithAnd);
        return $response->withJson($retval);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
// Make sure the id is in the body
->add( new ValidateMiddleware([
    'radius_miles' => 'required|numeric',
    'search_terms' => 'array',
    'search_with_and' => 'required_with:search_terms'
]) )
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
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
// Mandatory auth
->add( new AuthMiddleware() )
;


/////
// News
/////
$app->post('/v1/news', function (Request $request, Response $response, array $args) {
    try {
        $neighborId = $request->getAttribute("neighborId");
        $bodyArray = $request->getParsedBody();
        $radiusMiles = $bodyArray["radius_miles"];
        $afterId =  $bodyArray["afterId"];

        $retval = (new News())->getNews($neighborId, $radiusMiles, $afterId);
        return $response->withJson($retval);
    } catch (Exception $e) {
        $badresponse = new \GuzzleHttp\Psr7\Response();
        $badresponse->getBody()->write(json_encode($e->getMessage()));
        return $badresponse->withStatus(500);
    }
})
//  the id is in the body
->add( new ValidateMiddleware([
    'radius_miles' => 'numeric', // Request news in this radius of me
    'afterId' => 'integer', // Request news items > than this id (to get only new stuff)
]) )
// Make sure the body is JSON formatted
->add( new JSONBodyMiddleware() )
// Mandatory auth
->add( new AuthMiddleware() )
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
;









$app->options('/{routes:.+}', function ($request, $response, $args) {
    return $response;
});

// CORS stuff
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

// Record an audit record for each request
$app->add( new AuditMiddleware() );
// Put the 'ip_address' header on each request
$app->add(new RKA\Middleware\IpAddress($checkProxyHeaders, $trustedProxies));
// Time each request
$app->add( new TimerMiddleware() );
// Final cleanup to remove secretive stuff from the response
$app->add(new CleanupMiddleware([ "userId", "neighborId" ]));

$app->run();
