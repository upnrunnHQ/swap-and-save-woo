<?php
/*
Plugin Name: WooCommerce Swap & Save (AJAX)
Description: Allow users to swap cart items with similar cheaper products (AJAX-based).
Version: 1.2.1
Author: Kishores
Text Domain: sas-plugin
*/

if (!defined('ABSPATH')) exit;

// Enqueue JavaScript for AJAX
add_action('wp_enqueue_scripts', 'sas_enqueue_scripts');
function sas_enqueue_scripts() {
    if (is_cart()) {
        wp_enqueue_script(
            'sas-swap-ajax',
            esc_url(plugin_dir_url(__FILE__) . 'sas-swap.js'),
            array('jquery'),
            '1.2.1',
            true
        );

        wp_localize_script('sas-swap-ajax', 'sas_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('sas_nonce'),
        ));
    }
}

// Add Swap & Save options under product name in cart
add_filter('woocommerce_cart_item_name', 'sas_add_swap_options', 10, 3);
function sas_add_swap_options($product_name, $cart_item, $cart_item_key) {
    if (!is_cart()) return $product_name;

    $product_id = $cart_item['product_id'];
    $product = wc_get_product($product_id);

    if (!$product || !$product->exists()) return $product_name;

    $category_ids = $product->get_category_ids();

    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 3,
        'post__not_in' => array($product_id),
        'tax_query' => array(
            array(
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $category_ids,
            ),
        ),
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_price',
                'value' => $product->get_price(),
                'compare' => '<',
                'type' => 'NUMERIC'
            ),
            array(
                'key' => '_price',
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC'
            ),
        ),
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $product_name .= '<div class="sas-swap-save" style="margin-top:10px;">';
        $product_name .= '<strong>💡 ' . esc_html__('Swap & Save', 'sas-plugin') . ':</strong>';
        $product_name .= '<ul style="list-style:none;margin:5px 0;padding:0;">';

        while ($query->have_posts()) {
            $query->the_post();
            $alt_product = wc_get_product(get_the_ID());
            if (!$alt_product || !$alt_product->exists()) continue;

            $price_diff = $product->get_price() - $alt_product->get_price();
            $product_name .= '<li style="margin-bottom:5px;">';
            $product_name .= '<button type="button" class="sas-swap-button" data-original="' . esc_attr($product_id) . '" data-swap="' . get_the_ID() . '" data-key="' . esc_attr($cart_item_key) . '" style="background:none;border:none;padding:0;color:#0073aa;text-decoration:underline;cursor:pointer;">';
            $product_name .= esc_html(get_the_title()) . ' - ' . wc_price($alt_product->get_price()) . ' (↓' . wc_price($price_diff) . ')</button>';
            $product_name .= '</li>';
        }

        $product_name .= '</ul><div class="sas-swap-message" style="color:green;"></div></div>';
        wp_reset_postdata();
    }

    return $product_name;
}

// Handle AJAX swap request
add_action('wp_ajax_sas_swap_product', 'sas_handle_ajax_swap');
add_action('wp_ajax_nopriv_sas_swap_product', 'sas_handle_ajax_swap');
function sas_handle_ajax_swap() {
    check_ajax_referer('sas_nonce', 'nonce');

    $original_id = intval($_POST['original_id']);
    $swap_id     = intval($_POST['swap_id']);
    $item_key    = sanitize_text_field($_POST['cart_item_key']);

    $cart = WC()->cart;

    if (!$cart || !isset($cart->cart_contents[$item_key])) {
        wp_send_json_error(['message' => __('❌ Swap failed: Item not found in cart.', 'sas-plugin')]);
    }

    $item = $cart->get_cart_item($item_key);

    if ((int) $item['product_id'] !== $original_id) {
        wp_send_json_error(['message' => __('❌ Swap failed: Product mismatch.', 'sas-plugin')]);
    }

    $qty = $item['quantity'];
    $cart->remove_cart_item($item_key);

    $existing_key = false;

    foreach ($cart->get_cart() as $key => $cart_item) {
        if ((int) $cart_item['product_id'] === $swap_id) {
            $existing_key = $key;
            break;
        }
    }

    if ($existing_key) {
        $existing_qty = $cart->get_cart_item($existing_key)['quantity'];
        $cart->set_quantity($existing_key, $existing_qty + $qty);

        wp_send_json_success([
            'message' => __('🔁 Product already in cart. Increased quantity.', 'sas-plugin'),
            'reload'  => true,
        ]);
    } else {
        $cart->add_to_cart($swap_id, $qty);

        wp_send_json_success([
            'message' => __('✅ Product swapped successfully!', 'sas-plugin'),
            'reload'  => false,
        ]);
    }
}
