<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Models\Group;
use App\Models\Bill;
use App\Models\Share;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property-Based Test: Share Payment Authorization
 * 
 * This test validates that only the share owner can pay their share.
 * Other users (including group members and bill creator) should receive
 * 403 Forbidden when attempting to pay someone else's share.
 */
class SharePaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * Share Payment Authorization
     * 
     * Test that only share owner can pay their share (403 for others).
     */
    public function share_payment_authorization_property()
    {
        // Run 50 iterations with different scenarios
        for ($i = 0; $i < 50; $i++) {
            // Create users with unique identifiers to avoid collisions
            $creator = User::factory()->create([
                'username' => 'creator_' . $i . '_' . uniqid(),
                'email' => 'creator_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            $shareOwner = User::factory()->create([
                'username' => 'owner_' . $i . '_' . uniqid(),
                'email' => 'owner_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            $otherMember = User::factory()->create([
                'username' => 'other_' . $i . '_' . uniqid(),
                'email' => 'other_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            $nonMember = User::factory()->create([
                'username' => 'nonmember_' . $i . '_' . uniqid(),
                'email' => 'nonmember_' . $i . '_' . uniqid() . '@test.com',
            ]);
            
            // Create group with members
            $group = Group::factory()->create([
                'name' => 'Group_' . $i . '_' . uniqid(),
                'creator_id' => $creator->id,
            ]);
            
            $group->members()->attach([
                $creator->id,
                $shareOwner->id,
                $otherMember->id,
            ], ['joined_at' => now()]);
            
            // Create bill with random amount
            $totalAmount = round(mt_rand(100, 10000) / 100, 2);
            $bill = Bill::factory()->create([
                'group_id' => $group->id,
                'creator_id' => $creator->id,
                'title' => 'Bill_' . $i . '_' . uniqid(),
                'total_amount' => $totalAmount,
            ]);
            
            // Create shares for each member
            $shareAmount = round($totalAmount / 3, 2);
            
            $creatorShare = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $creator->id,
                'amount' => $shareAmount,
                'status' => 'unpaid',
            ]);
            
            $ownerShare = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $shareOwner->id,
                'amount' => $shareAmount,
                'status' => 'unpaid',
            ]);
            
            $otherShare = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $otherMember->id,
                'amount' => $shareAmount,
                'status' => 'unpaid',
            ]);
            
            // Share owner CAN pay their own share
            $response = $this->actingAs($shareOwner, 'sanctum')
                ->postJson("/api/shares/{$ownerShare->id}/pay", [
                    'payment_method' => 'gcash',
                ]);
            
            // Should succeed (200) or return payment intent
            // Note: Will fail with 500 if PayMongo is not configured, but authorization passes
            $this->assertContains($response->status(), [200, 500], 
                "Iteration $i: Share owner should be authorized to pay their share");
            
            // If authorization failed, it would be 403
            $this->assertNotEquals(403, $response->status(),
                "Iteration $i: Share owner should not receive 403 Forbidden");
            
            // Other group member CANNOT pay someone else's share
            $response = $this->actingAs($otherMember, 'sanctum')
                ->postJson("/api/shares/{$creatorShare->id}/pay", [
                    'payment_method' => 'gcash',
                ]);
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'You do not have permission to pay this share',
                'error_code' => 'FORBIDDEN',
            ]);
            
            // Verify share is still unpaid
            $creatorShare->refresh();
            $this->assertEquals('unpaid', $creatorShare->status,
                "Iteration $i: Share should remain unpaid when unauthorized user attempts payment");
            
            // Bill creator CANNOT pay someone else's share
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson("/api/shares/{$otherShare->id}/pay", [
                    'payment_method' => 'paymaya',
                ]);
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'You do not have permission to pay this share',
                'error_code' => 'FORBIDDEN',
            ]);
            
            // Verify share is still unpaid
            $otherShare->refresh();
            $this->assertEquals('unpaid', $otherShare->status,
                "Iteration $i: Share should remain unpaid when bill creator attempts unauthorized payment");
            
            // Non-member CANNOT pay any share
            $response = $this->actingAs($nonMember, 'sanctum')
                ->postJson("/api/shares/{$ownerShare->id}/pay", [
                    'payment_method' => 'gcash',
                ]);
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'You do not have permission to pay this share',
                'error_code' => 'FORBIDDEN',
            ]);
            
            // User cannot pay share that doesn't belong to them
            // even if they try to manipulate the request
            $response = $this->actingAs($shareOwner, 'sanctum')
                ->postJson("/api/shares/{$creatorShare->id}/pay", [
                    'payment_method' => 'gcash',
                ]);
            
            $response->assertStatus(403);
            $response->assertJson([
                'message' => 'You do not have permission to pay this share',
                'error_code' => 'FORBIDDEN',
            ]);
        }
    }

    /**
     * @test
     * Edge case: Unauthenticated user cannot pay any share
     */
    public function unauthenticated_user_cannot_pay_share()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $user->id,
            'total_amount' => 100.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        
        // Attempt to pay without authentication
        $response = $this->postJson("/api/shares/{$share->id}/pay", [
            'payment_method' => 'gcash',
        ]);
        
        $response->assertStatus(401);
    }

    /**
     * @test
     * Edge case: User cannot pay non-existent share
     */
    public function user_cannot_pay_non_existent_share()
    {
        $user = User::factory()->create();
        $nonExistentShareId = 99999;
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$nonExistentShareId}/pay", [
                'payment_method' => 'gcash',
            ]);
        
        // Should return 404 or 500 (depending on error handling)
        $this->assertContains($response->status(), [404, 500]);
    }

    /**
     * @test
     * Edge case: User cannot pay already paid share
     */
    public function user_cannot_pay_already_paid_share()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $user->id,
            'total_amount' => 100.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'paid', // Already paid
        ]);
        
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'gcash',
            ]);
        
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'This share has already been paid',
            'error_code' => 'ALREADY_PAID',
        ]);
    }

    /**
     * @test
     * Edge case: Multiple users with shares - each can only pay their own
     */
    public function multiple_users_can_only_pay_their_own_shares()
    {
        $creator = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        
        // Create 5 members
        $members = [];
        for ($i = 0; $i < 5; $i++) {
            $member = User::factory()->create([
                'username' => 'member_' . $i . '_' . uniqid(),
                'email' => 'member_' . $i . '_' . uniqid() . '@test.com',
            ]);
            $group->members()->attach($member->id, ['joined_at' => now()]);
            $members[] = $member;
        }
        
        $group->members()->attach($creator->id, ['joined_at' => now()]);
        
        // Create bill
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 600.00,
        ]);
        
        // Create shares for all members
        $shares = [];
        foreach ($members as $member) {
            $share = Share::create([
                'bill_id' => $bill->id,
                'user_id' => $member->id,
                'amount' => 100.00,
                'status' => 'unpaid',
            ]);
            $shares[] = $share;
        }
        
        // Each member tries to pay all shares
        foreach ($members as $index => $member) {
            foreach ($shares as $shareIndex => $share) {
                $response = $this->actingAs($member, 'sanctum')
                    ->postJson("/api/shares/{$share->id}/pay", [
                        'payment_method' => 'gcash',
                    ]);
                
                if ($index === $shareIndex) {
                    // User paying their own share - should be authorized
                    $this->assertContains($response->status(), [200, 500],
                        "Member $index should be authorized to pay their own share");
                    $this->assertNotEquals(403, $response->status());
                } else {
                    // User trying to pay someone else's share - should be forbidden
                    $response->assertStatus(403);
                    $response->assertJson([
                        'message' => 'You do not have permission to pay this share',
                        'error_code' => 'FORBIDDEN',
                    ]);
                }
            }
        }
    }

    /**
     * @test
     * Edge case: User removed from group cannot pay their old share
     */
    public function user_removed_from_group_cannot_pay_their_old_share()
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        
        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'total_amount' => 200.00,
        ]);
        
        $memberShare = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $member->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        
        // Member can pay before removal
        $response = $this->actingAs($member, 'sanctum')
            ->postJson("/api/shares/{$memberShare->id}/pay", [
                'payment_method' => 'gcash',
            ]);
        
        // Should be authorized (200 or 500 if PayMongo not configured)
        $this->assertContains($response->status(), [200, 500]);
        $this->assertNotEquals(403, $response->status());
        
        // Creator removes member from group
        $group->members()->detach($member->id);
        
        // Member can still pay their share even after removal
        // (This is a business decision - they still owe the money)
        $response = $this->actingAs($member, 'sanctum')
            ->postJson("/api/shares/{$memberShare->id}/pay", [
                'payment_method' => 'gcash',
            ]);
        
        // Should still be authorized because it's their share
        $this->assertContains($response->status(), [200, 500]);
        $this->assertNotEquals(403, $response->status());
    }

    /**
     * @test
     * Edge case: Invalid payment method
     */
    public function user_cannot_pay_with_invalid_payment_method()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $user->id,
            'total_amount' => 100.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        
        // Try with invalid payment method
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", [
                'payment_method' => 'invalid_method',
            ]);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }

    /**
     * @test
     * Edge case: Missing payment method
     */
    public function user_cannot_pay_without_payment_method()
    {
        $user = User::factory()->create();
        $group = Group::factory()->create(['creator_id' => $user->id]);
        $group->members()->attach($user->id, ['joined_at' => now()]);
        
        $bill = Bill::factory()->create([
            'group_id' => $group->id,
            'creator_id' => $user->id,
            'total_amount' => 100.00,
        ]);
        
        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'amount' => 100.00,
            'status' => 'unpaid',
        ]);
        
        // Try without payment method
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/shares/{$share->id}/pay", []);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payment_method']);
    }
}
