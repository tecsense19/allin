<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use App\Models\UserSignature;
use Log;
use Validator;

class UserSignatureController extends Controller
{
    /**
 * @OA\Post(
 *     path="/api/v1/signature_upload",
 *     summary="Upload a signature image (Base64)",
 *     tags={"User Signature"},
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"signature_upload"},
 *             @OA\Property(
 *                 property="signature_upload",
 *                 type="string",
 *                 description="Base64 encoded signature image"
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
        // Validate input
        $login_user_id = auth()->user()->id;
        $validator = Validator::make($request->all(), [
            'signature_upload' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 400,
                'message' => $validator->errors()->first(),
                'data' => ""
            ]);
        }

        $base64Image = $request->input('signature_upload');

        // Match Base64 pattern
        if (!preg_match('/^data:image\/(\w+);base64,/', $base64Image, $type)) {
            return response()->json([
                'status_code' => 400,
                'message' => 'Invalid image format',
                'data' => ""
            ]);
        }

        $image = substr($base64Image, strpos($base64Image, ',') + 1);
        $image = base64_decode($image);

        if ($image === false) {
            return response()->json([
                'status_code' => 400,
                'message' => 'Base64 decode failed',
                'data' => ""
            ]);
        }

        $extension = strtolower($type[1]); // jpg, png, gif

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
            return response()->json([
                'status_code' => 400,
                'message' => 'Unsupported image type',
                'data' => ""
            ]);
        }

        $fileName = uniqid() . '.' . $extension;
        $filePath = 'public/signatures/' . $fileName;

        // Store the file
        // Storage::disk('public')->put($filePath, $image);
        Storage::disk('local')->put($filePath, $image);


        // Save to database
        $getFilePath = 'signatures/' . $fileName;
        $signature = UserSignature::create([
            'signature_upload' => $getFilePath,
            'user_id' => $login_user_id,
        ]);


        $signature->signature_upload = setAssetPath('storage/' . $getFilePath);

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
     * @OA\Post(
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
    public function signatureListing(Request $request)
    {
        $login_user_id = auth()->user()->id;

        $signatures = UserSignature::where('user_id', $login_user_id)->get();
        
        // Add full URL to each signature_upload
        foreach ($signatures as $signature) {
            $signature->signature_upload = setAssetPath('storage/' . $signature->signature_upload);
        }
        return response()->json([
            'data' => $signatures
        ]);

    }
}
