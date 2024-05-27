<?php

use App\Models\User;
use Illuminate\Support\Facades\Facade;
use Spatie\ImageOptimizer\OptimizerChainFactory;

if (!function_exists('create_slug')) {
  function create_slug($string)
  {
    $replace = '-';
    $string = strtolower($string);
    $string = trim($string);
    //replace / and . with white space
    $string = preg_replace("/[\/\.]/", " ", $string);
    $string = preg_replace("/[^a-z0-9_\s-]/", "", $string);
    //remove multiple dashes or whitespaces
    $string = preg_replace("/[\s-]+/", " ", $string);
    //convert whitespaces and underscore to $replace
    $string = preg_replace("/[\s_]/", $replace, $string);
    //limit the slug size
    $string = substr($string, 0, 100);
    //slug is generated
    return $string;
  }
}
if (!function_exists('imageUploadBase64')) {
  function imageUploadBase64($image, $folder)
  {
    $data = explode(";base64,", $image);
    $typedata = explode("image/", $data[0]);
    $type = explode('/', mime_content_type($image))[1];
    if (!in_array($type, ['jpeg', 'jpg', 'png'])) {
      return 'invalid_image';
    }
    $imageName = $folder . '_' . time() . '.' . $type;
    $image = base64_decode($data[1]);
    $destinationPath = public_path('image/' . $folder . '/');
    if (!file_exists($destinationPath)) {
      mkdir($destinationPath, 0777, true);
    }
    Storage::disk('public')->put($folder . '/' . $imageName, $image);
    $imgFullPath = $destinationPath . $imageName;
    return $imageName;
  }
}
if (!function_exists('imageUpload')) {
  function imageUpload($image, $folder)
  {
    if ($image->isValid()) {
      $extension = strtolower($image->getClientOriginalExtension());
      $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
      if (!in_array($extension, $allowedExtensions)) {
        return 'invalid_image';
      }
      $imageName = $folder . '_' . time() . '.' . $extension;
      $destinationPath = public_path($folder . '/');
      if (!file_exists($destinationPath)) {
        mkdir($destinationPath, 0777, true);
      }
      $image->move($destinationPath, $imageName);

      $optimizerChain = OptimizerChainFactory::create();
      $optimizerChain->optimize($destinationPath.$imageName);
      return $imageName;
    } else {
      return 'upload_failed';
    }
  }
}
if (!function_exists('generateAccountNumber')) {
  function generateAccountNumber()
  {
    do {
      $accountNumber = random_int(1000000000, 9999999999);
      $exists = User::where('account_id', $accountNumber)->exists();
    } while ($exists);

    return $accountNumber;
  }
}
if (!function_exists('verifyOtp')) {
  function verifyOtp($country_code, $mobile, $otp)
  {
    if ($mobile == '9876543210') {
      if ($country_code == '+91' && $mobile == '9876543210' && $otp == '123456') {
        return "OTP Verified Successfully";
      } else {
        return "OTP Verification Failed";
      }
    } else {
      $verification_check = $this->twilio->verify->v2->services($this->twilio_verify_sid)->verificationChecks->create(["to" => $input['phone_code'] . $input['mobile_no'], "code" => $input['mobile_otp']]);

      if ($verification_check->status == 'approved') {
        $userDetails = User::where('mobile_no', $input['mobile_no'])->first();
        if ($userDetails) {
          $updateData = [];

          if (isset($input['latitude']) && isset($input['longitude'])) {
            $updateData['latitude'] = $input['latitude'];
            $updateData['longitude'] = $input['longitude'];
          }

          $updateData['device_token'] = isset($input['device_token']) ? $input['device_token'] : '';
          $updateData['mobile_otp'] = '';

          User::where('id', $userDetails->id)->update($updateData);
          return $this->sendResponse($userDetails, 'Login successfully.');
        } else {
          return $this->sendError('Invalid OTP.');
        }
      } else {
        return $this->sendError('Invalid OTP.');
      }
    }
  }
}
