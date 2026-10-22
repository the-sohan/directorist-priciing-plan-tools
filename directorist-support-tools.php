<?php
/**
 * Plugin Name: Directorist Support Tools
 * Description: Preview, repair, and restore legacy pricing-plan display priorities that prevent plans from saving.
 * Version: 1.0.0
 * Author: Sovware
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-3.0-or-later
 */

namespace Sovware\DirectoristSupportTools;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-plan-priority-repair.php';

add_action( 'admin_menu', function () {
    add_management_page( 'Directorist Support Tools', 'Directorist Support Tools', 'manage_options', 'directorist-support-tools', __NAMESPACE__ . '\\render_page' );
} );

add_action( 'admin_post_dst_plan_priority', __NAMESPACE__ . '\\handle_action' );

function handle_action() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You are not allowed to repair pricing plans.', '', [ 'response' => 403 ] );
    }

    check_admin_referer( 'dst_plan_priority' );
    $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

    try {
        $repair = new Plan_Priority_Repair();
        if ( 'repair' === $mode ) {
            $ids = isset( $_POST['plan_ids'] ) && is_array( $_POST['plan_ids'] ) ? wp_unslash( $_POST['plan_ids'] ) : [];
            $result = $repair->repair( $ids );
            $message = sprintf( 'Repaired %d plan(s). Their previous priorities are saved for restoration.', $result );
        } elseif ( 'restore' === $mode ) {
            $result = $repair->restore();
            $message = sprintf( 'Restored %d plan(s).', $result['restored'] );
            if ( $result['skipped'] ) {
                $message .= sprintf( ' Skipped %d changed or missing plan(s); their backup records were kept.', $result['skipped'] );
            }
        } else {
            throw new \RuntimeException( 'Choose a supported action.' );
        }
        $notice = [ 'type' => 'success', 'message' => $message ];
    } catch ( \Throwable $e ) {
        $notice = [ 'type' => 'error', 'message' => $e->getMessage() ];
    }

    set_transient( 'dst_priority_notice_' . get_current_user_id(), $notice, 60 );
    wp_safe_redirect( admin_url( 'tools.php?page=directorist-support-tools' ) );
    exit;
}

function render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $notice_key = 'dst_priority_notice_' . get_current_user_id();
    $notice = get_transient( $notice_key );
    delete_transient( $notice_key );
    $repair = new Plan_Priority_Repair();
    ?>
    <div class="wrap" style="max-width:1100px">
        <h1>Directorist Support Tools</h1>
        <h2>Plan save repair</h2>
        <p>Use this tool when a plan save returns <code>422</code> with <code>The listing_display_priority must be at least 1.</code></p>
        <p>Preview legacy priorities below 1, then select the plans to repair. This tool changes only the selected plans’ display priority to <strong>1</strong>. Packages, prices, quotas, features, and migration state are left alone.</p>
        <?php if ( is_array( $notice ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
        <?php endif; ?>
        <?php
        try {
            $repair->assert_environment();
            $scan = $repair->scan();
            $backup = $repair->backup();
        } catch ( \Throwable $e ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>';
            return;
        }
        ?>
        <p><strong><?php echo esc_html( $scan['total'] ); ?> plan(s) need repair.</strong> Pricing Plans version: <?php echo esc_html( directorist_pricing_plans_version() ); ?>.</p>
        <?php if ( $scan['rows'] ) : ?>
            <?php if ( $scan['total'] > Plan_Priority_Repair::BATCH_SIZE ) : ?>
                <p>Showing the first <?php echo esc_html( Plan_Priority_Repair::BATCH_SIZE ); ?> plans. Repair these, then scan the remaining plans on this page.</p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="dst_plan_priority">
                <input type="hidden" name="mode" value="repair">
                <?php wp_nonce_field( 'dst_plan_priority' ); ?>
                <table class="widefat striped">
                    <thead><tr><th style="width:100px"><label><input type="checkbox" id="dst-select-all"> Select all</label></th><th>Plan ID</th><th>Plan title</th><th>Current priority</th><th>Repair to</th></tr></thead>
                    <tbody>
                    <?php foreach ( $scan['rows'] as $row ) : ?>
                        <tr><td><label><input type="checkbox" name="plan_ids[]" value="<?php echo esc_attr( $row->id ); ?>"><span class="screen-reader-text">Select plan <?php echo esc_html( $row->id ); ?></span></label></td><td><?php echo esc_html( $row->id ); ?></td><td><?php echo esc_html( $row->title ); ?></td><td><?php echo null === $row->listing_display_priority ? 'NULL' : esc_html( $row->listing_display_priority ); ?></td><td>1</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( 'Repair selected plans' ); ?>
            </form>
            <script>
                document.getElementById('dst-select-all').addEventListener('change', function () {
                    this.closest('form').querySelectorAll('input[name="plan_ids[]"]').forEach(function (input) { input.checked = this.checked; }, this);
                });
            </script>
        <?php else : ?>
            <div class="notice notice-success inline"><p>No invalid plan priorities found. This repair does not apply to other plan-save errors.</p></div>
        <?php endif; ?>

        <h2>Restore previous values</h2>
        <?php if ( ! empty( $backup['rows'] ) ) : ?>
            <p><?php echo esc_html( count( $backup['rows'] ) ); ?> plan(s) have saved previous values. Last repair: <?php echo esc_html( $backup['updated_at'] ); ?> UTC.</p>
            <p>Restoring may bring back the original save error. Plans whose priority has since changed from 1 are skipped. Other plan fields are never restored or overwritten.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="dst_plan_priority">
                <input type="hidden" name="mode" value="restore">
                <?php wp_nonce_field( 'dst_plan_priority' ); ?>
                <?php submit_button( 'Restore previous priorities', 'secondary' ); ?>
            </form>
        <?php else : ?>
            <p>No priorities are waiting for restoration. Activating this plugin does not change any plans. Saved backups remain available after deactivation and reactivation.</p>
        <?php endif; ?>
        <p>After repair, reload the pricing-plan editor, save a small test edit, then reload again to verify it persisted.</p>
    </div>
    <?php
}
