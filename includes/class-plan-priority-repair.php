<?php

namespace Sovware\DirectoristSupportTools;

defined( 'ABSPATH' ) || exit;

final class Plan_Priority_Repair {
    public const BATCH_SIZE = 200;
    public const BACKUP_OPTION = 'directorist_support_tools_priority_backup_v1';

    private $db;
    private $plans;
    private $transactional = true;
    private $engines = [];

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->plans = $wpdb->prefix . 'directorist_plans';
    }

    public function assert_environment( bool $writing = true ): void {
        if ( ! function_exists( 'directorist_pricing_plans_version' ) || ! class_exists( '\\DirectoristPricingPlan\\App\\Models\\Plan' ) ) {
            throw new \RuntimeException( 'Activate Directorist and Directorist Pricing Plans 4.x before using this tool.' );
        }
        $version = directorist_pricing_plans_version();
        if ( version_compare( $version, '4.0', '<' ) || version_compare( $version, '5.0', '>=' ) ) {
            throw new \RuntimeException( 'This version of the repair tool supports Pricing Plans 4.x only.' );
        }
        foreach ( [ $this->plans, $this->db->options ] as $table ) {
            $status = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
            $engine = $status ? strtolower( (string) $status->Engine ) : '';
            if ( ! $engine ) {
                $engine = strtolower( (string) $this->db->get_var( $this->db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ) );
            }
            $this->engines[ $table ] = $engine;
        }
        $this->transactional = count( array_filter( $this->engines, static function ( $engine ) { return 'innodb' === $engine; } ) ) === 2;
        if ( $writing && array_diff( array_values( $this->engines ), [ 'innodb', 'myisam', 'aria' ] ) ) {
            throw new \RuntimeException( 'The table storage engines could not be verified as InnoDB, MyISAM, or Aria. The preview is available, but repair is blocked. No plans were changed.' );
        }
        $columns = $this->db->get_results( "SHOW COLUMNS FROM `{$this->plans}`", OBJECT_K );
        if ( ! isset( $columns['id'], $columns['title'], $columns['listing_display_priority'] )
            || ! preg_match( '/^(tinyint|smallint|mediumint|int|bigint)\b/i', $columns['listing_display_priority']->Type ) ) {
            throw new \RuntimeException( 'The pricing-plan schema is not supported. No plans were changed.' );
        }
    }

    public function storage(): array {
        return [ 'transactional' => $this->transactional, 'engines' => $this->engines ];
    }

    public function scan(): array {
        $rows = $this->db->get_results( "SELECT id, title, listing_display_priority FROM `{$this->plans}` WHERE listing_display_priority < 1 OR listing_display_priority IS NULL ORDER BY id LIMIT " . self::BATCH_SIZE );
        if ( null === $rows ) {
            throw new \RuntimeException( 'Could not read the pricing plans.' );
        }
        return [
            'rows' => $rows,
            'total' => (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$this->plans}` WHERE listing_display_priority < 1 OR listing_display_priority IS NULL" ),
        ];
    }

    public function backup(): array {
        return $this->validate_backup( get_option( self::BACKUP_OPTION, [ 'version' => 1, 'rows' => [] ] ) );
    }

    public function repair( array $ids ): int {
        $this->assert_environment();
        if ( ! $ids || count( $ids ) > self::BATCH_SIZE ) {
            throw new \RuntimeException( 'Select between 1 and 200 listed plans.' );
        }
        foreach ( $ids as $id ) {
            if ( ( ! is_int( $id ) && ( ! is_string( $id ) || ! ctype_digit( $id ) ) ) || (int) $id < 1 ) {
                throw new \RuntimeException( 'The plan selection is invalid. Select plans from the preview.' );
            }
        }
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

        if ( ! $this->transactional ) {
            return $this->repair_with_durable_backup( $ids );
        }

        $this->begin();
        try {
            $backup = $this->locked_backup();
            $slots = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
            $rows = $this->db->get_results( $this->db->prepare( "SELECT id, listing_display_priority FROM `{$this->plans}` WHERE id IN ({$slots}) AND (listing_display_priority < 1 OR listing_display_priority IS NULL) ORDER BY id FOR UPDATE", $ids ) );
            if ( null === $rows ) {
                throw new \RuntimeException( 'Could not lock the selected plans.' );
            }
            foreach ( $rows as $row ) {
                $key = (int) $row->id;
                if ( ! array_key_exists( $key, $backup['rows'] ) ) {
                    $backup['rows'][ $key ] = null === $row->listing_display_priority ? null : (int) $row->listing_display_priority;
                }
            }
            if ( count( $backup['rows'] ) > 5000 ) {
                throw new \RuntimeException( 'This repair backup has reached its limit. Ask a developer to review it before repairing additional plans.' );
            }
            if ( $rows ) {
                $backup['updated_at'] = gmdate( 'Y-m-d H:i:s' );
                $this->write_backup( $backup );
            }
            foreach ( $rows as $row ) {
                $changed = $this->db->update( $this->plans, [ 'listing_display_priority' => 1 ], [ 'id' => (int) $row->id ], [ '%d' ], [ '%d' ] );
                if ( 1 !== $changed ) {
                    throw new \RuntimeException( 'A selected plan could not be repaired. The batch was rolled back.' );
                }
            }
            $this->commit();
            return count( $rows );
        } catch ( \Throwable $e ) {
            $this->db->query( 'ROLLBACK' );
            $this->clear_backup_cache();
            throw $e;
        }
    }

    public function restore(): array {
        $this->assert_environment();
        if ( ! $this->transactional ) {
            return $this->restore_with_durable_backup();
        }
        $this->begin();
        try {
            $backup = $this->locked_backup();
            $result = [ 'restored' => 0, 'skipped' => 0 ];
            foreach ( $backup['rows'] as $id => $old_priority ) {
                $row = $this->db->get_row( $this->db->prepare( "SELECT id, listing_display_priority FROM `{$this->plans}` WHERE id = %d FOR UPDATE", $id ) );
                if ( ! empty( $this->db->last_error ) ) {
                    throw new \RuntimeException( 'Could not read a plan for restoration.' );
                }
                if ( ! $row || '1' !== (string) $row->listing_display_priority ) {
                    ++$result['skipped'];
                    continue;
                }
                $changed = $this->db->update( $this->plans, [ 'listing_display_priority' => $old_priority ], [ 'id' => (int) $id ], [ '%d' ], [ '%d' ] );
                if ( 1 !== $changed ) {
                    throw new \RuntimeException( 'A plan could not be restored. The batch was rolled back.' );
                }
                unset( $backup['rows'][ $id ] );
                ++$result['restored'];
            }
            $this->write_backup( $backup );
            $this->commit();
            return $result;
        } catch ( \Throwable $e ) {
            $this->db->query( 'ROLLBACK' );
            $this->clear_backup_cache();
            throw $e;
        }
    }

    private function acquire_lock(): string {
        $name = 'dst_priority_' . md5( $this->plans . '|' . $this->db->options );
        if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) {
            throw new \RuntimeException( 'Another priority repair is running, or the database does not support advisory locks. No plans were changed.' );
        }
        return $name;
    }

    private function release_lock( string $name ): void {
        $this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
        $this->clear_backup_cache();
    }

    private function persist_and_verify_backup( array $backup ): void {
        $this->write_backup( $backup );
        $stored = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$this->db->options}` WHERE option_name = %s", self::BACKUP_OPTION ) );
        if ( maybe_serialize( $backup ) !== $stored ) {
            throw new \RuntimeException( 'The previous priorities could not be verified in the database backup. No further plans were changed.' );
        }
    }

    private function repair_with_durable_backup( array $ids ): int {
        $lock = $this->acquire_lock();
        $repaired = 0;
        try {
            $backup = $this->locked_backup();
            $slots = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
            $rows = $this->db->get_results( $this->db->prepare( "SELECT id, listing_display_priority FROM `{$this->plans}` WHERE id IN ({$slots}) AND (listing_display_priority < 1 OR listing_display_priority IS NULL) ORDER BY id", $ids ) );
            if ( null === $rows ) {
                throw new \RuntimeException( 'Could not read the selected plans.' );
            }
            foreach ( $rows as $row ) {
                if ( ! array_key_exists( (int) $row->id, $backup['rows'] ) ) {
                    $backup['rows'][ (int) $row->id ] = null === $row->listing_display_priority ? null : (int) $row->listing_display_priority;
                }
            }
            if ( count( $backup['rows'] ) > 5000 ) {
                throw new \RuntimeException( 'The repair backup limit was reached. Ask a developer to review it.' );
            }
            if ( $rows ) {
                $backup['updated_at'] = gmdate( 'Y-m-d H:i:s' );
                // On MyISAM/Aria, persist and read back the backup BEFORE any plan update.
                $this->persist_and_verify_backup( $backup );
            }
            foreach ( $rows as $row ) {
                $where = null === $row->listing_display_priority ? 'listing_display_priority IS NULL' : $this->db->prepare( 'listing_display_priority = %d', $row->listing_display_priority );
                $changed = $this->db->query( $this->db->prepare( "UPDATE `{$this->plans}` SET listing_display_priority = 1 WHERE id = %d AND {$where}", $row->id ) );
                if ( 1 !== $changed ) {
                    throw new \RuntimeException( 'A plan changed concurrently or could not be updated. Reload the preview before continuing.' );
                }
                ++$repaired;
            }
            return $repaired;
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( sprintf( '%d plan(s) repaired before the batch stopped. Saved previous values were retained. %s', $repaired, $e->getMessage() ), 0, $e );
        } finally {
            $this->release_lock( $lock );
        }
    }

    private function restore_with_durable_backup(): array {
        $lock = $this->acquire_lock();
        $result = [ 'restored' => 0, 'skipped' => 0 ];
        try {
            $backup = $this->locked_backup();
            foreach ( $backup['rows'] as $id => $old ) {
                $value = null === $old ? 'NULL' : (string) (int) $old;
                $changed = $this->db->query( $this->db->prepare( "UPDATE `{$this->plans}` SET listing_display_priority = {$value} WHERE id = %d AND listing_display_priority = 1", $id ) );
                if ( false === $changed ) {
                    throw new \RuntimeException( 'A plan could not be restored. Previous backup entries were retained; reload before continuing.' );
                }
                if ( 0 === $changed ) {
                    ++$result['skipped'];
                    continue;
                }
                ++$result['restored'];
                unset( $backup['rows'][ $id ] );
                $this->persist_and_verify_backup( $backup );
            }
            return $result;
        } catch ( \Throwable $e ) {
            throw new \RuntimeException( sprintf( '%d plan(s) restored before the batch stopped. %s', $result['restored'], $e->getMessage() ), 0, $e );
        } finally {
            $this->release_lock( $lock );
        }
    }

    private function begin(): void {
        if ( false === $this->db->query( 'START TRANSACTION' ) ) {
            throw new \RuntimeException( 'Could not start the repair transaction. No plans were changed.' );
        }
    }

    private function locked_backup(): array {
        $empty = maybe_serialize( [ 'version' => 1, 'rows' => [] ] );
        if ( false === $this->db->query( $this->db->prepare( "INSERT IGNORE INTO `{$this->db->options}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::BACKUP_OPTION, $empty ) ) ) {
            throw new \RuntimeException( 'Could not create the repair backup.' );
        }
        $value = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM `{$this->db->options}` WHERE option_name = %s FOR UPDATE", self::BACKUP_OPTION ) );
        if ( null === $value || ! empty( $this->db->last_error ) ) {
            throw new \RuntimeException( 'Could not lock the repair backup.' );
        }
        return $this->validate_backup( maybe_unserialize( $value ) );
    }

    private function validate_backup( $backup ): array {
        if ( ! is_array( $backup ) || 1 !== ( $backup['version'] ?? null ) || ! isset( $backup['rows'] ) || ! is_array( $backup['rows'] ) ) {
            throw new \RuntimeException( 'The saved repair backup needs developer review. No plans were changed.' );
        }
        foreach ( $backup['rows'] as $id => $old ) {
            if ( (int) $id < 1 || ( null !== $old && ( ! is_int( $old ) || $old >= 1 ) ) ) {
                throw new \RuntimeException( 'The saved repair backup needs developer review. No plans were changed.' );
            }
        }
        return $backup;
    }

    private function write_backup( array $backup ): void {
        $changed = $this->db->update( $this->db->options, [ 'option_value' => maybe_serialize( $backup ) ], [ 'option_name' => self::BACKUP_OPTION ], [ '%s' ], [ '%s' ] );
        if ( false === $changed ) {
            throw new \RuntimeException( 'Could not save the repair backup. The batch was rolled back.' );
        }
    }

    private function commit(): void {
        if ( false === $this->db->query( 'COMMIT' ) ) {
            throw new \RuntimeException( 'Could not commit the repair batch.' );
        }
        $this->clear_backup_cache();
    }

    private function clear_backup_cache(): void {
        wp_cache_delete( self::BACKUP_OPTION, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }
}
