<?php

namespace App\Http\Controllers;

use App\Models\Share;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Get transaction history for authenticated user with optional filters.
     * 
     * Supports filtering by:
     * - from_date: Start date for transaction range
     * - to_date: End date for transaction range
     * - group_id: Filter transactions by specific group
     * 
     * Returns transactions with summary:
     * - total_paid: Sum of all paid transactions
     * - total_owed: Sum of all unpaid shares
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Validate optional filters
        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'group_id' => 'nullable|integer|exists:groups,id',
        ], [
            'from_date.date' => 'From date must be a valid date',
            'to_date.date' => 'To date must be a valid date',
            'to_date.after_or_equal' => 'To date must be equal to or after from date',
            'group_id.integer' => 'Group ID must be a number',
            'group_id.exists' => 'The selected group does not exist',
        ]);
        
        // Query transactions for authenticated user
        $query = Transaction::where('user_id', $user->id)
            ->with([
                'share.bill.group',
                'share.bill.creator',
                'user'
            ])
            ->orderBy('created_at', 'desc');
        
        // Apply date range filters
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        
        // Apply group filter
        if ($request->has('group_id')) {
            $query->whereHas('share.bill', function ($q) use ($request) {
                $q->where('group_id', $request->group_id);
            });
        }
        
        // Paginate results (20 per page as per requirements)
        $transactions = $query->paginate(20);
        
        // Calculate summary
        $summary = $this->calculateSummary($user, $request);
        
        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
            'summary' => $summary,
        ]);
    }
    
    /**
     * Calculate transaction summary for the user.
     * 
     * @param \App\Models\User $user
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    private function calculateSummary($user, $request)
    {
        // Calculate total_paid (sum of paid transactions)
        $totalPaidQuery = Transaction::where('user_id', $user->id)
            ->where('status', 'paid');
        
        // Apply same filters to summary
        if ($request->has('from_date')) {
            $totalPaidQuery->whereDate('created_at', '>=', $request->from_date);
        }
        
        if ($request->has('to_date')) {
            $totalPaidQuery->whereDate('created_at', '<=', $request->to_date);
        }
        
        if ($request->has('group_id')) {
            $totalPaidQuery->whereHas('share.bill', function ($q) use ($request) {
                $q->where('group_id', $request->group_id);
            });
        }
        
        $totalPaid = $totalPaidQuery->sum('amount');
        
        // Calculate total_owed (sum of unpaid shares)
        $totalOwedQuery = Share::where('user_id', $user->id)
            ->where('status', 'unpaid');
        
        // Apply group filter to unpaid shares
        if ($request->has('group_id')) {
            $totalOwedQuery->whereHas('bill', function ($q) use ($request) {
                $q->where('group_id', $request->group_id);
            });
        }
        
        $totalOwed = $totalOwedQuery->sum('amount');
        
        return [
            'total_paid' => (float) $totalPaid,
            'total_owed' => (float) $totalOwed,
        ];
    }
}

