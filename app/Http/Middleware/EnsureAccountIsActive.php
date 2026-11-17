<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Un compte suspendu par l'administrateur ne peut plus rien faire, même avec
// un jeton obtenu avant la suspension.
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->suspended_at !== null) {
            return response()->json(['message' => 'Ce compte a été suspendu. Contactez l\'équipe NADCEL.'], 403);
        }

        return $next($request);
    }
}
