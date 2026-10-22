<?php
/** Local-only checks of the official plan-save path with the compatibility guard. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! preg_match( '/\.(local|test)$/', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
    throw new RuntimeException( 'Run only with WP-CLI on a disposable .local or .test project.' );
}
require_once WP_PLUGIN_DIR . '/directorist-support-tools/directorist-support-tools.php';

global $wpdb;
wp_set_current_user( 1 );
$plans = $wpdb->prefix . 'directorist_plans';
$ids = [];
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$controller = directorist_pricing_plans_singleton( \DirectoristPricingPlan\App\Http\Controllers\Admin\PlanController::class );
$repository = directorist_pricing_plans_singleton( \DirectoristPricingPlan\App\Repositories\Admin\PlanRepository::class );
$types = get_terms( [ 'taxonomy' => 'atbdp_listing_types', 'hide_empty' => false, 'fields' => 'ids', 'number' => 1 ] );
$assert( ! is_wp_error( $types ) && $types, 'A local directory type is required' );
$request_for = static function ( $id ) use ( $repository ) {
    $data = json_decode( wp_json_encode( $repository->get_single( $id ) ), true );
    unset( $data['created_at'], $data['updated_at'], $data['fallback_plan_id'] );
    foreach ( $data as $key => $value ) {
        if ( 0 === strpos( $key, 'is_' ) ) { $data[ $key ] = (bool) (int) $value; }
        elseif ( null === $value ) { $data[ $key ] = ''; }
    }
    $request = new WP_REST_Request( 'PATCH' );
    $request->set_body_params( $data );
    return $request;
};
$save = static function ( $request ) use ( $controller ) {
    return $controller->update( new \DirectoristPricingPlan\WpMVC\RequestValidator\Validator( $request, new \DirectoristPricingPlan\WpMVC\RequestValidator\Mime() ), $request );
};
$priority = static function ( $id ) use ( $wpdb, $plans ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT listing_display_priority FROM `{$plans}` WHERE id=%d", $id ) ); };
try {
    foreach ( [ 0, 7, 0 ] as $value ) {
        $assert( 1 === $wpdb->insert( $plans, [ 'title' => 'DST request guard QA', 'directory_type_id' => (int) $types[0], 'listing_display_priority' => $value, 'allowed_listings' => 5, 'allowed_featured_listings' => 1, 'interval_type' => 'lifetime', 'created_at' => current_time( 'mysql' ) ] ), 'Could not create fixture' );
        $ids[] = (int) $wpdb->insert_id;
    }
    $request = $request_for( $ids[0] );
    $request->set_param( 'description', 'Guard save verified' );
    $request->set_param( 'is_allowed_unlimited_listings', true );
    $assert( 200 === $save( $request )['status_code'] && 1 === $priority( $ids[0] ), 'Legacy zero still blocks the official save path' );
    $stored = $repository->get_single( $ids[0] );
    $assert( 'Guard save verified' === $stored->description && 1 === (int) $stored->is_allowed_unlimited_listings && 5 === (int) $stored->allowed_listings, 'The intended edit did not persist' );
    echo "PASS: legacy-zero plan saves description and Unlimited Listing through the official API\n";

    $request = $request_for( $ids[1] );
    $request->set_param( 'listing_display_priority', '0' );
    $assert( 200 === $save( $request )['status_code'] && 7 === $priority( $ids[1] ), 'A stale editor overwrote an existing valid priority' );
    echo "PASS: stale-zero request preserves the current valid priority\n";

    $request = $request_for( $ids[2] );
    $request->set_param( 'price', 'invalid-price' );
    try { $save( $request ); throw new RuntimeException( 'Unrelated validation was bypassed' ); }
    catch ( \DirectoristPricingPlan\WpMVC\Exceptions\Exception $e ) { /* Expected. */ }
    $assert( 0 === $priority( $ids[2] ), 'Guard wrote data before validation completed' );
    foreach ( [ -1, '', null, false, [] ] as $invalid ) {
        $request = $request_for( $ids[2] );
        $request->set_param( 'listing_display_priority', $invalid );
        $guarded = \Sovware\DirectoristSupportTools\normalize_legacy_plan_priority( $request );
        $assert( $guarded->get_param( 'listing_display_priority' ) === $invalid, 'Guard changed a non-legacy input' );
    }
    echo "PASS: unrelated validation remains active and non-legacy inputs are untouched\n";
} finally {
    foreach ( $ids as $id ) {
        $wpdb->delete( $wpdb->prefix . 'directorist_plan_features', [ 'plan_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'directorist_plan_app_configurations', [ 'plan_id' => $id ] );
        $wpdb->delete( $plans, [ 'id' => $id ] );
    }
    echo "Request guard QA fixtures removed\n";
}
