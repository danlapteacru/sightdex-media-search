<?php
/**
 * Minimal in-memory $wpdb for the options-table lock queries.
 *
 * prepare() returns the SQL with its arguments so query()/get_var() can act on
 * them, and the unique option_name index is modelled by the array keys.
 */
class Fake_Options_Wpdb {
	public $options = 'wp_options';

	/** @var array<string,string> */
	public $rows = array();

	public function prepare( $sql, ...$args ) {
		return array( $sql, $args );
	}

	public function query( $prepared ) {
		list( $sql, $args ) = $prepared;
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			list( $name, $value ) = $args;
			if ( isset( $this->rows[ $name ] ) ) {
				return 0;
			}
			$this->rows[ $name ] = $value;
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			list( $value, $name, $expected ) = $args;
			if ( ! isset( $this->rows[ $name ] ) || $this->rows[ $name ] !== $expected ) {
				return 0;
			}
			$this->rows[ $name ] = $value;
			return 1;
		}
		return false;
	}

	public function get_var( $prepared ) {
		list( , $args ) = $prepared;
		return $this->rows[ $args[0] ] ?? null;
	}

	public function delete( $table, $where ) {
		$name = $where['option_name'];
		if ( isset( $this->rows[ $name ] ) && $this->rows[ $name ] === $where['option_value'] ) {
			unset( $this->rows[ $name ] );
			return 1;
		}
		return 0;
	}
}
