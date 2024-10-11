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
     *     path="/api/v1/group-list",
     *     summary="Get list of group names",
     *     description="Get list Group names",
     *     tags={"Group Chat"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of group names",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=200
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Group names fetched successfully"
     *             ),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="string",
     *                     example="Group Name"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=500
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Something went wrong"
     *             )
     *         )
     *     )
     * )
     */

     public function groupList(Request $request)
     {
        try {
                $login_user_id = auth()->user()->id;
        
                // Retrieve groups where the logged-in user is either the creator or a member
                $groups = Group::whereIn('id', function ($query) use ($login_user_id) {
                        $query->select('group_id')
                            ->from('group_members')
                            ->where('user_id', $login_user_id) // User is a member of the group
                            ->whereNull('deleted_at')                            
                            ->orWhere('created_by', $login_user_id) // User created the group
                            ->whereNull('deleted_at');
                    })                    
                    ->get()
                    ->map(function ($group) {
                        // Set Profile image path
                        $group->profile_pic = empty($group->profile_pic) 
                        ? setAssetPath('assets/media/avatars/blank.png') 
                        : setAssetPath('group-profile/' . $group->profile_pic);
                    
                        // Set cover image path
                        $group->cover_image = empty($group->cover_image) 
                            ? setAssetPath('assets/media/avatars/blank.png') 
                            : setAssetPath('group-profile-cover-image/' . $group->cover_image);
                        return $group;
                    });
        
                $data = [
                    'status_code' => 200,
                    'message' => 'Group names fetched successfully',
                    'data' => $groups
                ];
        
                return response()->json($data, 200);
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
             return response()->json(['status_code' => 500, 'message' => 'Something went wrong'], 500);
         }
     }     


     /**
     * @OA\Post(
     *     path="/api/v1/group-member-search",
     *     summary="Search group members by name",
     *     description="Get list of group members based on the search term",
     *     tags={"Group Chat"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="group_id",
     *         in="query",
     *         required=true,
     *         @OA\Schema(
     *             type="integer",
     *             example=1
     *         ),
     *         description="The ID of the group for which members are fetched"
     *     ),
     *     @OA\Parameter(
     *         name="search_term",
     *         in="query",
     *         description="Search term for group member names",
     *         required=false,
     *         @OA\Schema(
     *             type="string"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Group members fetched successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=200
     *             ),             
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Group members fetched successfully"
     *             ),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="group_name", type="string", example="Group Name"),
     *                     @OA\Property(
     *                         property="members",
     *                         type="array",
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="member_name", type="string", example="John Doe"),
     *                             @OA\Property(property="profile_pic", type="string", example="path-to-profile-pic")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=500
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Something went wrong"
     *             )
     *         )
     *     )
     * )
     */
    public function groupMemberSearch(Request $request)
    {
        try {
            $login_user_id = auth()->user()->id;
            $group_id = $request->input('group_id');
            $searchTerm = $request->input('search_term');
            
            // Check if the group_id is provided
            if (!$group_id) {
                return response()->json(['status_code' => 400, 'message' => 'Group ID ( group_id ) is required'], 400);
            }
            
            // Find users based on group_id and optional search term for both first and last names
            $groupMembers = User::join('group_members', 'users.id', '=', 'group_members.user_id')
                ->where('group_members.group_id', $group_id)
                ->when($searchTerm, function ($query, $searchTerm) {
                    return $query->where(function ($query) use ($searchTerm) {
                        $query->where('users.first_name', 'LIKE', '%' . $searchTerm . '%')
                              ->orWhere('users.last_name', 'LIKE', '%' . $searchTerm . '%');
                    });
                })
                ->whereNull('users.deleted_at') // Ensure the users are not soft-deleted
                ->get(['users.*']); // Fetch all user fields or customize as needed
            
            // Map through the group members to set profile_pic and cover_image paths
            $groupMembers = $groupMembers->map(function ($user) {
                // Set profile_pic path only if it's not empty
                if (!empty($user->profile)) {
                    $user->profile = empty($user->profile) 
                    ? setAssetPath('assets/media/avatars/blank.png') 
                    : setAssetPath('group-profile/' . $user->profile);
                }
            
                // Set cover_image path only if it's not empty
                if (!empty($user->cover_image)) {
                    $user->cover_image = empty($user->cover_image) 
                    ? setAssetPath('assets/media/avatars/blank.png') 
                    : setAssetPath('group-profile-cover-image/' . $user->cover_image);                    
                }            
                return $user;
            });
            
            $data = [
                'status_code' => 200,
                'message' => 'Group members fetched successfully',
                'data' => $groupMembers
            ];
            
            return response()->json($data, 200);            
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
                return response()->json(['status_code' => 500, 'message' => 'Something went wrong'], 500);
            }
    }


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
                $profileImageName = imageUpload($profileImage, 'group-profile');
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
                $coverImageName = imageUpload($coverImage, 'group-profile-cover-image');
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
            // Validation rules and messages
            $rules = [
                'name' => 'required',
                'description' => 'nullable|string',
                'profile' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'cover_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048'
            ];
            $messages = [
                'name.required' => 'Name is required',
                'description.string' => 'Description must be a string',
                'profile.image' => 'Profile image must be an image file.',
                'profile.mimes' => 'Profile image must be a JPEG, JPG, PNG, SVG, or WebP file.',
                'profile.max' => 'Profile image size must not exceed 2MB.',
                'cover_image.image' => 'Cover image must be an image file.',
                'cover_image.mimes' => 'Cover image must be a JPEG, JPG, PNG, SVG, or WebP file.',
                'cover_image.max' => 'Cover image size must not exceed 2MB.'
            ];

            // Validate request
            $validator = \Validator::make($request->all(), $rules, $messages);
            if ($validator->fails()) {
                return $this->sendJsonResponse([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            // Find the existing group
            $group = Group::find($request->id);
            if (!$group) {
                return $this->sendJsonResponse([
                    'status_code' => 404,
                    'message' => 'Group not found',
                    'data' => ""
                ]);
            }

            // Handle profile image upload
            if ($request->hasFile('profile')) {
                $profileImage = $request->file('profile');
                $profileImageName = imageUpload($profileImage, 'group-profile');
                if ($profileImageName == 'upload_failed') {
                    return $this->sendJsonResponse([
                        'status_code' => 400,
                        'message' => 'Profile upload failed',
                        'data' => ""
                    ]);
                } elseif ($profileImageName == 'invalid_image') {
                    return $this->sendJsonResponse([
                        'status_code' => 400,
                        'message' => 'Please select a valid image file (jpg, jpeg, png, webp, svg)',
                        'data' => ""
                    ]);
                }
                $group->profile_pic = $profileImageName;
            }

            // Handle cover image upload
            if ($request->hasFile('cover_image')) {
                $coverImage = $request->file('cover_image');
                $coverImageName = imageUpload($coverImage, 'group-profile-cover-image');
                if ($coverImageName == 'upload_failed') {
                    return $this->sendJsonResponse([
                        'status_code' => 400,
                        'message' => 'Cover image upload failed',
                        'data' => ""
                    ]);
                } elseif ($coverImageName == 'invalid_image') {
                    return $this->sendJsonResponse([
                        'status_code' => 400,
                        'message' => 'Please select a valid image file (jpg, jpeg, png, webp, svg)',
                        'data' => ""
                    ]);
                }            
                $group->cover_image = $coverImageName;
            }

            // Update group details
            $group->name = $request->name;
            $group->description = $request->description;
            $group->save();

            $group->profile_pic = empty($group->profile_pic) 
                    ? setAssetPath('assets/media/avatars/blank.png') 
                    : setAssetPath('group-profile/' . $group->profile_pic);   

            $group->cover_image = empty($group->cover_image) 
                    ? setAssetPath('assets/media/avatars/blank.png') 
                    : setAssetPath('group-profile-cover-image/' . $group->cover_image);               

            return $this->sendJsonResponse([
                'status_code' => 200,
                'message' => 'Group updated successfully',
                'data' => $group
            ]);
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
            $userList = User::whereIn('id', $groupUser)->where('role', 'User')->get([
                'id',
                'first_name',
                'last_name',
                'profile',
            ]);
            $userList = $userList->map(function ($item) {
                $item->full_name = $item->first_name . ' ' . $item->last_name;
                $item->profile = @$item->profile ? setAssetPath('group-profile/' . $item->profile) : setAssetPath('assets/media/avatars/blank.png');
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

    /**
     * Delete a group by ID.
     *
     * @OA\Delete(
     *     path="/api/v1/group-delete",
     *     summary="Delete a group by ID",
     *     description="Delete a group by its group_id, only if the logged-in user is the creator.",
     *     tags={"Group Chat"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="group_id",
     *         in="query",
     *         required=true,
     *         @OA\Schema(
     *             type="integer",
     *             example=1
     *         ),
     *         description="The ID of the group to be deleted"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Group deleted successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=200
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Group deleted successfully"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=403
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="You are not authorized to delete this group"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Group not found",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=404
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Group not found"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="status_code",
     *                 type="integer",
     *                 example=500
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Something went wrong"
     *             )
     *         )
     *     )
     * )
     */

    public function deleteGroup(Request $request)
    {
        try {
            $login_user_id = auth()->user()->id;
            $group_id = $request->input('group_id');

            // Check if the group_id is provided
            if (!$group_id) {
                return response()->json(['status_code' => 400, 'message' => 'Group ID (group_id) is required'], 400);
            }

            // Find the group by ID
            $group = Group::where('id', $group_id)->whereNull('deleted_at')->first();

            // Check if group exists
            if (!$group) {
                return response()->json(['status_code' => 404, 'message' => 'Group not found'], 404);
            }

            // Check if the logged-in user is the creator of the group
            if ($group->created_by != $login_user_id) {
                return response()->json(['status_code' => 403, 'message' => 'You are not authorized to delete this group'], 403);
            }

            // Perform soft delete (set deleted_at)
            $group->delete();

            return response()->json(['status_code' => 200, 'message' => 'Group deleted successfully'], 200);
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
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong'], 500);
        }
    }



}