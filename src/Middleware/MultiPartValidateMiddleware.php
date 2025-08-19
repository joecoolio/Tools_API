<?php

namespace App\Middleware;

use Rakit\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Description of ValidateMiddleware
 *
 * @author mike
 */
class MultiPartValidateMiddleware {
    private $validationArray = null;
    
    function __construct(array $validationArray) {
        $this->validationArray = $validationArray;
    }
    
    /**
     * Validate the body's JSON using the rules passed in the constructor.
     * Return code 422 (Unprocessable Entity) if it fails
     */
    public function __invoke(Request $request, RequestHandler $handler): Response {
        $validator = new Validator;
        $validation = $validator->make($_POST + $_FILES, $this->validationArray);

        $validation->validate();
        if (!$validation->fails()) {
            return $handler->handle($request);
        } else {
            error_log("Validation failed: " . json_encode($validation->errors()->firstOfAll()));
            $badresponse = new \GuzzleHttp\Psr7\Response();
            return $badresponse->withStatus(422, "Validation of input failed");
        }        

    }
}
