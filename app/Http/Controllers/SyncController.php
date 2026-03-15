<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;

class SyncController extends Controller
{
    /**
     * Batch sync endpoint - processes multiple operations in a single request
     * Implements server-wins conflict resolution strategy
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sync(Request $request)
    {
        $user = $request->user();
        $operations = $request->input('operations', []);
        
        if (empty($operations)) {
            return response()->json([
                'message' => 'No operations to sync',
                'results' => []
            ], 200);
        }

        $results = [];

        // Process each operation in a transaction
        foreach ($operations as $index => $operation) {
            try {
                $result = DB::transaction(function () use ($operation, $user) {
                    return $this->processOperation($operation, $user);
                });
                
                $results[] = [
                    'index' => $index,
                    'status' => 'success',
                    'data' => $result
                ];
            } catch (\Exception $e) {
                Log::error('Sync operation failed', [
                    'operation' => $operation,
                    'error' => $e->getMessage(),
                    'user_id' => $user->id
                ]);
                
                $results[] = [
                    'index' => $index,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'message' => 'Sync completed',
            'results' => $results
        ], 200);
    }

    /**
     * Process a single sync operation
     * 
     * @param array $operation
     * @param \App\Models\User $user
     * @return array
     */
    private function processOperation($operation, $user)
    {
        $type = $operation['type'] ?? null;
        $data = $operation['data'] ?? [];

        switch ($type) {
            case 'create_group':
                return $this->createGroup($data, $user);
            
            case 'create_bill':
                return $this->createBill($data, $user);
            
            default:
                throw new \Exception("Unknown operation type: {$type}");
        }
    }

    /**
     * Create a group from sync operation
     * 
     * @param array $data
     * @param \App\Models\User $user
     * @return array
     */
    private function createGroup($data, $user)
    {
        // Validate required fields
        if (empty($data['name'])) {
            throw new \Exception('Group name is required');
        }

        // Create group
        $group = Group::create([
            'name' => $data['name'],
            'creator_id' => $user->id
        ]);

        // Add creator as member
        $group->members()->attach($user->id, [
            'joined_at' => now()
        ]);

        // Load members relationship
        $group->load('members');

        return [
            'local_id' => $data['local_id'] ?? null,
            'server_id' => $group->id,
            'group' => $group
        ];
    }

    /**
     * Create a bill from sync operation
     * 
     * @param array $data
     * @param \App\Models\User $user
     * @return array
     */
    private function createBill($data, $user)
    {
        // Validate required fields
        if (empty($data['group_id'])) {
            throw new \Exception('Group ID is required');
        }
        if (empty($data['title'])) {
            throw new \Exception('Bill title is required');
        }
        if (!isset($data['total_amount']) || $data['total_amount'] <= 0) {
            throw new \Exception('Valid total amount is required');
        }
        if (empty($data['bill_date'])) {
            throw new \Exception('Bill date is required');
        }

        // Verify user is group member
        $group = Group::findOrFail($data['group_id']);
        if (!$user->groups->contains($group->id)) {
            throw new \Exception('User is not a member of this group');
        }

        // Create bill
        $bill = Bill::create([
            'group_id' => $data['group_id'],
            'creator_id' => $user->id,
            'title' => $data['title'],
            'total_amount' => $data['total_amount'],
            'bill_date' => $data['bill_date']
        ]);

        // Create shares
        $shares = $data['shares'] ?? [];
        foreach ($shares as $shareData) {
            Share::create([
                'bill_id' => $bill->id,
                'user_id' => $shareData['user_id'],
                'amount' => $shareData['amount'],
                'status' => 'unpaid'
            ]);
        }

        // Load relationships
        $bill->load(['shares.user', 'group', 'creator']);

        return [
            'local_id' => $data['local_id'] ?? null,
            'server_id' => $bill->id,
            'bill' => $bill
        ];
    }

    /**
     * Get last sync timestamp for incremental sync
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function timestamp()
    {
        return response()->json([
            'timestamp' => now()->toIso8601String()
        ], 200);
    }
}
