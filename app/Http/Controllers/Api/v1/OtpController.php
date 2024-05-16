<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class OtpController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/send-otp",
     *     summary="Send Otp",
     *     tags={"Authentication"},
     *     description="Send Otp",
     *     operationId="sendOtp",
     *     @OA\Parameter(
     *         name="country_code",
     *         in="query",
     *         example="+91",
     *         description="Enter Country Code",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="mobile",
     *         in="query",
     *         example="9876543210",
     *         description="Enter Mobile Number",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

     public function sendOtp(Request $request){
        try {
            $rules = [
                'country_code' => 'required|string|max:255|regex:/^\+\d{1,3}$/',
                'mobile' => 'required|string|max:10|regex:/^\d{10,}$/',
            ];
            
            $message = [
                'country_code.required' => 'Country code is required.',
                'country_code.string' => 'Country code must be a string.',
                'country_code.max' => 'Country code must not exceed 255 characters.',
                'country_code.regex' => 'Invalid country code format. It should start with "+" followed by one to three digits.',
                'mobile.required' => 'Mobile number is required.',
                'mobile.string' => 'Mobile number must be a string.',
                'mobile.max' => 'Mobile number must not exceed 10 characters.',
                'mobile.regex' => 'Invalid mobile number format. It should be numeric and at least 10 digits long.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $user = new User();
            $userOtp = new UserOtp();
            $userData = $user->getUserDetailsUsingMobile($request->country_code,$request->mobile);
            if(empty($userData)){
                $data = [
                    'status_code' => 400,
                    'message' => "User Not Found!",
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            UserOtp::where('country_code',$request->country_code)
                    ->where('mobile',$request->mobile)
                    ->update([
                        'status' => 'Inactive'
                    ]);

            $userOtp->user_id = $userData->id;
            $userOtp->country_code = $request->country_code;
            $userOtp->mobile = $request->mobile;
            $userOtp->otp = '123456';
            $userOtp->status = 'Active';
            $userOtp->save();

            $data = [
                'status_code' => 200,
                'message' => 'OTP Sent successfully',
                'data' => [
                    'country_code' => $request->country_code,
                    'mobile_number' => $request->mobile,
                ]
            ];
            return $this->sendJsonResponse($data);

        } catch (\Exception $e) {
            Log::error(
                [
                    'method' => __METHOD__,
                    'error' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage()
                    ],
                    'created_at' => date("Y-m-d H:i:s")
                ]
            );
            return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
        }
    }
    /**
     * @OA\Post(
     *     path="/api/v1/verify-otp",
     *     summary="Verify Otp",
     *     tags={"Authentication"},
     *     description="Verify Otp",
     *     operationId="verifyOtp",
     *     @OA\Parameter(
     *         name="country_code",
     *         in="query",
     *         example="+91",
     *         description="Enter Country Code",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="mobile",
     *         in="query",
     *         example="9876543210",
     *         description="Enter Mobile Number",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="otp",
     *         in="query",
     *         example="123456",
     *         description="Enter OTP",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="json schema",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *         ),
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid Request"
     *     ),
     * )
     */

     public function verifyOtp(Request $request){
        try {
            $rules = [
                'country_code' => 'required|string|max:255|regex:/^\+\d{1,3}$/',
                'mobile' => 'required|string|max:10|regex:/^\d{10,}$/',
                'otp' => 'required|numeric|min:100000|max:999999',
            ];
            
            $message = [
                'country_code.required' => 'Country code is required.',
                'country_code.string' => 'Country code must be a string.',
                'country_code.max' => 'Country code must not exceed 255 characters.',
                'country_code.regex' => 'Invalid country code format. It should start with "+" followed by one to three digits.',
                'mobile.required' => 'Mobile number is required.',
                'mobile.string' => 'Mobile number must be a string.',
                'mobile.max' => 'Mobile number must not exceed 10 characters.',
                'mobile.regex' => 'Invalid mobile number format. It should be numeric and at least 10 digits long.',
                'otp.required' => 'OTP is required.',
                'otp.numeric' => 'OTP must be a numeric value.',
                'otp.min' => 'OTP must be at least 6 digits long.',
                'otp.max' => 'OTP must be at most 6 digits long.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $otpVerification = UserOTP::where([
                'country_code' => $request->country_code,
                'mobile' => $request->mobile,
                'otp' => $request->otp,
                'status' => 'Active'
            ])->first();

            if (!$otpVerification) {
                $data = [
                    'status_code' => 400,
                    'message' => 'Invalid OTP',
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $user = User::where('country_code', $request->country_code)
                ->where('mobile', $request->mobile)
                ->where('status','Active')
                ->first();

            if (!$user) {
                $data = [
                    'status_code' => 400,
                    'message' => 'User not found',
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $token = JWTAuth::fromUser($user);

            $otpVerification->status = 'Inactive';
            $otpVerification->save();

            $authData['userDetails'] = $user;
            $authData['token'] = $token;
            $authData['token_type'] = 'bearer';
            $authData['expires_in'] = JWTAuth::factory()->getTTL() * 60 * 24;

            $data = [
                'status_code' => 200,
                'message' => 'OTP Verified Successfully!',
                'data' => $authData
            ];
            return $this->sendJsonResponse($data);

        } catch (\Exception $e) {
            Log::error(
                [
                    'method' => __METHOD__,
                    'error' => [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage()
                    ],
                    'created_at' => date("Y-m-d H:i:s")
                ]
            );
            return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
        }
     }
    
    
    
}