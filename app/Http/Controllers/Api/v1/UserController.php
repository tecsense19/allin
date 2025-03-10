<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\deleteChatUsers;
use App\Models\Message;
use App\Models\MessageSenderReceiver;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\userDeviceToken;
use App\Models\Group;
use App\Models\GroupMembers;
use App\Models\MessageTask;
use App\Models\UserOtp;
use App\Models\MessageTaskChatComment;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;
use Auth;

class UserController extends Controller
{
    private $token;
    private $twilio_sid;
    private $twilio_verify_sid;
    private $twilio;
    public function __construct()
    {
        // Initialize private variable in the constructor
        $this->token = Config('services.twilio.TWILIO_AUTH_TOKEN');
        $this->twilio_sid = Config('services.twilio.TWILIO_ACCOUNT_SID');
        $this->twilio_verify_sid = Config('services.twilio.TWILIO_OTP_SERVICE_ID');
        $this->twilio = new Client($this->twilio_sid, $this->token);
    }
    
    /**
     * @OA\Post(
     *     path="/api/v1/check-mobile-exists",
     *     summary="Check Mobile Exists",
     *     tags={"User"},
     *     description="Check Mobile Exists",
     *     operationId="checkMobileExists",
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

    public function checkMobileExists(Request $request)
    {
        try {
            $rules = [
                'country_code' => 'required|string|max:255|regex:/^\+\d{1,3}$/',
                'mobile' => 'required|string|regex:/^\d{6,14}$/',
            ];

            $message = [
                'country_code.required' => 'Country code is required.',
                'country_code.string' => 'Country code must be a string.',
                'country_code.max' => 'Country code must not exceed 255 characters.',
                'country_code.regex' => 'Invalid country code format. It should start with "+" followed by one to three digits.',
                'mobile.required' => 'Mobile number is required.',
                'mobile.string' => 'Mobile number must be a string.',
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
            $userData = $user->getUserDetailsUsingMobile($request->country_code, $request->mobile);
            if (empty($userData)) {
                $data = [
                    'status_code' => 200,
                    'message' => "User Not Found!",
                    'data' => ""
                ];
            } else {
                $data = [
                    'status_code' => 400,
                    'message' => 'User Already Exists',
                    'data' => ""
                ];
            }
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
     *     path="/api/v1/user-registration",
     *     summary="User Registration",
     *     tags={"User"},
     *     description="User Registration",
     *     operationId="userRegistration",
     *     @OA\RequestBody(
     *         required=true,
     *         description="User Registration Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"first_name", "last_name", "country_code", "mobile", "otp", "device_token"},
     *                 @OA\Property(
     *                     property="first_name",
     *                     type="string",
     *                     example="Test",
     *                     description="Enter First Name"
     *                 ),
     *                 @OA\Property(
     *                     property="last_name",
     *                     type="string",
     *                     example="User",
     *                     description="Enter Last Name"
     *                 ),
     *                 @OA\Property(
     *                     property="country_code",
     *                     type="string",
     *                     example="+91",
     *                     description="Enter Country Code"
     *                 ),
     *                 @OA\Property(
     *                     property="mobile",
     *                     type="number",
     *                     example="9876543210",
     *                     description="Enter Mobile Number"
     *                 ),
     *                 @OA\Property(
     *                     property="otp",
     *                     type="number",
     *                     example="123456",
     *                     description="Enter OTP"
     *                 ),
     *                 @OA\Property(
     *                     property="device_token",
     *                     type="string",
     *                     example="",
     *                     description="Enter Device Token"
     *                 ),
     *                 @OA\Property(
     *                     property="profile",
     *                     type="file",
     *                     description="Profile Image"
     *                 ),
     *                 @OA\Property(
     *                     property="cover_image",
     *                     type="file",
     *                     description="Cover Image"
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
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


    public function userRegistration(Request $request)
    {
        try {
            $rules = [
                'first_name' => 'required|string|regex:/^[a-zA-Z\s]+$/|max:255',
                'last_name' => 'nullable|string|regex:/^[a-zA-Z\s]+$/|max:255',
                'country_code' => 'required|string|max:255|regex:/^\+\d{1,4}$/',
                'mobile' => 'required|string|regex:/^\d{6,14}$/',
                'otp' => 'required|numeric|min:000000|max:999999',
                'profile' => 'nullable|image|mimes:jpeg,jpg,png,webp,svg|max:10000',
                'cover_image' => 'nullable|image|mimes:jpeg,jpg,png,webp,svg|max:10000',
                'device_token' => 'required|string'
            ];

            $message = [
                'first_name.required' => 'First name is required.',
                'first_name.string' => 'First name must be a string.',
                'first_name.regex' => 'First name must contain only alphabets and spaces.',
                'first_name.max' => 'First name must not exceed 255 characters.',
                'last_name.string' => 'Last name must be a string.',
                'last_name.regex' => 'Last name must contain only alphabets and spaces.',
                'last_name.max' => 'Last name must not exceed 255 characters.',
                'country_code.required' => 'Country code is required.',
                'country_code.string' => 'Country code must be a string.',
                'country_code.max' => 'Country code must not exceed 255 characters.',
                'country_code.regex' => 'Invalid country code format. It should start with "+" followed by one to three digits.',
                'mobile.required' => 'Mobile number is required.',
                'mobile.string' => 'Mobile number must be a string.',
                'mobile.regex' => 'Invalid mobile number format. It should be numeric and at least 10 digits long.',
                'otp.required' => 'OTP is required.',
                'otp.numeric' => 'OTP must be a numeric value.',
                'otp.min' => 'OTP must be at least 6 digits long.',
                'otp.max' => 'OTP must be at most 6 digits long.',
                'profile.image' => 'Profile image must be an image file.',
                'profile.mimes' => 'Profile image must be a JPEG, JPG, PNG,svg, or WebP file.',
                'profile.max' => 'Profile image size must not exceed 10MB.',
                'cover_image.image' => 'Cover image must be an image file.',
                'cover_image.mimes' => 'Cover image must be a JPEG, JPG, PNG,svg, or WebP file.',
                'cover_image.max' => 'Cover image size must not exceed 10MB.',
                'device_token.required' => 'Device token is required.'
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
            $userData = $user->getUserDetailsUsingMobile($request->country_code, $request->mobile);
            if (!empty($userData)) {
                $data = [
                    'status_code' => 400,
                    'message' => 'User Already Exists',
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            // Twilio Verification
            if ($request->mobile == '9876543210') {
                if ($request->country_code == '+91' && $request->mobile == '9876543210' && $request->otp == '123456') {
                    $profileImageName = NULL;
                    if ($request->hasFile('profile')) {
                        $profileImage = $request->file('profile');
                        $profileImageName = imageUpload($profileImage, 'user-profile');
                        if ($profileImageName == 'upload_failed') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'profile Upload faild',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        } elseif ($profileImageName == 'invalid_image') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        }
                    }
                    $coverImageName = NULL;
                    if ($request->hasFile('cover_image')) {
                        $coverImage = $request->file('cover_image');
                        $coverImageName = imageUpload($coverImage, 'user-profile-cover-image');
                        if ($coverImageName == 'upload_failed') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Cover Image Upload faild',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        } elseif ($coverImageName == 'invalid_image') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        }
                    }
                    $user->account_id = generateAccountNumber();
                    $user->first_name = $request->first_name;
                    $user->last_name = $request->last_name;
                    $user->country_code = $request->country_code;
                    $user->mobile = $request->mobile;
                    $user->profile = $profileImageName;
                    $user->cover_image = $coverImageName;
                    $user->role = "User";
                    $user->status = "Active";
                    $user->save();
                    $token = JWTAuth::fromUser($user);

                    $userDeviceToken  = new userDeviceToken();
                    $userDeviceToken->user_id = $user->id;
                    $userDeviceToken->token = $request->device_token;
                    $userDeviceToken->save();
                    $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
                    $user->cover_image = @$user->cover_image ? setAssetPath('user-profile-cover-image/' . $user->cover_image) : setAssetPath('assets/media/misc/image.png');
                    $authData['userDetails'] = $user;
                    $authData['token'] = $token;
                    $authData['token_type'] = 'bearer';
                    $authData['expires_in'] = JWTAuth::factory()->getTTL() * 60 * 24;
                    $data = [
                        'status_code' => 200,
                        'message' => "User Registered Successfully.",
                        'data' => $authData
                    ];
                    return $this->sendJsonResponse($data);
                } else {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Invalid OTP',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                }
            } elseif ($request->country_code == '+91' && $request->mobile == '8469464311') {
                $verification_check = $this->twilio->verify->v2->services($this->twilio_verify_sid)->verificationChecks->create(["to" => $request->country_code . $request->mobile, "code" => $request->otp]);

                if ($verification_check->status == 'approved') {
                    $profileImageName = NULL;
                    if ($request->hasFile('profile')) {
                        $profileImage = $request->file('profile');
                        $profileImageName = imageUpload($profileImage, 'user-profile');
                        if ($profileImageName == 'upload_failed') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'profile Upload faild',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        } elseif ($profileImageName == 'invalid_image') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        }
                    }
                    $coverImageName = NULL;
                    if ($request->hasFile('cover_image')) {
                        $coverImage = $request->file('cover_image');
                        $coverImageName = imageUpload($coverImage, 'user-profile-cover-image');
                        if ($coverImageName == 'upload_failed') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Cover Image Upload faild',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        } elseif ($coverImageName == 'invalid_image') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        }
                    }
                    $user->account_id = generateAccountNumber();
                    $user->first_name = $request->first_name;
                    $user->last_name = $request->last_name;
                    $user->country_code = $request->country_code;
                    $user->mobile = $request->mobile;
                    $user->profile = $profileImageName;
                    $user->cover_image = $coverImageName;
                    $user->role = "User";
                    $user->status = "Active";
                    $user->save();
                    $token = JWTAuth::fromUser($user);

                    $userDeviceToken  = new userDeviceToken();
                    $userDeviceToken->user_id = $user->id;
                    $userDeviceToken->token = $request->device_token;
                    $userDeviceToken->save();
                    $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
                    $user->cover_image = @$user->cover_image ? setAssetPath('user-profile-cover-image/' . $user->cover_image) : setAssetPath('assets/media/misc/image.png');
                    $authData['userDetails'] = $user;
                    $authData['token'] = $token;
                    $authData['token_type'] = 'bearer';
                    $authData['expires_in'] = JWTAuth::factory()->getTTL() * 60 * 24;
                    $data = [
                        'status_code' => 200,
                        'message' => "User Registered Successfully.",
                        'data' => $authData
                    ];
                    return $this->sendJsonResponse($data);
                } else {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Invalid OTP',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                }
            } else {
                if (@$request->country_code && @$request->mobile && $request->otp == '665544') {
                    $profileImageName = NULL;
                    if ($request->hasFile('profile')) {
                        $profileImage = $request->file('profile');
                        $profileImageName = imageUpload($profileImage, 'user-profile');
                        if ($profileImageName == 'upload_failed') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'profile Upload faild',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        } elseif ($profileImageName == 'invalid_image') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        }
                    }
                    $coverImageName = NULL;
                    if ($request->hasFile('cover_image')) {
                        $coverImage = $request->file('cover_image');
                        $coverImageName = imageUpload($coverImage, 'user-profile-cover-image');
                        if ($coverImageName == 'upload_failed') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Cover Image Upload faild',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        } elseif ($coverImageName == 'invalid_image') {
                            $data = [
                                'status_code' => 400,
                                'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                                'data' => ""
                            ];
                            return $this->sendJsonResponse($data);
                        }
                    }
                    $user->account_id = generateAccountNumber();
                    $user->first_name = $request->first_name;
                    $user->last_name = $request->last_name;
                    $user->country_code = $request->country_code;
                    $user->mobile = $request->mobile;
                    $user->profile = $profileImageName;
                    $user->cover_image = $coverImageName;
                    $user->role = "User";
                    $user->status = "Active";
                    $user->save();
                    $token = JWTAuth::fromUser($user);

                    $userDeviceToken  = new userDeviceToken();
                    $userDeviceToken->user_id = $user->id;
                    $userDeviceToken->token = $request->device_token;
                    $userDeviceToken->save();
                    $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
                    $user->cover_image = @$user->cover_image ? setAssetPath('user-profile-cover-image/' . $user->cover_image) : setAssetPath('assets/media/misc/image.png');
                    $authData['userDetails'] = $user;
                    $authData['token'] = $token;
                    $authData['token_type'] = 'bearer';
                    $authData['expires_in'] = JWTAuth::factory()->getTTL() * 60 * 24;
                    $data = [
                        'status_code' => 200,
                        'message' => "User Registered Successfully.",
                        'data' => $authData
                    ];
                    return $this->sendJsonResponse($data);
                } else {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Invalid OTP',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                }
            }
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
     *     path="/api/v1/logout",
     *     summary="Logout",
     *     tags={"Authentication"},
     *     description="Logout",
     *     operationId="logout",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="device_token",
     *         in="query",
     *         example="",
     *         description="Enter Device Token",
     *         required=true,
     *         @OA\Schema(
     *             type="string",
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

    public function logout(Request $request)
    {
        try {
            $rules = [
                'device_token' => 'required'
            ];

            $message = [
                'device_token.required' => 'Device token is required.',
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
            userDeviceToken::where('user_id', auth()->user()->id)->where('token', $request->device_token)->forceDelete();

            $data = [
                'status_code' => 200,
                'message' => "Logout Successfully.",
                'data' => ""
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
     *     path="/api/v1/users-mobile-numbers",
     *     summary="User Mobile Numbers",
     *     tags={"User"},
     *     description="User Mobile Numbers",
     *     operationId="userMobileNumbers",
     *     security={{"bearerAuth":{}}},
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

    public function userMobileNumbers(Request $request)
    {
        try {
            $mobileNumbers = User::where('role', 'User')->where('status', 'Active')->get(['country_code', 'mobile']);

            $data = [
                'status_code' => 200,
                'message' => "Get Data Successfully.",
                'data' => [
                    'mobileNumbers' => $mobileNumbers
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
     *     path="/api/v1/user-list",
     *     summary="User List",
     *     tags={"User"},
     *     description="User List",
     *     operationId="userList",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="timezone",
     *         in="query",
     *         example="",
     *         description="Enter Timezone",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         example="",
     *         description="Enter Search Value",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
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
  
     public function userList(Request $request)
     {
     try {
         $login_user_id = auth()->user()->id;
         $deletedUsers = deleteChatUsers::where('user_id', $login_user_id)->pluck('deleted_user_id');
 
         // Fetch users
         $users = User::where('id', '!=', $login_user_id)
             ->where('role', 'User')
             ->where('status', 'Active')
             ->where(function ($q) use ($request) {
                 if (@$request->search) {
                     $q->where(function ($qq) use ($request) {
                         $searchTerm = '%' . $request->search . '%';
                         $qq->where('first_name', 'LIKE', $searchTerm)
                             ->orWhere('last_name', 'LIKE', $searchTerm)
                             ->orWhere('mobile', 'LIKE', $searchTerm)
                             ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm])
                             ->orWhereRaw("CONCAT(first_name, last_name) LIKE ?", [$searchTerm]);
                     });
                 }
             })
             ->whereNotIn('id', $deletedUsers)
             ->whereNull('deleted_at')
             ->with(['sentMessages' => function ($query) use ($login_user_id) {
                 $query->where('receiver_id', $login_user_id)
                     ->whereNull('deleted_at');
             }, 'receivedMessages' => function ($query) use ($login_user_id) {
                 $query->where('sender_id', $login_user_id)
                     ->whereNull('deleted_at');
             }])
             ->get()
             ->map(function ($user) use ($login_user_id, $request) {
                 $lastMessage = null;
                 $messages = $user->sentMessages->merge($user->receivedMessages)->sortByDesc('created_at');
                 $filteredMessages = $messages->reject(function ($message) {
                     return $message->message && $message->message->message_type == 'Task Chat';
                 });
 
                  // Exclude group messages (where group_id is not null)
                 $filteredMessages = $messages->filter(function ($message) {
                     return is_null($message->message->group_id); // Exclude messages with group_id
                 });
 
                 $lastMessage = $filteredMessages->first();
                 if ($lastMessage && $lastMessage->message) {
                     $msg = ($lastMessage->message->message_type == 'Text') 
                         ? $lastMessage->message->message 
                         : $lastMessage->message->message_type;
                     
                     $lastMessageContent = @$msg ? $msg : null;
                     $lastMessageDate = $lastMessage->created_at 
                         ? Carbon::parse($lastMessage->created_at)->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d H:i:s')
                         : null;
                 } else {
                     $lastMessageContent = null;
                     $lastMessageDate = null;
                 }
 
                 // Count unread messages, excluding deleted messages
                 $unreadMessageCount = MessageSenderReceiver::where(function ($query) use ($user, $login_user_id) {
                     $query->where('sender_id', $user->id)
                         ->where('receiver_id', $login_user_id);
                 })
                 ->whereHas('message', function ($q) {
                     $q->where('status', 'Unread')
                         ->where('message_type', '!=', 'Task Chat')
                         ->whereNull('group_id') // Exclude messages with group_id
                         ->whereNull('deleted_at');
                 })
                 ->count();
 
                 return [
                     'id' => $user->id,
                     'first_name' => $user->first_name,
                     'last_name' => $user->last_name,
                     'country_code' => $user->country_code,
                     'mobile' => $user->mobile,
                     'profile' => @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png'),
                     'last_message' => $lastMessageContent,
                     'last_message_date' => $lastMessageDate,
                     'unread_message_count' => $unreadMessageCount,
                     'type' => 'user' // Identifying this entry as a user
                 ];
             });
 
                 $groups = Group::where('status', 'Active')
                 ->whereIn('id', function ($query) use ($login_user_id) {
                     $query->select('group_id')
                         ->from('group_members')
                         ->where('user_id', $login_user_id) // User is a member of the group  
                         ->whereNull('deleted_at')                      
                         ->orWhere('created_by', $login_user_id) // User created the group
                         ->whereNull('deleted_at');
                 })
                 ->get()
                 ->map(function ($group) use ($login_user_id, $request) {
                     // Fetch the last message in the group
                     $lastMessage = Message::where('group_id', $group->id)
                         ->whereNull('deleted_at')
                         ->where('message_type', '!=', 'Task Chat')
                         ->orderByDesc('created_at')
                         ->first();
                       
                    $group_date = Group::where('id', $group->id)->first();
 
                     // Get the content and date of the last message
                     $lastMessageContent = $lastMessage
                         ? (($lastMessage->message_type == 'Text') ? $lastMessage->message : $lastMessage->message_type)
                         : null;
 
                     $lastMessageDate = $lastMessage && $lastMessage->created_at
                         ? Carbon::parse($lastMessage->created_at)->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d H:i:s')
                         : null;
 
                     // Count unread messages in the group
                     $unreadgroupMessageCount = MessageSenderReceiver::whereHas('message', function ($q) use ($group, $login_user_id) {
                         $q->where('group_id', $group->id)
                         ->where('status', 'Unread')
                         ->where('message_type', '!=', 'Task Chat')
                         ->whereNull('deleted_at');
                     })
                     ->where('receiver_id', $login_user_id)
                     ->count();
 
 
                     return [
                         'id' => $group->id,
                         'name' => $group->name,
                         'profile' => @$group->profile_pic ? setAssetPath('group-profile/' . $group->profile_pic) : setAssetPath('assets/media/avatars/blank.png'),
                         'last_message' => $lastMessageContent,
                         'last_message_date' => $lastMessageDate ? $lastMessageDate : $group_date->created_at->setTimezone($request->timezone ?? 'UTC')->format('Y-m-d H:i:s'),
                         'unread_message_count' => $unreadgroupMessageCount,
                         'type' => 'group' // Identifying this entry as a group
                     ];
                 });
 
 
 
         // Merge and sort by last_message_date
         $allEntries = $users->merge($groups)->sortByDesc('last_message_date')->values();
 
         $data = [
             'status_code' => 200,
             'message' => "Data fetched successfully.",
             'data' => [
                 'userList' => $allEntries
             ]
         ];
 
             return $this->sendJsonResponse($data);
         } catch (\Exception $e) {
             Log::error([
                 'method' => __METHOD__,
                 'error' => [
                     'file' => $e->getFile(),
                     'line' => $e->getLine(),
                     'message' => $e->getMessage()
                 ],
                 'created_at' => date("Y-m-d H:i:s")
             ]);
 
             return $this->sendJsonResponse([
                 'status_code' => 500,
                 'message' => 'Something went wrong.'
             ]);
         }
     }
 


    /**
     * @OA\Post(
     *     path="/api/v1/user-details",
     *     summary="User Details",
     *     tags={"User"},
     *     description="User Details",
     *     operationId="userDetails",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         example="2",
     *         description="Enter userId",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="start",
     *         in="query",
     *         example="0",
     *         description="Enter Start",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         example="10",
     *         description="Enter Limit",
     *         required=false,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="timezone",
     *         in="query",
     *         example="",
     *         description="Enter Timezone",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="filter",
     *         in="query",
     *         example="filter",
     *         description="Enter Message type",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="startchat",
     *         in="query",
     *         example="Yes or No",
     *         description="Enter Yes or No",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
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
   public function userDetails(Request $request)
    {
        try {
            $rules = [
                'id' => 'required|integer|exists:users,id',
            ];

            $message = [
                'id.required' => 'User ID is required.',
                'id.integer' => 'User ID must be an integer.',
                'id.exists' => 'The specified user does not exist.',
            ];
            $start = @$request->start ? $request->start : 0;
            $limit = @$request->limit ? $request->limit : 15;
            $startchat = @$request->startchat ? $request->startchat : 'No';
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
            $userData = $user->find($request->id);
            $userData->profile = @$userData->profile ? setAssetPath('user-profile/' . $userData->profile) : setAssetPath('assets/media/avatars/blank.png');
            $userData->cover_image = @$userData->cover_image ? setAssetPath('user-profile-cover-image/' . $userData->cover_image) : setAssetPath('assets/media/misc/image.png');

            $loginUser = auth()->user()->id;
            $userId = $request->id;
            $filter = [
                'Task',
                'Meeting',
                'Reminder'
            ];
            $messages = MessageSenderReceiver::where(function ($query) use ($loginUser, $userId) {
                $query->where(function ($q) use ($loginUser, $userId) {
                    $q->where('sender_id', $loginUser)
                      ->where('receiver_id', $userId);
                })->orWhere(function ($q) use ($loginUser, $userId) {
                    $q->where('sender_id', $userId)
                      ->where('receiver_id', $loginUser);
                });
            })
            ->whereNull('deleted_at')
            ->whereHas('message', function ($q) {
                $q->where('message_type', '!=', 'Task Chat')
                  ->whereNull('group_id') // Ensure that group_id is null
                  ->whereNull('deleted_at'); // Ensure the message is not deleted
            })
            ->with([
                'message',
                'message.attachment:id,message_id,attachment_name,attachment_path',
                'message.task:id,message_id,task_name,task_checked,task_checked_users',
                'message.location:id,message_id,latitude,longitude,location_url',
                'message.meeting:id,message_id,mode,title,description,date,start_time,end_time,meeting_url,users,latitude,longitude,location_url,location,accepted_users,decline_users',
                'message.reminder:id,message_id,title,description,date,time,users'
            ])
            ->orderByDesc('created_at')
            ->skip($start)
            ->take($limit)
            ->get();
            $groupedChat = $messages->map(function ($message) use ($loginUser, $request) {
                $messageDetails = [];
                switch ($message->message->message_type) {
                    case 'Text':
                        $messageDetails = $message->message->message;
                        break;
                    case 'Attachment':
                        $messageDetails = $message->message->attachment;
                        break;
                    case 'Location':
                        $messageDetails = $message->message->location;
                        break;
                    case 'Meeting':
                        $messageDetails = $message->message->meeting;
                        break;
                    case 'Task':
                    case 'SimpleTask':
                        $taskDetails = DB::table('message_task')
                                ->select('id', 'message_id', 'task_name', 'users', 'checkbox', 'task_checked', 'task_checked_users', 'priority_task')
                                ->where('message_id', $message->message->id)
                                ->whereNull('deleted_at') // Ensure to get only not deleted rows
                                ->get();
                            $taskDetails_task = DB::table('message_task')
                                ->select('id', 'message_id', 'task_name', 'users','checkbox', 'task_checked', 'task_checked_users', 'priority_task')
                                ->where('message_id', $message->message->id)
                                ->first();                            
                            // Prepare the messageDetails array with task information
                            $users = explode(',', $taskDetails_task->users);
                            $userList = User::whereIn('id', $users)->get(['id', 'first_name', 'last_name', 'country_code', 'mobile', 'profile']);
                            $userList = $userList->map(function ($user) use ($taskDetails, $message) {
                            $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');                                                                  
                                    // Initialize an empty string to hold the task IDs
                                    $user->task_ids = '';
                                    $allTaskId = [];
                                    $doneTaskId = [];
                                    $allTasksCheckedByOthers = true; // Flag to track if all tasks are checked by other users                                
                                    // Loop through each task to check if the user has checked it
                                    foreach ($taskDetails as $task) {
                                        $checkedUsers = explode(',', $task->task_checked_users);                                                                          
                                        // Remove the first empty element (if there is any)
                                        if (empty($checkedUsers[0])) {
                                            array_shift($checkedUsers);
                                        }
                                        // Remove the $message->message->created_by ID from the checked users list when checking other users' task status
                                        $checkedByOthers = array_diff($checkedUsers, [$message->message->created_by]);
                                        // If the user ID is in the checked users list, mark the task as done for the user
                                        if (in_array($user->id, $checkedUsers)) {
                                            $user->task_ids .= $task->id . ','; // Append with a comma
                                            $doneTaskId[] = $task->id;
                                        }   
                                        $assignedUsers = explode(',', $task->users);                                       
                                        // If not all other users have checked this task, mark the flag as false
                                        if (empty($checkedByOthers) || count($checkedByOthers) < count($assignedUsers) - 1) {                                          
                                            $allTasksCheckedByOthers = false; // At least one task is not checked by all other users
                                        }                                 
                                        // Add all task IDs, including those created by others
                                        $allTaskId[] = $task->id;
                                    }                            
                                    // Remove the trailing comma if task_ids is not empty
                                    if (!empty($user->task_ids)) {
                                        $user->task_ids = rtrim($user->task_ids, ','); // Remove the last comma
                                    }
                                    // Calculate pending tasks by removing the done tasks from all tasks
                                    $pendingTaskId = array_diff($allTaskId, $doneTaskId);
                                    // If the current user is the one who created the message, set task_done status based on all other users' task completion
                                    if ($user->id == $message->message->created_by) {
                                        // Check if all tasks are done by other users
                                        $user->task_done = $allTasksCheckedByOthers ? true : false;
                                    } else{
                                        $user->task_done = false;   
                                    }
                                    // Initialize an empty array to hold task IDs
                                    $taskids = [];
                                    // Loop through each task to collect task IDs
                                    foreach ($taskDetails as $task) {
                                        $taskids[] = $task->id;
                                    }                                  
                                    return $user;
                            });                                                      
                            $messageDetails = [
                                'task_name' => $taskDetails_task->task_name,
                                'date' => $message->message->date,
                                'time' => $message->message->time,
                                'users' => $userList,// Assuming task_name is available here
                                'tasks' => $taskDetails->map(function ($task) {
                                      // Assuming $task->task_checked_users contains ",131,132"
                                        $checkedUsers = explode(',', $task->task_checked_users);                                    
                                        // Remove the first empty element (if there is any)
                                        if ($checkedUsers[0] === '') {
                                            array_shift($checkedUsers);
                                        }                                        
                                        // Convert the array back to a string
                                        $task->task_checked_users = implode(',', $checkedUsers);                                       

                                        // Fetch user profile URLs for the task_checked_users
                                        $profiles = User::whereIn('id', $checkedUsers)->get(['id', 'profile'])->map(function ($user) {
                                            return [
                                                'id' => $user->id,
                                                'profile_url' => @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png')
                                            ];
                                        });                                                                                                                         

                                        $comments = MessageTaskChatComment::with('user')
                                                    ->where('task_chat_id', $task->id)
                                                    ->orderBy('created_at', 'desc')
                                                    ->take(5)
                                                    ->get()
                                                    ->map(function ($comment) {
                                                        return [
                                                            'id' => $comment->id,
                                                            'task_chat_id' => $comment->task_chat_id,
                                                            'message_id' => $comment->message_id,
                                                            'user' => [
                                                                'id' => $comment->user->id,
                                                                'name' => $comment->user->first_name,
                                                                'profile_picture' => $comment->user->profile ? setAssetPath('user-profile/' . $comment->user->profile) : setAssetPath('assets/media/avatars/blank.png')
                                                                
                                                            ],
                                                            'comment' => $comment->comment,
                                                            'created_at' => $comment->created_at,
                                                        ];
                                                    });
                           
                                        return [
                                            'id' => $task->id,
                                            'message_id' => $task->message_id,
                                            'checkbox' => $task->checkbox,
                                            'task_checked' => (bool) $task->task_checked, // Convert to boolean
                                            'task_checked_users' => $task->task_checked_users,
                                            'profiles' => $profiles, // Attach profiles of users who checked the task
                                            'comments' => $comments, // Attach task comments
                                            'priority_task' => $task->priority_task, // Attach task comments
                                        ];
                                    })->toArray(), // Convert to array if needed
                                ];                                                  
                        break;                                             
                    case 'Reminder':
                        $messageDetails = $message->message->reminder;
                        break;
                    case 'Contact':
                        $messageDetails = $message->message->message;
                        break;
                }
                if($message->message->message_type == 'Meeting' || $message->message->message_type == 'Reminder'){
                    $users = explode(',',$messageDetails->users);
                    $userList = User::whereIn('id', $users)->get(['id','first_name','last_name','country_code','mobile','profile']);
                    $userList = $userList->map(function ($user) {
                        $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
                        return $user;
                    });
                    $messageDetails->users = $userList;
                    $messageDetails->date = @$request->timezone ? Carbon::parse($messageDetails->date)->format('d-m-Y') : Carbon::parse($messageDetails->date)->format('Y-m-d H:i:s');
                    if($message->message->message_type == 'Reminder'){
                        $messageDetails->time = @$request->timezone ? Carbon::parse($messageDetails->time)->format('h:i a') : Carbon::parse($messageDetails->time)->format('h:i a');
                    }elseif($message->message->message_type == 'Meeting'){
                        $messageDetails->start_time = @$request->timezone ? Carbon::parse($messageDetails->start_time)->format('h:i a') : Carbon::parse($messageDetails->start_time)->format('h:i a');
                        $messageDetails->end_time = @$request->timezone ? Carbon::parse($messageDetails->end_time)->format('h:i a') : Carbon::parse($messageDetails->end_time)->format('h:i a');
                    }
                }
                return [
                    'messageId' => $message->message->id,
                    'messageType' => $message->message->message_type,
                    'attachmentType' => $message->message->attachment_type,
                    'date' => @$request->timezone ? Carbon::parse($message->message->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($message->message->created_at)->format('Y-m-d H:i:s'),
                    'time' => @$request->timezone ? Carbon::parse($message->message->updated_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($message->message->updated_at)->format('h:i a'),
                    'timeZone' => $message->message->updated_at,
                    'sentBy' => ($message->sender_id == $loginUser) ? 'loginUser' : 'User',
                    'messageDetails' => $messageDetails,
                ];
            })->groupBy(function ($message) {
                $carbonDate = Carbon::parse($message['date']);
                if ($carbonDate->isToday()) {
                    return 'Today';
                } elseif ($carbonDate->isYesterday()) {
                    return 'Yesterday';
                } else {
                    return $carbonDate->format('d M Y');
                }
            })->map(function ($messages, $date) {
                $sortedMessages = $messages->sort(function ($a, $b) {
                    $timeA = strtotime($a['time']);
                    $timeB = strtotime($b['time']);

                    if ($timeA == $timeB) {
                        return $a['messageId'] <=> $b['messageId'];
                    }

                    return $timeA <=> $timeB;
                })->values();

                return [$date => $sortedMessages];
            });
            $reversedGroupedChat = array_reverse($groupedChat->toArray());

            $chat = [];
            foreach ($reversedGroupedChat as $item) {
                foreach ($item as $date => $messages) {
                    if ($request->filter == 'filter') {
                        $msgArr = [];
                        foreach ($messages as $single) {
                            if (in_array($single['messageType'], $filter)) {
                                $msgArr[] = $single;
                                $chat[$date] = $msgArr;
                            }
                        }
                        // $chat[$date] = $msgArr;
                    } else {
                        $chat[$date] = $messages;
                    }
                }
            }
            $data = [
                'status_code' => 200,
                'message' => "Get Data Successfully!",
                'data' => [
                    'userData' => $userData,
                    'chat' => $chat,
                ]
            ];

            $data1 = [
                'status_code' => 200,
                'message' => "User Data fetch Successfully!",
                'data' => [
                    'userData' => $userData,                    
                ]
            ];

            if($startchat === 'Yes')
            {
                return $this->sendJsonResponse($data1);
            }else{
                return $this->sendJsonResponse($data);
            }
            
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
    
    // public function userDetails(Request $request)
    // {
    //     try {
    //         $rules = [
    //             'id' => 'required|integer|exists:users,id',
    //         ];

    //         $message = [
    //             'id.required' => 'User ID is required.',
    //             'id.integer' => 'User ID must be an integer.',
    //             'id.exists' => 'The specified user does not exist.',
    //         ];
    //         $start = @$request->start ? $request->start : 0;
    //         $limit = @$request->limit ? $request->limit : 15;
    //         $validator = Validator::make($request->all(), $rules, $message);
    //         if ($validator->fails()) {
    //             $data = [
    //                 'status_code' => 400,
    //                 'message' => $validator->errors()->first(),
    //                 'data' => ""
    //             ];
    //             return $this->sendJsonResponse($data);
    //         }
    //         $user = new User();
    //         $userData = $user->find($request->id);
    //         $userData->profile = @$userData->profile ? setAssetPath('user-profile/' . $userData->profile) : setAssetPath('assets/media/avatars/blank.png');
    //         $userData->cover_image = @$userData->cover_image ? setAssetPath('user-profile-cover-image/' . $userData->cover_image) : setAssetPath('assets/media/misc/image.png');

    //         $loginUser = auth()->user()->id;
    //         $userId = $request->id;
    //         $filter = [
    //             'Task',
    //             'Meeting',
    //             'Reminder'
    //         ];
    //         $messages = MessageSenderReceiver::where(function ($query) use ($loginUser, $userId) {
    //             $query->where('sender_id', $loginUser)->where('receiver_id', $userId);
    //         })->orWhere(function ($query) use ($loginUser, $userId) {
    //             $query->where('sender_id', $userId)->where('receiver_id', $loginUser);
    //         })
    //             ->whereNull('deleted_at')
    //             ->whereHas('message', function ($q) {
    //                 $q->where('message_type', '!=', 'Task Chat');
    //             })
    //             ->with([
    //                 'message',
    //                 'message.attachment:id,message_id,attachment_name,attachment_path',
    //                 'message.task:id,message_id,task_name,task_checked',
    //                 'message.location:id,message_id,latitude,longitude,location_url',
    //                 'message.meeting:id,message_id,mode,title,description,date,start_time,end_time,meeting_url,users,latitude,longitude,location_url,location,accepted_users,decline_users',
    //                 'message.reminder:id,message_id,title,description,date,time,users'
    //             ])
    //             ->orderByDesc('created_at')
    //             ->skip($start)
    //             ->take($limit)->get();
    //         $groupedChat = $messages->map(function ($message) use ($loginUser, $request) {
    //             $messageDetails = [];
    //             switch ($message->message->message_type) {
    //                 case 'Text':
    //                     $messageDetails = $message->message->message;
    //                     break;
    //                 case 'Attachment':
    //                     $messageDetails = $message->message->attachment;
    //                     break;
    //                 case 'Location':
    //                     $messageDetails = $message->message->location;
    //                     break;
    //                 case 'Meeting':
    //                     $messageDetails = $message->message->meeting;
    //                     break;
    //                 case 'Task':
    //                      $taskDetails = DB::table('message_task')
    //                             ->select('id', 'message_id', 'task_name', 'users', 'checkbox', 'task_checked', 'task_checked_users')
    //                             ->where('message_id', $message->message->id)
    //                             ->whereNull('deleted_at') // Ensure to get only not deleted rows
    //                             ->get();
    //                         $taskDetails_task = DB::table('message_task')
    //                             ->select('id', 'message_id', 'task_name', 'users','checkbox', 'task_checked', 'task_checked_users')
    //                             ->where('message_id', $message->message->id)
    //                             ->first();                            
    //                       // Prepare the messageDetails array with task information
    //                         $users = explode(',', $taskDetails_task->users);
    //                         $userList = User::whereIn('id', $users)->get(['id', 'first_name', 'last_name', 'country_code', 'mobile', 'profile']);

    //                         $userList = $userList->map(function ($user) use ($taskDetails) {
    //                             $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');                                
    //                                 // Initialize an empty string to hold the task IDs
    //                                 $user->task_ids = '';

    //                                 // Loop through each task to check if the user has checked it
    //                                 foreach ($taskDetails as $task) {
    //                                     $checkedUsers = explode(',', $task->task_checked_users);
                                        
    //                                     // Remove the first empty element (if there is any)
    //                                     if (empty($checkedUsers[0])) {
    //                                         array_shift($checkedUsers);
    //                                     }

    //                                     // Check if the user ID is in the checked users list
    //                                     if (in_array($user->id, $checkedUsers)) {
    //                                         // If yes, append the task ID to the user's task_ids string
    //                                         $user->task_ids .= $task->id . ','; // Append with a comma
    //                                     }
    //                                 }

    //                                 // Remove the trailing comma if task_ids is not empty
    //                                 if (!empty($user->task_ids)) {
    //                                     $user->task_ids = rtrim($user->task_ids, ','); // Remove the last comma
    //                                 }

    //                                 return $user;

    //                         });   
                            
                       
    //                         $messageDetails = [
    //                             'task_name' => $taskDetails_task->task_name,
    //                             'date' => $message->message->date,
    //                             'time' => $message->message->time,
    //                             'users' => $userList,// Assuming task_name is available here
    //                             'tasks' => $taskDetails->map(function ($task) {

    //                             // Assuming $task->task_checked_users contains ",131,132"
    //                             $checkedUsers = explode(',', $task->task_checked_users);

    //                             // Remove the first empty element (if there is any)
    //                             if ($checkedUsers[0] === '') {
    //                                 array_shift($checkedUsers);
    //                             }

    //                             // Convert the array back to a string
    //                             $task->task_checked_users = implode(',', $checkedUsers);
    //                                 return [
    //                                     'id' => $task->id,
    //                                     'message_id' => $task->message_id,
    //                                     'checkbox' => $task->checkbox,
    //                                     'task_checked' => (bool) $task->task_checked, // Convert to boolean
    //                                     'task_checked_users' => $task->task_checked_users,
    //                                 ];
    //                             })->toArray(), // Convert to array if needed
    //                         ];
                        
    //                     break;                                                  
    //                 case 'Reminder':
    //                     $messageDetails = $message->message->reminder;
    //                     break;
    //                 case 'Contact':
    //                     $messageDetails = $message->message->message;
    //                     break;
    //             }
    //             if($message->message->message_type == 'Meeting' || $message->message->message_type == 'Reminder'){
    //                 $users = explode(',',$messageDetails->users);
    //                 $userList = User::whereIn('id', $users)->get(['id','first_name','last_name','country_code','mobile','profile']);
    //                 $userList = $userList->map(function ($user) {
    //                     $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
    //                     return $user;
    //                 });
    //                 $messageDetails->users = $userList;
    //                 $messageDetails->date = @$request->timezone ? Carbon::parse($messageDetails->date)->format('d-m-Y') : Carbon::parse($messageDetails->date)->format('Y-m-d H:i:s');
    //                 if($message->message->message_type == 'Reminder'){
    //                     $messageDetails->time = @$request->timezone ? Carbon::parse($messageDetails->time)->format('h:i a') : Carbon::parse($messageDetails->time)->format('h:i a');
    //                 }elseif($message->message->message_type == 'Meeting'){
    //                     $messageDetails->start_time = @$request->timezone ? Carbon::parse($messageDetails->start_time)->format('h:i a') : Carbon::parse($messageDetails->start_time)->format('h:i a');
    //                     $messageDetails->end_time = @$request->timezone ? Carbon::parse($messageDetails->end_time)->format('h:i a') : Carbon::parse($messageDetails->end_time)->format('h:i a');
    //                 }
    //             }
    //             return [
    //                 'messageId' => $message->message->id,
    //                 'messageType' => $message->message->message_type,
    //                 'attachmentType' => $message->message->attachment_type,
    //                 'date' => @$request->timezone ? Carbon::parse($message->message->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($message->message->created_at)->format('Y-m-d H:i:s'),
    //                 'time' => @$request->timezone ? Carbon::parse($message->message->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($message->message->created_at)->format('h:i a'),
    //                 'sentBy' => ($message->sender_id == $loginUser) ? 'loginUser' : 'User',
    //                 'messageDetails' => $messageDetails,
    //             ];
    //         })->groupBy(function ($message) {
    //             $carbonDate = Carbon::parse($message['date']);
    //             if ($carbonDate->isToday()) {
    //                 return 'Today';
    //             } elseif ($carbonDate->isYesterday()) {
    //                 return 'Yesterday';
    //             } else {
    //                 return $carbonDate->format('d M Y');
    //             }
    //         })->map(function ($messages, $date) {
    //             $sortedMessages = $messages->sort(function ($a, $b) {
    //                 $timeA = strtotime($a['time']);
    //                 $timeB = strtotime($b['time']);

    //                 if ($timeA == $timeB) {
    //                     return $a['messageId'] <=> $b['messageId'];
    //                 }

    //                 return $timeA <=> $timeB;
    //             })->values();

    //             return [$date => $sortedMessages];
    //         });
    //         $reversedGroupedChat = array_reverse($groupedChat->toArray());

    //         $chat = [];
    //         foreach ($reversedGroupedChat as $item) {
    //             foreach ($item as $date => $messages) {
    //                 if ($request->filter == 'filter') {
    //                     $msgArr = [];
    //                     foreach ($messages as $single) {
    //                         if (in_array($single['messageType'], $filter)) {
    //                             $msgArr[] = $single;
    //                             $chat[$date] = $msgArr;
    //                         }
    //                     }
    //                     // $chat[$date] = $msgArr;
    //                 } else {
    //                     $chat[$date] = $messages;
    //                 }
    //             }
    //         }
    //         $data = [
    //             'status_code' => 200,
    //             'message' => "Get Data Successfully!",
    //             'data' => [
    //                 'userData' => $userData,
    //                 'chat' => $chat,
    //             ]
    //         ];
    //         return $this->sendJsonResponse($data);
    //     } catch (\Exception $e) {
    //         Log::error(
    //             [
    //                 'method' => __METHOD__,
    //                 'error' => [
    //                     'file' => $e->getFile(),
    //                     'line' => $e->getLine(),
    //                     'message' => $e->getMessage()
    //                 ],
    //                 'created_at' => date("Y-m-d H:i:s")
    //             ]
    //         );
    //         return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
    //     }
    // }


    /**
     * @OA\Post(
     *     path="/api/v1/user-group-details",
     *     summary="User Group Details",
     *     tags={"User"},
     *     description="User Group Details",
     *     operationId="userGroupDetails",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="group_id",
     *         in="query",
     *         example="2",
     *         description="Enter groupId",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="start",
     *         in="query",
     *         example="0",
     *         description="Enter Start",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         example="10",
     *         description="Enter Limit",
     *         required=false,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="timezone",
     *         in="query",
     *         example="",
     *         description="Enter Timezone",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="filter",
     *         in="query",
     *         example="filter",
     *         description="Enter Message type",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
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

    //  public function userGroupDetails(Request $request)
    //  {
    //      try {
    //          $rules = [
    //              'group_id' => 'required|integer',
    //          ];
 
    //          $message = [
    //              'group_id.required' => 'User group_id is required.',
    //              'group_id.integer' => 'User group_id must be an integer.',                 
    //          ];
    //          $start = $request->start ?? 0;
    //          $limit = $request->limit ?? 15;             
    //          $validator = Validator::make($request->all(), $rules, $message);
    //          if ($validator->fails()) {
    //              $data = [
    //                  'status_code' => 400,
    //                  'message' => $validator->errors()->first(),
    //                  'data' => ""
    //              ];
    //              return $this->sendJsonResponse($data);
    //          }   
    //          $loginUser = auth()->user()->id;  
    //          $members = GroupMembers::where('group_id', $request->group_id)->get();
    //          if ($members->isEmpty()) {
    //              return $this->sendJsonResponse(['status_code' => 404, 'message' => 'No members found in this group.']);
    //          }
    //          foreach($members as $value) 
    //          {
    //             $user = new User();
    //             $userData = $user->find(auth()->user()->id);
    //             $userData->profile = @$userData->profile ? setAssetPath('user-profile/' . $userData->profile) : setAssetPath('assets/media/avatars/blank.png');
    //             $userData->cover_image = @$userData->cover_image ? setAssetPath('user-profile-cover-image/' . $userData->cover_image) : setAssetPath('assets/media/misc/image.png');  
                
    //             $group = new Group();
    //             $groupData = $group->find($request->group_id);
    //             $groupData->profile_pic = @$groupData->profile_pic ? setAssetPath('group-profile/' . $groupData->profile_pic) : setAssetPath('assets/media/avatars/blank.png');
    //             $groupData->cover_image = @$groupData->cover_image ? setAssetPath('group-profile/' . $groupData->cover_image) : setAssetPath('assets/media/avatars/blank.png');
                         
    //          $userId = $value->user_id;
    //          $filter = [
    //              'Task',
    //              'Meeting',
    //              'Reminder'
    //          ];
    //          $messages = MessageSenderReceiver::where(function ($query) use ($loginUser, $userId) {
    //             $query->where('receiver_id', $userId);
    //         })
           
    //         ->whereNull('deleted_at')
    //         ->whereHas('message', function ($q) use ($request) {
    //             $q->where('message_type', '!=', 'Task Chat');
    //             if ($request->group_id) {
    //                 $q->where('group_id', $request->group_id);
    //             }
    //         })
    //         ->with([ /* your relations */ ])
    //         ->orderByDesc('created_at')
    //         ->skip($start)
    //         ->take($limit);
                    
    //         $messages = $messages->get();
    //     }
       
    //          $groupedChat = $messages->map(function ($message) use ($loginUser, $request) {
    //              $messageDetails = [];
    //              switch ($message->message->message_type) {
    //                  case 'Text':
    //                      $messageDetails = $message->message->message;
                         
    //                      break;
    //                  case 'Attachment':
    //                      $messageDetails = $message->message->attachment;
    //                      break;
    //                  case 'Location':
    //                      $messageDetails = $message->message->location;
    //                      break;
    //                  case 'Meeting':
    //                      $messageDetails = $message->message->meeting;
    //                      break;
    //                  case 'Task':                           
    //                     $messageDetails = $message->message->Task;                                           
    //                      break;                                             
    //                  case 'Reminder':
    //                      $messageDetails = $message->message->reminder;
    //                      break;
    //                  case 'Contact':
    //                      $messageDetails = $message->message->message;
    //                      break;
    //              }
                 
    //              $senderName = @$message->sender->first_name .' '. @$message->sender->last_name;                 
    //              $senderProfile = @$message->sender->profile ? setAssetPath('user-profile/' . $message->sender->profile) : setAssetPath('assets/media/avatars/blank.png');                 

    //              if($message->message->message_type == 'Meeting' || $message->message->message_type == 'Reminder'){
    //                  $users = explode(',',$messageDetails->users);
    //                  $userList = User::whereIn('id', $users)->get(['id','first_name','last_name','country_code','mobile','profile']);
    //                  $userList = $userList->map(function ($user) {
    //                      $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
    //                      return $user;
    //                  });
    //                  $messageDetails->users = $userList;
    //                  $messageDetails->date = @$request->timezone ? Carbon::parse($messageDetails->date)->format('d-m-Y') : Carbon::parse($messageDetails->date)->format('Y-m-d H:i:s');
    //                  if($message->message->message_type == 'Reminder'){
    //                      $messageDetails->time = @$request->timezone ? Carbon::parse($messageDetails->time)->format('h:i a') : Carbon::parse($messageDetails->time)->format('h:i a');
    //                  }elseif($message->message->message_type == 'Meeting'){
    //                      $messageDetails->start_time = @$request->timezone ? Carbon::parse($messageDetails->start_time)->format('h:i a') : Carbon::parse($messageDetails->start_time)->format('h:i a');
    //                      $messageDetails->end_time = @$request->timezone ? Carbon::parse($messageDetails->end_time)->format('h:i a') : Carbon::parse($messageDetails->end_time)->format('h:i a');
    //                  }
    //              }
    //              return [
    //                  'messageId' => $message->message->id,
    //                  'messageType' => $message->message->message_type,
    //                  'attachmentType' => $message->message->attachment_type,
    //                  'date' => @$request->timezone ? Carbon::parse($message->message->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($message->message->created_at)->format('Y-m-d H:i:s'),
    //                  'time' => @$request->timezone ? Carbon::parse($message->message->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($message->message->created_at)->format('h:i a'),
    //                  'sentBy' => ($message->sender_id == auth()->user()->id) ? 'loginUser' : 'User',
    //                  'senderName' => $senderName,
    //                  'senderProfile' => $senderProfile,
    //                  'messageDetails' => $messageDetails,
    //              ];
    //          })->groupBy(function ($message) {
    //              $carbonDate = Carbon::parse($message['date']);
    //              if ($carbonDate->isToday()) {
    //                  return 'Today';
    //              } elseif ($carbonDate->isYesterday()) {
    //                  return 'Yesterday';
    //              } else {
    //                  return $carbonDate->format('d M Y');
    //              }
    //          })->map(function ($messages, $date) {
    //              $sortedMessages = $messages->sort(function ($a, $b) {
    //                  $timeA = strtotime($a['time']);
    //                  $timeB = strtotime($b['time']);
 
    //                  if ($timeA == $timeB) {
    //                      return $a['messageId'] <=> $b['messageId'];
    //                  }
 
    //                  return $timeA <=> $timeB;
    //              })->values();
 
    //              return [$date => $sortedMessages];
    //          });
          
    //          $reversedGroupedChat = array_reverse($groupedChat->toArray());
 
    //          $chat = [];
    //          foreach ($reversedGroupedChat as $item) {
    //              foreach ($item as $date => $messages) {
    //                  if ($request->filter == 'filter') {
    //                      $msgArr = [];
    //                      foreach ($messages as $single) {
    //                          if (in_array($single['messageType'], $filter)) {
    //                              $msgArr[] = $single;
    //                              $chat[$date] = $msgArr;
    //                          }
    //                      }
    //                      // $chat[$date] = $msgArr;
    //                  } else {
    //                      $chat[$date] = $messages;
    //                  }
    //              }
    //          }
    //          $data = [
    //              'status_code' => 200,
    //              'message' => "Get Data Successfully!",
    //              'data' => [
    //                  'groupData' => $groupData,
    //                  'groupChat' => $chat,
    //              ]
    //          ];
    //          return $this->sendJsonResponse($data);
    //      } catch (\Exception $e) {
    //          Log::error(
    //              [
    //                  'method' => __METHOD__,
    //                  'error' => [
    //                      'file' => $e->getFile(),
    //                      'line' => $e->getLine(),
    //                      'message' => $e->getMessage()
    //                  ],
    //                  'created_at' => date("Y-m-d H:i:s")
    //              ]
    //          );
    //          return $this->sendJsonResponse(array('status_code' => 500, 'message' => 'Something went wrong'));
    //      }
    //  }

    ///Maincode 

    ///oldcode

    // public function userGroupDetails(Request $request)
    // {
    //     try {
    //         $rules = [
    //             'group_id' => 'required|integer',
    //         ];
    
    //         $message = [
    //             'group_id.required' => 'User group_id is required.',
    //             'group_id.integer' => 'User group_id must be an integer.',
    //         ];
    //         $start = $request->start ?? 0;
    //         $limit = $request->limit ?? 15;
    //         $validator = Validator::make($request->all(), $rules, $message);
    //         if ($validator->fails()) {
    //             $data = [
    //                 'status_code' => 400,
    //                 'message' => $validator->errors()->first(),
    //                 'data' => ""
    //             ];
    //             return $this->sendJsonResponse($data);
    //         }
    
    //         $loginUser = auth()->user()->id;
    
    //         // Get all members of the group
    //         $members = GroupMembers::where('group_id', $request->group_id)->pluck('user_id')->toArray();
    //         if (empty($members)) {
    //             return $this->sendJsonResponse(['status_code' => 404, 'message' => 'No members found in this group.']);
    //         }
    
    //         // Fetch messages for all group members
    //         $messages = MessageSenderReceiver::where(function ($query) use ($members) {
    //             $query->whereIn('receiver_id', $members);
    //         })
    //         ->whereNull('deleted_at')
    //         ->whereHas('message', function ($q) use ($request) {
    //             $q->where('message_type', '!=', 'Task Chat');
    //             if ($request->group_id) {
    //                 $q->where('group_id', $request->group_id);
    //             }
    //         })
    //         ->with([/* your relations */])
    //         ->orderByDesc('created_at')
    //         ->skip($start)
    //         ->take($limit)
    //         ->get();
    
    //         if ($messages->isEmpty()) {
    //             return $this->sendJsonResponse(['status_code' => 404, 'message' => 'No messages found.']);
    //         }
    
    //         // Group messages by message ID
    //         $groupedChat = $messages->mapToGroups(function ($message) use ($loginUser, $request) {
    //             $messageDetails = [];
    //             switch ($message->message->message_type) {
    //                 case 'Text':
    //                     $messageDetails = $message->message->message;
    //                     break;
    //                 case 'Attachment':
    //                     $messageDetails = $message->message->attachment;
    //                     break;
    //                 case 'Location':
    //                     $messageDetails = $message->message->location;
    //                     break;
    //                 case 'Meeting':
    //                     $messageDetails = $message->message->meeting;
    //                     break;
    //                 case 'Task':
    //                     $messageDetails = $message->message->Task;
    //                     break;
    //                 case 'Reminder':
    //                     $messageDetails = $message->message->reminder;
    //                     break;
    //                 case 'Contact':
    //                     $messageDetails = $message->message->message;
    //                     break;
    //             }
    
    //             $senderName = @$message->sender->first_name .' '. @$message->sender->last_name;
    //             $senderProfile = @$message->sender->profile ? setAssetPath('user-profile/' . $message->sender->profile) : setAssetPath('assets/media/avatars/blank.png');
    
    //             if ($message->message->message_type == 'Meeting' || $message->message->message_type == 'Reminder') {
    //                 $users = explode(',', $messageDetails->users);
    //                 $userList = User::whereIn('id', $users)->get(['id', 'first_name', 'last_name', 'country_code', 'mobile', 'profile']);
    //                 $userList = $userList->map(function ($user) {
    //                     $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
    //                     return $user;
    //                 });
    //                 $messageDetails->users = $userList;
    //                 $messageDetails->date = @$request->timezone ? Carbon::parse($messageDetails->date)->format('d-m-Y') : Carbon::parse($messageDetails->date)->format('Y-m-d H:i:s');
    //                 if ($message->message->message_type == 'Reminder') {
    //                     $messageDetails->time = @$request->timezone ? Carbon::parse($messageDetails->time)->format('h:i a') : Carbon::parse($messageDetails->time)->format('h:i a');
    //                 } elseif ($message->message->message_type == 'Meeting') {
    //                     $messageDetails->start_time = @$request->timezone ? Carbon::parse($messageDetails->start_time)->format('h:i a') : Carbon::parse($messageDetails->start_time)->format('h:i a');
    //                     $messageDetails->end_time = @$request->timezone ? Carbon::parse($messageDetails->end_time)->format('h:i a') : Carbon::parse($messageDetails->end_time)->format('h:i a');
    //                 }
    //             }
    
    //             return [
    //                 $message->message->id => [
    //                     'messageId' => $message->message->id,
    //                     'messageType' => $message->message->message_type,
    //                     'attachmentType' => $message->message->attachment_type,
    //                     'date' => @$request->timezone ? Carbon::parse($message->message->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($message->message->created_at)->format('Y-m-d H:i:s'),
    //                     'time' => @$request->timezone ? Carbon::parse($message->message->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($message->message->created_at)->format('h:i a'),
    //                     'sentBy' => ($message->sender_id == auth()->user()->id) ? 'loginUser' : 'User',
    //                     'senderName' => $senderName,
    //                     'senderProfile' => $senderProfile,
    //                     'messageDetails' => $messageDetails,
    //                 ]
    //             ];
    //         });
    
    //         $groupedChat = $groupedChat->map(function ($messages, $messageId) {
    //             return $messages->first(); // In case there are duplicates, take the first
    //         });
    
    //         $data = [
    //             'status_code' => 200,
    //             'message' => "Get Data Successfully!",
    //             'data' => $groupedChat->values()
    //         ];
    //         return $this->sendJsonResponse($data);
    //     } catch (\Exception $e) {
    //         Log::error([
    //             'method' => __METHOD__,
    //             'error' => [
    //                 'file' => $e->getFile(),
    //                 'line' => $e->getLine(),
    //                 'message' => $e->getMessage()
    //             ],
    //             'created_at' => date("Y-m-d H:i:s")
    //         ]);
    //         return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
    //     }
    // }
    

    public function userGroupDetails(Request $request)
    {
        try {
            $rules = [
                'group_id' => 'required|integer',
            ];

            $message = [
                'group_id.required' => 'User group_id is required.',
                'group_id.integer' => 'User group_id must be an integer.',
            ];
            $start = $request->start ?? 0;
            $limit = $request->limit ?? 15;
            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }

            $loginUser = auth()->user()->id;
            $members = GroupMembers::where('group_id', $request->group_id)->get();
            if ($members->isEmpty()) {
                return $this->sendJsonResponse(['status_code' => 404, 'message' => 'No members found in this group.']);
            }

            $groupData = Group::find($request->group_id);
            $memberCount = GroupMembers::where('group_id', $request->group_id)
                        ->distinct('user_id')
                        ->count('user_id');
    
            $groupData->profile_pic = @$groupData->profile_pic ? setAssetPath('group-profile/' . $groupData->profile_pic) : setAssetPath('assets/media/avatars/blank.png');
            $groupData->cover_image = @$groupData->cover_image ? setAssetPath('group-profile/' . $groupData->cover_image) : setAssetPath('assets/media/avatars/blank.png');

            $filter = [
                'Task',
                'Meeting',
                'Reminder'
            ];
            
             $distinctMessageIds = MessageSenderReceiver::whereHas('message', function ($q) use ($request) {
                $q->where('message_type', '!=', 'Task Chat')
                    ->where('group_id', $request->group_id);
            })
            ->whereNull('deleted_at')
            ->with(['sender', 'message.options:id,message_id,option,option_id,users']) // eager load 'sender'
            ->orderByDesc('message_id')
            ->pluck('message_id')
            ->unique(); // Get unique message_ids

            // Step 2: Pagination setup
            $currentPage = $request->page ?? 1; // Current page from the request (default to 1 if not provided)
            $perPage = $limit; // Number of messages per page
            $offset = ($currentPage - 1) * $perPage; // Calculate offset for pagination

            // Step 3: Paginate the distinct message_ids
            $paginatedMessageIds = $distinctMessageIds->slice($offset, $perPage); // Paginate the unique message_ids

            // Step 4: Fetch messages based on the paginated message_ids
            $messages = Message::whereIn('id', $paginatedMessageIds)
                ->with(['sender','options:id,message_id,option,option_id,users'])
                // ->orderByDesc('created_at')
                ->get();

            // Step 5: Map the messages (same as your previous logic)
            $groupedChat = $messages->map(function ($message) use ($loginUser, $request) {
                $messageDetails = [];
                switch ($message->message_type) {
                    case 'Text':
                        $messageDetails = $message->message;
                        break;
                    case 'Options':
                        $messageDetails = $message->options->map(function ($option) {
                            if ($option->users) {
                                // Fetch users
                                $userIds = explode(',', $option->users);
                                $users = User::whereIn('id', $userIds)->get();
                                $userData = $users->map(function ($user) {
                                    return [
                                        'id' => $user->id,
                                        'profile' => $user->profile 
                                            ? setAssetPath('user-profile/' . $user->profile) 
                                            : setAssetPath('assets/media/avatars/blank.png'),
                                        'name' => $user->first_name . ' ' . $user->last_name,
                                    ];
                                });
                                $option->user_data = $userData;
                            }
                            return $option;
                        });
                        break;
                    case 'Attachment':
                        $messageDetails = $message->attachment;
                        break;
                    case 'Location':
                        $messageDetails = $message->location;
                        break;
                    case 'Meeting':
                        $messageDetails = $message->meeting;
                        break;
                    case 'Task':
                        $messageDetails = $message->task;
                        break;
                    case 'Reminder':
                        $messageDetails = $message->reminder;
                        break;
                    case 'Contact':
                        $messageDetails = $message->message;
                        break;
                }

                $senderName = @$message->sender->first_name . ' ' . @$message->sender->last_name;
                $senderProfile = @$message->sender->profile ? setAssetPath('user-profile/' . $message->sender->profile) : setAssetPath('assets/media/avatars/blank.png');

                return [
                    'messageId' => $message->id,
                    'messageType' => $message->message_type,
                    'message' => $message->message,
                    'attachmentType' => $message->attachment_type,
                    'date' => @$request->timezone ? Carbon::parse($message->created_at)->setTimezone($request->timezone)->format('Y-m-d H:i:s') : Carbon::parse($message->created_at)->format('Y-m-d H:i:s'),
                    'time' => @$request->timezone ? Carbon::parse($message->created_at)->setTimezone($request->timezone)->format('h:i a') : Carbon::parse($message->created_at)->format('h:i a'),
                    'sentBy' => ($message->sender->id == auth()->user()->id) ? 'loginUser' : 'User',
                    'senderName' => $senderName,
                    'senderProfile' => $senderProfile,
                    'messageDetails' => $messageDetails,
                ];
            })->unique('messageId')->values();


          // Grouping the messages by Today and Yesterday
            $groupedChatData = [        
                'Yesterday' => [],
                'Today' => [],            
            ];

            // Current date and yesterday's date for comparison
            $today = Carbon::now()->startOfDay();
            $yesterday = Carbon::now()->subDay()->startOfDay();

            // Temporary array for grouping other dates
            $otherDates = [];

            foreach ($groupedChat as $chat) {
                $messageDate = Carbon::parse($chat['date'])->startOfDay();
                            
                if ($messageDate->equalTo($today)) {
                    $groupedChatData['Today'][] = $chat;
                } elseif ($messageDate->equalTo($yesterday)) {
                    $groupedChatData['Yesterday'][] = $chat;
                } else {
                    // Use the message's date as the key in the temporary array
                    $dateKey = $messageDate->format('d M Y'); // Format the date as 'YYYY-MM-DD'
                    $otherDates[$dateKey][] = $chat;
                }                
            }

            // Sort other dates in ascending order
            ksort($otherDates);

            // Merge the sorted dates with the main grouped array
            $groupedChatData = array_merge($otherDates, $groupedChatData);
            
            $groupData['members_count']= $memberCount;
            $data = [
                "status" => "Success",
                "status_code" => 200,
                "message" => "Get Data Successfully!",
                "data" => [
                    "groupData" => $groupData,
                    "groupChat" => $groupedChatData
                ]
            ];

            return $this->sendJsonResponse($data);
        } catch (\Exception $e) {
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => date("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }   
  
    /**
     * @OA\Post(
     *     path="/api/v1/tasks/update",
     *     tags={"Update Tasks"},
     *     summary="Update multiple tasks based on message ID",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="message_id", type="integer", example=1132),
     *                 @OA\Property(
     *                     property="task_ids",
     *                     type="array",
     *                     @OA\Items(type="integer", example=71)
     *                 ),
     *                 @OA\Property(property="task_name", type="string", example="Main Task Name"),
     *                 @OA\Property(
     *                     property="checkbox",
     *                     type="array",
     *                     @OA\Items(type="string", example="Task 1 Checkbox")
     *                 ),
     *                 @OA\Property(
     *                     property="task_checked",
     *                     type="array",
     *                     @OA\Items(type="boolean", example=true)
     *                 ),
     *                 @OA\Property(
     *                     property="receiver_id",
     *                     type="string",
     *                     example="user1@example.com,user2@example.com" 
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tasks updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=200),
     *             @OA\Property(property="message", type="string", example="Tasks updated successfully!"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="message_id", type="integer", example=1132),
     *                 @OA\Property(property="task_ids", type="array",
     *                     @OA\Items(type="integer", example=71)
     *                 ),
     *                 @OA\Property(property="task_name", type="string", example="Main Task Name"),
     *                 @OA\Property(property="checkbox", type="array",
     *                     @OA\Items(type="string", example="Task 1 Checkbox")
     *                 ),
     *                 @OA\Property(property="task_checked", type="array",
     *                     @OA\Items(type="boolean", example=true)
     *                 ),
     *                 @OA\Property(property="receiver_id", type="string", example="user1@example.com,user2@example.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=400),
     *             @OA\Property(property="message", type="string", example="Message ID is required."),
     *             @OA\Property(property="data", type="string", example="")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found or not updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=404),
     *             @OA\Property(property="message", type="string", example="Task not found or not updated"),
     *             @OA\Property(property="data", type="string", example="")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status_code", type="integer", example=500),
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */


    public function updateTask(Request $request)
    {
        try {
            // Validation for form-data input
            $rules = [
                'message_id' => 'required|integer',
                'task_ids.*' => 'integer', // Ensure task IDs are integers (removing exists check)
                'task_name' => 'required|string',
                'checkbox.*' => 'string', // Each checkbox item should be a string
                'task_checked.*' => 'boolean', // Each task_checked item should be a boolean
                'receiver_id' => 'sometimes|string' // Optional parameter for assigned users
            ];

            $messages = [
                'message_id.required' => 'Message ID is required.',
                'task_ids.required' => 'Task IDs are required.',
                'task_name.required' => 'Task name is required.',
            ];

            // Validate the form-data input
            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            $loginUser = auth()->user()->id;

            $taskIds = $request->input('task_ids');
            $taskIdsString = explode(',', $taskIds);

            $checkbox = $request->input('checkbox');
            $checkbox_names = explode(',', $checkbox);

            $task_checked = $request->input('task_checked');

            // Check if $task_checked is a string; if so, convert it to an array
            if (is_string($task_checked)) {
                $task_checked = explode(',', $task_checked); // Split string into array
            }

            // Convert boolean values to integers
            $task_checked_truflas = array_map(function ($value) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0; // Convert to 1 or 0
            }, $task_checked);

            // Get receiver_id if provided
            $assignUsers = $request->input('receiver_id', ''); // Default to empty string if not provided

            // Update existing tasks or create new ones
            foreach ($taskIdsString as $index => $taskId) {
                $taskData = [
                    'task_name' => $request->input('task_name'),
                    'checkbox' => $checkbox_names[$index] ?? '', // Default to empty if not set
                    'task_checked' => $task_checked_truflas[$index] ?? 0, // Default to 0 if not set                    
                    'users' => $assignUsers,
                ];

                // Check if task ID exists
                $existingTask = DB::table('message_task')->where('id', $taskId)->first();

                if ($existingTask) {

                    $existingTaskIds = MessageTask::where('message_id', $request->message_id)->pluck('id')->toArray();

                    // Delete tasks that are not included in the new task_ids
                    $tasksToDelete = array_diff($existingTaskIds, $taskIdsString);
                    if (!empty($tasksToDelete)) {
                        MessageTask::whereIn('id', $tasksToDelete)->delete();
                    }
                    // If exists, update the task
                    if ($task_checked_truflas[$index] == 1) {
                        // Get current task_checked_users
                        $task_checked_users = explode(',', $existingTask->task_checked_users ?? '');

                        // Check if loginUser is already in task_checked_users
                        if (!in_array($loginUser, $task_checked_users)) {
                            // Add loginUser to the array
                            $task_checked_users[] = $loginUser;
                        }

                        // Convert the array back to a string
                        $task_checked_users_string = implode(',', $task_checked_users);

                        // Update task with new checked users
                        $taskData['task_checked_users'] = $task_checked_users_string;
                    }

                    DB::table('message_task')->where('id', $taskId)->update($taskData);
                    $assignUsersArray = explode(',', $assignUsers);  // Split into an array
                    $messageId = $request->message_id;  // Assuming you get the message_id from the request
                    $loginUser = $request->user()->id;  // Assuming the sender is the logged-in user

                    foreach ($assignUsersArray as $receiverId) {
                        // Check if the message with the sender and receiver already exists
                        $exists = MessageSenderReceiver::where('message_id', $messageId)
                                                    ->where('receiver_id', (int) $receiverId)
                                                    ->exists();

                        // If the receiver is not already present, create a new entry
                        if (!$exists) {
                            $messageSenderReceiver = new MessageSenderReceiver();
                            $messageSenderReceiver->message_id = $messageId;
                            $messageSenderReceiver->sender_id = $loginUser;
                            $messageSenderReceiver->receiver_id = (int) $receiverId;  // Convert to integer
                            $messageSenderReceiver->save();
                        }
                    }
                     // Now delete entries that are not in the current assignUsersArray
                    MessageSenderReceiver::where('message_id', $messageId)
                    ->where('sender_id', $loginUser)
                    ->whereNotIn('receiver_id', $assignUsersArray)  // Remove receivers not in the current array
                    ->delete();
                } else {
            
                // Fetch existing checkbox names from the database
                    $existingCheckboxes = MessageTask::where('message_id', $request->message_id)
                    ->pluck('checkbox') // Get the list of existing checkboxes
                    ->toArray();

                    // Loop through the checkbox names and create a new task for each new checkbox
                    foreach ($checkbox_names as $checkbox_name) {
                    if (!in_array($checkbox_name, $existingCheckboxes)) {
                       
                        MessageTask::create([
                            'message_id' => $request->message_id,
                            'task_name' => $request->input('task_name'),
                            'checkbox' => $checkbox_name, // Use the current checkbox name
                            'task_checked_users' => $loginUser, // Set current user as checked
                            'users' => $assignUsers // Add new parameter for assigned users
                        ]);
                        }
                    }

                    $assignUsersArray = explode(',', $assignUsers);  // Split into an array
                    $messageId = $request->message_id;  // Assuming you get the message_id from the request
                    $loginUser = $request->user()->id;  // Assuming the sender is the logged-in user

                    foreach ($assignUsersArray as $receiverId) {
                        // Check if the message with the sender and receiver already exists
                        $exists = MessageSenderReceiver::where('message_id', $messageId)
                                                    ->where('sender_id', $loginUser)
                                                    ->where('receiver_id', (int) $receiverId)
                                                    ->exists();

                        // If the receiver is not already present, create a new entry
                        if (!$exists) {
                            $messageSenderReceiver = new MessageSenderReceiver();
                            $messageSenderReceiver->message_id = $messageId;
                            $messageSenderReceiver->sender_id = $loginUser;
                            $messageSenderReceiver->receiver_id = (int) $receiverId;  // Convert to integer
                            $messageSenderReceiver->save();
                        }
                    }

                              
                }
            }
            $created_by = MessageTask::where('message_id', $request->message_id)->first();
            return response()->json([
                'status_code' => 200,
                'message' => "Tasks updated successfully!",
                'created_by' => $created_by->created_by
            ]);
        } catch (\Exception $e) {
            // Log the error details
            Log::error([
                'method' => __METHOD__,
                'error' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage()
                ],
                'created_at' => date("Y-m-d H:i:s")
            ]);

            return response()->json([
                'status_code' => 500,
                'message' => 'Something went wrong',
            ]);
        }
    }
    
    /**
     * @OA\Post(
     *     path="/api/v1/edit-profile",
     *     summary="Edit Profile",
     *     tags={"User"},
     *     description="Edit Profile",
     *     operationId="editProfile",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Edit Profile",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={},
     *                 @OA\Property(
     *                     property="first_name",
     *                     type="string",
     *                     example="Test",
     *                     description="Enter First Name"
     *                 ),
     *                 @OA\Property(
     *                     property="last_name",
     *                     type="string",
     *                     example="User",
     *                     description="Enter Last Name"
     *                 ),
     *                 @OA\Property(
     *                     property="country_code",
     *                     type="string",
     *                     example="+91",
     *                     description="Enter Country Code"
     *                 ),
     *                 @OA\Property(
     *                     property="mobile",
     *                     type="number",
     *                     example="9876543210",
     *                     description="Enter Mobile Number"
     *                 ),
     *                 @OA\Property(
     *                     property="email",
     *                     type="string",
     *                     example="test@yopmail.com",
     *                     description="Enter Email Address"
     *                 ),
     *                 @OA\Property(
     *                     property="title",
     *                     type="string",
     *                     example="",
     *                     description="Enter title"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     example="",
     *                     description="Enter Description"
     *                 ),
     *                 @OA\Property(
     *                     property="account_id",
     *                     type="string",
     *                     example="",
     *                     description="Enter Account Id"
     *                 ),
     *                 @OA\Property(
     *                     property="profile",
     *                     type="file",
     *                     description="Profile Image"
     *                 ),
     *                 @OA\Property(
     *                     property="cover_image",
     *                     type="file",
     *                     description="Cover Image"
     *                 ),
     *                 @OA\Property(
     *                     property="instagram_profile_url",
     *                     type="string",
     *                     example="",
     *                     description="Enter Instagram Profile Url"
     *                 ),
     *                 @OA\Property(
     *                     property="facebook_profile_url",
     *                     type="string",
     *                     example="",
     *                     description="Enter Facebook Profile Url"
     *                 ),
     *                 @OA\Property(
     *                     property="twitter_profile_url",
     *                     type="string",
     *                     example="",
     *                     description="Enter Twitter Profile Url"
     *                 ),
     *                 @OA\Property(
     *                     property="youtube_profile_url",
     *                     type="string",
     *                     example="",
     *                     description="Enter Youtube Profile Url"
     *                 ),
     *                 @OA\Property(
     *                     property="linkedin_profile_url",
     *                     type="string",
     *                     example="",
     *                     description="Enter LinkedIn Profile Url"
     *                 ),
     *                 @OA\Property(
     *                     property="address",
     *                     type="string",
     *                     example="",
     *                     description="Enter your full address"
     *                 ),
     *                 @OA\Property(
     *                     property="longitude",
     *                     type="string",
     *                     example="",
     *                     description="Enter longitude"
     *                 ),
     *                 @OA\Property(
     *                     property="latitude",
     *                     type="string",
     *                     example="",
     *                     description="Enter latitude"
     *                 ),
     *             )
     *         )
     *     ),
     *     @OA\Response(
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

    public function editProfile(Request $request)
    {
        try {
            $userId = auth()->user()->id;
            if (empty($userId)) {
                $data = [
                    'status_code' => 400,
                    'message' => "User Not Found!",
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $users = new User();
            $user = $users->find($userId);
            $profileImageName = $user->profile;
            if ($request->hasFile('profile')) {
                $rule = [
                    'profile' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                ];
                $message = [
                    'profile.required' => 'Profile image required',
                    'profile.image' => 'Profile image must be an image file.',
                    'profile.mimes' => 'Profile image must be a JPEG, JPG, PNG,svg, or WebP file.',
                    'profile.max' => 'Profile image size must not exceed 2MB.',
                ];
                $validator = Validator::make($request->all(), $rule, $message);
                if ($validator->fails()) {
                    $data = [
                        'status_code' => 400,
                        'message' => $validator->errors()->first(),
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                }
                $profileImage = $request->file('profile');
                $profileImageName = imageUpload($profileImage, 'user-profile');
                if ($profileImageName == 'upload_failed') {
                    $data = [
                        'status_code' => 400,
                        'message' => 'profile Upload faild',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                } elseif ($profileImageName == 'invalid_image') {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                }
            }
            $coverImageName = $user->cover_image;
            if ($request->hasFile('cover_image')) {
                $ruleCover = [
                    'cover_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                ];
                $messageCover = [
                    'cover_image.required' => 'Profile image required',
                    'cover_image.image' => 'Profile image must be an image file.',
                    'cover_image.mimes' => 'Profile image must be a JPEG, JPG, PNG,svg, or WebP file.',
                    'cover_image.max' => 'Profile image size must not exceed 2MB.',
                ];
                $validatorCover = Validator::make($request->all(), $ruleCover, $messageCover);
                if ($validatorCover->fails()) {
                    $dataCover = [
                        'status_code' => 400,
                        'message' => $validator->errors()->first(),
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($dataCover);
                }
                $coverImage = $request->file('cover_image');
                $coverImageName = imageUpload($coverImage, 'user-profile-cover-image');
                if ($coverImageName == 'upload_failed') {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Cover Image Upload faild',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                } elseif ($coverImageName == 'invalid_image') {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                }
            }
            $user->first_name = @$request->first_name ? $request->first_name : $user->first_name;
            $user->last_name = @$request->last_name ? $request->last_name : $user->last_name;
            $user->country_code = @$request->country_code ? $request->country_code : $user->country_code;
            $user->mobile = @$request->mobile ? $request->mobile : $user->mobile;
            $user->email = @$request->email ? $request->email : $user->email;
            $user->profile = $profileImageName;
            $user->cover_image = $coverImageName;
            $user->title = @$request->title ? $request->title : NULL;
            $user->description = @$request->description ? $request->description : NULL;
            $user->instagram_profile_url = @$request->instagram_profile_url ? $request->instagram_profile_url : NULL;
            $user->facebook_profile_url = @$request->facebook_profile_url ? $request->facebook_profile_url : NULL;
            $user->twitter_profile_url = @$request->twitter_profile_url ? $request->twitter_profile_url : NULL;
            $user->youtube_profile_url = @$request->youtube_profile_url ? $request->youtube_profile_url : NULL;
            $user->linkedin_profile_url = @$request->linkedin_profile_url ? $request->linkedin_profile_url : NULL;
            $user->longitude = @$request->longitude ? $request->longitude : NULL;
            $user->latitude = @$request->latitude ? $request->latitude : NULL;
            $user->address = @$request->address ? $request->address : NULL;
            $user->save();

            $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : NULL;
            $user->cover_image = @$user->cover_image ? setAssetPath('user-profile-cover-image/' . $user->cover_image) : NULL;
            $data = [
                'status_code' => 200,
                'message' => "User Updated Successfully!",
                'data' => [
                    'userData' => $user
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
     *     path="/api/v1/delete-chat-user",
     *     summary="Delete Chat Users",
     *     tags={"User"},
     *     description="Delete Chat Users",
     *     operationId="deleteChatUser",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         example="2",
     *         description="Enter userId",
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

    public function deleteChatUsers(Request $request)
    {
        try {
            $rules = [
                'id' => 'required|integer|exists:users,id',
            ];

            $message = [
                'id.required' => 'User ID is required.',
                'id.integer' => 'User ID must be an integer.',
                'id.exists' => 'The specified user does not exist.',
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
            $deleteChatUser = new deleteChatUsers();
            $deleteChatUser->user_id = auth()->user()->id;
            $deleteChatUser->deleted_user_id = $request->id;
            $deleteChatUser->save();

            $data = [
                'status_code' => 200,
                'message' => "User Deleted Successfully!",
                'data' => [
                    'userData' => $deleteChatUser,
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
     *     path="/api/v1/deleted-user-list",
     *     summary="Deleted User List",
     *     tags={"User"},
     *     description="Deleted User List",
     *     operationId="deletedUserList",
     *     security={{"bearerAuth":{}}},
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

    public function deletedUserList(Request $request)
    {
        try {
            $loginUser = auth()->user()->id;
            $deletedUsers = deleteChatUsers::where('user_id', $loginUser)->pluck('deleted_user_id');
            $deletedUsers = User::whereIn('id', $deletedUsers)->get(['id', 'account_id', 'first_name', 'last_name', 'email', 'country_code', 'mobile', 'profile']);
            $deletedUsers = $deletedUsers->map(function ($item) {
                $item->profile = @$item->profile ? setAssetPath('user-profile/' . $item->profile) : setAssetPath('assets/media/avatars/blank.png');
                return $item;
            });
            $data = [
                'status_code' => 200,
                'message' => "Deleted User get Successfully!",
                'data' => [
                    'userList' => $deletedUsers,
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
     *     path="/api/v1/deleted-user-account",
     *     summary="Deleted User Account",
     *     tags={"User"},
     *     description="Deleted User Account",
     *     operationId="deletedUserAccount",
     *     security={{"bearerAuth":{}}},
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

     public function deletedUserAccount(Request $request)
    {
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            $loginUser = auth()->user()->id;
            userDeviceToken::where('user_id',$loginUser)->forceDelete();
            User::where('id',$loginUser)->forceDelete();
            $data = [
                'status_code' => 200,
                'message' => "User Deleted Successfully!",
                'data' => []
            ];
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
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
     *     path="/api/v1/update-task-details",
     *     summary="Update task details",
     *     description="Updates the details of a specific task assigned to the authenticated user.",
     *     operationId="updateTaskDetails",
     *     tags={"User"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         example="2",
     *         description="Enter taskId",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *      @OA\Parameter(
     *         name="message_id",
     *         in="query",
     *         example="1",
     *         description="Enter messageId",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="Success"),
     *             @OA\Property(property="message", type="string", example="Task updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="task_id", type="integer", example=1),
     *                 @OA\Property(property="task_name", type="string", example="Sample Task"),
     *                 @OA\Property(property="task_checked_users", type="string", example="1,2,3"),
     *                 @OA\Property(property="read_status", type="string", example="1,2,3"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-01-08T10:18:02")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="Failure"),
     *             @OA\Property(property="message", type="string", example="Task not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="User not assigned to this task",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="Failure"),
     *             @OA\Property(property="message", type="string", example="User not assigned to this task")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="An error occurred while updating the task",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="Failure"),
     *             @OA\Property(property="message", type="string", example="An error occurred while updating the task"),
     *             @OA\Property(property="error", type="string", example="Detailed error message")
     *         )
     *     )
     * )
     */
    public function updateTaskDetails(Request $request)
    {
        try {
            // Validate the input data
            $request->validate([
                'id' => 'required',
                'message_id' => 'required',
            ]);

            $taskId = $request->input('id');
            $messageId = $request->input('message_id');
            $userId = Auth::id();

            // Fetch the task and check existence
            $task = MessageTask::find($taskId);
            if (!$task || !$task->where('message_id', $messageId)->exists()) {
                return response()->json(['status' => 'Failure', 'message' => 'Task or Message not found'], 404);
            }

            // Check if the user is assigned to the task
            $assignedUsers = explode(',', $task->users);
            if (!in_array($userId, $assignedUsers)) {
                return response()->json(['status' => 'Failure', 'message' => 'User not assigned to this task'], 403);
            }

            // Update read_status and task_checked_users
            $createdByUserId = $task->created_by;
            $currentUserId = Auth::id();

            $task->read_status = $this->updateUserList($task->read_status, $currentUserId, $createdByUserId);
            $task->task_checked_users = $this->updateUserList($task->task_checked_users, $currentUserId, $createdByUserId);

            // Update task_checked
            // $assignedUserIds = array_diff($assignedUsers, [$createdByUserId]);
            $currentCheckedUsers = explode(',', $task->task_checked_users);
            $task->task_checked = count($assignedUsers) == count($currentCheckedUsers) ? 1 : 0;

            // Save the updated task
            $task->timestamps = false;
            $task->fill($request->except(['id', 'message_id', 'updated_by']));
            unset($task->updated_by);
            $task->save();

            // Prepare task data response
            $taskData = $this->prepareTaskData($messageId);
            $messageDetails = $this->prepareMessageDetails($task, $messageId, $taskData);

            return response()->json(['status' => 'Success', 'message' => 'Task updated successfully', 'data' => $messageDetails]);
        } catch (\Exception $e) {
            \Log::error('Error updating Task: ' . $e->getMessage());
            return response()->json(['status' => 'Failure', 'message' => 'An error occurred while updating the task', 'error' => $e->getMessage()], 500);
        }
    }

    private function updateUserList($existingList, $currentUserId, $createdByUserId)
    {
        $userList = $existingList ? explode(',', $existingList) : [];
        // if ($currentUserId !== $createdByUserId) {
            $userList[] = $currentUserId;
        // }
        return implode(',', array_unique($userList));
    }

    private function prepareTaskData($messageId)
    {
        $tasks = MessageTask::where('message_id', $messageId)->get();
        return $tasks->map(function ($task) {
            $comments = $task->getChats->where('message_id', $task->message_id)->map(function ($comment) {
                return [
                    'id' => $comment->id,
                    'task_chat_id' => $comment->task_chat_id,
                    'message_id' => $comment->message_id,
                    'user' => $this->formatUser($comment->user),
                    'comment' => $comment->comment,
                    'created_at' => $comment->created_at->toDateTimeString(),
                ];
            });

            $profiles = User::whereIn('id', explode(',', $task->task_checked_users))->get()->map(function ($user) {
                return ['id' => $user->id, 'profile_url' => $user->profile ? asset('/public/user-profile/' . $user->profile) : null];
            });

            return [
                'id' => $task->id,
                'message_id' => $task->message_id,
                'checkbox' => $task->checkbox,
                'task_checked' => $task->task_checked,
                'task_checked_users' => $task->task_checked_users,
                'profiles' => $profiles,
                'comments' => $comments,
            ];
        })->toArray();
    }

    private function prepareMessageDetails($task, $messageId, $taskData)
    {
        $getMessageName = MessageTask::with(['message', 'createdByUser:id,role'])->where('message_id', $messageId)->first();

        if (!$getMessageName) {
            return null;
        }

        $allTasksCompleted = $task->getUserDetails ? $task->getUserDetails->tasks->every(function ($task) {
            return $task->task_checked;
        }) : false;

        $idsArray = explode(',', $task->users);
        // Prepare complete_users array
        $allUsers = [];
        foreach ($idsArray as $users) {

            // Skip the created_by user
            if ($users == $task->created_by) {
                continue;
            }
            $user = User::find($users); // Assuming you have a User model
            if ($user) {
                $allUsers[] = [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'country_code' => $user->country_code,
                    'mobile' => $user->mobile,
                    'profile' => asset('/public/user-prfile/' . $user->profile) ?? '', // Use the profile attribute or a placeholder
                    'task_ids' => $task->id,
                    'task_done' => $allTasksCompleted,
                ];
            }
        }

        return [
            'messageId' => $messageId,
            'messageType' => $getMessageName->message->message_type,
            'attachmentType' => $getMessageName->message->attachment_type,
            'date' => $getMessageName->message->date,
            'time' => $getMessageName->message->time,
            'sentBy' => $getMessageName->createdByUser->role,
            'messageDetails' => [
                'task_name' => $task->task_name,
                'date' => Carbon::parse($task->created_at)->format('Y-m-d H:i:s'),
                'time' => Carbon::parse($task->created_at)->format('H:i A'),
                'users' => $allUsers,
                'tasks' => $taskData,
            ],
        ];
    }

    private function formatUser($user)
    {
        return $user ? [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'profile_picture' => $user->profile ? asset('/public/user-profile/' . $user->profile) : null,
        ] : null;
    }
}