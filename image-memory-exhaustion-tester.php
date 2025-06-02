<?php
/*
Plugin Name: Image Memory Exhaustion Tester
Description: A plugin to simulate memory exhaustion due to unattached images, specifically testing previous_image_link() -> adjacent_image_link() -> get_children() chain.
Version: 1.0.0
Author: SirLouen <sir.louen@gmail.com>
License: GPL-2.0+
Text Domain: image-memory-exhaustion-tester
Domain Path: /languages
*/

// Add admin menu
add_action('admin_menu', 'memory_tester_admin_menu');

function memory_tester_admin_menu() {
    add_management_page(
        'Image Memory Exhaustion Tester',
        'Memory Tester',
        'manage_options',
        'memory-tester',
        'memory_tester_admin_page'
    );
}

function memory_tester_admin_page() {
    $results = '';
    $test_executed = false;
    
    if (isset($_POST['run_test']) && wp_verify_nonce($_POST['memory_tester_nonce'], 'run_memory_test')) {
        $results = reproduce_memory_exhaustion();
        $test_executed = true;
    }
    
    ?>
    <div class="wrap">
        <h1>Image Memory Exhaustion Tester</h1>
        <p>This tool simulates memory exhaustion by loading all unattached images into memory.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('run_memory_test', 'memory_tester_nonce'); ?>
            <p>
                <input type="submit" name="run_test" class="button button-primary" value="Run Full Memory Test" />
            </p>
        </form>
        
        <?php if ($test_executed): ?>
            <div class="card card-body">
                <h3>Test Results:</h3>
                <pre style="background: #f1f1f1; padding: 15px; border-radius: 4px; overflow-x: auto;"><?php echo esc_html($results); ?></pre>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function reproduce_memory_exhaustion() {
    $overall_start_time = microtime(true);
    $overall_start_memory = memory_get_usage(true);
    $overall_start_peak = memory_get_peak_usage(true);
    
    $output = "=== Memory Exhaustion Test Results ===\n";
    $output .= "Start Time: " . date('Y-m-d H:i:s') . "\n";
    $output .= "Initial Memory Usage: " . format_bytes($overall_start_memory) . "\n";
    $output .= "Initial Peak Memory: " . format_bytes($overall_start_peak) . "\n";
    
    // Test 1: get_children() function.
    $output .= "--- TEST 1: get_children() Function ---\n";
    $test1_start_time = microtime(true);
    $test1_start_memory = memory_get_usage(true);
    $test1_start_peak = memory_get_peak_usage(true);
    
    $args = array(
        'post_parent' => 0,
        'post_type' => 'attachment',
        'numberposts' => -1,
        'post_status' => 'any'
    );
    
    $children = get_children($args);
    $attachment_count = count($children);
    
    $test1_end_time = microtime(true);
    $test1_end_memory = memory_get_usage(true);
    $test1_end_peak = memory_get_peak_usage(true);
    
    $test1_execution_time = $test1_end_time - $test1_start_time;
    $test1_memory_increase = $test1_end_memory - $test1_start_memory;
    $test1_peak_increase = $test1_end_peak - $test1_start_peak;
    
    $output .= "Attachments Found: " . $attachment_count . "\n";
    $output .= "Execution Time: " . round($test1_execution_time, 4) . " seconds\n";
    $output .= "Memory Increase: " . format_bytes($test1_memory_increase) . "\n";
    $output .= "Peak Memory Increase: " . format_bytes($test1_peak_increase) . "\n\n";
    
    // Test 2: previous_image_link() -> adjacent_image_link() -> get_children() chain.
    $output .= "--- TEST 2: previous_image_link() Chain (Simulating Unattached Attachment Page) ---\n";
    $test2_start_time = microtime(true);
    $test2_start_memory = memory_get_usage(true);
    $test2_start_peak = memory_get_peak_usage(true);
    
    $previous_calls = 0;
    $previous_results = array();
    
    if (!empty($children)) {
        $unattached_images = array_filter($children, function($attachment) {
            return $attachment->post_parent == 0 && wp_attachment_is_image($attachment->ID);
        });
        
        $output .= "Found " . count($unattached_images) . " unattached images to test\n";
        
        foreach ($unattached_images as $attachment) {
            global $post, $wp_query;
            $original_post = $post;
            $original_query = $wp_query;
            
            $post = $attachment;
            setup_postdata($post);
            
            $wp_query->is_attachment = true;
            $wp_query->is_single = true;
            $wp_query->is_singular = true;
            
            $prev_link = previous_image_link(false, '');
            $previous_calls++;
            
            $previous_results[] = array(
                'prev_link' => $prev_link,
                'post_id' => $attachment->ID,
                'post_parent' => $attachment->post_parent
            );
            
            $post = $original_post;
            $wp_query = $original_query;
            wp_reset_postdata();
            
            // Using blocks of 100.
            if ($previous_calls % 100 == 0) {
                $current_memory = memory_get_usage(true);
                $current_peak = memory_get_peak_usage(true);
                $output .= "Progress: " . $previous_calls . " images processed, Current Memory: " . format_bytes($current_memory) . ", Peak: " . format_bytes($current_peak) . "\n";
            }
        }
    } else {
        $output .= "No attachments found to test previous_image_link()\n";
    }
    
    $test2_end_time = microtime(true);
    $test2_end_memory = memory_get_usage(true);
    $test2_end_peak = memory_get_peak_usage(true);
    
    $test2_execution_time = $test2_end_time - $test2_start_time;
    $test2_memory_increase = $test2_end_memory - $test2_start_memory;
    $test2_peak_increase = $test2_end_peak - $test2_start_peak;
    
    $output .= "Previous Image Link Calls: " . $previous_calls . "\n";
    $output .= "Unattached Images Tested: " . count($previous_results) . "\n";
    $output .= "Execution Time: " . round($test2_execution_time, 4) . " seconds\n";
    $output .= "Memory Increase: " . format_bytes($test2_memory_increase) . "\n";
    $output .= "Peak Memory Increase: " . format_bytes($test2_peak_increase) . "\n\n";
    
    // Test 3: Direct adjacent_image_link() with post_parent=0 simulation.
    // Not 100% confident this is the best way to test this.
    $output .= "--- TEST 3: Direct adjacent_image_link() with post_parent=0 Simulation ---\n";
    $test3_start_time = microtime(true);
    $test3_start_memory = memory_get_usage(true);
    $test3_start_peak = memory_get_peak_usage(true);
    
    $adjacent_calls = 0;
    $adjacent_results = array();
    
    if (!empty($children)) {
        
        foreach ($children as $attachment) {
            global $post;
            $original_post = $post;
            $post = $attachment;
            setup_postdata($post);
            
            $prev_link = adjacent_image_link(false, '', true);
            $next_link = adjacent_image_link(false, '', false);
            
            $adjacent_calls += 2;
            
            $adjacent_results[] = array(
                'prev' => $prev_link,
                'next' => $next_link,
                'post_id' => $attachment->ID
            );
            
            $post = $original_post;
            wp_reset_postdata();
            
            // Using blocks of 50.
            if ((count($adjacent_results) % 50 == 0)) {
                $current_memory = memory_get_usage(true);
                $current_peak = memory_get_peak_usage(true);
                $output .= "Progress: " . count($adjacent_results) . " attachments processed, Current Memory: " . format_bytes($current_memory) . ", Peak: " . format_bytes($current_peak) . "\n";
            }
        }
    }
    
    $test3_end_time = microtime(true);
    $test3_end_memory = memory_get_usage(true);
    $test3_end_peak = memory_get_peak_usage(true);
    
    $test3_execution_time = $test3_end_time - $test3_start_time;
    $test3_memory_increase = $test3_end_memory - $test3_start_memory;
    $test3_peak_increase = $test3_end_peak - $test3_start_peak;
    
    $output .= "Adjacent Image Link Calls: " . $adjacent_calls . "\n";
    $output .= "Attachments Tested: " . count($adjacent_results) . "\n";
    $output .= "Execution Time: " . round($test3_execution_time, 4) . " seconds\n";
    $output .= "Memory Increase: " . format_bytes($test3_memory_increase) . "\n";
    $output .= "Peak Memory Increase: " . format_bytes($test3_peak_increase) . "\n\n";
    
    // Overall results.
    $overall_end_time = microtime(true);
    $overall_end_memory = memory_get_usage(true);
    $overall_end_peak = memory_get_peak_usage(true);
    
    $overall_execution_time = $overall_end_time - $overall_start_time;
    $overall_memory_increase = $overall_end_memory - $overall_start_memory;
    $overall_peak_increase = $overall_end_peak - $overall_start_peak;
    
    $output .= "--- OVERALL RESULTS ---\n";
    $output .= "Total Execution Time: " . round($overall_execution_time, 4) . " seconds\n";
    $output .= "Final Memory Usage: " . format_bytes($overall_end_memory) . "\n";
    $output .= "Final Peak Memory: " . format_bytes($overall_end_peak) . "\n";
    $output .= "Total Memory Increase: " . format_bytes($overall_memory_increase) . "\n";
    $output .= "Total Peak Memory Increase: " . format_bytes($overall_peak_increase) . "\n\n";
    
    // Memory limit information.
    $memory_limit = ini_get('memory_limit');
    $output .= "PHP Memory Limit: " . $memory_limit . "\n";
    
    if ($memory_limit !== '-1') {
        $limit_bytes = wp_convert_hr_to_bytes($memory_limit);
        $usage_percentage = ($overall_end_peak / $limit_bytes) * 100;
        $output .= "Memory Usage: " . round($usage_percentage, 2) . "% of limit\n";
    }
    
    return $output;
}

// Inspired by https://stackoverflow.com/a/2510434.
function format_bytes($size, $precision = 2) {
    if ($size <= 0) {
        return '0 B';
    }
    
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
}

