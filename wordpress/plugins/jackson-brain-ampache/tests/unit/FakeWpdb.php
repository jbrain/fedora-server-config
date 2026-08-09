<?php
/**
 * Minimal stand-in for $wpdb: real `prepare()`/`get_var()`/`query()` semantics are overkill
 * here, only the values needed to drive Snapshot_Repository's lock control flow. Its own file
 * so Composer's PSR-4 autoloader finds it regardless of PHPUnit's test-discovery order.
 */

namespace JacksonBrain\Ampache\Tests\Unit;

final class FakeWpdb {
	public string $options = 'wp_options';
	public array $queries  = array();

	public $get_var_return = null;
	public $query_return   = 0;

	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function get_var( $query ) {
		$this->queries[] = $query;

		return $this->get_var_return;
	}

	public function query( $query ) {
		$this->queries[] = $query;

		return $this->query_return;
	}
}
