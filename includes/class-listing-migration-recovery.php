<?php

namespace Sovware\DirectoristSupportTools;

defined( 'ABSPATH' ) || exit;

/** Manual recovery for a completed marker paired with unfinished listing progress. */
final class Listing_Migration_Recovery {
    public const STATE_OPTION = 'directorist_listing_plan_meta_migration_state';
    public const MIGRATIONS_OPTION = 'directorist_pricing_plans_migrations';
    public const MIGRATION_KEY = 'listing-plan-meta-v4-0-2';
    public const BACKUP_OPTION = 'directorist_support_tools_listing_recovery_v1';
    public const BATCH_SIZE = 250;
    private const SOURCE_HASH = 'dfe33ea08738ae946c030c2aa3c86d67f9dcd7552d5ead6ee5e4058f213a6703';
    private const MIGRATION_CLASS = '\\DirectoristPricingPlan\\Database\\Migrations\\ListingPlanMetaMigration';
    private $db;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    public function assert_environment(): void {
        if ( ! function_exists( 'directorist_pricing_plans_version' ) || '4.2.0' !== directorist_pricing_plans_version() || ! class_exists( self::MIGRATION_CLASS ) ) {
            throw new \RuntimeException( 'This recovery requires active Directorist Pricing Plans 4.2.0.' );
        }
        $file = ( new \ReflectionClass( self::MIGRATION_CLASS ) )->getFileName();
        if ( ! is_readable( $file ) || self::SOURCE_HASH !== hash_file( 'sha256', $file ) ) {
            throw new \RuntimeException( 'The installed listing migration differs from the verified implementation. Ask a developer to review it before recovery.' );
        }
        foreach ( [ $this->db->options, $this->db->posts, $this->db->postmeta, $this->db->prefix . 'directorist_user_packages' ] as $table ) {
            $row = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
            if ( ! $row || 'innodb' !== strtolower( (string) $row->Engine ) ) {
                throw new \RuntimeException( 'Recovery requires existing InnoDB options, posts, postmeta and user-packages tables.' );
            }
        }
    }

    private function option( string $key ) {
        $raw = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$this->db->options}` WHERE option_name = %s", $key ) );
        $this->check_db();
        return null === $raw ? null : maybe_unserialize( $raw );
    }

    public function backup(): ?array {
        $backup = $this->option( self::BACKUP_OPTION );
        if ( null === $backup ) {
            return null;
        }
        if ( ! is_array( $backup ) || 1 !== ( $backup['version'] ?? null ) || ! isset( $backup['original_state'], $backup['rows'], $backup['status'] ) || ! is_array( $backup['rows'] ) || ! array_key_exists( 'expected_state', $backup ) || ! in_array( $backup['status'], [ 'active', 'completed', 'restored' ], true ) ) {
            throw new \RuntimeException( 'The existing recovery backup needs developer review.' );
        }
        foreach ( $backup['rows'] as $id => $row ) {
            if ( ! ctype_digit( (string) $id ) || (int) $id < 1 || ! is_array( $row ) || ! isset( $row['before'], $row['after'] ) || ! is_array( $row['before'] ) || ! is_array( $row['after'] ) || 1 !== count( $row['after'] ) ) {
                throw new \RuntimeException( 'The listing assignment backup is invalid.' );
            }
            foreach ( array_merge( $row['before'], $row['after'] ) as $value ) {
                if ( ! is_string( $value ) ) {
                    throw new \RuntimeException( 'The listing assignment backup contains an unsupported value.' );
                }
            }
        }
        return $backup;
    }

    /** Read-only preview; the token also protects against a stale preview submission. */
    public function preview(): array {
        $this->assert_environment();
        $state = $this->option( self::STATE_OPTION );
        $migrations = $this->option( self::MIGRATIONS_OPTION );
        if ( ! is_array( $migrations ) || ! in_array( self::MIGRATION_KEY, $migrations, true ) ) {
            throw new \RuntimeException( 'No conflicting completed marker found. This tool does not take over an ordinary running migration.' );
        }
        if ( ! is_array( $state ) || 'running' !== ( $state['status'] ?? '' ) || ! empty( $state['error'] ) ) {
            throw new \RuntimeException( 'No supported unfinished progress state found. Failed or missing progress needs investigation.' );
        }
        foreach ( [ 'cursor', 'processed', 'total' ] as $key ) {
            if ( ! isset( $state[ $key ] ) || ! is_int( $state[ $key ] ) || $state[ $key ] < 0 ) {
                throw new \RuntimeException( 'The saved migration cursor or counts are invalid.' );
            }
        }
        if ( 0 === $state['total'] || $state['processed'] > $state['total'] ) {
            throw new \RuntimeException( 'The saved migration counts need developer review.' );
        }
        $backup = $this->backup();
        if ( $backup && ( 'active' !== $backup['status'] || $backup['expected_state'] !== $state ) ) {
            throw new \RuntimeException( 'An existing recovery backup or changed progress prevents another batch. Review or restore that backup first.' );
        }
        $post_type = defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir';
        $ids = array_map( 'intval', $this->db->get_col( $this->db->prepare(
            "SELECT ID FROM `{$this->db->posts}` WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
            $post_type, $state['cursor'], self::BATCH_SIZE
        ) ) );
        $this->check_db();
        $remaining = (int) $this->db->get_var( $this->db->prepare(
            "SELECT COUNT(*) FROM `{$this->db->posts}` WHERE post_type = %s AND ID > %d", $post_type, $state['cursor']
        ) );
        $this->check_db();
        $rows = $this->assignments( $ids );
        $will_write = 0;
        foreach ( $rows as &$row ) {
            $row['before'] = $this->plan_values( (int) $row['id'] );
            $row['will_write'] = ! $row['valid_legacy'] && $row['target_plan_id'] > 0;
            $will_write += (int) $row['will_write'];
        }
        unset( $row );
        $result = [ 'state' => $state, 'remaining' => $remaining, 'rows' => $rows, 'will_write' => $will_write, 'backup' => $backup ];
        $result['token'] = wp_hash( wp_json_encode( [ $state, $migrations, $rows, $backup ] ) );
        return $result;
    }

    private function assignments( array $ids ): array {
        if ( ! $ids ) {
            return [];
        }
        $slots = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $posts = $this->db->posts;
        $meta = $this->db->postmeta;
        $packages = $this->db->prefix . 'directorist_user_packages';
        // Match the verified native migration's latest-meta and package selection rules.
        $directory = "CAST((SELECT m.meta_value FROM `$meta` m WHERE m.post_id=p.ID AND m.meta_key='_directory_type' ORDER BY m.meta_id DESC LIMIT 1) AS UNSIGNED)";
        $plan = "CAST((SELECT m.meta_value FROM `$meta` m WHERE m.post_id=p.ID AND m.meta_key='_plan_id' ORDER BY m.meta_id DESC LIMIT 1) AS UNSIGNED)";
        $status = "('active','canceled_at_period_end')";
        $sql = "SELECT p.ID AS id, $plan AS current_plan_id,
            (SELECT u.plan_id FROM `$packages` u WHERE u.user_id=p.post_author AND u.directory_type_id=$directory AND u.is_legacy=0 AND u.status IN $status ORDER BY u.started_at DESC,u.id DESC LIMIT 1) AS target_plan_id,
            (SELECT COUNT(*) FROM `$packages` u WHERE u.user_id=p.post_author AND u.directory_type_id=$directory AND u.plan_id=$plan AND u.is_legacy=1 AND u.status IN $status) AS valid_legacy
            FROM `$posts` p WHERE p.ID IN ($slots) ORDER BY p.ID";
        $rows = $this->db->get_results( $this->db->prepare( $sql, $ids ), ARRAY_A );
        $this->check_db();
        foreach ( $rows as &$row ) {
            $row = array_map( 'intval', $row );
        }
        return $rows;
    }

    private function plan_values( int $id ): array {
        $values = $this->db->get_col( $this->db->prepare(
            "SELECT meta_value FROM `{$this->db->postmeta}` WHERE post_id=%d AND meta_key='_plan_id' ORDER BY meta_id", $id
        ) );
        $this->check_db();
        return $values;
    }

    /** Execute only the listing migration, once. Keep its completed marker as a guard. */
    public function recover_batch( string $token ): array {
        $this->assert_environment();
        $this->begin();
        $ids = [];
        try {
            $this->lock_options();
            $preview = $this->preview();
            if ( ! hash_equals( $preview['token'], $token ) ) {
                throw new \RuntimeException( 'The preview has changed. Reload and review the next batch before continuing.' );
            }
            $ids = array_column( $preview['rows'], 'id' );
            $this->lock_listings( $ids );
            $locked = $this->preview();
            if ( ! hash_equals( $locked['token'], $token ) ) {
                throw new \RuntimeException( 'Listing data changed while acquiring locks. Reload the preview.' );
            }
            $backup = $preview['backup'] ?? [
                'version' => 1, 'status' => 'active', 'created_at' => gmdate( 'c' ),
                'original_state' => $preview['state'], 'original_migrations' => $this->option( self::MIGRATIONS_OPTION ),
                'rows' => [], 'batches' => 0,
            ];
            foreach ( $preview['rows'] as $row ) {
                if ( $row['will_write'] ) {
                    if ( isset( $backup['rows'][ $row['id'] ] ) ) {
                        throw new \RuntimeException( 'The recovery cursor overlaps a backed-up listing. No batch was applied.' );
                    }
                    $backup['rows'][ $row['id'] ] = [ 'before' => $row['before'], 'after' => [ (string) $row['target_plan_id'] ] ];
                }
            }
            if ( count( $backup['rows'] ) > 5000 ) {
                throw new \RuntimeException( 'Recovery exceeds the 5,000 changed-listing backup limit. Ask a developer to review it.' );
            }
            $class = self::MIGRATION_CLASS;
            $migration = new $class();
            $finished = $migration->execute();
            $this->check_db();
            $this->clear_caches( $ids );
            $state = $this->option( self::STATE_OPTION );
            if ( $finished ) {
                if ( null !== $state ) {
                    throw new \RuntimeException( 'The migration returned completion without clearing its progress. The batch was rolled back.' );
                }
            } else {
                $expected = $preview['state'];
                $expected['cursor'] = $ids ? (int) end( $ids ) : $expected['cursor'];
                $expected['processed'] = min( $expected['total'], $expected['processed'] + count( $ids ) );
                if ( $state !== $expected ) {
                    throw new \RuntimeException( 'The migration did not advance as previewed. The batch was rolled back.' );
                }
            }
            foreach ( $preview['rows'] as $row ) {
                $expected = $row['will_write'] ? [ (string) $row['target_plan_id'] ] : $row['before'];
                if ( $this->plan_values( $row['id'] ) !== $expected ) {
                    throw new \RuntimeException( 'A plan assignment did not match the preview. The batch was rolled back.' );
                }
            }
            $backup['expected_state'] = $state;
            $backup['status'] = $finished ? 'completed' : 'active';
            $backup['batches']++;
            $backup['updated_at'] = gmdate( 'c' );
            $this->save_backup( $backup );
            $this->commit();
            return [ 'finished' => $finished, 'processed' => count( $ids ), 'written' => $preview['will_write'] ];
        } catch ( \Throwable $e ) {
            $this->db->query( 'ROLLBACK' );
            throw $e;
        } finally {
            $this->clear_caches( $ids );
        }
    }

    /** Restore only recovery-owned values; refuse to overwrite later assignment edits. */
    public function restore(): int {
        $this->assert_environment();
        $this->begin();
        $ids = [];
        try {
            $this->lock_options();
            $backup = $this->backup();
            if ( ! $backup || ! in_array( $backup['status'], [ 'active', 'completed' ], true ) || $this->option( self::STATE_OPTION ) !== $backup['expected_state'] ) {
                throw new \RuntimeException( 'No restorable backup, or migration progress has changed since recovery.' );
            }
            $migrations = $this->option( self::MIGRATIONS_OPTION );
            if ( ! is_array( $migrations ) || ! in_array( self::MIGRATION_KEY, $migrations, true ) ) {
                throw new \RuntimeException( 'The completed marker changed. No backup was restored.' );
            }
            $ids = array_map( 'intval', array_keys( $backup['rows'] ) );
            $this->lock_listings( $ids );
            foreach ( $backup['rows'] as $id => $row ) {
                if ( $this->plan_values( (int) $id ) !== $row['after'] ) {
                    throw new \RuntimeException( 'A recovered listing assignment was edited later. No backup was restored.' );
                }
            }
            foreach ( $backup['rows'] as $id => $row ) {
                $this->db->delete( $this->db->postmeta, [ 'post_id' => (int) $id, 'meta_key' => '_plan_id' ], [ '%d', '%s' ] );
                $this->check_db();
                foreach ( $row['before'] as $value ) {
                    $ok = $this->db->insert( $this->db->postmeta, [ 'post_id' => (int) $id, 'meta_key' => '_plan_id', 'meta_value' => $value ], [ '%d', '%s', '%s' ] );
                    if ( false === $ok ) {
                        throw new \RuntimeException( 'A plan assignment could not be restored. Restoration was rolled back.' );
                    }
                }
                if ( $this->plan_values( (int) $id ) !== $row['before'] ) {
                    throw new \RuntimeException( 'Restored plan values did not match the backup. Restoration was rolled back.' );
                }
            }
            update_option( self::STATE_OPTION, $backup['original_state'], false );
            $this->check_db();
            if ( $this->option( self::STATE_OPTION ) !== $backup['original_state'] ) {
                throw new \RuntimeException( 'The original progress could not be restored.' );
            }
            $backup['status'] = 'restored';
            $backup['updated_at'] = gmdate( 'c' );
            $this->save_backup( $backup );
            $this->commit();
            return count( $ids );
        } catch ( \Throwable $e ) {
            $this->db->query( 'ROLLBACK' );
            throw $e;
        } finally {
            $this->clear_caches( $ids );
        }
    }

    private function begin(): void {
        if ( false === $this->db->query( 'START TRANSACTION' ) ) {
            throw new \RuntimeException( 'Could not start the recovery transaction.' );
        }
    }

    private function lock_options(): void {
        // Lock the marker first to serialize support actions before creating a backup row.
        $this->db->get_results( $this->db->prepare(
            "SELECT option_name FROM `{$this->db->options}` WHERE option_name=%s FOR UPDATE", self::MIGRATIONS_OPTION
        ) );
        $this->check_db();
        foreach ( [ self::STATE_OPTION, self::BACKUP_OPTION ] as $key ) {
            $this->db->get_results( $this->db->prepare( "SELECT option_name FROM `{$this->db->options}` WHERE option_name=%s FOR UPDATE", $key ) );
            $this->check_db();
        }
        $this->clear_caches( [] );
    }

    private function lock_listings( array $ids ): void {
        if ( ! $ids ) {
            return;
        }
        $slots = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $this->db->get_results( $this->db->prepare( "SELECT ID FROM `{$this->db->posts}` WHERE ID IN ($slots) ORDER BY ID FOR UPDATE", $ids ) );
        $this->check_db();
        $this->db->get_results( $this->db->prepare( "SELECT meta_id FROM `{$this->db->postmeta}` WHERE post_id IN ($slots) ORDER BY post_id,meta_id FOR UPDATE", $ids ) );
        $this->check_db();
    }

    private function save_backup( array $backup ): void {
        $raw = maybe_serialize( $backup );
        if ( strlen( $raw ) > 4 * 1024 * 1024 ) {
            throw new \RuntimeException( 'The recovery backup exceeds 4 MB. No batch was committed.' );
        }
        if ( false === $this->db->query( $this->db->prepare(
            "INSERT INTO `{$this->db->options}` (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",
            self::BACKUP_OPTION, $raw
        ) ) ) {
            throw new \RuntimeException( 'Could not save the recovery backup. The batch was rolled back.' );
        }
    }

    private function check_db(): void {
        if ( $this->db->last_error ) {
            throw new \RuntimeException( 'A database operation failed. No recovery batch was committed.' );
        }
    }

    private function commit(): void {
        if ( false === $this->db->query( 'COMMIT' ) ) {
            throw new \RuntimeException( 'The recovery transaction could not be committed.' );
        }
    }

    private function clear_caches( array $ids ): void {
        foreach ( [ self::STATE_OPTION, self::MIGRATIONS_OPTION, self::BACKUP_OPTION, 'alloptions', 'notoptions' ] as $key ) {
            wp_cache_delete( $key, 'options' );
        }
        foreach ( $ids as $id ) {
            wp_cache_delete( $id, 'post_meta' );
        }
    }
}
