<?php
use Ashko\Patris\API\REST_Controller;
use Ashko\Patris\Sync_Service;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-report-repository.php';
require_once dirname(__DIR__) . '/includes/class-sync-service.php';
require_once dirname(__DIR__) . '/includes/api/class-rest-controller.php';

final class SyncServiceResponseTest extends TestCase {
    public function test_success_response_is_wrapped_with_complete_living_state(): void {
        $service = (new ReflectionClass(Sync_Service::class))->newInstanceWithoutConstructor();
        $response = new ReflectionMethod(Sync_Service::class, 'response');
        $payload = $response->invoke(
            $service,
            'apply',
            'accepted',
            array('event_id' => 'sha256:test-event'),
            array('replay' => false),
            42,
            array('changed_products' => 2),
            array(
                'deferred_products' => 3,
                'deferred_reconciliation' => array(
                    'missing' => 2,
                    'ambiguous' => 1,
                    'details' => array(),
                    'details_truncated' => 0,
                ),
            ),
            false,
            0,
            3
        );

        self::assertSame(array('success', 'data'), array_keys($payload));
        self::assertTrue($payload['success']);
        foreach (array('status', 'event_id', 'retryable', 'pending_products', 'deferred_products') as $field) {
            self::assertArrayHasKey($field, $payload['data']);
            self::assertNotNull($payload['data'][$field]);
        }
        self::assertSame('accepted', $payload['data']['status']);
        self::assertSame('sha256:test-event', $payload['data']['event_id']);
        self::assertFalse($payload['data']['retryable']);
        self::assertSame(0, $payload['data']['pending_products']);
        self::assertSame(3, $payload['data']['deferred_products']);
        self::assertSame(
            array(
                'missing' => 2,
                'ambiguous' => 1,
                'details' => array(),
                'details_truncated' => 0,
            ),
            $payload['data']['deferred_reconciliation']
        );

        $respond = new ReflectionMethod(REST_Controller::class, 'respond');
        $rest_response = $respond->invoke(null, $payload);
        self::assertInstanceOf(WP_REST_Response::class, $rest_response);
        self::assertSame(200, $rest_response->get_status());
        self::assertSame($payload, $rest_response->get_data());
    }

    public function test_report_batches_map_to_the_living_delivery_state_machine(): void {
        $service = (new ReflectionClass(Sync_Service::class))->newInstanceWithoutConstructor();
        $state = new ReflectionMethod(Sync_Service::class, 'report_stage_state');

        self::assertSame(
            array(
                'status' => 'retry_pending',
                'retryable' => true,
                'pending_products' => 7,
                'report_status' => 'report_pending',
            ),
            $state->invoke($service, 'apply', false, 7)
        );
        self::assertSame(
            array(
                'status' => 'retry_pending',
                'retryable' => true,
                'pending_products' => 1,
                'report_status' => 'report_ready',
            ),
            $state->invoke($service, 'apply', true, 0)
        );
        self::assertSame(
            array(
                'status' => 'report_pending',
                'retryable' => true,
                'pending_products' => 4,
                'report_status' => 'report_pending',
            ),
            $state->invoke($service, 'dry-run', false, 4)
        );
        self::assertSame(
            array(
                'status' => 'report_ready',
                'retryable' => false,
                'pending_products' => 0,
                'report_status' => 'report_ready',
            ),
            $state->invoke($service, 'dry-run', true, 0)
        );
    }
}
