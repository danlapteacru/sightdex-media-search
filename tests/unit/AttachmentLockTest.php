<?php
namespace AIMS\Tests;

use AIMS\Attachment_Lock;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class AttachmentLockTest extends TestCase {
	/** @var \Fake_Options_Wpdb */
	private $db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$n = 0;
		Functions\when( 'wp_generate_password' )->alias( function () use ( &$n ) { return 'tok' . ( ++$n ); } );
		$this->db        = new \Fake_Options_Wpdb();
		$GLOBALS['wpdb'] = $this->db;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_only_one_request_gets_a_free_lock() {
		$lock = new Attachment_Lock();
		$this->assertNotNull( $lock->acquire( 5, 120 ) );
		$this->assertNull( $lock->acquire( 5, 120 ) );
	}

	public function test_locks_are_per_attachment() {
		$lock = new Attachment_Lock();
		$this->assertNotNull( $lock->acquire( 5, 120 ) );
		$this->assertNotNull( $lock->acquire( 6, 120 ) );
	}

	public function test_release_frees_the_lock() {
		$lock  = new Attachment_Lock();
		$token = $lock->acquire( 5, 120 );
		$lock->release( 5, $token );
		$this->assertNotNull( $lock->acquire( 5, 120 ) );
	}

	public function test_expired_lock_is_taken_over_by_exactly_one_request() {
		$this->db->rows['aims_lock_5'] = ( time() - 10 ) . ':old';
		$lock                          = new Attachment_Lock();

		$first  = $lock->acquire( 5, 120 );
		$second = $lock->acquire( 5, 120 );

		$this->assertNotNull( $first );
		$this->assertNull( $second );
	}

	public function test_takeover_fails_if_another_request_swapped_the_row_first() {
		$stale                         = ( time() - 10 ) . ':old';
		$this->db->rows['aims_lock_5'] = $stale;

		// Simulate a competitor winning the compare-and-swap between our SELECT and UPDATE.
		$db = new class() extends \Fake_Options_Wpdb {
			public function get_var( $prepared ) {
				$value                   = parent::get_var( $prepared );
				$this->rows['aims_lock_5'] = ( time() + 120 ) . ':competitor';
				return $value;
			}
		};
		$db->rows        = $this->db->rows;
		$GLOBALS['wpdb'] = $db;

		$this->assertNull( ( new Attachment_Lock() )->acquire( 5, 120 ) );
	}

	public function test_release_does_not_remove_someone_elses_lock() {
		$lock = new Attachment_Lock();
		$lock->acquire( 5, 120 );
		$lock->release( 5, 'not-my-token' );
		$this->assertNull( $lock->acquire( 5, 120 ) );
	}
}
