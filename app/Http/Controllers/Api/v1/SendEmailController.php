<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\SendEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\AttachmentMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SendEmailController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/send-email",
     *     summary="Send email with attachment",
     *     description="Sends an email with optional file attachment to multiple recipients",
     *     operationId="sendEmail",
     *     security={{"bearerAuth":{}}}, 
     *     tags={"Emails"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"emails", "file_upload"},
     *                 @OA\Property(
     *                     property="emails",
     *                     type="string",
     *                     description="Comma-separated email addresses",
     *                     example="xyz@gmail.com,abc@gmail.com"
     *                 ),
     *                 @OA\Property(
     *                     property="file_upload",
     *                     type="string",
     *                     format="binary",
     *                     description="File to attach"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Emails sent successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function sendEmail(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'emails' => 'required|string',
                'file_upload' => 'required|file|mimes:pdf,jpg,png,doc,docx|max:2048'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status_code' => 400,
                    'message' => $validator->errors()->first(),
                    'data' => ""
                ]);
            }

            $emails = array_filter(array_map('trim', explode(',', $request->emails)));

            if (empty($emails)) {
                return response()->json([
                    'status_code' => 400,
                    'message' => 'No valid email addresses provided.',
                    'data' => ""
                ]);
            }

            // Store file
            $file = $request->file('file_upload');
            $filePath = $file->store('attachments');
            $fileName = $file->getClientOriginalName();

            $userId = auth()->user()->id;

            foreach ($emails as $email) {
                Mail::to($email)->send(new AttachmentMail($filePath));

                // Add details to result array
                $sentDetails = [
                    'user_id' => $userId,
                    'email' => $email,
                    'file_name' => $fileName,
                ];

                SendEmail::create($sentDetails);
            }

            return response()->json([
                'status_code' => 200,
                'message' => 'Emails sent successfully.',
                'data' => $sentDetails
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

            return response()->json([
                'status_code' => 500,
                'message' => 'Something went wrong while sending emails.',
                'data' => ""
            ]);
        }
    }

}
