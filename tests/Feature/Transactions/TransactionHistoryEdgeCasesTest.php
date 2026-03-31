<?php

namespace Tests\Feature\Transactions;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit Tests: Transaction History Edge Cases
 * 
 * This test suite validates edge cases for the transaction history endpoint
 * including empty results, invalid date ranges, non-existent groups, and zero transactions.
 */
class TransactionHistoryEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Edge case: Transactions with no results
     */
    public function transaction_history_returns_empty_array_when_no_transactions_exist()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Verify response structure
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('summary', $responseData);
        $this->assertArrayHasKey('meta', $responseData);
        
        // Verify empty data array
        $this->assertIsArray($responseData['data']);
        $this->assertCount(0, $responseData['data']);
        
        // Verify summary with zero values
        $this->assertEquals(0, $responseData['summary']['total_paid']);
        $this->assertEquals(0, $responseData['summary']['total_owed']);
        
        // Verify pagination metadata
        $this->assertEquals(1, $responseData['meta']['current_page']);
        $this->assertEquals(0, $responseData['meta']['total']);
    }

    /**
     * @test
     * Edge case: Transactions with invalid date range (from_date after to_date)
     */
    public function transaction_history_returns_validation_error_for_invalid_date_range()
    {
        $user = User::factory()->create();
        
        // Create a transaction to ensure data exists
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);
        
        Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_test_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Request with from_date after to_date
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?from_date=2024-12-31&to_date=2024-01-01');
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['to_date']);
        
        $errors = $response->json('errors');
        $this->assertStringContainsString(
            'To date must be equal to or after from date',
            $errors['to_date'][0]
        );
    }

    /**
     * @test
     * Edge case: Transactions with malformed date format
     */
    public function transaction_history_returns_validation_error_for_malformed_date()
    {
        $user = User::factory()->create();
        
        // Request with invalid date format
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?from_date=invalid-date');
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['from_date']);
        
        $errors = $response->json('errors');
        $this->assertStringContainsString(
            'From date must be a valid date',
            $errors['from_date'][0]
        );
    }

    /**
     * @test
     * Edge case: Transactions for non-existent group
     */
    public function transaction_history_returns_validation_error_for_non_existent_group()
    {
        $user = User::factory()->create();
        
        // Request with non-existent group_id
        $nonExistentGroupId = 99999;
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/transactions?group_id={$nonExistentGroupId}");
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['group_id']);
        
        $errors = $response->json('errors');
        $this->assertStringContainsString(
            'The selected group does not exist',
            $errors['group_id'][0]
        );
    }

    /**
     * @test
     * Edge case: Transactions for group user is not a member of
     */
    public function transaction_history_returns_empty_for_group_user_is_not_member_of()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        
        // Create group without adding user as member
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id], ['joined_at' => now()]);
        
        // Create transaction for creator in this group
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $creator->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);
        
        Transaction::create([
            'share_id' => $share->id,
            'user_id' => $creator->id,
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_test_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // User requests transactions for group they're not a member of
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/transactions?group_id={$group->id}");
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Should return empty array (user has no transactions in this group)
        $this->assertCount(0, $responseData['data']);
        $this->assertEquals(0, $responseData['summary']['total_paid']);
        $this->assertEquals(0, $responseData['summary']['total_owed']);
    }

    /**
     * @test
     * Edge case: Summary with zero transactions
     */
    public function transaction_history_summary_shows_zero_values_when_no_transactions()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Verify summary structure
        $this->assertArrayHasKey('summary', $responseData);
        $summary = $responseData['summary'];
        
        $this->assertArrayHasKey('total_paid', $summary);
        $this->assertArrayHasKey('total_owed', $summary);
        
        // Verify zero values
        $this->assertEquals(0, $summary['total_paid']);
        $this->assertEquals(0, $summary['total_owed']);
        
        // Verify types (should be numeric, not null)
        $this->assertIsNumeric($summary['total_paid']);
        $this->assertIsNumeric($summary['total_owed']);
    }

    /**
     * @test
     * Edge case: Summary calculation with only pending transactions
     */
    public function transaction_history_summary_excludes_pending_transactions_from_total_paid()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);
        
        // Create pending transaction
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);
        
        Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_test_' . uniqid(),
            'status' => 'pending',
            'paid_at' => null,
        ]);
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $summary = $response->json('summary');
        
        // Pending transactions should not be counted in total_paid
        $this->assertEquals(0, $summary['total_paid']);
        
        // Unpaid share should be counted in total_owed
        $this->assertEquals(500.00, $summary['total_owed']);
    }

    /**
     * @test
     * Edge case: Summary calculation with only failed transactions
     */
    public function transaction_history_summary_excludes_failed_transactions_from_total_paid()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 1000.00,
        ]);
        
        // Create failed transaction
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);
        
        Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_test_' . uniqid(),
            'status' => 'failed',
            'paid_at' => null,
        ]);
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions');
        
        $response->assertStatus(200);
        $summary = $response->json('summary');
        
        // Failed transactions should not be counted in total_paid
        $this->assertEquals(0, $summary['total_paid']);
        
        // Unpaid share should be counted in total_owed
        $this->assertEquals(500.00, $summary['total_owed']);
    }

    /**
     * @test
     * Edge case: Date range filter with same from_date and to_date
     */
    public function transaction_history_accepts_same_from_date_and_to_date()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);
        
        $transaction = Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_test_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Set transaction created_at to specific date
        $specificDate = '2024-03-15';
        $transaction->created_at = $specificDate;
        $transaction->save();
        
        // Request with same from_date and to_date
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/transactions?from_date={$specificDate}&to_date={$specificDate}");
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Should return the transaction created on that date
        $this->assertCount(1, $responseData['data']);
        $this->assertEquals($transaction->id, $responseData['data'][0]['id']);
    }

    /**
     * @test
     * Edge case: Unauthenticated request
     */
    public function transaction_history_requires_authentication()
    {
        $response = $this->getJson('/api/transactions');
        
        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.'
        ]);
    }

    /**
     * @test
     * Edge case: Pagination with no results
     */
    public function transaction_history_pagination_works_with_empty_results()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?page=1');
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Verify pagination metadata exists even with no results
        $this->assertArrayHasKey('meta', $responseData);
        $meta = $responseData['meta'];
        
        $this->assertEquals(1, $meta['current_page']);
        $this->assertEquals(1, $meta['last_page']);
        $this->assertEquals(0, $meta['total']);
        $this->assertEquals(20, $meta['per_page']);
    }

    /**
     * @test
     * Edge case: Invalid group_id format (non-numeric)
     */
    public function transaction_history_returns_validation_error_for_non_numeric_group_id()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=invalid');
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['group_id']);
        
        $errors = $response->json('errors');
        $this->assertStringContainsString(
            'Group ID must be a number',
            $errors['group_id'][0]
        );
    }

    /**
     * @test
     * Edge case: Negative group_id
     */
    public function transaction_history_returns_validation_error_for_negative_group_id()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions?group_id=-1');
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['group_id']);
    }

    /**
     * @test
     * Edge case: Future date range
     */
    public function transaction_history_returns_empty_for_future_date_range()
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$user->id, $creator->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 500.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'status' => 'unpaid',
        ]);
        
        Transaction::create([
            'share_id' => $share->id,
            'user_id' => $user->id,
            'amount' => 500.00,
            'payment_method' => 'gcash',
            'paymongo_transaction_id' => 'pay_test_' . uniqid(),
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        
        // Request with future date range
        $futureDate = now()->addYears(1)->format('Y-m-d');
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/transactions?from_date={$futureDate}&to_date={$futureDate}");
        
        $response->assertStatus(200);
        $responseData = $response->json();
        
        // Should return empty array (no transactions in future)
        $this->assertCount(0, $responseData['data']);
        $this->assertEquals(0, $responseData['summary']['total_paid']);
    }
}
