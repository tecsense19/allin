<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserSignature;

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
        $request->validate([
            'signature_upload' => 'required|image|max:2048'
        ]);

        $path = $request->file('signature_upload')->store('signatures', 'public');

        $signature = UserSignature::create([
            'signature_upload' => $path
        ]);

        // Return full URL
        $signature->signature_upload = asset('storage/' . $signature->signature_upload);

        return response()->json([
            'message' => 'Signature uploaded successfully',
            'data' => $signature
        ]);
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
