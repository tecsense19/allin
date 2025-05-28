<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SignaturePdf;

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
        $request->validate([
            'file_upload' => 'required|mimes:pdf|max:2048'
        ]);

        $path = $request->file('file_upload')->store('signatures', 'public');

        $signature = SignaturePdf::create([
            'file_upload' => $path
           
        ]);

        $signature->file_upload = asset('storage/' . $signature->file_upload);

        return response()->json([
            'message' => 'PDF uploaded successfully',
            'data' => $signature
        ]);
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
