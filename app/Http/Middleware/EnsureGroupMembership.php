<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGroupMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        // Get group_id from route parameter or request body
        $groupId = $request->route('id') ?? $request->input('group_id');
        
        if (!$groupId) {
            return response()->json([
                'message' => 'Group ID is required',
                'error_code' => 'GROUP_ID_MISSING'
            ], 400);
        }
        
        // Check if user is a member of the group
        $isMember = $user->groups()->where('groups.id', $groupId)->exists();
        
        if (!$isMember) {
            return response()->json([
                'message' => 'Forbidden',
                'error_code' => 'FORBIDDEN'
            ], 403);
        }
        
        return $next($request);
    }
}
