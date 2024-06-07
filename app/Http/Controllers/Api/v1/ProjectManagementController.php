<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\WorkingHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class ProjectManagementController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/add-work-hours",
     *     summary="Add a new Reminder",
     *     tags={"ProjectManagement"},
     *     description="Create a new message for Project Management.",
     *     operationId="addWorkHours",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Add Message Request",
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"start_date_time","end_date_time"},
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
            ];

            $message = [
                'start_date_time.required' => 'Start Date Time is required.',
                'start_date_time.string' => 'Start Date Time must be an String.',
                'end_date_time.required' => 'End Date Time is required.',
                'end_date_time.string' => 'End Date Time must be an String.',
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
     *     tags={"ProjectManagement"},
     *     description="Create a new message for Project Management.",
     *     operationId="workHours",
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

     public function workHours(Request $request){
         try {
             $workHours = WorkingHours::where('user_id',auth()->user()->id)->get();
             $format = $workHours->map(function ($workHour) {
                $workHour->start_date_time = Carbon::parse($workHour->start_date_time)->format('Y-m-d H:i:s');
                $workHour->end_date_time = Carbon::parse($workHour->end_date_time)->format('Y-m-d H:i:s');
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
             return response()->json(['status_code' => 500,'message' => 'Something went wrong']);
         }
     }
}