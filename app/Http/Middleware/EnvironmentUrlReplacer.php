<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

class EnvironmentUrlReplacer
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
        $response = $next($request);
        if (App::environment('local') && $response instanceof \Illuminate\Http\Response) {
            $content = $response->getContent();
            $publicUrl = 'public/';
            $assetUrl = '';
            $modifiedContent = str_replace($publicUrl, $assetUrl, $content);

            // Set the modified content back to the response
            $response->setContent($modifiedContent);
        }

        return $response;
    }
}