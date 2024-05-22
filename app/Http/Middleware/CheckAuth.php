<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class CheckAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // If the user is authenticated and trying to access the login page, redirect to dashboard
        if (Auth::check() && $request->route()->named('login')) {
            return redirect()->route('dashboard');
        }

        // If the user is not authenticated and trying to access protected pages, redirect to login
        if (!Auth::check() && !$request->route()->named('login')) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
