<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SignaturePdf;
use App\Models\UserSignature;
use Log;
use Validator;

class SignaturePdfController extends Controller
{
    /**
 * @OA\Post(
 *     path="/api/v1/signature_pdf_upload",
 *     summary="Place signature on uploaded PDF at specified coordinates",
 *     tags={"Signature PDF"},
 *     security={{"bearerAuth":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\MediaType(
 *             mediaType="multipart/form-data",
 *             @OA\Schema(
 *                 required={"file_upload", "signature_id", "x", "y", "pagenumber"},
 *                 @OA\Property(
 *                     property="file_upload",
 *                     type="string",
 *                     format="binary",
 *                     description="PDF file to sign"
 *                 ),
 *                 @OA\Property(
 *                     property="signature_id",
 *                     type="integer",
 *                     description="ID of the signature in DB"
 *                 ),
 *                 @OA\Property(
 *                     property="x",
 *                     type="number",
 *                     description="X coordinate for signature placement"
 *                 ),
 *                 @OA\Property(
 *                     property="y",
 *                     type="number",
 *                     description="Y coordinate for signature placement"
 *                 ),
 *                 @OA\Property(
 *                     property="pagenumber",
 *                     type="integer",
 *                     description="Page number to place the signature on"
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="PDF signed successfully"
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Invalid input or missing data"
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Unauthorized"
 *     )
 * )
 */

    public function signaturePdfUpload(Request $request)
    {
        $login_user_id = auth()->user()->id;
        $validator = Validator::make($request->all(), [
            'file_upload' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'signature_id' => 'required|exists:user_signature,id',
            'x' => 'required|numeric',
            'y' => 'required|numeric',
            'pagenumber' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status_code' => 400,
                'message' => $validator->errors()->first()
            ]);
        }

        try {
            $uploadedFile = $request->file('file_upload');
            $extension = strtolower($uploadedFile->getClientOriginalExtension());

            $signature = UserSignature::findOrFail($request->signature_id);
            $signaturePath = storage_path('app/public/' . $signature->signature_upload);

            $pdf = new \setasign\Fpdi\Fpdi();

            if ($extension === 'pdf') {
                // Handle PDF Upload
                $originalPdfPath = storage_path('app/public/temp_original.pdf');
                $uploadedFile->move(dirname($originalPdfPath), basename($originalPdfPath));

                $pageCount = $pdf->setSourceFile($originalPdfPath);

                for ($page = 1; $page <= $pageCount; $page++) {
                    $tplId = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($tplId);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tplId);

                    if ($page == $request->pagenumber) {
                        $pdf->Image($signaturePath, $request->x, $request->y, 50);
                    }
                }
            } else {
                // Handle Image Upload
                $imagePath = storage_path('app/public/temp_image.' . $extension);
                $uploadedFile->move(dirname($imagePath), basename($imagePath));

                list($width, $height) = getimagesize($imagePath);

                $pdf->AddPage('P', [$width, $height]);
                $pdf->Image($imagePath, 0, 0, $width, $height);
                $pdf->Image($signaturePath, $request->x, $request->y, 50);
            }

            $signedFilename = 'signed_' . uniqid() . '.pdf';
            $signedDirectory = storage_path('app/public/signed');
            if (!file_exists($signedDirectory)) {
                mkdir($signedDirectory, 0755, true);
            }

            $signedFilePath = $signedDirectory . '/' . $signedFilename;
            $pdf->Output($signedFilePath, 'F');

            // Store record in DB
            $storedPath = 'signed/' . $signedFilename;
            $saved = SignaturePdf::create([
                'file_upload' => $storedPath,
                'user_id' => $login_user_id
            ]);

            return response()->json([
                'status_code' => 200,
                'message' => 'File processed successfully',
                'data' => [
                    'id' => $saved->id,
                    'url' => setAssetPath('storage/' . $storedPath)
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status_code' => 500,
                'message' => 'Error processing file',
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
        $login_user_id = auth()->user()->id;
        $signatures = SignaturePdf::where('user_id', $login_user_id)->get();

        foreach ($signatures as $signature) {
            $signature->file_upload = setAssetPath('storage/' . $signature->file_upload);
        }

        return response()->json([
            'data' => $signatures
        ]);
    }
}
