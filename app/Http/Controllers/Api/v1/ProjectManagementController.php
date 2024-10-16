<?php

namespace App\Http\Controllers\Api\v1;

use App\Exports\workHoursExport;
use App\Http\Controllers\Controller;
use App\Mail\WorkHoursMail;
use App\Models\Notes;
use App\Models\User;
use App\Models\WorkingHours;
use App\Models\ProjectEvent;
use App\Models\userDeviceToken;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\File;

class ProjectManagementController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/add-work-hours",
     *     summary="Add a new Reminder",
     *     tags={"Project Management"},
     *     description="Create a new message for Project Management.",
     *     operationId="addWorkHours",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"start_date_time","end_date_time","timezone"},
     *                 @OA\Property(
     *                     property="start_date_time",
     *                     type="string",
     *                     example="2024-05-17 19:15:00",
     *                     description="Enter Start Date Time"
     *                 ),
     *                 @OA\Property(
     *                     property="end_date_time",
     *                     type="string",
     *                     example="2024-05-17 21:15:00",
     *                     description="Enter End Date Time"
     *                 ),
     *                 @OA\Property(
     *                     property="summary",
     *                     type="string",
     *                     example="",
     *                     description="Enter Work Summary"
     *                 ),
     *                 @OA\Property(
     *                     property="timezone",
     *                     type="string",
     *                     example="Asia/Kolkata",
     *                     description="Enter timezone"
     *                 ),
     *             )
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

    public function addWorkHours(Request $request)
    {
        try {
            $rules = [
                'start_date_time' => 'required|string',
                'end_date_time' => 'nullable|string',
                'timezone' => 'required|string',
            ];

            $message = [
                'start_date_time.required' => 'Start Date Time is required.',
                'start_date_time.string' => 'Start Date Time must be an String.',
                'end_date_time.required' => 'End Date Time is required.',
                'end_date_time.string' => 'End Date Time must be an String.',
                'timezone.required' => 'Timezone is required.',
                'timezone.string' => 'Timezone must be an String.',
            ];

            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            $startDateTime = Carbon::parse($request->start_date_time);
            $endDateTime = Carbon::parse($request->end_date_time);
            $totalMinutes = $startDateTime->diffInMinutes($endDateTime);
            $totalHours = floor($totalMinutes / 60);
            $remainingMinutes = $totalMinutes % 60;
            $totalWorkingTime = sprintf('%02dh%02dmin', $totalHours, $remainingMinutes);

            $workHours = new WorkingHours();
            $workHours->user_id = auth()->user()->id;
            $workHours->start_date_time = $request->start_date_time;
            $workHours->end_date_time = $request->end_date_time;
            $workHours->summary = $request->summary;
            $workHours->timezone = $request->timezone;
            $workHours->total_hours = $totalWorkingTime;
            $workHours->save();



            $data = [
                'status_code' => 200,
                'message' => "Work Hours Successfully Add!",
                'data' => []
            ];

            return response()->json($data);
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
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/work-hours",
     *     summary="Add a new Reminder",
     *     tags={"Project Management"},
     *     description="Create a new message for Project Management.",
     *     operationId="workHours",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         description="Enter Month Name",
     *         example="2024-06",
     *         required=false,
     *         @OA\Schema(
     *             type="string"
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

    public function workHours(Request $request)
    {
        try {
            $rules = [
                'month' => 'nullable|string',
            ];
            $message = [
                'month.nullable' => 'Month is required',
                'month.string' => 'Month must be an String'
            ];
            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }
            $filterMonth = $request->filled('month') ? Carbon::parse($request->month) : Carbon::now();
       
            $workHours = WorkingHours::where('user_id', auth()->user()->id)
                ->whereYear('start_date_time', $filterMonth->year)
                ->whereMonth('start_date_time', $filterMonth->month)
                ->orderByDesc('id')
                ->get(['id', 'start_date_time', 'end_date_time', 'total_hours', 'summary']);
            $format = $workHours->map(function ($workHour) {
                $workHour->start_date_time = Carbon::parse($workHour->start_date_time)->format('Y-m-d H:i:s');
                $workHour->end_date_time = Carbon::parse($workHour->end_date_time)->format('Y-m-d H:i:s');
                $formattedHours = str_replace(['h', 'min'], ['h:', 'mi'], $workHour->total_hours);
                $workHour->total_hours = $formattedHours;
                return $workHour;
            });
            $data = [
                'status_code' => 200,
                'message' => "Work Hours Successfully get!",
                'data' => [
                    'workHours' => $format
                ]
            ];

            return response()->json($data);
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
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/edit-work-hours-summary",
     *     summary="Edit a work hours",
     *     tags={"Project Management"},
     *     description="Create a new message for Project Management.",
     *     operationId="editWorkHoursSummary",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         description="Enter work hour Id",
     *         example="1",
     *         required=true,
     *         @OA\Schema(
     *             type="number"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="summary",
     *         in="query",
     *         description="Enter work hour Summary",
     *         example="",
     *         required=false,
     *         @OA\Schema(
     *             type="string"
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

    public function editWorkHoursSummary(Request $request)
    {
        try {
            $rules = [
                'id' => 'required|integer',
                'summary' => 'nullable|string',
            ];
            $message = [
                'id.required' => 'Id is required',
                'id.integer' => 'Id must be an Integer',
                'summary.string' => 'Summary must be an String'
            ];
            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }
            $workHour = WorkingHours::find($request->id);
            if ($workHour) {
                $workHour->summary = $request->summary;
                $workHour->save();
                $data = [
                    'status_code' => 200,
                    'message' => "Work Hours Successfully get!",
                    'data' => [
                        'workHours' => $workHour
                    ]
                ];
                return response()->json($data);
            } else {
                return response()->json([
                    'status_code' => 400,
                    'message' => "Work Hours Not Found!",
                    'data' => ""
                ]);
            }
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
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/add-note",
     *     summary="Add a new Note",
     *     tags={"Project Management"},
     *     description="Create a new note for Project Management.",
     *     operationId="addNote",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="title",
     *         in="query",
     *         description="Enter Note Title",
     *         example="",
     *         required=true,
     *         @OA\Schema(
     *             type="string"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="description",
     *         in="query",
     *         description="Enter Note description",
     *         example="",
     *         required=false,
     *         @OA\Schema(
     *             type="string"
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

    public function addNote(Request $request)
    {
        try {
            $rules = [
                'title' => 'required|string',
                'description' => 'nullable|string',
            ];
            $message = [
                'title.required' => 'Title is required',
                'title.string' => 'Title must be an String',
                'description.string' => 'Description must be an String'
            ];
            $validator = Validator::make($request->all(), $rules, $message);
            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }
            $note = new Notes();
            $note->user_id = auth()->user()->id;
            $note->title = $request->title;
            $note->description = $request->description;
            $note->save();
            $data = [
                'status_code' => 200,
                'message' => "Note Successfully Add!",
                'data' => [
                    'note' => $note
                ]
            ];
            return response()->json($data);
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
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/note",
     *     summary="new Note list",
     *     tags={"Project Management"},
     *     description="List of note for Project Management.",
     *     operationId="noteLost",
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

    public function notes(Request $request)
    {
        try {
            $data = [
                'status_code' => 200,
                'message' => "Note get Successfully!",
                'data' => [
                    'notes' => Notes::where('user_id', auth()->user()->id)->get()
                ]
            ];
            return response()->json($data);
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
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/note-details",
     *     summary="Note Details",
     *     tags={"Project Management"},
     *     description="Details of note for Project Management.",
     *     operationId="noteDetails",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         description="Enter Note Id",
     *         example="1",
     *         required=true,
     *         @OA\Schema(
     *             type="number"
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

    public function noteDetails(Request $request)
    {
        try {
            $rules = [
                'id' => 'required|integer|exists:notes,id',
            ];

            $message = [
                'id.required' => 'User ID is required.',
                'id.integer' => 'User ID must be an integer.',
                'id.exists' => 'The specified Note does not exist.',
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
            $data = [
                'status_code' => 200,
                'message' => "Note Successfully get!",
                'data' => [
                    'note' => Notes::where('id', $request->id)->first()
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
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/edit-note",
     *     summary="Edit Note",
     *     tags={"Project Management"},
     *     description="Edit note for Project Management.",
     *     operationId="editNotes",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         description="Enter Note Id",
     *         example="1",
     *         required=true,
     *         @OA\Schema(
     *             type="number"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="title",
     *         in="query",
     *         description="Enter Note Title",
     *         example="",
     *         required=true,
     *         @OA\Schema(
     *             type="string"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="description",
     *         in="query",
     *         description="Enter Note Description",
     *         example="",
     *         required=false,
     *         @OA\Schema(
     *             type="string"
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
    public function editNotes(Request $request)
    {
        try {
            $rules = [
                'id' => 'required|integer|exists:notes,id',
                'title' => 'required|string',
                'description' => 'nullable|string',
            ];
            $message = [
                'id.required' => 'Note ID is required.',
                'id.integer' => 'Note ID must be an integer.',
                'id.exists' => 'The specified Note does not exist.',
                'title.required' => 'Title is required.',
                'description.string' => 'Description must be a string.',
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
            $note = Notes::find($request->id);
            $note->title = $request->title;
            $note->description = $request->description;
            $note->save();
            $data = [
                'status_code' => 200,
                'message' => "Note Successfully Updated!",
                'data' => [
                    'note' => $note
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
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/delete-note",
     *     summary="Delete Note",
     *     tags={"Project Management"},
     *     description="Delete note for Project Management.",
     *     operationId="deleteNotes",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         description="Enter Note Id",
     *         example="1",
     *         required=true,
     *         @OA\Schema(
     *             type="number"
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

    public function deleteNote(Request $request)
    {
        try {
            $rules = [
                'id' => 'required|integer|exists:notes,id',
            ];
            $message = [
                'id.required' => 'Note ID is required.',
                'id.integer' => 'Note ID must be an integer.',
                'id.exists' => 'The specified Note does not exist.',
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
            $note = Notes::find($request->id);
            $note->delete();
            $data = [
                'status_code' => 200,
                'message' => "Note Successfully Deleted!",
                'data' => [
                    'note' => $note
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
                'message' => 'Internal Server Error',
                'data' => ''
            ]);
        }
    }


    /**
     * @OA\Post(
     *     path="/api/v1/send-work-hours-email",
     *     summary="Send Work Hours email",
     *     tags={"Project Management"},
     *     description="Send Email of work Hours.",
     *     operationId="sendWorkHoursEmail",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         description="Enter Comma Separated UserId",
     *         example="1,2,3",
     *         required=true,
     *         @OA\Schema(
     *             type="string"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="month",
     *         in="query",
     *         description="Enter Month Year",
     *         example="2024-06",
     *         required=true,
     *         @OA\Schema(
     *             type="string"
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="summary",
     *         in="query",
     *         description="Enter Email Summary",
     *         example="Lorem Ipsum is simply dummy text of the printing and typesetting industry.",
     *         required=false,
     *         @OA\Schema(
     *             type="string"
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
    public function sendWorkHoursEmail(Request $request)
    {
        try {
            $rules = [
                'id' => 'required|string',
                'month' => 'required|string',
                'summary' => 'nullable|string',
            ];
            $message = [
                'id.required' => 'Id is required',
                'id.string' => 'Id must be an String',
                'month.required' => 'Month is required',
                'month.string' => 'Month must be an String',
                'summary.string' => 'Summary must be an String'
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
            $recipient = explode(',', $request->id);
            $filterMonth = Carbon::parse($request->month);
            $uniqueName = auth()->user()->account_id;
            $timestamp = Carbon::now()->timestamp;
            $fileName = "work_hours_{$uniqueName}_{$timestamp}_{$filterMonth->year}_{$filterMonth->month}.xlsx";

            $excelContent = Excel::raw(new WorkHoursExport($request), \Maatwebsite\Excel\Excel::XLSX);
            $tempFilePath = tempnam(sys_get_temp_dir(), $fileName);
            file_put_contents($tempFilePath, $excelContent);
            $email = [];
            foreach ($recipient as $single) {
                $user = User::where('id', $single)->first();
                if (!empty($user)) {
                    $email[] = $user;
                }
            }
            if (count($email) == 0) {
                $data = [
                    'status_code' => 400,
                    'message' => "No valid recipients found.",
                    'data' => []
                ];
                return $this->sendJsonResponse($data);
            }
            $month = $request->month;
            $summary = $request->summary;
            foreach ($email as $singleEmail) {
                if (!empty($singleEmail->email)) {
                    Mail::to($singleEmail->email)->send(new WorkHoursMail($tempFilePath, $month, $fileName,$summary));
                }
            }
            unlink($tempFilePath);
            $data = [
                'status_code' => 200,
                'message' => "Work Hours sent Successfully!",
                'data' => []
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
            return response()->json(['status_code' => 500, 'message' => 'Something went wrong']);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/events-create-update",
     *     summary="Event create or update",
     *     tags={"Project Management"},
     *     description="Event create or update",
     *     operationId="eventsCreateUpdate",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Event Create or Update Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"event_title", "event_description", "event_date", "event_time", "latitude", "longitude", "location_url", "location"},
     *                 @OA\Property(
     *                     property="id",
     *                     type="string",
     *                     example="",
     *                     description="Event id used only update request"
     *                 ),
     *                 @OA\Property(
     *                     property="event_title",
     *                     type="string",
     *                     example="Test Event",
     *                     description="Event Title"
     *                 ),
     *                 @OA\Property(
     *                     property="event_description",
     *                     type="string",
     *                     example="Enter Event Description",
     *                     description="Event Description"
     *                 ),
     *                 @OA\Property(
     *                     property="event_date",
     *                     type="string",
     *                     example="",
     *                     description="Event date"
     *                 ),
     *                 @OA\Property(
     *                     property="event_time",
     *                     type="string",
     *                     example="",
     *                     description="Event Time"
     *                 ),
     *                 @OA\Property(
     *                     property="event_image",
     *                     type="file",
     *                     description="Event Image"
     *                 ),
     *                 @OA\Property(
     *                     property="latitude",
     *                     type="string",
     *                     example="",
     *                     description="Event latitude"
     *                 ),
     *                 @OA\Property(
     *                     property="longitude",
     *                     type="string",
     *                     example="",
     *                     description="Event longitude"
     *                 ),
     *                 @OA\Property(
     *                     property="location_url",
     *                     type="string",
     *                     example="",
     *                     description="Event location URL"
     *                 ),
     *                 @OA\Property(
     *                     property="location",
     *                     type="string",
     *                     example="",
     *                     description="Event location"
     *                 ),
     *                 @OA\Property(
     *                     property="users",
     *                     type="string",
     *                     example="1,2,3,4",
     *                     description="Assign event for comma separated users id"
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
    public function eventsCreateUpdate(Request $request)
    {
        try {
            $rules = [
                'event_title' => 'required|string',
                'event_description' => 'nullable|string',
                'event_date' => 'required|string',
                'event_time' => 'required|string',
                'event_image' => 'nullable|image|mimes:jpeg,jpg,png,webp,svg|max:2048',
                'latitude' => 'required|string',
                'longitude' => 'required|string',
                'location_url' => 'required|string',
                'location' => 'required|string'
            ];

            $message = [
                'event_title.required' => 'Event title is required.',
                'event_title.string' => 'Event title must be an String.',
                'event_description.string' => 'Event description must be an String.',
                'event_date.required' => 'Event date is required.',
                'event_date.string' => 'Event date must be an String.',
                'event_time.required' => 'Event time is required.',
                'event_time.string' => 'Event time must be an String.',
                'event_image.image' => 'Event image must be an image file.',
                'event_image.mimes' => 'Event image must be a JPEG, JPG, PNG,svg, or WebP file.',
                'event_image.max' => 'Event image size must not exceed 2MB.',
                'latitude.required' => 'The latitude is required.',
                'latitude.string' => 'The latitude must be an string.',
                'longitude.required' => 'The longitude is required.',
                'longitude.string' => 'The longitude must be an string.',
                'location_url.required' => 'The location url is required.',
                'location_url.string' => 'The location url must be an string.',
                'location.required' => 'The location is required.',
                'location.string' => 'The location must be an string.'
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

            $eventImageName = NULL;
            if ($request->hasFile('event_image')) {
                $eventImage = $request->file('event_image');
                $eventImageName = imageUpload($eventImage, 'event-image');
                if ($eventImageName == 'upload_failed') {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Event image upload failed',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                } elseif ($eventImageName == 'invalid_image') {
                    $data = [
                        'status_code' => 400,
                        'message' => 'Please Select jpg, jpeg, png, webp, svg File',
                        'data' => ""
                    ];
                    return $this->sendJsonResponse($data);
                }

                // Delete the old image if it exists
                if(@$request->id)
                {
                    $event = ProjectEvent::find($request->id);
                    if ($event->event_image && file_exists(public_path('event-image/' . $event->event_image))) {
                        unlink(public_path('event-image/' . $event->event_image));
                    }
                }
            }

            $receiverIdsArray = $request->users ? explode(',', $request->users) : [];
            $senderId = auth()->user()->id;
            $receiverIdsArray[] = $senderId;
            $uniqueIdsArray = array_unique($receiverIdsArray);
            $mergedIds = implode(',', $uniqueIdsArray);

            $event = $request->id ? ProjectEvent::find($request->id) : new ProjectEvent();
            $isUpdate = $event->exists;
            $event->user_id = auth()->user()->id;
            $event->event_title = $request->event_title;
            $event->event_description = $request->event_description;
            $event->event_image = @$eventImageName ? setAssetPath('event-image/' . $eventImageName) : setAssetPath('assets/media/avatars/blank.png');
            $event->event_date = Carbon::parse($request->event_date)->format('Y-m-d');
            $event->event_time = Carbon::parse($request->event_time)->setTimezone('UTC')->format('H:i:s');
            $event->latitude = $request->latitude;
            $event->longitude = $request->longitude;
            $event->location_url = $request->location_url;
            $event->location = $request->location;
            $event->users = $mergedIds;
            $event->save();

            $receiverIdsArray = $event->users ? explode(',', $event->users) : [];
            $senderId = NULL;
            if (in_array($event->created_by, $receiverIdsArray)) {
                $senderId = $event->created_by;
            }

            foreach ($receiverIdsArray as $receiverId) {
                $messageForNotification = [
                    'id' => $event->id,
                    'sender' => $senderId,
                    'receiver' => $receiverId,
                    'message_type' => "Event",
                    'title' => $request->event_title,
                    'description' => @$request->event_description ? $request->event_description : NULL,
                    'date' => Carbon::parse($request->event_date)->format('Y-m-d'),
                    'time' => Carbon::parse($request->event_time)->setTimezone('UTC')->format('H:i:s'),
                    'users' => $mergedIds,
                ];

                //Push Notification
                $validationResults = validateToken($receiverId);
                $validTokens = [];
                $invalidTokens = [];
                foreach ($validationResults as $result) {
                    $validTokens = array_merge($validTokens, $result['valid']);
                    $invalidTokens = array_merge($invalidTokens, $result['invalid']);
                }
                if (count($invalidTokens) > 0) {
                    foreach ($invalidTokens as $singleInvalidToken) {
                        userDeviceToken::where('token', $singleInvalidToken)->forceDelete();
                    }
                }

                $notification = [
                    'title' => auth()->user()->first_name . ' ' . auth()->user()->last_name,
                    'body' => 'Event: ' . @$request->event_title ? $request->event_title : '',
                    'image' => "",
                ];

                if (count($validTokens) > 0) {
                    sendPushNotification($validTokens, $notification, $messageForNotification);
                }
            }

            $data = [
                'status_code' => 200,
                'message' => $isUpdate ? 'Event Updated successfully!' : 'Event Created Successfully!',
                'data' => [
                    'event' => $event
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
     *     path="/api/v1/events-list",
     *     summary="Event Listing",
     *     tags={"Project Management"},
     *     description="Event Listing",
     *     operationId="eventsList",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         example="1",
     *         description="Enter User Id",
     *         required=true,
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
     *         description="Event Title Filter",
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
    public function eventsList(Request $request)
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
            $user = new User();
            $userData = $user->find($request->id);

            $timezone = @$request->timezone ? $request->timezone : 'UTC';
            $eventTitle = $request->filter;

            $eventList = ProjectEvent::where('created_by', auth()->user()->id)
                        ->when($eventTitle, function ($query, $eventTitle) {
                            return $query->where('event_title', 'like', "%{$eventTitle}%");
                        })
                        ->get()
                        ->map(function($event) use ($timezone) {
                            $event->event_date = Carbon::parse($event->event_date)->setTimezone($timezone)->format('Y-m-d H:i:s');
                            $event->event_time = Carbon::parse($event->event_time)->setTimezone($timezone)->format('h:i a');

                            $event->event_image = @$event->event_image ?  $event->event_image : setAssetPath('assets/media/avatars/blank.png');

                            $userList = [];
                            if($event->users)
                            {
                                // Convert comma-separated string to array
                                $userIds = explode(',', $event->users);

                                // Get the current user's ID
                                $currentUserId = auth()->user()->id;

                                // Remove the current user's ID from the list of user IDs
                                $userIds = array_filter($userIds, function($id) use ($currentUserId) {
                                    return $id != $currentUserId;
                                });

                                // Get the user details based on the remaining user IDs
                                $userList = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'country_code', 'mobile', 'profile'])
                                ->map(function ($user) {
                                    $user->profile = @$user->profile ? setAssetPath('user-profile/' . $user->profile) : setAssetPath('assets/media/avatars/blank.png');
                                    return $user;
                                });
                            }

                            // Assign the user array
                            $event->usersArr = $userList;

                            return $event;
                        });
            
            $data = [
                'status_code' => 200,
                'message' => "Get Data Successfully!",
                'data' => [
                    'eventList' => $eventList,
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
     *     path="/api/v1/events-delete",
     *     summary="Delete Event",
     *     tags={"Project Management"},
     *     description="Delete Event",
     *     operationId="eventsDelete",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="query",
     *         example="1",
     *         description="Enter User Id",
     *         required=true,
     *         @OA\Schema(
     *             type="number",
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="event_id",
     *         in="query",
     *         example="1",
     *         description="Enter Event Id",
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
    public function eventsDelete(Request $request)
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
            $user = new User();
            $userData = $user->find($request->id);

            ProjectEvent::where('created_by', auth()->user()->id)->where('id', $request->event_id)->delete();

            $data = [
                'status_code' => 200,
                'message' => "Event Deleted Successfully!",
                'data' => []
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