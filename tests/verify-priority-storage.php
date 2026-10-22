<?php
/** Local-only storage compatibility checks using disposable tables. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/\.(local|test)$/', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
    throw new RuntimeException( 'Run only with WP-CLI on a disposable .local or .test project.' );
}
require_once WP_PLUGIN_DIR . '/directorist-support-tools/includes/class-plan-priority-repair.php';

use Sovware\DirectoristSupportTools\Plan_Priority_Repair;

global $wpdb;
$plans = $wpdb->prefix . 'dst_priority_storage_qa';
$options = $wpdb->prefix . 'dst_priority_storage_options_qa';
$trigger = $wpdb->prefix . 'dst_priority_storage_failure_qa';
foreach ( [ $plans, $options ] as $table ) {
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
        throw new RuntimeException( 'A QA table already exists; refusing to overwrite it.' );
    }
}
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };

try {
    foreach ( [ [ 'MyISAM', 'InnoDB' ], [ 'MyISAM', 'MyISAM' ], [ 'InnoDB', 'MyISAM' ] ] as [ $plan_engine, $option_engine ] ) {
        $assert( false !== $wpdb->query( "CREATE TABLE `{$plans}` (id bigint unsigned NOT NULL PRIMARY KEY, title varchar(255), listing_display_priority int NULL, price decimal(10,2), allowed_listings int) ENGINE={$plan_engine}" ), 'Could not create test plans' );
        $assert( false !== $wpdb->query( "CREATE TABLE `{$options}` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE={$option_engine}" ), 'Could not create test options' );
        $wpdb->query( "INSERT INTO `{$plans}` VALUES (1,'zero',0,297,5),(2,'null',NULL,49,3),(3,'valid',7,99,10)" );
        $db = clone $wpdb;
        $db->options = $options;
        $repair = new Plan_Priority_Repair();
        foreach ( [ 'db' => $db, 'plans' => $plans ] as $property => $value ) {
            $reflection = new ReflectionProperty( $repair, $property );
            $reflection->setAccessible( true );
            $reflection->setValue( $repair, $value );
        }
        $read_backup = static function () use ( $wpdb, $options ) {
            return maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$options}` WHERE option_name=%s", Plan_Priority_Repair::BACKUP_OPTION ) ) );
        };
        $rows = static function () use ( $wpdb, $plans ) { return $wpdb->get_results( "SELECT * FROM `{$plans}` ORDER BY id", ARRAY_A ); };
        $original = $rows();
        $repair->assert_environment();
        $assert( false === $repair->storage()['transactional'], 'Non-transactional storage was not recognized' );
        $assert( 2 === $repair->scan()['total'], 'Preview mismatch' );
        $assert( 2 === $repair->repair( [ 1, 2, 3 ] ), 'Repair count mismatch' );
        $assert( [ 1 => 0, 2 => null ] === $read_backup()['rows'], 'Backup mismatch' );
        $assert( 0 === $repair->repair( [ 1, 2, 3 ] ), 'Repair is not idempotent' );
        $repaired = $rows();
        $assert( '1' === $repaired[0]['listing_display_priority'] && '1' === $repaired[1]['listing_display_priority'] && '7' === $repaired[2]['listing_display_priority'], 'Invalid repair values' );
        $wpdb->query( "UPDATE `{$plans}` SET listing_display_priority=9 WHERE id=2" );
        $assert( [ 'restored' => 1, 'skipped' => 1 ] === $repair->restore(), 'Restore overwrote a changed priority' );
        $wpdb->query( "UPDATE `{$plans}` SET listing_display_priority=1 WHERE id=2" );
        $assert( [ 'restored' => 1, 'skipped' => 0 ] === $repair->restore(), 'NULL restore failed' );
        $assert( $original === $rows(), 'Repair/restore changed unrelated fields or valid priorities' );
        echo "PASS: {$plan_engine} plans / {$option_engine} options preview, backup, repair, idempotency, restore conflicts, NULL and field preservation\n";

        // A backup write error must stop before any plan is changed.
        $wpdb->query( "CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `{$options}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DST QA backup failure'" );
        $suppressed = $wpdb->suppress_errors( true );
        try {
            try { $repair->repair( [ 1, 2 ] ); throw new Exception( 'Backup failure was ignored' ); }
            catch ( RuntimeException $e ) { $assert( false !== strpos( $e->getMessage(), '0 plan(s)' ), 'Unexpected backup failure result' ); }
        } finally {
            $wpdb->suppress_errors( $suppressed );
            $wpdb->query( "DROP TRIGGER `{$trigger}`" );
        }
        $assert( $original === $rows(), 'A plan changed without a verified backup' );
        echo "PASS: backup write failure prevents plan mutation\n";

        // MyISAM cannot roll back: the original values must already be durable
        // when the second plan update fails, including the first repaired plan.
        $wpdb->query( "CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `{$plans}` FOR EACH ROW BEGIN IF NEW.id=2 AND NEW.listing_display_priority=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DST QA plan failure'; END IF; END" );
        $suppressed = $wpdb->suppress_errors( true );
        try {
            try { $repair->repair( [ 1, 2 ] ); throw new Exception( 'Plan failure was ignored' ); }
            catch ( RuntimeException $e ) { $assert( false !== strpos( $e->getMessage(), '1 plan(s)' ), 'Partial repair count was not reported' ); }
        } finally {
            $wpdb->suppress_errors( $suppressed );
            $wpdb->query( "DROP TRIGGER `{$trigger}`" );
        }
        $partial = $rows();
        $assert( '1' === $partial[0]['listing_display_priority'] && null === $partial[1]['listing_display_priority'], 'Unexpected partial repair' );
        $assert( [ 1 => 0, 2 => null ] === $read_backup()['rows'], 'Durable backup missing after partial failure' );
        $assert( [ 'restored' => 1, 'skipped' => 1 ] === $repair->restore(), 'Partial repair could not be restored' );
        $assert( $original === $rows(), 'Partial repair restore changed other data' );
        echo "PASS: partial write failure is reported, backed up and recoverable\n";
        $wpdb->query( "DROP TABLE `{$plans}`, `{$options}`" );
    }
} finally {
    $wpdb->query( "DROP TRIGGER IF EXISTS `{$trigger}`" );
    $wpdb->query( "DROP TABLE IF EXISTS `{$plans}`, `{$options}`" );
    echo "Storage QA tables removed; real plans/options were not modified\n";
}
