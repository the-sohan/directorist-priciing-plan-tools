<?php
/** Run only on the disposable local WordPress project with wp eval-file. */
require_once WP_PLUGIN_DIR . '/directorist-support-tools/directorist-support-tools.php';
// Reproduce the unprotected product failure before testing the manual repair.
remove_filter( 'directorist_pricing_plan_admin_plan_request', 'Sovware\\DirectoristSupportTools\\normalize_legacy_plan_priority' );

use Sovware\DirectoristSupportTools\Plan_Priority_Repair;

global $wpdb;
wp_set_current_user(1);
if (get_option(Plan_Priority_Repair::BACKUP_OPTION, false) !== false) {
    throw new Exception('An existing repair backup must not be overwritten by QA.');
}
$repair = new Plan_Priority_Repair();
$repair->assert_environment();
$ids = [];
$trigger = $wpdb->prefix . 'dst_qa_priority_failure';
$plans = $wpdb->prefix . 'directorist_plans';
$packages_before = hash('sha256', wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}directorist_user_packages ORDER BY id")));
$options_before = get_option('atbdp_option');
$directory_ids = get_terms(['taxonomy'=>'atbdp_listing_types','hide_empty'=>false,'fields'=>'ids','number'=>1]);
if (is_wp_error($directory_ids) || !$directory_ids) throw new Exception('A local directory type is required.');
$assert = static function($ok, $message) { if (!$ok) throw new Exception($message); };
$die_handler = static function() { return static function() { throw new RuntimeException('DST access blocked'); }; };
$saved_request = $_REQUEST;
add_filter('wp_die_handler', $die_handler);
try {
    wp_set_current_user(0);
    try { \Sovware\DirectoristSupportTools\handle_action(); throw new Exception('Unauthenticated repair was allowed'); }
    catch(RuntimeException $e) { $assert($e->getMessage()==='DST access blocked','Unexpected capability result'); }
    wp_set_current_user(1);
    $_REQUEST['_wpnonce']='invalid';
    try { \Sovware\DirectoristSupportTools\handle_action(); throw new Exception('Nonce-free repair was allowed'); }
    catch(RuntimeException $e) { $assert($e->getMessage()==='DST access blocked','Unexpected nonce result'); }
} finally {
    remove_filter('wp_die_handler',$die_handler);
    $_REQUEST=$saved_request;
    wp_set_current_user(1);
}
echo "PASS: capability and nonce checks block unauthorized repair actions\n";
foreach([[],[[]],[-1],['1 OR 1=1']] as $selection) {
    try { $repair->repair($selection); throw new Exception('Malformed selection was accepted'); }
    catch(RuntimeException $e) { /* Expected rejection before any write. */ }
}
$assert(get_option(Plan_Priority_Repair::BACKUP_OPTION,false)===false,'Invalid request wrote a backup');
echo "PASS: malformed selections are rejected before mutation\n";
$value = static function($id) use ($wpdb,$plans) { return (int)$wpdb->get_var($wpdb->prepare("SELECT listing_display_priority FROM {$plans} WHERE id=%d",$id)); };
$controller = directorist_pricing_plans_singleton(\DirectoristPricingPlan\App\Http\Controllers\Admin\PlanController::class);
$make_request = static function($id) {
    $data=directorist_pricing_plans_singleton(\DirectoristPricingPlan\App\Repositories\Admin\PlanRepository::class)->get_single($id);
    $data=json_decode(wp_json_encode($data),true);
    unset($data['created_at'],$data['updated_at'],$data['fallback_plan_id']);
    foreach($data as $key=>$v) {
        if(strpos($key,'is_')===0) $data[$key]=(bool)(int)$v;
        elseif($v===null) $data[$key]='';
    }
    $data['description']='DST QA persisted edit';
    $request=new WP_REST_Request('PATCH');
    $request->set_body_params($data);
    return $request;
};
$validator = static function($request) { return new \DirectoristPricingPlan\WpMVC\RequestValidator\Validator($request,new \DirectoristPricingPlan\WpMVC\RequestValidator\Mime()); };

try {
    foreach([0,0,7] as $i=>$priority) {
        $ok=$wpdb->insert($plans,['title'=>'DST QA fixture '.$i,'directory_type_id'=>(int)$directory_ids[0],'listing_display_priority'=>$priority,'allowed_listings'=>1,'interval_type'=>'lifetime','created_at'=>current_time('mysql')]);
        $assert($ok===1,'Could not create local fixture');
        $ids[]=(int)$wpdb->insert_id;
    }
    $scan=$repair->scan();
    $scanned=array_map('intval',array_column($scan['rows'],'id'));
    $assert(in_array($ids[0],$scanned,true)&&in_array($ids[1],$scanned,true)&&!in_array($ids[2],$scanned,true),'Scan did not distinguish valid priorities');
    echo "PASS: preview identifies invalid priorities and skips valid priorities\n";

    $request=$make_request($ids[0]);
    $v=$validator($request);
    try { $controller->update($v,$request); throw new Exception('Expected the original save failure'); }
    catch(\DirectoristPricingPlan\WpMVC\Exceptions\Exception $e) {
        $assert(isset($v->errors['listing_display_priority']),'Unexpected save validation failure');
    }
    echo "PASS: original plan-save validation failure reproduced\n";

    // Force the second UPDATE to fail after the first one has run. Both data
    // updates and the new backup must roll back together.
    $trigger_sql="CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `{$plans}` FOR EACH ROW BEGIN IF NEW.id=".$ids[1]." AND NEW.listing_display_priority=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='DST QA forced failure'; END IF; END";
    $assert($wpdb->query($trigger_sql)!==false,'Could not create local rollback-test trigger');
    $was_suppressed=$wpdb->suppress_errors(true);
    try { $repair->repair([$ids[0],$ids[1]]); throw new Exception('Expected rollback'); }
    catch(RuntimeException $e) { $assert(strpos($e->getMessage(),'rolled back')!==false,'Unexpected rollback failure'); }
    finally { $wpdb->suppress_errors($was_suppressed); $wpdb->query("DROP TRIGGER IF EXISTS `{$trigger}`"); }
    $assert($value($ids[0])===0&&$value($ids[1])===0,'Partial repair was not rolled back');
    $assert(get_option(Plan_Priority_Repair::BACKUP_OPTION,false)===false,'Rolled-back backup was retained');
    echo "PASS: forced database failure rolls back both plans and backup\n";

    $assert($repair->repair($ids)===2,'Selected repair count mismatch');
    $assert($value($ids[0])===1&&$value($ids[1])===1&&$value($ids[2])===7,'Repair changed the wrong priorities');
    $assert(count($repair->backup()['rows'])===2,'Original values not backed up');
    $assert($repair->repair($ids)===0,'Repair is not idempotent');
    echo "PASS: selective repair, backup, valid-value preservation, repeated repair\n";

    $request=$make_request($ids[0]);
    $response=$controller->update($validator($request),$request);
    $assert($response['status_code']===200,'Plan API save did not succeed after repair');
    $assert($wpdb->get_var($wpdb->prepare("SELECT description FROM {$plans} WHERE id=%d",$ids[0]))==='DST QA persisted edit','API edit did not persist');
    echo "PASS: repaired plan accepts and persists the original API save flow\n";

    $wpdb->update($plans,['listing_display_priority'=>9],['id'=>$ids[1]]);
    $result=$repair->restore();
    $assert($result===['restored'=>1,'skipped'=>1],'Restore conflict counts mismatch');
    $assert($value($ids[0])===0&&$value($ids[1])===9&&$value($ids[2])===7,'Restore overwrote a later priority edit');
    $assert($wpdb->get_var($wpdb->prepare("SELECT description FROM {$plans} WHERE id=%d",$ids[0]))==='DST QA persisted edit','Restore overwrote another plan field');
    $assert(count($repair->backup()['rows'])===1,'Skipped backup was not retained');
    $wpdb->update($plans,['listing_display_priority'=>1],['id'=>$ids[1]]);
    $assert($repair->restore()===['restored'=>1,'skipped'=>0],'Remaining restore failed');
    $assert(empty($repair->backup()['rows']),'Restored backup entries remain');
    echo "PASS: restoration preserves unrelated edits and skips changed priorities\n";

    $assert($packages_before===hash('sha256',wp_json_encode($wpdb->get_results("SELECT * FROM {$wpdb->prefix}directorist_user_packages ORDER BY id"))),'Packages changed during repair');
    $assert($options_before===get_option('atbdp_option'),'Directorist settings changed during repair');
    echo "PASS: packages and Directorist settings unchanged\n";
} finally {
    $wpdb->query("DROP TRIGGER IF EXISTS `{$trigger}`");
    foreach($ids as $id) {
        $wpdb->delete($wpdb->prefix.'directorist_plan_features',['plan_id'=>$id]);
        $wpdb->delete($wpdb->prefix.'directorist_plan_app_configurations',['plan_id'=>$id]);
        $wpdb->delete($plans,['id'=>$id]);
    }
    delete_option(Plan_Priority_Repair::BACKUP_OPTION);
    echo "Local QA fixtures removed\n";
}
