<?php

namespace App\Utility;

use App\Utility\ResponseFormatter;
use Illuminate\Support\Facades\Mail;
use Intervention\Image\Facades\Image as Image;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Utility\Crypt\RSA as Crypt_RSA;
use Illuminate\Support\Facades\Storage;
use AWS;
use URL;

use App\Model\ApiRequestLog;

class Common {

    public static function sendJsonResponse($response) {
        if ($response['status_code'] == 200) {
            $responseStr = ResponseFormatter::responseSuccess($response);
        }
        else if ($response['status_code'] == 500)
            $responseStr = ResponseFormatter::responseServerError($response);
        else if ($response['status_code'] == 401)
            $responseStr = ResponseFormatter::responseUnauthorized($response);
        else if ($response['status_code'] == 400)
            $responseStr = ResponseFormatter::responseBadRequest($response);
        else if ($response['status_code'] == 406)
            $responseStr = ResponseFormatter::responseNotAcceptable($response);

        return \Illuminate\Support\Facades\Response::json($responseStr)->header('Content-Type', "application/json");
    }
}