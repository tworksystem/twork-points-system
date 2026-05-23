<?php

namespace TWorkPointsSystem\Tests;

use Brain\Monkey\Functions;
use ReflectionMethod;
use stdClass;
use TWork_Points_System;

class PointsTransactionTest extends TestCase
{
    private TWork_Points_System $system;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb = new WPDBStub();

        $this->system = TWork_Points_System::get_instance();
    }

    public function test_create_transaction_prevents_duplicate_by_order(): void
    {
        global $wpdb;
        $wpdb->reset();
        $wpdb->enqueueGetVarResult(99); // simulate duplicate match

        $method = new ReflectionMethod(TWork_Points_System::class, 'create_transaction');
        $method->setAccessible(true);

        $transactionId = $method->invoke($this->system, [
            'user_id' => 123,
            'type' => 'earn',
            'points' => 250,
            'description' => 'Order points',
            'order_id' => 'ABC-123',
        ]);

        $this->assertSame(99, $transactionId, 'Duplicate transactions should return existing ID');
        $this->assertCount(
            0,
            $wpdb->insertLog,
            'No new rows should be inserted when duplicate is detected'
        );
    }

    public function test_refund_points_creates_refund_and_adjust_entries(): void
    {
        global $wpdb;
        $wpdb->reset();

        $wpdb->setRowResult((object)[
            'id' => 501,
            'points' => 120,
        ]);

        $metaStore = [];

        Functions\when('get_post_meta')->alias(static function ($orderId, $key) {
            if ($key === '_points_refunded') {
                return false;
            }

            if ($key === '_points_redeemed') {
                return 300;
            }

            return null;
        });

        Functions\when('update_post_meta')->alias(static function ($orderId, $key, $value) use (&$metaStore) {
            $metaStore[$key] = $value;
            return true;
        });

        $order = new class {
            private array $notes = [];

            public function get_user_id()
            {
                return 123;
            }

            public function get_id()
            {
                return 777;
            }

            public function add_order_note($note)
            {
                $this->notes[] = $note;
            }

            public function getNotes(): array
            {
                return $this->notes;
            }
        };

        Functions\when('wc_get_order')->justReturn($order);

        $this->system->refund_points_on_order_cancellation(777);

        $this->assertCount(
            2,
            $wpdb->insertLog,
            'Refund should insert refund and adjustment transactions'
        );

        $refundEntry = $wpdb->insertLog[0]['data'];
        $this->assertSame('refund', $refundEntry['type']);
        $this->assertSame(300, $refundEntry['points']);

        $adjustEntry = $wpdb->insertLog[1]['data'];
        $this->assertSame('adjust', $adjustEntry['type']);
        $this->assertSame(-120, $adjustEntry['points']);

        $this->assertArrayHasKey('_points_refunded', $metaStore);
        $this->assertTrue($metaStore['_points_refunded']);
    }

    public function test_create_transaction_inserts_row_and_invalidates_cache(): void
    {
        global $wpdb;
        $wpdb->reset();

        $deletedMeta = [];

        Functions\when('delete_user_meta')->alias(static function ($userId, $key) use (&$deletedMeta) {
            $deletedMeta[] = [$userId, $key];
            return true;
        });

        Functions\when('update_user_meta')->returnArg(0);
        Functions\when('get_user_meta')->returnFalse();

        $method = new ReflectionMethod(TWork_Points_System::class, 'create_transaction');
        $method->setAccessible(true);

        $transactionId = $method->invoke($this->system, [
            'user_id' => 456,
            'type' => 'earn',
            'points' => 180,
            'description' => 'Test earn transaction',
            'order_id' => '',
        ]);

        $this->assertSame(1, $transactionId);
        $this->assertCount(1, $wpdb->insertLog);

        $insert = $wpdb->insertLog[0];
        $this->assertSame('wp_twork_point_transactions', $insert['table']);
        $this->assertSame(456, $insert['data']['user_id']);
        $this->assertSame('earn', $insert['data']['type']);
        $this->assertSame(180, $insert['data']['points']);

        $this->assertNotEmpty($deletedMeta, 'Cache invalidation should delete user meta');
        $this->assertSame([456, 'points_balance_cache'], $deletedMeta[0]);

        $this->assertContains('START TRANSACTION', $wpdb->queries, 'Should start DB transaction');
        $this->assertContains('COMMIT', $wpdb->queries, 'Should commit DB transaction');
    }

    public function test_create_transaction_rolls_back_redeem_when_balance_insufficient(): void
    {
        global $wpdb;
        $wpdb->reset();

        $wpdb->setRowResult([
            'earned' => 50,
            'redeemed' => 0,
            'expired' => 0,
        ]);

        Functions\when('delete_user_meta')->justReturn(true);
        Functions\when('update_user_meta')->justReturn(true);
        Functions\when('get_user_meta')->returnFalse();

        $method = new ReflectionMethod(TWork_Points_System::class, 'create_transaction');
        $method->setAccessible(true);

        $result = $method->invoke($this->system, [
            'user_id' => 789,
            'type' => 'redeem',
            'points' => 100,
            'description' => 'Attempted redeem',
            'order_id' => 'ORDER-1',
        ]);

        $this->assertFalse($result, 'Redeem should fail when balance insufficient');
        $this->assertCount(0, $wpdb->insertLog, 'No rows should be inserted on failure');
        $this->assertContains('ROLLBACK', $wpdb->queries, 'Should rollback transaction on failure');
    }

    public function test_refund_points_skip_when_already_processed(): void
    {
        global $wpdb;
        $wpdb->reset();

        Functions\when('get_post_meta')->alias(static function ($orderId, $key) {
            if ($key === '_points_refunded') {
                return true;
            }

            return null;
        });

        $order = new class {
            public function get_user_id()
            {
                return 321;
            }
        };

        Functions\when('wc_get_order')->justReturn($order);

        $this->system->refund_points_on_order_cancellation(900);

        $this->assertCount(0, $wpdb->insertLog, 'No transactions should be created if refund already processed');
    }
}

