<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMembers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/create-group",
     *     summary="Create Group",
     *     tags={"Group Chat"},
     *     description="Create Group",
     *     operationId="createGroup",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Create Group",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"name"},
     *                 @OA\Property(
     *                     property="name",
     *                     type="string",
     *                     example="Test",
     *                     description="Enter First Name"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     example="User",
     *                     description="Enter Description"
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

    public function createGroup(Request $request)
    {
        try {
            $rule = [
                'name' => 'required',
                'description' => 'nullable|string'
            ];
            $message = [
                'name.required' => 'Name is required',
                'description.string' => 'Description must be string',
            ];
            $validator = \Validator::make($request->all(), $rule, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $profileImageName = "";
            $coverImageName = "";
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
            if ($request->hasFile('cover_image')) {
                $ruleCover = [
                    'cover_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                ];
                $messageCover = [
                    'cover_image.required' => 'Cover image required',
                    'cover_image.image' => 'Cover image must be an image file.',
                    'cover_image.mimes' => 'Cover image must be a JPEG, JPG, PNG,svg, or WebP file.',
                    'cover_image.max' => 'Cover image size must not exceed 2MB.',
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
            $group = new Group();
            $group->name = $request->name;
            $group->description = $request->description;
            $group->profile_pic = $profileImageName;
            $group->cover_image = $coverImageName;
            $group->save();

            $groupUser = new GroupMembers();
            $groupUser->group_id = $group->id;
            $groupUser->user_id = auth()->user()->id;                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       
            $groupUser->is_admin = "Yes";
            $groupUser->save();

            $data = [
                'status_code' => 200,
                'message' => 'Group created successfully',
                'data' => $group
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
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/edit-group",
     *     summary="Edit Group",
     *     tags={"Group Chat"},
     *     description="Edit Group",
     *     operationId="editGroup",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Edit Group",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"id"},
     *                 @OA\Property(
     *                     property="id",
     *                     type="string",
     *                     example="1",
     *                     description="Enter Group Id"
     *                 ),
     *                 @OA\Property(
     *                     property="name",
     *                     type="string",
     *                     example="Test",
     *                     description="Enter Group Name"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     example="This is a group description.",
     *                     description="Enter Group Description"
     *                 ),
     *                 @OA\Property(
     *                     property="profile",
     *                     type="string",
     *                     format="binary",
     *                     description="Profile Image"
     *                 ),
     *                 @OA\Property(
     *                     property="cover_image",
     *                     type="string",
     *                     format="binary",
     *                     description="Cover Image"
     *                 )
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
     *     )
     * )
     */


    public function editGroup(Request $request)
    {
        try {
            $rule = [
                'name' => 'required',
                'description' => 'nullable|string'
            ];
            $message = [
                'name.required' => 'Name is required',
                'description.string' => 'Description must be string',
            ];
            $validator = \Validator::make($request->all(), $rule, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $group = new Group();
            $groupDetails = $group->find($request->id);
            $profileImageName = $groupDetails->profile_pic;
            $coverImageName = $groupDetails->cover_image;
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
            if ($request->hasFile('cover_image')) {
                $ruleCover = [
                    'cover_image' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                ];
                $messageCover = [
                    'cover_image.required' => 'Cover image required',
                    'cover_image.image' => 'Cover image must be an image file.',
                    'cover_image.mimes' => 'Cover image must be a JPEG, JPG, PNG,svg, or WebP file.',
                    'cover_image.max' => 'Cover image size must not exceed 2MB.',
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
            $group = new Group();
            $group->name = @$request->name ? $request->name : $groupDetails->name;
            $group->description = @$request->description ? $request->description : $groupDetails->description;
            $group->profile_pic = $profileImageName;
            $group->cover_image = $coverImageName;
            $group->save();
            $data = [
                'status_code' => 200,
                'message' => 'Group updated successfully',
                'data' => $group
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
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/user-list-for-group",
     *     summary="User List for Group",
     *     tags={"Group Chat"},
     *     description="User List for Group",
     *     operationId="userListForGroup",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="User List for Group",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"id"},
     *                 @OA\Property(
     *                     property="id",
     *                     type="number",
     *                     example="1",
     *                     description="Enter Group Id"
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
    public function userListForGroup(Request $request)
    {
        try {
            $rule = [
                'id' => 'required|exists:group,id',
            ];
            $message = [
                'id.required' => 'Group Id is required',
                'id.exists' => 'Group Id not found',
            ];
            $validator = \Validator::make($request->all(), $rule, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $groupUser = GroupMembers::where('group_id', $request->id)->pluck('user_id');
            $userList = User::whereNotIn('id', $groupUser)->where('role', 'User')->get([
                'id',
                'first_name',
                'last_name',
                'profile',
            ]);
            $userList = $userList->map(function ($item) {
                $item->full_name = $item->first_name . ' ' . $item->last_name;
                $item->profile = @$item->profile ? setAssetPath('user-profile/' . $item->profile) : setAssetPath('assets/media/avatars/blank.png');
                return $item;
            });

            $data = [
                'status_code' => 200,
                'message' => 'Group User get successfully',
                'data' => [
                    'user_list' => $userList
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
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/add-group-user",
     *     summary="Add Group User",
     *     tags={"Group Chat"},
     *     description="Add Group User",
     *     operationId="addGroupUser",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Group User",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"id","user_id"},
     *                 @OA\Property(
     *                     property="id",
     *                     type="number",
     *                     example="1",
     *                     description="Enter Group Id"
     *                 ),
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter Comma Separated User Id"
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

    public function addGroupUser(Request $request)
    {
        try {
            $rule = [
                'id' => 'required|exists:group,id',
                'user_id' => 'required|string',
            ];
            $message = [
                'id.required' => 'Group Id is required',
                'id.exists' => 'Group Id not found',
                'user_id.required' => 'User Id is required',
                'user_id.string' => 'User Id must be string',
            ];
            $validator = \Validator::make($request->all(), $rule, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $userIds = explode(',', $request->user_id);
            foreach ($userIds as $user) {
                GroupMembers::create([
                    'group_id' => $request->id,
                    'user_id' => $user
                ]);
            }

            $data = [
                'status_code' => 200,
                'message' => 'Group User added successfully',
                'data' => ""
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
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/remove-group-user",
     *     summary="Remove Group User",
     *     tags={"Group Chat"},
     *     description="Remove Group User",
     *     operationId="removeGroupUser",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Remove Group User",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"id","user_id"},
     *                 @OA\Property(
     *                     property="id",
     *                     type="number",
     *                     example="1",
     *                     description="Enter Group Id"
     *                 ),
     *                 @OA\Property(
     *                     property="user_id",
     *                     type="string",
     *                     example="1,2,3",
     *                     description="Enter Comma Separated User Id"
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

    public function removeGroupUser(Request $request)
    {
        try {
            $rule = [
                'id' => 'required|exists:group,id',
                'user_id' => 'required|string',
            ];
            $message = [
                'id.required' => 'Group Id is required',
                'id.exists' => 'Group Id not found',
                'user_id.required' => 'User Id is required',
                'user_id.string' => 'User Id must be string',
            ];
            $validator = \Validator::make($request->all(), $rule, $message);
            if ($validator->fails()) {
                $data = [
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ];
                return $this->sendJsonResponse($data);
            }
            $userIds = explode(',', $request->user_id);
            GroupMembers::where('group_id',$request->id)->whereIn('user_id',$userIds)->delete();

            $totalGroupMembers = GroupMembers::where('group_id',$request->id)->count();
            if($totalGroupMembers == 0){
                Group::where('id',$request->id)->delete();
            }

            $data = [
                'status_code' => 200,
                'message' => 'Group Users deleted successfully',
                'data' => ""
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
                'created_at' => now()->format("Y-m-d H:i:s")
            ]);
            return $this->sendJsonResponse(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }


}
