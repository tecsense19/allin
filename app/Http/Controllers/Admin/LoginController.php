<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{
    public function login(){
        if (Auth::check()) {
            return redirect()->back();
        }
        return view('Admin.Auth.login');
    }
    public function loginPost(Request $request){
        $rules = [
            'email' => 'required|email:rfc,dns',
            'password' => 'required',
        ];

        $message = [
            'email.required' => 'Email is required.',
            'email.email' => 'Please enter a valid email address.',
            'password.required' => 'Password is required.',
        ];

        $validator = Validator::make($request->all(), $rules, $message);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            // Authentication passed, redirect to dashboard
            return redirect()->route('dashboard');
        } else {
            // Authentication failed, redirect back with an error message
            return redirect()->back()->with('error', 'Invalid email or password.');
        }
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->route('login');
    }
}
