<?php
/** Local-only WP-CLI integration test with disposable tables, no customer fixtures. */
use Sovware\DirectoristSupportTools\Listing_Migration_Recovery as Recovery;
use DirectoristPricingPlan\Database\Migrations\ListingPlanMetaMigration;
use DirectoristPricingPlan\WpMVC\App;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '~(?:\.local|\.test)(?::\d+)?$~', (string) wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) ) ) {
    throw new RuntimeException( 'Use a disposable .local or .test WordPress project.' );
}
require_once WP_PLUGIN_DIR . '/directorist-pricing-plans/directorist-pricing-plans.php';
require_once dirname( __DIR__ ) . '/directorist-support-tools.php';
global $wpdb;
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$original = [ 'prefix' => $wpdb->prefix, 'posts' => $wpdb->posts, 'postmeta' => $wpdb->postmeta, 'options' => $wpdb->options ];
$prefix = 'dst_recovery_qa_';
$tables = [ $prefix . 'posts', $prefix . 'postmeta', $prefix . 'options', $prefix . 'directorist_user_packages' ];
$trigger = $prefix . 'fail_backup';
foreach ( $tables as $table ) {
    $assert( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Fixture table already exists: ' . $table );
}
$real_options_before = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM `{$wpdb->options}` WHERE option_name IN (%s,%s,%s) ORDER BY option_name", Recovery::STATE_OPTION, Recovery::MIGRATIONS_OPTION, Recovery::BACKUP_OPTION ), ARRAY_A );
$fail_expected = static function ( callable $fn, string $contains ) use ( $assert ): void {
    try { $fn(); } catch ( RuntimeException $e ) {
        $assert( false !== strpos( $e->getMessage(), $contains ), 'Unexpected rejection: ' . $e->getMessage() );
        return;
    }
    throw new RuntimeException( 'Expected rejection: ' . $contains );
};
try {
    $assert( false !== $wpdb->query( "CREATE TABLE {$tables[0]} (ID bigint unsigned PRIMARY KEY,post_author bigint unsigned NOT NULL,post_type varchar(20) NOT NULL) ENGINE=InnoDB" ), 'Could not create posts fixture.' );
    $assert( false !== $wpdb->query( "CREATE TABLE {$tables[1]} (meta_id bigint unsigned PRIMARY KEY AUTO_INCREMENT,post_id bigint unsigned NOT NULL,meta_key varchar(255),meta_value longtext,KEY post_id(post_id)) ENGINE=InnoDB" ), 'Could not create metadata fixture.' );
    $assert( false !== $wpdb->query( "CREATE TABLE {$tables[2]} LIKE `{$original['options']}`" ), 'Could not create options fixture.' );
    $assert( false !== $wpdb->query( "CREATE TABLE {$tables[3]} (id bigint unsigned PRIMARY KEY,user_id bigint unsigned,directory_type_id bigint unsigned,plan_id bigint unsigned,is_legacy tinyint,status varchar(50),started_at datetime) ENGINE=InnoDB" ), 'Could not create package fixture.' );
    $wpdb->prefix = $prefix;
    $wpdb->posts = $tables[0];
    $wpdb->postmeta = $tables[1];
    $wpdb->options = $tables[2];
    wp_cache_flush();
    $posts = $meta = [];
    for ( $i = 1; $i <= 967; $i++ ) {
        $id = 10000 + $i;
        $group = $i % 5;
        $posts[] = "($id," . ( 2000 + $group ) . ",'at_biz_dir')";
        $meta[] = "($id,'_directory_type','1'),($id,'_plan_id','" . ( 10 + $group ) . "'),($id,'_fixture_note','preserve')";
        if ( 4 === $group ) { $meta[] = "($id,'_plan_id','15')"; }
    }
    $assert( false !== $wpdb->query( "INSERT INTO {$wpdb->posts} VALUES " . implode( ',', $posts ) ), 'Could not seed posts.' );
    $assert( false !== $wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES " . implode( ',', $meta ) ), 'Could not seed metadata.' );
    $assert( false !== $wpdb->query( "INSERT INTO {$tables[3]} VALUES
        (1,2000,1,100,0,'active','2026-01-01'),
        (2,2001,2,100,0,'active','2026-01-01'),
        (3,2002,1,12,1,'active','2026-01-01'),
        (4,2002,1,100,0,'active','2026-01-01'),
        (5,2003,1,100,0,'active','2026-01-01'),
        (6,2003,1,101,0,'canceled_at_period_end','2026-01-01'),
        (7,2004,1,102,0,'active','2026-01-01')" ), 'Could not seed packages.' );
    update_option( 'atbdp_option', [ 'fixture' => 'unchanged' ], false );
    $migration = new ListingPlanMetaMigration();
    $assert( false === $migration->execute(), 'The native first batch should be incomplete.' );
    $all_migrations = array_keys( App::$config->get( 'app.migrations' ) );
    update_option( Recovery::MIGRATIONS_OPTION, $all_migrations, false );
    $start_state = get_option( Recovery::STATE_OPTION );
    $assert( 250 === $start_state['processed'] && 10250 === $start_state['cursor'], 'Stuck fixture does not match the reported condition.' );
    echo "PASS: native first batch plus completed marker reproduces running 250/967\n";
    $snapshot_meta = static function () use ( $wpdb ) { return $wpdb->get_results( "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} ORDER BY post_id,meta_key,meta_id", ARRAY_A ); };
    $snapshot_options = static function () use ( $wpdb ) { return $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ); };
    $before_meta = $snapshot_meta();
    $before_options = $snapshot_options();
    $packages_before = $wpdb->get_results( "SELECT * FROM {$tables[3]} ORDER BY id", ARRAY_A );
    $recovery = new Recovery();
    $preview = $recovery->preview();
    $assert( 717 === $preview['remaining'] && 250 === count( $preview['rows'] ) && 150 === $preview['will_write'], 'Preview did not match native assignment rules.' );
    $assert( $before_meta === $snapshot_meta() && $before_options === $snapshot_options(), 'Preview modified data.' );
    echo "PASS: read-only preview covers active/ending packages, latest package ties, valid legacy plans, other-directory packages, duplicate metadata\n";

    // Server-side capability and nonce gate, without calling a redirect/exit path.
    $die_filter = static function () { return static function () { throw new RuntimeException( 'access blocked' ); }; };
    $request_before = $_REQUEST;
    $old_user = get_current_user_id();
    add_filter( 'wp_die_handler', $die_filter );
    try {
        wp_set_current_user( 0 );
        $fail_expected( static function () { \Sovware\DirectoristSupportTools\handle_listing_recovery(); }, 'access blocked' );
        wp_set_current_user( 1 );
        $_REQUEST['_wpnonce'] = 'invalid';
        $fail_expected( static function () { \Sovware\DirectoristSupportTools\handle_listing_recovery(); }, 'access blocked' );
    } finally {
        remove_filter( 'wp_die_handler', $die_filter );
        $_REQUEST = $request_before;
        wp_set_current_user( $old_user );
    }
    echo "PASS: administrator capability and nonce gates reject unauthorized actions\n";
    $fail_expected( static function () use ( $recovery ) { $recovery->recover_batch( 'stale-preview' ); }, 'preview has changed' );
    $assert( null === $recovery->backup() && $before_meta === $snapshot_meta(), 'A stale request changed data.' );
    $wpdb->update( $wpdb->postmeta, [ 'meta_value' => '999' ], [ 'post_id' => 10251, 'meta_key' => '_plan_id' ] );
    $fail_expected( static function () use ( $recovery, $preview ) { $recovery->recover_batch( $preview['token'] ); }, 'preview has changed' );
    $wpdb->update( $wpdb->postmeta, [ 'meta_value' => '11' ], [ 'post_id' => 10251, 'meta_key' => '_plan_id' ] );
    $assert( $before_meta === $snapshot_meta(), 'Stale-preview fixture was not restored.' );
    echo "PASS: stale tokens and edits after preview stop before recovery\n";

    // Fail the backup after native metadata updates; all those updates must roll back.
    $sql = "CREATE TRIGGER `$trigger` BEFORE INSERT ON `{$wpdb->options}` FOR EACH ROW BEGIN IF NEW.option_name='" . Recovery::BACKUP_OPTION . "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA backup failure'; END IF; END";
    $assert( false !== $wpdb->query( $sql ), 'Could not create rollback fixture trigger.' );
    $suppressed = $wpdb->suppress_errors( true );
    try {
        $preview = $recovery->preview();
        $fail_expected( static function () use ( $recovery, $preview ) { $recovery->recover_batch( $preview['token'] ); }, 'save the recovery backup' );
    } finally {
        $wpdb->suppress_errors( $suppressed );
        $wpdb->query( "DROP TRIGGER IF EXISTS `$trigger`" );
    }
    $assert( $before_meta === $snapshot_meta() && $before_options === $snapshot_options(), 'A failed backup left partial data changes.' );
    echo "PASS: forced backup failure atomically rolls back native assignments and progress\n";
    foreach ( [ 500, 750, null ] as $expected_count ) {
        $preview = $recovery->preview();
        $result = $recovery->recover_batch( $preview['token'] );
        $state = get_option( Recovery::STATE_OPTION, null );
        $assert( null === $expected_count ? $result['finished'] && null === $state : ! $result['finished'] && $expected_count === $state['processed'], 'Recovery progress mismatch.' );
        $assert( get_option( Recovery::MIGRATIONS_OPTION ) === $all_migrations, 'Recovery changed the completed-migration list.' );
        echo 'PASS: recovered batch to ' . ( $expected_count ?? 'complete' ) . "\n";
    }
    $backup = $recovery->backup();
    $assert( 'completed' === $backup['status'] && 429 === count( $backup['rows'] ) && 3 === $backup['batches'], 'Incomplete cumulative backup.' );
    $assert( $packages_before === $wpdb->get_results( "SELECT * FROM {$tables[3]} ORDER BY id", ARRAY_A ), 'Packages were modified.' );
    $assert( [ 'fixture' => 'unchanged' ] === get_option( 'atbdp_option' ), 'Directorist settings were modified.' );
    $fail_expected( static function () use ( $recovery, $preview ) { $recovery->recover_batch( $preview['token'] ); }, 'No supported unfinished' );
    echo "PASS: full completion clears the progress notice state; repeated completion is rejected; packages and settings preserved\n";

    $edited_id = (int) array_key_first( $backup['rows'] );
    $wpdb->update( $wpdb->postmeta, [ 'meta_value' => '999' ], [ 'post_id' => $edited_id, 'meta_key' => '_plan_id' ] );
    $edited_meta = $snapshot_meta();
    $fail_expected( static function () use ( $recovery ) { $recovery->restore(); }, 'edited later' );
    $assert( $edited_meta === $snapshot_meta() && null === get_option( Recovery::STATE_OPTION, null ), 'A restore conflict partially applied.' );
    $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $backup['rows'][ $edited_id ]['after'][0] ], [ 'post_id' => $edited_id, 'meta_key' => '_plan_id' ] );
    $later_migrations = array_merge( $all_migrations, [ 'unrelated-later-migration' ] );
    update_option( Recovery::MIGRATIONS_OPTION, $later_migrations );
    $assert( 429 === $recovery->restore(), 'Restore count mismatch.' );
    $assert( $before_meta === $snapshot_meta(), 'Restoration did not preserve original metadata values and duplicate order.' );
    $assert( get_option( Recovery::STATE_OPTION ) === $start_state, 'Original stalled progress was not restored.' );
    $assert( get_option( Recovery::MIGRATIONS_OPTION ) === $later_migrations, 'Restoration overwrote unrelated migration completion.' );
    $assert( 'restored' === $recovery->backup()['status'], 'Restoration result not retained.' );
    echo "PASS: restoration refuses later assignment edits, restores original values/progress, preserves unrelated completed migrations\n";
    delete_option( Recovery::BACKUP_OPTION );
    update_option( Recovery::MIGRATIONS_OPTION, array_values( array_diff( $all_migrations, [ Recovery::MIGRATION_KEY ] ) ) );
    $fail_expected( static function () use ( $recovery ) { $recovery->preview(); }, 'No conflicting completed marker' );
    echo "PASS: ordinary unmarked migrations are excluded\n";
    update_option( Recovery::MIGRATIONS_OPTION, $all_migrations );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID>10500" );
    update_option( Recovery::STATE_OPTION, [ 'status' => 'running', 'cursor' => 10250, 'processed' => 250, 'total' => 500, 'error' => '' ], false );
    $preview = $recovery->preview();
    $result = $recovery->recover_batch( $preview['token'] );
    $assert( ! $result['finished'] && 250 === $result['processed'], 'An exact-size last batch must retain the native finalization step.' );
    $preview = $recovery->preview();
    $assert( [] === $preview['rows'] && 0 === $preview['remaining'], 'Empty finalization preview mismatch.' );
    $result = $recovery->recover_batch( $preview['token'] );
    $assert( $result['finished'] && 0 === $result['processed'] && null === get_option( Recovery::STATE_OPTION, null ), 'Empty finalization did not clear progress.' );
    echo "PASS: exact 250-item final batch and empty finalization follow the native completion contract\n";
} finally {
    $wpdb->query( 'ROLLBACK' );
    $wpdb->query( "DROP TRIGGER IF EXISTS `$trigger`" );
    foreach ( $original as $key => $value ) { $wpdb->$key = $value; }
    foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `$table`" ); }
    wp_cache_flush();
    $real_options_after = $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM `{$wpdb->options}` WHERE option_name IN (%s,%s,%s) ORDER BY option_name", Recovery::STATE_OPTION, Recovery::MIGRATIONS_OPTION, Recovery::BACKUP_OPTION ), ARRAY_A );
    $assert( $real_options_before === $real_options_after, 'The actual local migration options changed.' );
    echo "Disposable tables removed; original local migration options unchanged.\n";
}
