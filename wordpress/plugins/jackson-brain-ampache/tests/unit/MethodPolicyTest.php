<?php
/**
 * Method_Policy is the closed read-method allowlist described in
 * plans/wordpress-ampache-plugin/03-security-privacy.md - the enforceable boundary that
 * does not depend on the Ampache account's own access level.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

use JacksonBrain\Ampache\Method_Policy;
use JacksonBrain\Ampache\Tests\TestCase;

final class MethodPolicyTest extends TestCase {

	public function test_unknown_kind_is_rejected(): void {
		$result = Method_Policy::validate_params( 'not_a_real_kind', array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'jba_unknown_request', $result->get_error_code() );
	}

	public function test_is_allowed_reflects_the_closed_set(): void {
		$this->assertTrue( Method_Policy::is_allowed( 'now_playing' ) );
		$this->assertTrue( Method_Policy::is_allowed( 'stats_recent' ) );
		$this->assertFalse( Method_Policy::is_allowed( 'playlist_delete' ) );
		$this->assertFalse( Method_Policy::is_allowed( 'user_create' ) );
		$this->assertFalse( Method_Policy::is_allowed( 'catalog_action' ) );
	}

	public function test_song_requires_a_positive_integer_id(): void {
		$this->assertInstanceOf( \WP_Error::class, Method_Policy::validate_params( 'song', array() ) );
		$this->assertInstanceOf( \WP_Error::class, Method_Policy::validate_params( 'song', array( 'filter' => 0 ) ) );
		$this->assertInstanceOf( \WP_Error::class, Method_Policy::validate_params( 'song', array( 'filter' => -5 ) ) );

		$this->assertSame(
			array( 'filter' => '42' ),
			Method_Policy::validate_params( 'song', array( 'filter' => 42 ) )
		);
	}

	public function test_stats_recent_forces_its_fixed_params_regardless_of_input(): void {
		$result = Method_Policy::validate_params(
			'stats_recent',
			array(
				'type'   => 'video', // attempted override; must be ignored.
				'filter' => 'newest', // attempted override; must be ignored.
				'limit'  => 5,
			)
		);

		$this->assertSame(
			array(
				'type'   => 'song',
				'filter' => 'recent',
				'limit'  => '5',
			),
			$result
		);
	}

	public function test_limit_is_clamped_to_the_maximum_of_25(): void {
		$result = Method_Policy::validate_params( 'stats_recent', array( 'limit' => 999 ) );

		$this->assertSame( '25', $result['limit'] );
	}

	public function test_limit_of_zero_or_missing_is_invalid(): void {
		$this->assertInstanceOf(
			\WP_Error::class,
			Method_Policy::validate_params( 'stats_recent', array( 'limit' => 0 ) )
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			Method_Policy::validate_params( 'stats_recent', array() )
		);
	}

	public function test_get_art_only_allows_the_song_type(): void {
		$this->assertInstanceOf(
			\WP_Error::class,
			Method_Policy::validate_params(
				'get_art',
				array(
					'filter' => 1,
					'type'   => 'album',
				)
			)
		);

		$result = Method_Policy::validate_params(
			'get_art',
			array(
				'filter' => 1,
				'type'   => 'song',
			)
		);

		$this->assertSame(
			array(
				'filter' => '1',
				'type'   => 'song',
				'size'   => '512x512',
			),
			$result
		);
	}

	public function test_timeline_requires_a_non_empty_username(): void {
		$this->assertInstanceOf(
			\WP_Error::class,
			Method_Policy::validate_params(
				'timeline',
				array(
					'filter' => '',
					'limit'  => 5,
				)
			)
		);

		$result = Method_Policy::validate_params(
			'timeline',
			array(
				'filter' => 'alice',
				'limit'  => 5,
			)
		);

		$this->assertSame( 'alice', $result['filter'] );
	}
}
