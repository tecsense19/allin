<?php

namespace App\Http\Middleware;

use Closure;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use App\Utility\Common;
use App\Models\User;

class UserAuthentication {

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next) {
        $requestHeader = substr($request->header('content-type'), 0, strpos($request->header('content-type'), ';'));

        if ($request->header('authorization') !== null) {
            try {
                $user = JWTAuth::parseToken()->authenticate();

                if (!$user) {
                    return $this->unauthorizedResponse('Invalid User');
                }

                if ($user->is_delete == "Yes") {
                    JWTAuth::invalidate(JWTAuth::getToken());
                    return $this->unauthorizedResponse('Invalid Token');
                }

                $request->merge(["user" => $user]);
            } catch (TokenExpiredException $e) {
                if ($request->is('api/v1/refresh-token')) {
                    // Allow token refresh route to proceed
                    $request->merge(["token_expired" => true]);
                } else {
                    return $this->unauthorizedResponse('Token Expired');
                }
            } catch (JWTException $e) {
                return $this->unauthorizedResponse('Invalid Token');
            }

            $response = $next($request);
            $response->header('Access-Control-Allow-Headers', 'Origin, Content-Type, Content-Range, Content-Disposition, Content-Description, X-Auth-Token');
            $response->header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS,DELETE,PUT');

            return $response;
        } else {
            return $this->unauthorizedResponse('Invalid request params');
        }
    }

    private function unauthorizedResponse($message) {
        $data = [
            'status' => 'Failure',
            'status_code' => 401,
            'message' => $message,
            'data' => null
        ];
        return response()->json($data, 401);
    }
}
