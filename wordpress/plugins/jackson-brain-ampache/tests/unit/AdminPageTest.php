<?php
/**
 * Admin_Page's four manual actions all end in a literal `exit;` after wp_safe_redirect(),
 * which can't be safely exercised in-process (PHP's `exit` language construct isn't
 * interceptable the way a function call is). Scope here is deliberately narrow: proving the
 * security gate order - capability checked before nonce, nonce never replacing it, and
 * neither check ever skipped - since that ordering is exactly what
 * 03-security-privacy.md requires ("A nonce protects every state-changing admin action; a
 * nonce never replaces the capability check").
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use Brain\Monkey\Functions;
use JacksonBrain\Ampache\Admin_Page;
use JacksonBrain\Ampache\Tests\TestCase;

final class WpDieException extends \Exception {}

final class AdminPageTest extends TestCase {

	public function test_action_is_blocked_without_manage_options_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new WpDieException();
			}
		);
		Functions\expect( 'check_admin_referer' )->never();

		$this->expectException( WpDieException::class );

		Admin_Page::handle_clear_snapshot();
	}

	public function test_action_is_blocked_by_an_invalid_nonce_even_with_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->alias(
			static function () {
				throw new WpDieException();
			}
		);

		$this->expectException( WpDieException::class );

		Admin_Page::handle_clear_snapshot();
	}
}
