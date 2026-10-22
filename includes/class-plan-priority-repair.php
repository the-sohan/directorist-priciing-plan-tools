<?php

namespace Sovware\DirectoristSupportTools;

defined( 'ABSPATH' ) || exit;

final class Plan_Priority_Repair {
    public const BATCH_SIZE = 200;
    public const BACKUP_OPTION = 'directorist_support_tools_priority_backup_v1';

    private $db;
    private $plans;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->plans = $wpdb->prefix . 'directorist_plans';
    }

    public function assert_environment(): void {
        if ( ! function_exists( 'directorist_pricing_plans_version' ) || ! class_exists( '\\DirectoristPricingPlan\\App\\Models\\Plan' ) ) {
            throw new \RuntimeException( 'Activate Directorist and Directorist Pricing Plans 4.x before using this tool.' );
        }
        $version = directorist_pricing_plans_version();
        if ( version_compare( $version, '4.0', '<' ) || version_compare( $version, '5.0', '>=' ) ) {
            throw new \RuntimeException( 'This version of the repair tool supports Pricing Plans 4.x only.' );
        }
        foreach ( [ $this->plans, $this->db->options ] as $table ) {
            $status = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
            if ( ! $status || 'innodb' !== strtolower( (string) $status->Engine ) ) {
                throw new \RuntimeException( 'The plans and options tables must use InnoDB so each repair and its backup can be committed together. No plans were changed.' );
            }
        }
        $columns = $this->db->get_results( "SHOW COLUMNS FROM `{$this->plans}`", OBJECT_K );
        if ( ! isset( $columns['id'], $columns['title'], $columns['listing_display_priority'] )
            || ! preg_match( '/^(tinyint|smallint|mediumint|int|bigint)\b/i', $columns['listing_display_priority']->Type ) ) {
            throw new \RuntimeException( 'The pricing-plan schema is not supported. No plans were changed.' );
        }
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
