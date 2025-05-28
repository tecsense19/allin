<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SignaturePdf;
use Log;
use Validator;

class SignaturePdfController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/v1/signature_pdf_upload",
     *     summary="Upload a signature PDF",
     *     tags={"Signature PDF"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file_upload"},
     *                 @OA\Property(
     *                     property="file_upload",
     *                     type="string",
     *                     format="binary",
     *                     description="Signature PDF file"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="PDF uploaded successfully"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid file or missing data"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function signaturePdfUpload(Request $request)
{
    try {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'file_upload' => 'required|mimes:pdf|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 400,
                'message' => $validator->errors()->first(),
                'data' => ""
            ]);
        }

        // Store the file
        $path = $request->file('file_upload')->store('signatures', 'public');

        // Save to database
        $signature = SignaturePdf::create([
            'file_upload' => $path
        ]);

        // Convert file path to accessible URL
        $signature->file_upload = asset('storage/' . $signature->file_upload);

        return response()->json([
            'status_code' => 200,
            'message' => 'PDF uploaded successfully',
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
            'message' => 'Something went wrong while uploading the PDF.',
            'data' => ""
        ]);
    }
}


    /**
     * @OA\Get(
     *     path="/api/v1/signature_pdf_listing",
     *     summary="List uploaded signature PDFs",
     *     tags={"Signature PDF"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of uploaded signature PDFs"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function signaturePdfListing()
    {
        $signatures = SignaturePdf::all();

        foreach ($signatures as $signature) {
            $signature->file_upload = asset('storage/' . $signature->file_upload);
        }

        return response()->json([
            'data' => $signatures
        ]);
    }
}
