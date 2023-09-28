<?php
/*
Plugin Name: CSV Price Updater
Description: A WordPress plugin to update product woocommerce product prices using CSV files.
Version: 1.7
Author: Aeros Salaga
Author URI: aerossalaga.com
*/

// Add admin menu page for CSV upload
function csv_price_updater_admin_page() {
    add_menu_page(
        'CSV Price Updater',
        'CSV Price Updater',
        'manage_options',
        'csv-price-updater',
        'csv_price_updater_render_admin_page',
        'dashicons-upload'
    );
}
add_action('admin_menu', 'csv_price_updater_admin_page');

// Render the admin page
function csv_price_updater_render_admin_page() {
    ?>
    <div class="wrap">
        <h1>CSV Price Updater</h1>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csv_price_updater_upload" value="">
            <input type="file" name="csv_file" accept=".csv"><br>
            <input type="text" name="parent_ids" placeholder="comma separated IDs of products parent" style="width: 674px;margin-bottom:5px;"><br>
            <textarea name="excluded_products" placeholder="Paste space separated product IDs to exclude" rows="10" cols="100"></textarea><br>
            <textarea name="included_products" placeholder="Paste space separated product IDs to include" rows="10" cols="100"></textarea><br>
            <label><input type="checkbox" name="dry_run" value="1"> Dry Run</label>
            <?php submit_button('Upload CSV'); ?>
        </form>
    </div>
    <?php
}

// Handle CSV file upload
function csv_price_updater_handle_upload() {
    if(isset($_POST['csv_price_updater_upload'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $csv_file = $_FILES['csv_file']['tmp_name'];

            if (!empty($csv_file)) {            

                $csv_data = array_map('str_getcsv', file($csv_file));
                $headers = array_shift($csv_data);

                $stone_size_index = array_search('stone size', array_map('strtolower', $headers));
                $price_index = array_search('price', array_map('strtolower', $headers));
                $excluded_products = $_REQUEST['excluded_products'] != '' ? explode(' ', $_REQUEST['excluded_products']) : false;
                $included_products = $_REQUEST['included_products'] != '' ? explode(' ', $_REQUEST['included_products']) : false;
                $all_product_ids = [];
                $variant_ids = [];
                $simple_ids = [];
                $dry_run = isset($_REQUEST['dry_run']) ? $_REQUEST['dry_run'] : 0;
                $counter = 0;
                $parent_ids = $_REQUEST['parent_ids'] != '' ? explode(',', $_REQUEST['parent_ids']) : false;

                if($stone_size_index !== false && $price_index !== false) {        

                    foreach ($csv_data as $row) { 
                        $stone_size = $row[$stone_size_index];
                        $price = $row[$price_index];                    

                        // Get products that match the stone size
                        $args = array(
                            'post_type' => array('product', 'product_variation'),
                            'post_status' => 'publish',
                            'posts_per_page' => -1,
                            'tax_query' => array(
                                array(
                                    'taxonomy' => 'pa_stone-size',
                                    'field'    => 'name',
                                    'terms'    => $stone_size,
                                ),
                            ),
                        );

                        if($parent_ids) {
                            $args['post_parent__in'] = $parent_ids;
                        } elseif($excluded_products) {
                            $args['post__not_in'] = array_unique($excluded_products);
                        } elseif($included_products) {
                            $args['post__in'] = array_unique($included_products);
                        }
            
                        $products_query = new WP_Query($args);
            
                        if ($products_query->have_posts()) {
                            while ($products_query->have_posts()) {
                                $products_query->the_post();
            
                                // Get the product ID and update its price
                                $product_id = get_the_ID();
        
                                $product = wc_get_product($product_id);

                                if ($product->get_type() === 'variation') {
                                    // For variations, update the variation price
                                    $current_price = floatval($product->get_price());
                                } elseif ($product->get_type() === 'simple') {
                                    // For simple products, update the product price
                                    $current_price = floatval($product->get_regular_price());
                                } else {
                                    // Skip processing for other product types
                                    continue;
                                }

                                $updated_price = $current_price + $price;

                                if ($product->get_type() === 'variation') {
                                    $variant_ids[] = $product_id;
                                    $all_product_ids[] = $product_id;
                                    
                                    $counter++;

                                    if ($dry_run === 0) {
                                        $product->set_regular_price($updated_price);
                                        $product->save();
                                    }
                                } elseif ($product->get_type() === 'simple') {
                                    $simple_ids[] = $product_id;
                                    $all_product_ids[] = $product_id;
                                    
                                    $counter++;

                                    if ($dry_run === 0) {
                                        update_post_meta($product_id, '_price', $updated_price);
                                    }
                                }                           
                                
                                if($product->get_type() === 'simple' || $product->get_type() === 'variation') {
                                    echo '<div class="notice notice-success"><p>Product ID: '. $product_id .', Old price: '. $current_price .', New price: '. $updated_price .'</p></div>';
                                }
                            }
                        }
            
                        wp_reset_postdata();
                    } 

                    if($dry_run === 0) {
                        echo '<p><strong>Prices Updated: </strong>'. $counter .' products has been updated.</p>';
                    } else {
                        echo '<p><strong>Dry run: </strong>'. $counter .' products found! No product prices has been updated yet.</p>';
                    }
                    echo '<p>Product IDs:</p>'. implode(' ', array_unique($all_product_ids));
                    echo '<p>Product variation IDs:</p>'. implode(' ', array_unique($variant_ids));
                    echo '<p>Product simple IDs:</p>'. implode(' ', array_unique($simple_ids));

                } else { echo '<div class="notice notice-error"><p>An error occur!</p></div>'; }
            } 
        } else { echo '<div class="notice notice-error"><p>Invalid format of CSV file.</p></div>'; }
    }
}
add_action('admin_notices', 'csv_price_updater_handle_upload');