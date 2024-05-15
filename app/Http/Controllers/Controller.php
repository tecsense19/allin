<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use App\Utility\Common;

/**
 * @OA\Info(
 *    title="API",
 *    version="1.0.0",
 * ),
 * @OA\SecurityScheme(
 *   securityScheme="bearerAuth",
 *   type="http",
 *   scheme="bearer",
 *   bearerFormat= "JWT"
 *  ),
 * @OA\Tag(
 *     name="Authentication",
 *     description="",
 * )
 *
 */

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    protected $utility;

    protected $request_time;

    public function __construct() {

        $this->request_time = date('Y-m-d H:i:s');
    }

    public function sendJsonResponse($response) {
        return Common::sendJsonResponse($response);
    }
}
