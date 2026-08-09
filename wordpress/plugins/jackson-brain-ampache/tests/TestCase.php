<?php
/**
 * Shared base for all unit tests: wires Brain Monkey's per-test function interception and
 * stubs the handful of simple WP functions almost every test needs.
 */

namespace JacksonBrain\Ampache\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'__'                  => static fn( $text ) => $text,
				'esc_html__'          => static fn( $text ) => $text,
				'sanitize_text_field' => static fn( $text ) => trim( (string) $text ),
				'sanitize_user'       => static fn( $text ) => trim( (string) $text ),
				'sanitize_key'        => static fn( $text ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $text ) ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
