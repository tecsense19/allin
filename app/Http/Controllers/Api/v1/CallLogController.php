<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CallLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CallLogController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/call-log",
     *     summary="Store a new call log",
     *     tags={"Call Logs"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"receiver_id", "call_start_time", "call_end_time"},
     *                 @OA\Property(property="id", type="integer", example=""),
     *                 @OA\Property(property="receiver_id", type="integer", format="int64", example=2)           
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Call log created",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=5),
     *             @OA\Property(property="time_min", type="integer", example=20)
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation failed"),
     *     @OA\Response(response=500, description="Internal server error")
     * )
     */
    public function store(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'id' => 'nullable|string',
            'receiver_id' => 'required|exists:users,id',
            'call_start_time' => 'nullable|date',
            'call_end_time' => 'nullable|date|after:call_start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 422,
                'message' => $validator->errors()->first(),
                'data' => ''
            ]);
        }

        $validated = $validator->validated();

        $senderId = auth()->user()->id;

        if(isset($validated['id']) && $validated['id'] != '') {
            $call = CallLog::where('id', $validated['id'])->update([
                'sender_id' => $senderId,
                'receiver_id' => $validated['receiver_id'],
                'call_end_time' => now()
            ]);

            $call = CallLog::where('id', $validated['id'])->first();

            // $start = Carbon::parse($validated['call_start_time']);
            // $end = Carbon::parse($validated['call_end_time']);
            // $timeMin = $start->diffInMinutes($end);
        } else {
            // Store the call log with sender_id
            $call = CallLog::create([
                'sender_id' => $senderId,
                'receiver_id' => $validated['receiver_id'],
                'call_start_time' => now()
            ]);
        }
        

        return response()->json([
            'status_code' => 201,
            'message' => 'Call log created successfully.',
            'data' => $call
        ], 201);
    } catch (\Exception $e) {
        Log::error([
            'method' => __METHOD__,
            'error' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'message' => $e->getMessage()
            ],
            'created_at' => now()->format('Y-m-d H:i:s')
        ]);

        return response()->json([
            'status_code' => 500,
            'message' => 'Something went wrong while saving call log.',
            'data' => ''
        ]);
    }
}

}
