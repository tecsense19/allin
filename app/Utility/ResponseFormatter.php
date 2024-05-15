<?php

namespace App\Utility;

use Illuminate\Support\Facades\Log;
use \Symfony\Component\HttpFoundation\Response;

class ResponseFormatter {

    const SUCCESS = 'Success';
    const FAILURE = 'Failure';
    const ERROR = 'Error';

    public function __construct() {

    }

    public static function responseSuccess($params = []) {

        $response = array(
            'status' => self::SUCCESS,
            'status_code' => Response::HTTP_OK,
            'message' => $params['message'],
            'data' => isset($params['data']) ? $params['data'] : [],
        );

        if(isset($params['data']['meta'])){
            $response['meta'] = $params['data']['meta'];
            unset($params['data']['meta']);
        }

        if(isset($params['data']['links'])){
            $response['links'] = $params['data']['links'];
            unset($params['data']['links']);
        }

        if(isset($params['data']['paginate_data'])){
            $response['data'] =  $params['data']['paginate_data'];
        }
        return $response;
    }

    public static function responseFailure($params = []) {
        $response = array(
            'status' => self::FAILURE,
            'status_code' => Response::HTTP_BAD_REQUEST,
            'message' => $params['message'],
            'data' => NULL,
        );
        Log::error($response);
        return $response;
    }

    public static function responseUnauthorized($params = []) {
        $response = array(
            'status' => self::FAILURE,
            'status_code' => Response::HTTP_UNAUTHORIZED,
            'message' => $params['message'],
            'data' => NULL,
        );
        Log::error($response);
        return $response;
    }

    public static function responseBadRequest($params = []) {
        $response = array(
            'status' => self::ERROR,
            'status_code' => Response::HTTP_BAD_REQUEST,
            'message' => $params['message'],
            'data' => (object) (isset($params['data']) ? $params['data'] : []),
        );

        Log::error($response);
        return $response;
    }

    public static function responseNotFound($params = []) {
        $response = array(
            'status' => self::ERROR,
            'status_code' => Response::HTTP_NOT_FOUND,
            'message' => $params['message'],
            'data' => (object) (isset($params['data']) ? $params['data'] : []),
        );

        Log::error($response);
        return $response;
    }

    public static function responseServerError($params = []) {

        $response = array(
            'status' => self::ERROR,
            'status_code' => Response::HTTP_INTERNAL_SERVER_ERROR,
            'message' => $params['message'],
            'data' => (object) (isset($params['data']) ? $params['data'] : []),
        );

        Log::error($response);
        return $response;
    }

    public static function responseNotAcceptable($params = []) {
        $response = array(
            'status' => self::ERROR,
            'status_code' => Response::HTTP_NOT_ACCEPTABLE,
            'message' => $params['message'],
            'data' => (object) (isset($params['data']) ? $params['data'] : []),
        );

        Log::error($response);
        return $response;
    }

    public function validation_error($validator) {
        $this->success = 0;
        $this->message = $validator->errors()->first();
        $this->statusCode = Response::HTTP_NOT_FOUND;
        return $this->render($this->success, $this->message, $this->statusCode);
    }

    public function render($success, $message, $status, $data = null) {
        return [
            'status' => $success,
            'status_code' => $message,
            'message' => $status,
            'data' => $data,
        ];
    }
}