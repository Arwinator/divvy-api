<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\Bill;
use App\Models\FcmToken;
use App\Models\Group;
use App\Models\GroupInvitation;
use App\Models\Share;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Property-Based Test: Notification Delivery on Events
 * 
 * This test validates that notifications are sent for all specified events:
 * 1. Group invitation sent → notification to invitee
 * 2. Invitation accepted → notification to group creator
 * 3. Bill created → notifications to all members with shares
 * 4. Payment completed → notification to bill creator
 * 5. Bill fully settled → notifications to all group members
 */
class NotificationDeliveryOnEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * Helper method to create an FCM token for a user
     */
    private function createFcmToken(User $user): FcmToken
    {
        return FcmToken::create([
            'user_id' => $user->id,
            'device_id' => 'device_' . uniqid(),
            'token' => 'fcm_token_' . uniqid(),
            'status' => 'active',
        ]);
    }

    /**
     * @test
     * Notification Delivery on Events
     * 
     * Test that notifications are sent for all specified events.
     * Runs 30 iterations with different scenarios to verify property holds.
     */
    public function notification_delivery_on_events_property()
    {
        for ($i = 0; $i < 30; $i++) {
            Queue::fake(); // Reset queue for each iteration

            // Create users
            $creator = User::factory()->create();
            $invitee = User::factory()->create();
            $member1 = User::factory()->create();
            $member2 = User::factory()->create();

            // Create FCM tokens for all users
            $this->createFcmToken($creator);
            $this->createFcmToken($invitee);
            $this->createFcmToken($member1);
            $this->createFcmToken($member2);

            // Create group with creator
            $group = Group::factory()->create(['creator_id' => $creator->id]);
            $group->members()->attach($creator->id, ['joined_at' => now()]);
            $group->members()->attach($member1->id, ['joined_at' => now()]);
            $group->members()->attach($member2->id, ['joined_at' => now()]);

            // Event 1: Group invitation sent → notification to invitee
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson("/api/groups/{$group->id}/invitations", [
                    'identifier' => $invitee->email,
                ]);

            $response->assertStatus(201);
            Queue::assertPushed(SendPushNotification::class, function ($job) use ($invitee) {
                return $job->userId === $invitee->id
                    && str_contains($job->title, 'Group Invitation');
            });

            $invitationId = $response->json('id');

            // Event 2: Invitation accepted → notification to group creator
            Queue::fake(); // Reset queue
            $response = $this->actingAs($invitee, 'sanctum')
                ->postJson("/api/invitations/{$invitationId}/accept");

            $response->assertStatus(200);
            Queue::assertPushed(SendPushNotification::class, function ($job) use ($creator) {
                return $job->userId === $creator->id
                    && str_contains($job->title, 'Invitation Accepted');
            });

            // Event 3: Bill created → notifications to all members with shares
            Queue::fake(); // Reset queue
            $billAmount = rand(100, 1000);
            $response = $this->actingAs($creator, 'sanctum')
                ->postJson('/api/bills', [
                    'title' => 'Test Bill ' . $i,
                    'total_amount' => $billAmount,
                    'bill_date' => now()->format('Y-m-d'),
                    'group_id' => $group->id,
                    'split_type' => 'equal',
                ]);

            $response->assertStatus(201);
            $billId = $response->json('id');

            // Verify notifications sent to all members (creator, member1, member2, invitee)
            $memberIds = [$creator->id, $member1->id, $member2->id, $invitee->id];
            foreach ($memberIds as $memberId) {
                Queue::assertPushed(SendPushNotification::class, function ($job) use ($memberId) {
                    return $job->userId === $memberId
                        && str_contains($job->title, 'New Bill Created');
                });
            }

            // Event 4: Payment completed → notification to bill creator
            $bill = Bill::find($billId);
            $share = $bill->shares()->where('user_id', $member1->id)->first();

            // Create a pending transaction
            $transaction = Transaction::create([
                'share_id' => $share->id,
                'user_id' => $member1->id,
                'amount' => $share->amount,
                'payment_method' => 'gcash',
                'status' => 'pending',
            ]);

            Queue::fake(); // Reset queue

            // Simulate payment.paid webhook
            $webhookPayload = [
                'data' => [
                    'type' => 'payment.paid',
                    'id' => 'evt_' . uniqid(),
                    'attributes' => [
                        'data' => [
                            'id' => 'pay_' . uniqid(),
                            'attributes' => [
                                'metadata' => [
                                    'share_id' => $share->id,
                                    'user_id' => $member1->id,
                                    'bill_id' => $bill->id,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $paymentService = app(\App\Services\PaymentService::class);
            $paymentService->handleWebhook($webhookPayload);

            // Verify notification sent to bill creator
            Queue::assertPushed(SendPushNotification::class, function ($job) use ($creator) {
                return $job->userId === $creator->id
                    && str_contains($job->title, 'Payment Received');
            });

            // Event 5: Bill fully settled → notifications to all group members
            Queue::fake(); // Reset queue

            // Pay remaining shares
            $remainingShares = $bill->shares()->where('status', 'unpaid')->get();
            foreach ($remainingShares as $remainingShare) {
                $remainingTransaction = Transaction::create([
                    'share_id' => $remainingShare->id,
                    'user_id' => $remainingShare->user_id,
                    'amount' => $remainingShare->amount,
                    'payment_method' => 'gcash',
                    'status' => 'pending',
                ]);

                $webhookPayload = [
                    'data' => [
                        'type' => 'payment.paid',
                        'id' => 'evt_' . uniqid(),
                        'attributes' => [
                            'data' => [
                                'id' => 'pay_' . uniqid(),
                                'attributes' => [
                                    'metadata' => [
                                        'share_id' => $remainingShare->id,
                                        'user_id' => $remainingShare->user_id,
                                        'bill_id' => $bill->id,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];

                $paymentService->handleWebhook($webhookPayload);
            }

            // Verify notifications sent to all group members for bill settlement
            foreach ($memberIds as $memberId) {
                Queue::assertPushed(SendPushNotification::class, function ($job) use ($memberId) {
                    return $job->userId === $memberId
                        && str_contains($job->title, 'Bill Fully Settled');
                });
            }
        }
    }

    /**
     * @test
     * Scenario: Group invitation notification sent to invitee
     */
    public function group_invitation_sends_notification_to_invitee()
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $this->createFcmToken($invitee);

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson("/api/groups/{$group->id}/invitations", [
                'identifier' => $invitee->email,
            ]);

        $response->assertStatus(201);

        Queue::assertPushed(SendPushNotification::class, function ($job) use ($invitee, $creator, $group) {
            return $job->userId === $invitee->id
                && $job->title === 'Group Invitation'
                && str_contains($job->body, $creator->username)
                && str_contains($job->body, $group->name);
        });
    }

    /**
     * @test
     * Scenario: Invitation accepted notification sent to group creator
     */
    public function invitation_accepted_sends_notification_to_creator()
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $this->createFcmToken($creator);

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach($creator->id, ['joined_at' => now()]);

        $invitation = GroupInvitation::create([
            'group_id' => $group->id,
            'inviter_id' => $creator->id,
            'invitee_id' => $invitee->id,
            'status' => 'pending',
        ]);

        Queue::fake();

        $response = $this->actingAs($invitee, 'sanctum')
            ->postJson("/api/invitations/{$invitation->id}/accept");

        $response->assertStatus(200);

        Queue::assertPushed(SendPushNotification::class, function ($job) use ($creator, $invitee, $group) {
            return $job->userId === $creator->id
                && $job->title === 'Invitation Accepted'
                && str_contains($job->body, $invitee->username)
                && str_contains($job->body, $group->name);
        });
    }

    /**
     * @test
     * Scenario: Bill created notification sent to all members with shares
     */
    public function bill_created_sends_notifications_to_all_members()
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $this->createFcmToken($creator);
        $this->createFcmToken($member1);
        $this->createFcmToken($member2);

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member1->id, $member2->id], ['joined_at' => now()]);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/bills', [
                'title' => 'Dinner',
                'total_amount' => 300,
                'bill_date' => now()->format('Y-m-d'),
                'group_id' => $group->id,
                'split_type' => 'equal',
            ]);

        $response->assertStatus(201);

        // Verify notifications sent to all 3 members
        Queue::assertPushed(SendPushNotification::class, 3);

        foreach ([$creator, $member1, $member2] as $member) {
            Queue::assertPushed(SendPushNotification::class, function ($job) use ($member) {
                return $job->userId === $member->id
                    && $job->title === 'New Bill Created';
            });
        }
    }

    /**
     * @test
     * Scenario: Payment completed notification sent to bill creator
     */
    public function payment_completed_sends_notification_to_bill_creator()
    {
        $creator = User::factory()->create();
        $payer = User::factory()->create();

        $this->createFcmToken($creator);

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $payer->id], ['joined_at' => now()]);

        $bill = Bill::create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'title' => 'Lunch',
            'total_amount' => 200,
            'bill_date' => now(),
        ]);

        $share = Share::create([
            'bill_id' => $bill->id,
            'user_id' => $payer->id,
            'amount' => 100,
            'status' => 'unpaid',
        ]);

        $transaction = Transaction::create([
            'share_id' => $share->id,
            'user_id' => $payer->id,
            'amount' => 100,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);

        Queue::fake();

        // Simulate payment.paid webhook
        $webhookPayload = [
            'data' => [
                'type' => 'payment.paid',
                'id' => 'evt_test',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_test',
                        'attributes' => [
                            'metadata' => [
                                'share_id' => $share->id,
                                'user_id' => $payer->id,
                                'bill_id' => $bill->id,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $paymentService = app(\App\Services\PaymentService::class);
        $paymentService->handleWebhook($webhookPayload);

        Queue::assertPushed(SendPushNotification::class, function ($job) use ($creator, $payer) {
            return $job->userId === $creator->id
                && $job->title === 'Payment Received'
                && str_contains($job->body, $payer->username);
        });
    }

    /**
     * @test
     * Scenario: Bill fully settled notification sent to all group members
     */
    public function bill_fully_settled_sends_notifications_to_all_members()
    {
        $creator = User::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $this->createFcmToken($creator);
        $this->createFcmToken($member1);
        $this->createFcmToken($member2);

        $group = Group::factory()->create(['creator_id' => $creator->id]);
        $group->members()->attach([$creator->id, $member1->id, $member2->id], ['joined_at' => now()]);

        $bill = Bill::create([
            'group_id' => $group->id,
            'creator_id' => $creator->id,
            'title' => 'Dinner',
            'total_amount' => 300,
            'bill_date' => now(),
        ]);

        // Create shares for all members
        $share1 = Share::create(['bill_id' => $bill->id, 'user_id' => $creator->id, 'amount' => 100, 'status' => 'unpaid']);
        $share2 = Share::create(['bill_id' => $bill->id, 'user_id' => $member1->id, 'amount' => 100, 'status' => 'unpaid']);
        $share3 = Share::create(['bill_id' => $bill->id, 'user_id' => $member2->id, 'amount' => 100, 'status' => 'unpaid']);

        // Pay first two shares
        foreach ([$share1, $share2] as $share) {
            $transaction = Transaction::create([
                'share_id' => $share->id,
                'user_id' => $share->user_id,
                'amount' => $share->amount,
                'payment_method' => 'gcash',
                'status' => 'pending',
            ]);

            $webhookPayload = [
                'data' => [
                    'type' => 'payment.paid',
                    'id' => 'evt_' . uniqid(),
                    'attributes' => [
                        'data' => [
                            'id' => 'pay_' . uniqid(),
                            'attributes' => [
                                'metadata' => [
                                    'share_id' => $share->id,
                                    'user_id' => $share->user_id,
                                    'bill_id' => $bill->id,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $paymentService = app(\App\Services\PaymentService::class);
            $paymentService->handleWebhook($webhookPayload);
        }

        Queue::fake(); // Reset queue before final payment

        // Pay the last share (this should trigger bill fully settled notifications)
        $transaction3 = Transaction::create([
            'share_id' => $share3->id,
            'user_id' => $share3->user_id,
            'amount' => $share3->amount,
            'payment_method' => 'gcash',
            'status' => 'pending',
        ]);

        $webhookPayload = [
            'data' => [
                'type' => 'payment.paid',
                'id' => 'evt_final',
                'attributes' => [
                    'data' => [
                        'id' => 'pay_final',
                        'attributes' => [
                            'metadata' => [
                                'share_id' => $share3->id,
                                'user_id' => $share3->user_id,
                                'bill_id' => $bill->id,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $paymentService = app(\App\Services\PaymentService::class);
        $paymentService->handleWebhook($webhookPayload);

        // Verify notifications sent to all 3 members for bill settlement
        Queue::assertPushed(SendPushNotification::class, function ($job) use ($creator) {
            return $job->userId === $creator->id && $job->title === 'Bill Fully Settled';
        });

        Queue::assertPushed(SendPushNotification::class, function ($job) use ($member1) {
            return $job->userId === $member1->id && $job->title === 'Bill Fully Settled';
        });

        Queue::assertPushed(SendPushNotification::class, function ($job) use ($member2) {
            return $job->userId === $member2->id && $job->title === 'Bill Fully Settled';
        });
    }
}
