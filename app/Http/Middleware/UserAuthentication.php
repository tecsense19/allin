<?php

namespace App\Http\Middleware;

use Closure;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use App\Utility\Common;
use App\Models\User;

class UserAuthentication {

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next) {
        $requestHeader = substr($request->header('content-type'), 0, strpos($request->header('content-type'), ';'));
        //dd($request->headers->all());
        if ($request->header('authorization') !== null) {
            try {
                if(!$user = JWTAuth::parseToken()->authenticate()) {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Invalid User',
                        'data' => []
                    ];
                    return Common::sendJsonResponse($data);
                }
                if(JWTAuth::parseToken()->authenticate()->is_delete=="Yes") {
                    $token = JWTAuth::getToken();
                    JWTAuth::parseToken()->invalidate($token);
                    $data = [
                        'status_code' => 401,
                        'message' => 'Invalid Token',
                        'data' => []
                    ];
                    return Common::sendJsonResponse($data);
                }
            } catch (JWTException $e) {
                if ($e instanceof \PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException) {
                    $data = [
                        'status_code' => 401,
                        'message' => 'Token Expired',
                    ];
                } elseif ($e instanceof \PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException) {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Invalid Token',
                    ];
                } else {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Token Not found',
                    ];
                }
                return Common::sendJsonResponse($data);
            }

            $request->merge(["user" => $user]);
            $response = $next($request);
            $response->header('Access-Control-Allow-Headers', 'Origin, Content-Type, Content-Range, Content-Disposition, Content-Description, X-Auth-Token');
            $response->header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS,DELETE,PUT');

            return $response;
        }else{
            $data = [
                'status' => false,
                'status_code' => 400,
                'message' => 'Invalid request params',
            ];
            return Common::sendJsonResponse($data);
        }
    }

}