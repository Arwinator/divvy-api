<?php

namespace App\Http\Controllers;

use App\Jobs\SendPushNotification;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroupController extends Controller
{
    /**
     * Create a new group.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ], [
            'name.required' => 'Group name is required',
            'name.string' => 'Group name must be a text value',
            'name.max' => 'Group name cannot exceed 255 characters',
        ]);

        DB::beginTransaction();
        try {
            // Create the group
            $group = Group::create([
                'name' => $request->name,
                'creator_id' => $request->user()->id,
            ]);

            // Add creator to group_members table manually
            DB::table('group_members')->insert([
                'group_id' => $group->id,
                'user_id' => $request->user()->id,
                'joined_at' => now(),
            ]);

            // Load members relationship
            $group->load('members');

            DB::commit();

            return response()->json($group, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create group'
            ], 500);
        }
    }

    /**
     * List all groups where the user is a member.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $groups = $request->user()
            ->groups()
            ->with('members')
            ->get();

        return response()->json(['data' => $groups]);
    }

    /**
     * Get pending invitations for the authenticated user.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInvitations(Request $request)
    {
        $invitations = GroupInvitation::where('invitee_id', $request->user()->id)
            ->where('status', 'pending')
            ->with(['group', 'inviter'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Transform the data to include group name and inviter username
        $transformedInvitations = $invitations->map(function ($invitation) {
            return [
                'id' => $invitation->id,
                'group_id' => $invitation->group_id,
                'group_name' => $invitation->group->name,
                'inviter_id' => $invitation->inviter_id,
                'inviter_username' => $invitation->inviter->username,
                'status' => $invitation->status,
                'created_at' => $invitation->created_at->toIso8601String(),
            ];
        });

        return response()->json(['data' => $transformedInvitations]);
    }

    /**
     * Send a group invitation.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendInvitation(Request $request, $id)
    {
        $request->validate([
            'identifier' => 'required|string',
        ], [
            'identifier.required' => 'Email or username is required',
            'identifier.string' => 'Email or username must be a text value',
        ]);

        $group = Group::findOrFail($id);

        // Verify sender is group creator
        if ($group->creator_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the group creator can send invitations'
            ], 403);
        }

        // Search user by email or username
        $invitee = User::where('email', $request->identifier)
            ->orWhere('username', $request->identifier)
            ->first();

        if (!$invitee) {
            return response()->json([
                'message' => 'User not found'
            ], 404);
        }

        // Check if user is already a member
        if ($group->members()->where('user_id', $invitee->id)->exists()) {
            return response()->json([
                'message' => 'User is already a member of this group'
            ], 422);
        }

        // Check for existing pending invitation
        $existingInvitation = GroupInvitation::where('group_id', $group->id)
            ->where('invitee_id', $invitee->id)
            ->where('status', 'pending')
            ->first();

        if ($existingInvitation) {
            return response()->json([
                'message' => 'A pending invitation already exists for this user'
            ], 422);
        }

        // Create invitation
        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'inviter_id' => $request->user()->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending',
        ]);

        // Send notification to invitee
        SendPushNotification::dispatch(
            $invitee->id,
            'Group Invitation',
            "{$request->user()->username} invited you to join {$group->name}",
            [
                'type' => 'group_invitation',
                'group_id' => $group->id,
                'invitation_id' => $invitation->id,
            ]
        );

        return response()->json($invitation, 201);
    }

    /**
     * Accept a group invitation.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptInvitation(Request $request, $id)
    {
        $invitation = GroupInvitation::findOrFail($id);

        // Verify invitation belongs to auth user
        if ($invitation->invitee_id !== $request->user()->id) {
            return response()->json([
                'message' => 'This invitation does not belong to you'
            ], 403);
        }

        // Verify invitation status is pending
        if ($invitation->status !== 'pending') {
            return response()->json([
                'message' => 'This invitation has already been processed'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Update invitation status
            $invitation->update(['status' => 'accepted']);

            // Add user to group_members table
            $invitation->group->members()->attach($request->user()->id);

            // Load group with members
            $group = $invitation->group->load('members');

            DB::commit();

            // Send notification to group creator
            SendPushNotification::dispatch(
                $invitation->group->creator_id,
                'Invitation Accepted',
                "{$request->user()->username} joined {$invitation->group->name}",
                [
                    'type' => 'member_joined',
                    'group_id' => $invitation->group->id,
                    'user_id' => $request->user()->id,
                ]
            );

            return response()->json([
                'message' => 'Invitation accepted',
                'group' => $group
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to accept invitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Decline a group invitation.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function declineInvitation(Request $request, $id)
    {
        $invitation = GroupInvitation::findOrFail($id);

        // Verify invitation belongs to auth user
        if ($invitation->invitee_id !== $request->user()->id) {
            return response()->json([
                'message' => 'This invitation does not belong to you'
            ], 403);
        }

        // Update invitation status
        $invitation->update(['status' => 'declined']);

        return response()->json([
            'message' => 'Invitation declined'
        ]);
    }

    /**
     * Remove a member from a group.
     * 
     * @param Request $request
     * @param int $id
     * @param int $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeMember(Request $request, $id, $userId)
    {
        $group = Group::findOrFail($id);

        // Verify auth user is group creator
        if ($group->creator_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Only the group creator can remove members'
            ], 403);
        }

        // Verify target user is not creator
        if ($userId == $group->creator_id) {
            return response()->json([
                'message' => 'Cannot remove the group creator'
            ], 422);
        }

        // Remove user from group_members table
        $group->members()->detach($userId);

        return response()->json([
            'message' => 'Member removed successfully'
        ]);
    }

    /**
     * Leave a group.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function leaveGroup(Request $request, $id)
    {
        $group = Group::findOrFail($id);

        // Verify auth user is not group creator
        if ($group->creator_id === $request->user()->id) {
            return response()->json([
                'message' => 'Group creator cannot leave the group'
            ], 422);
        }

        // Remove user from group_members table
        $group->members()->detach($request->user()->id);

        return response()->json([
            'message' => 'You have left the group'
        ]);
    }
}
