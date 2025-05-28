<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserSignature;
use Log;
use Validator;

class UserSignatureController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/signature_upload",
     *     summary="Upload a signature image",
     *     tags={"User Signature"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"signature_upload"},
     *                 @OA\Property(
     *                     property="signature_upload",
     *                     type="string",
     *                     format="binary",
     *                     description="Signature image file"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Image uploaded successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid image or missing data"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
   public function signatureUpload(Request $request)
{
    try {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'signature_upload' => 'required|image|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 400,
                'message' => $validator->errors()->first(),
                'data' => ""
            ]);
        }

        // Store the file
        $path = $request->file('signature_upload')->store('signatures', 'public');

        // Save to database
        $signature = UserSignature::create([
            'signature_upload' => $path
        ]);

        // Return full URL for the stored signature
        $signature->signature_upload = asset('storage/' . $signature->signature_upload);

        return response()->json([
            'status_code' => 200,
            'message' => 'Signature uploaded successfully',
            'data' => $signature
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
            'message' => 'Something went wrong while uploading the signature.',
            'data' => ""
        ]);
    }
}


    /**
     * @OA\Get(
     *     path="/api/v1/signature_listing",
     *     summary="List uploaded signatures",
     *     tags={"User Signature"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of uploaded signatures"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function signatureListing()
    {
        $signatures = UserSignature::all();

        // Add full URL to each signature_upload
        foreach ($signatures as $signature) {
            $signature->signature_upload = asset('storage/' . $signature->signature_upload);
        }

        return response()->json([
            'data' => $signatures
        ]);
    }
}
