<?php

namespace Sovware\DirectoristSupportTools;

defined( 'ABSPATH' ) || exit;

add_filter( 'directorist_pricing_plan_admin_plan_request', __NAMESPACE__ . '\\normalize_legacy_plan_priority' );

/** Protect the Pricing Plans 4.x admin update path from stale legacy zeroes. */
function normalize_legacy_plan_priority( $request ) {
    if ( ! $request instanceof \WP_REST_Request || ! function_exists( 'directorist_pricing_plans_version' ) ) {
        return $request;
    }
    $version = directorist_pricing_plans_version();
    if ( version_compare( $version, '4.0', '<' ) || version_compare( $version, '5.0', '>=' ) ) {
        return $request;
    }
    $priority = $request->get_param( 'listing_display_priority' );
    if ( 0 !== $priority && '0' !== $priority ) {
        return $request;
    }
    $id = filter_var( $request->get_param( 'id' ), FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
    if ( false === $id ) {
        return $request;
    }
    global $wpdb;
    $plan = $wpdb->get_row( $wpdb->prepare( "SELECT listing_display_priority FROM `{$wpdb->prefix}directorist_plans` WHERE id = %d", $id ) );
    if ( $plan ) {
        // A stale tab must not replace a newer, valid ranking with the minimum.
        $request->set_param( 'listing_display_priority', max( 1, (int) $plan->listing_display_priority ) );
    }
    return $request;
}
