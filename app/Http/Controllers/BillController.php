<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateBillRequest;
use App\Jobs\SendPushNotification;
use App\Models\Bill;
use App\Models\Group;
use App\Models\Share;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillController extends Controller
{
    /**
     * Create a new bill with equal or custom split.
     * 
     * @param CreateBillRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(CreateBillRequest $request)
    {
        $user = $request->user();
        $group = Group::findOrFail($request->group_id);

        // Verify user is group member
        if (!$group->members()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You must be a member of this group to create bills'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Create bill record
            $bill = Bill::create([
                'group_id' => $request->group_id,
                'creator_id' => $user->id,
                'title' => $request->title,
                'total_amount' => $request->total_amount,
                'bill_date' => $request->bill_date,
            ]);

            // Handle split type
            if ($request->split_type === 'equal') {
                // Calculate equal shares for all group members
                $members = $group->members;
                $memberCount = $members->count();
                $shareAmount = round($request->total_amount / $memberCount, 2);
                
                // Calculate remainder to handle rounding
                $totalAssigned = $shareAmount * $memberCount;
                $remainder = $request->total_amount - $totalAssigned;

                foreach ($members as $index => $member) {
                    $amount = $shareAmount;
                    
                    // Add remainder to the last share to ensure total matches
                    if ($index === $memberCount - 1) {
                        $amount += $remainder;
                    }

                    Share::create([
                        'bill_id' => $bill->id,
                        'user_id' => $member->id,
                        'amount' => $amount,
                        'status' => 'unpaid',
                    ]);
                }
            } else {
                // Custom split - create shares from provided array
                foreach ($request->shares as $shareData) {
                    Share::create([
                        'bill_id' => $bill->id,
                        'user_id' => $shareData['user_id'],
                        'amount' => $shareData['amount'],
                        'status' => 'unpaid',
                    ]);
                }
            }

            // Load bill with shares and user details
            $bill->load(['shares.user', 'creator', 'group']);

            // Dispatch notifications to all members with shares
            foreach ($bill->shares as $share) {
                SendPushNotification::dispatch(
                    $share->user_id,
                    'New Bill Created',
                    "{$user->username} created a bill '{$bill->title}' in {$group->name}. Your share: ₱{$share->amount}",
                    [
                        'type' => 'bill_created',
                        'bill_id' => $bill->id,
                        'group_id' => $group->id,
                        'share_id' => $share->id,
                    ]
                );
            }

            DB::commit();

            return response()->json($bill, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create bill',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List bills where user is a group member.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get all group IDs where user is a member
        $groupIds = $user->groups()->pluck('groups.id');

        // Query bills from those groups
        $query = Bill::whereIn('group_id', $groupIds);

        // Apply optional filters
        if ($request->has('group_id')) {
            $query->where('group_id', $request->group_id);
        }

        if ($request->has('from_date')) {
            $query->where('bill_date', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->where('bill_date', '<=', $request->to_date);
        }

        // Eager load relationships
        $bills = $query->with(['shares.user', 'creator', 'group'])
            ->orderBy('bill_date', 'desc')
            ->get();

        // Add computed attributes to each bill
        $bills->each(function ($bill) {
            $bill->total_paid = $bill->total_paid;
            $bill->total_remaining = $bill->total_amount - $bill->total_paid;
            $bill->is_fully_settled = $bill->is_fully_settled;
        });

        return response()->json(['data' => $bills]);
    }

    /**
     * Get bill details.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $bill = Bill::with(['shares.user', 'creator', 'group'])->findOrFail($id);

        // Verify user is group member
        if (!$bill->group->members()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You must be a member of this group to view this bill'
            ], 403);
        }

        // Add computed attributes
        $bill->total_paid = $bill->total_paid;
        $bill->total_remaining = $bill->total_amount - $bill->total_paid;
        $bill->is_fully_settled = $bill->is_fully_settled;

        return response()->json($bill);
    }
}
