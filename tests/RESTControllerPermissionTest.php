<?php
use Ashko\Patris\API\REST_Controller;
use Ashko\Patris\Config;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/api/class-rest-controller.php';

final class RESTControllerPermissionTest extends TestCase {
    private const SECRET = 'ssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssssss';

    protected function setUp(): void {
        $GLOBALS['ashko_test_current_user_can'] = false;
        $GLOBALS['ashko_test_options'][Config::SECRET_OPTION] = self::SECRET;
    }

    public function test_accepts_neutral_patris_secret_header(): void {
        $request = new WP_REST_Request(array('X-Patris-Product-Sync-Secret' => self::SECRET));

        self::assertTrue(REST_Controller::permission($request));
    }

    public function test_rejects_invalid_secret_with_correct_branding(): void {
        $request = new WP_REST_Request(array('X-Patris-Product-Sync-Secret' => 'wrong'));

        $result = REST_Controller::permission($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('ashko_product_sync_unauthorized', $result->get_error_code());
        self::assertSame('A valid Ashco sync credential is required.', $result->get_error_message());
    }

    public function test_woocommerce_manager_permission_bypasses_secret(): void {
        $GLOBALS['ashko_test_current_user_can'] = true;

        self::assertTrue(REST_Controller::permission(new WP_REST_Request()));
    }
}
