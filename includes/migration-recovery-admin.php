<?php

namespace Sovware\DirectoristSupportTools;

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function (): void {
    add_management_page( 'Directorist Migration Recovery', 'Directorist Migration Recovery', 'manage_options', 'directorist-migration-recovery', __NAMESPACE__ . '\\render_migration_recovery' );
} );

add_action( 'admin_post_dst_listing_recovery', __NAMESPACE__ . '\\handle_listing_recovery' );

function handle_listing_recovery(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You are not allowed to recover listing migrations.', '', [ 'response' => 403 ] );
    }
    check_admin_referer( 'dst_listing_recovery' );
    $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
    try {
        $recovery = new Listing_Migration_Recovery();
        if ( 'download' === $mode ) {
            $backup = $recovery->backup();
            if ( ! $backup ) {
                throw new \RuntimeException( 'No recovery backup is available.' );
            }
            nocache_headers();
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="directorist-listing-recovery-backup.json"' );
            echo wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            exit;
        }
        if ( 'batch' === $mode ) {
            if ( ! isset( $_POST['reviewed'] ) || 'yes' !== $_POST['reviewed'] ) {
                throw new \RuntimeException( 'Review and confirm the batch before recovering it.' );
            }
            $token = isset( $_POST['preview_token'] ) && is_string( $_POST['preview_token'] ) ? wp_unslash( $_POST['preview_token'] ) : '';
            $result = $recovery->recover_batch( $token );
            $message = sprintf( 'Processed %d listings; normalized %d plan assignments. ', $result['processed'], $result['written'] );
            $message .= $result['finished'] ? 'Listing migration recovery is complete. Review your listings, then deactivate the support tool.' : 'Review the next batch below to continue.';
        } elseif ( 'restore' === $mode ) {
            if ( ! isset( $_POST['confirm_restore'] ) || 'yes' !== $_POST['confirm_restore'] ) {
                throw new \RuntimeException( 'Confirm restoration before continuing.' );
            }
            $count = $recovery->restore();
            $message = sprintf( 'Restored %d listing assignments and the original migration progress. The original stalled state is back; recovery is no longer active.', $count );
        } else {
            throw new \RuntimeException( 'Choose a supported recovery action.' );
        }
        $notice = [ 'type' => 'success', 'message' => $message ];
    } catch ( \Throwable $e ) {
        $notice = [ 'type' => 'error', 'message' => $e->getMessage() ];
    }
    set_transient( 'dst_recovery_notice_' . get_current_user_id(), $notice, 120 );
    wp_safe_redirect( admin_url( 'tools.php?page=directorist-migration-recovery' ) );
    exit;
}

function render_migration_recovery(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $recovery = new Listing_Migration_Recovery();
    $notice_key = 'dst_recovery_notice_' . get_current_user_id();
    $notice = get_transient( $notice_key );
    delete_transient( $notice_key );
    echo '<div class="wrap" style="max-width:1150px"><h1>Directorist Migration Recovery</h1>';
    echo '<p>Recover a listing migration whose progress is unfinished even though it is recorded as completed. Each action processes one batch from the saved position using Pricing Plans’ own listing migration.</p>';
    if ( is_array( $notice ) ) {
        echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
    }
    try {
        $backup = $recovery->backup();
        try {
            $preview = $recovery->preview();
        } catch ( \RuntimeException $e ) {
            $preview = null;
            $complete = $backup && 'completed' === $backup['status'] && null === get_option( Listing_Migration_Recovery::STATE_OPTION, null ) && false !== strpos( $e->getMessage(), 'No supported unfinished' );
            $message = $complete ? 'Recovery is complete. No further listing batch is needed.' : $e->getMessage();
            echo '<div class="notice notice-info inline"><p>' . esc_html( $message ) . '</p></div>';
        }
        if ( $preview ) {
            echo '<h2>Review the next batch</h2><table class="widefat striped" style="max-width:750px"><tbody>';
            foreach ( [
                'Saved progress' => $preview['state']['processed'] . ' / ' . $preview['state']['total'],
                'Saved listing position' => $preview['state']['cursor'],
                'Listings after that position' => $preview['remaining'],
                'Listings in this batch' => count( $preview['rows'] ),
                'Plan assignments to normalize' => $preview['will_write'],
            ] as $label => $value ) {
                echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
            }
            echo '</tbody></table><p>The stored total is historical. The preview counts current listings after the saved position. Listings with a valid legacy assignment or no eligible package retain their plan metadata.</p>';
            echo '<p>The first batch saves the current progress and each affected listing’s plan values. Later batches add to that backup. Packages, billing dates, prices and quotas are not repaired by this action.</p>';
            echo '<details><summary>Review listing IDs and plan assignments</summary><table class="widefat striped"><thead><tr><th>Listing ID</th><th>Current plan values</th><th>Eligible package plan</th><th>Action</th></tr></thead><tbody>';
            foreach ( $preview['rows'] as $row ) {
                $action = $row['will_write'] ? 'Normalize to eligible package plan' : ( $row['valid_legacy'] ? 'Keep valid legacy assignment' : 'Keep current values; no eligible package' );
                echo '<tr><td>' . esc_html( (string) $row['id'] ) . '</td><td>' . esc_html( wp_json_encode( $row['before'] ) ) . '</td><td>' . esc_html( (string) $row['target_plan_id'] ) . '</td><td>' . esc_html( $action ) . '</td></tr>';
            }
            echo '</tbody></table></details><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="dst_listing_recovery"><input type="hidden" name="mode" value="batch"><input type="hidden" name="preview_token" value="' . esc_attr( $preview['token'] ) . '">';
            wp_nonce_field( 'dst_listing_recovery' );
            echo '<p><label><input type="checkbox" name="reviewed" value="yes" required> I reviewed this batch and want to recover it.</label></p>';
            submit_button( $preview['rows'] ? 'Recover next ' . count( $preview['rows'] ) . ' listings' : 'Finalize saved migration' );
            echo '</form>';
        }
        if ( $backup ) {
            echo '<h2>Recovery backup</h2><p>Status: <strong>' . esc_html( $backup['status'] ) . '</strong>. Saved assignments: <strong>' . count( $backup['rows'] ) . '</strong>. Batches: <strong>' . (int) $backup['batches'] . '</strong>.</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dst_listing_recovery"><input type="hidden" name="mode" value="download">';
            wp_nonce_field( 'dst_listing_recovery' );
            submit_button( 'Download recovery backup', 'secondary' );
            echo '</form>';
            if ( in_array( $backup['status'], [ 'active', 'completed' ], true ) ) {
                echo '<details><summary>Restore the original state</summary><p>Restoration returns the backed-up plan values and original progress, including the original stalled condition. It stops if an affected assignment or progress has changed since recovery. Other completed migrations are preserved.</p>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dst_listing_recovery"><input type="hidden" name="mode" value="restore">';
                wp_nonce_field( 'dst_listing_recovery' );
                echo '<p><label><input type="checkbox" name="confirm_restore" value="yes" required> Restore the saved assignments and original stalled progress.</label></p>';
                submit_button( 'Restore recovery backup', 'secondary' );
                echo '</form></details>';
            }
        }
    } catch ( \Throwable $e ) {
        echo '<div class="notice notice-error inline"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
    }
    echo '</div>';
}
