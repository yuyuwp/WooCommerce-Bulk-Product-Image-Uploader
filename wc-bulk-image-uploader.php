<?php
/**
 * Plugin Name: WooCommerce Bulk Image Uploader
 * Description: Lists all WooCommerce products with URL input fields to bulk-set featured images from external URLs. Images are downloaded to the Media Library.
 * Version: 1.0.0
 * Author: Yuyu
 * Author URI: https://yuyu.ng
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Bulk_Image_Uploader {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'wp_ajax_wcbiu_upload', [ $this, 'ajax_upload_single' ] );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Bulk Image Uploader',
            'Bulk Image Uploader',
            'manage_woocommerce',
            'wc-bulk-image-uploader',
            [ $this, 'render_page' ]
        );
    }

    /* ═══════════════════════════════════════
       AJAX: Upload one image for one product
       ═══════════════════════════════════════ */

    public function ajax_upload_single() {
        check_ajax_referer( 'wcbiu_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        @set_time_limit( 60 );

        $pid = (int) ( $_POST['product_id'] ?? 0 );
        $url = esc_url_raw( trim( $_POST['image_url'] ?? '' ) );

        if ( ! $pid || ! $url ) {
            wp_send_json_error( 'Missing product ID or URL.' );
        }

        $product = wc_get_product( $pid );
        if ( ! $product ) {
            wp_send_json_error( 'Product not found.' );
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download to temp.
        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            wp_send_json_error( 'Download failed: ' . $tmp->get_error_message() );
        }

        // Determine extension.
        $path = wp_parse_url( $url, PHP_URL_PATH );
        $ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
        $ext  = preg_replace( '/[^a-z0-9]/', '', $ext );
        if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ], true ) ) {
            // Try detecting from file contents.
            $check = wp_check_filetype_and_ext( $tmp, 'image.' . ( $ext ?: 'jpg' ) );
            $ext   = $check['ext'] ?: 'jpg';
        }

        $file_array = [
            'name'     => sanitize_file_name( $product->get_name() ) . '.' . $ext,
            'tmp_name' => $tmp,
        ];

        $attach_id = media_handle_sideload( $file_array, $pid );
        if ( is_wp_error( $attach_id ) ) {
            @unlink( $tmp );
            wp_send_json_error( 'Sideload failed: ' . $attach_id->get_error_message() );
        }

        // Set as featured image.
        $product->set_image_id( $attach_id );
        $product->save();

        // Get the new thumbnail URL for preview.
        $thumb_url = wp_get_attachment_image_url( $attach_id, 'thumbnail' );

        wp_send_json_success( [
            'product_id' => $pid,
            'attach_id'  => $attach_id,
            'thumb_url'  => $thumb_url,
        ] );
    }

    /* ═══════════════════════════════════════
       Render Admin Page
       ═══════════════════════════════════════ */

    public function render_page() {
        // Pagination.
        $per_page = 50;
        $paged    = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
        $search   = sanitize_text_field( $_GET['s'] ?? '' );
        $filter   = sanitize_text_field( $_GET['filter'] ?? 'all' );

        // Count products.
        $count_args = [
            'status' => 'publish',
            'limit'  => -1,
            'return' => 'ids',
        ];
        $all_ids       = wc_get_products( $count_args );
        $total_all     = count( $all_ids );
        $with_images   = 0;
        $without_images = 0;
        foreach ( $all_ids as $id ) {
            if ( get_post_meta( $id, '_thumbnail_id', true ) ) $with_images++;
            else $without_images++;
        }

        // Build query.
        global $wpdb;
        $where = "p.post_type = 'product' AND p.post_status = 'publish'";
        if ( $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( " AND p.post_title LIKE %s", $like );
        }
        if ( 'missing' === $filter ) {
            $where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value > 0)";
        } elseif ( 'has' === $filter ) {
            $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value > 0)";
        }

        $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" );
        $pages   = max( 1, ceil( $total / $per_page ) );
        $offset  = ( $paged - 1 ) * $per_page;

        $products = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.post_title ASC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        $ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
        $nonce    = wp_create_nonce( 'wcbiu_nonce' );
        $base_url = admin_url( 'admin.php?page=wc-bulk-image-uploader' );
        ?>

        <style>
            .wcbiu-wrap { max-width: 1100px; }
            .wcbiu-toolbar { display: flex; gap: 12px; align-items: center; margin: 16px 0; flex-wrap: wrap; }
            .wcbiu-toolbar .search-box { display: flex; gap: 6px; }
            .wcbiu-toolbar input[type="search"] { min-width: 220px; }
            .wcbiu-filters a { text-decoration: none; padding: 4px 10px; border-radius: 4px; }
            .wcbiu-filters a.current { background: #2271b1; color: #fff; }
            .wcbiu-count { color: #666; font-size: 13px; }

            .wcbiu-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.04); }
            .wcbiu-table th { background: #f6f7f7; text-align: left; padding: 12px 16px; font-size: 13px; font-weight: 600; border-bottom: 1px solid #ddd; }
            .wcbiu-table td { padding: 10px 16px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
            .wcbiu-table tr:last-child td { border-bottom: none; }
            .wcbiu-table tr:hover { background: #f9f9fb; }

            .wcbiu-product-cell { display: flex; align-items: center; gap: 12px; }
            .wcbiu-thumb { width: 48px; height: 48px; border-radius: 6px; object-fit: cover; border: 1px solid #eee; background: #f6f7f7; flex-shrink: 0; }
            .wcbiu-thumb-placeholder { width: 48px; height: 48px; border-radius: 6px; border: 2px dashed #ccc; background: #fafafa; display: flex; align-items: center; justify-content: center; color: #bbb; font-size: 18px; flex-shrink: 0; }
            .wcbiu-name { font-weight: 500; font-size: 13px; color: #2271b1; text-decoration: none; display: inline-block; }
            .wcbiu-name:hover { color: #135e96; text-decoration: underline; }
            .wcbiu-search-icon { font-size: 14px; width: 14px; height: 14px; vertical-align: middle; opacity: 0.35; transition: opacity 0.2s; }
            .wcbiu-name:hover .wcbiu-search-icon { opacity: 1; }
            .wcbiu-id { color: #999; font-size: 11px; }

            .wcbiu-url-input { width: 100%; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; font-family: monospace; }
            .wcbiu-url-input:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
            .wcbiu-url-input.wcbiu-done { border-color: #00a32a; background: #f0fdf0; }
            .wcbiu-url-input.wcbiu-error { border-color: #d63638; background: #fef0f0; }

            .wcbiu-status { font-size: 12px; margin-top: 4px; }
            .wcbiu-status.success { color: #00a32a; }
            .wcbiu-status.error { color: #d63638; }
            .wcbiu-status.uploading { color: #2271b1; }

            .wcbiu-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; flex-wrap: wrap; gap: 12px; }
            .wcbiu-pagination { display: flex; gap: 4px; align-items: center; }
            .wcbiu-pagination a, .wcbiu-pagination span { display: inline-block; padding: 4px 10px; border: 1px solid #ddd; border-radius: 3px; text-decoration: none; font-size: 13px; }
            .wcbiu-pagination span.current { background: #2271b1; color: #fff; border-color: #2271b1; }

            .wcbiu-progress-bar { width: 300px; height: 6px; background: #eee; border-radius: 3px; overflow: hidden; display: none; }
            .wcbiu-progress-fill { height: 100%; background: #00a32a; transition: width 0.3s; width: 0; }
            .wcbiu-bulk-status { font-size: 13px; color: #666; margin-left: 12px; }

            .wcbiu-save-area { display: flex; align-items: center; gap: 12px; }

            /* Dashicon integration */
            .wcbiu-thumb-placeholder .dashicons { font-size: 22px; width: 22px; height: 22px; color: #c3c4c7; }
            .wcbiu-status .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 3px; }
            .wcbiu-status.success .dashicons { color: #00a32a; }
            .wcbiu-status.error .dashicons { color: #d63638; }
            .wcbiu-status.uploading .dashicons { color: #2271b1; }
            #wcbiu-save-btn .dashicons { vertical-align: middle; margin-top: -2px; margin-right: 4px; }
            .wcbiu-spin { animation: wcbiu-spin 1s linear infinite; display: inline-block; }
            @keyframes wcbiu-spin { 100% { transform: rotate(360deg); } }
        </style>

        <div class="wrap wcbiu-wrap">
            <h1>WooCommerce Bulk Image Uploader</h1>

            <div id="wcbiu-notice" style="display:none;" class="notice"><p id="wcbiu-notice-msg"></p></div>

            <!-- Toolbar -->
            <div class="wcbiu-toolbar">
                <div class="wcbiu-filters">
                    <a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'all', 'pg' => 1 ], $base_url ) ); ?>" class="<?php echo 'all' === $filter || '' === $filter ? 'current' : ''; ?>">All (<?php echo $total_all; ?>)</a>
                    <a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'missing', 'pg' => 1 ], $base_url ) ); ?>" class="<?php echo 'missing' === $filter ? 'current' : ''; ?>">Missing (<?php echo $without_images; ?>)</a>
                    <a href="<?php echo esc_url( add_query_arg( [ 'filter' => 'has', 'pg' => 1 ], $base_url ) ); ?>" class="<?php echo 'has' === $filter ? 'current' : ''; ?>">Has Image (<?php echo $with_images; ?>)</a>
                </div>
                <form class="search-box" method="get">
                    <input type="hidden" name="page" value="wc-bulk-image-uploader">
                    <input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search products…">
                    <button type="submit" class="button">Search</button>
                </form>
                <span class="wcbiu-count">Showing <?php echo count( $products ); ?> of <?php echo $total; ?> products</span>
            </div>

            <!-- Product Table -->
            <table class="wcbiu-table">
                <thead>
                    <tr>
                        <th style="width:40%">Product</th>
                        <th>Image URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $products as $p ) :
                        $thumb_id      = (int) get_post_meta( $p->ID, '_thumbnail_id', true );
                        $thumb_url     = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
                        $full_img_url  = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
                    ?>
                    <tr data-pid="<?php echo (int) $p->ID; ?>">
                        <td>
                            <div class="wcbiu-product-cell">
                                <?php if ( $thumb_url ) : ?>
                                    <img class="wcbiu-thumb" id="thumb-<?php echo (int) $p->ID; ?>" src="<?php echo esc_url( $thumb_url ); ?>" alt="">
                                <?php else : ?>
                                    <div class="wcbiu-thumb-placeholder" id="thumb-<?php echo (int) $p->ID; ?>"><span class="dashicons dashicons-format-image"></span></div>
                                <?php endif; ?>
                                <div>
                                    <a class="wcbiu-name" href="<?php echo esc_url( 'https://www.google.com/search?tbm=isch&q=' . urlencode( $p->post_title . ' product' ) ); ?>" target="_blank" title="Search Google Images for this product"><?php echo esc_html( $p->post_title ); ?> <span class="dashicons dashicons-search wcbiu-search-icon"></span></a>
                                    <div class="wcbiu-id">#<?php echo (int) $p->ID; ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <input type="url" class="wcbiu-url-input" id="url-<?php echo (int) $p->ID; ?>" placeholder="<?php echo $full_img_url ? esc_url( $full_img_url ) : 'https://example.com/image.jpg'; ?>" autocomplete="off">
                            <div class="wcbiu-status" id="status-<?php echo (int) $p->ID; ?>"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $products ) ) : ?>
                    <tr><td colspan="2" style="text-align:center;padding:40px;color:#999;">No products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Footer -->
            <div class="wcbiu-footer">
                <!-- Pagination -->
                <div class="wcbiu-pagination">
                    <?php if ( $pages > 1 ) :
                        for ( $i = 1; $i <= $pages; $i++ ) :
                            $pg_url = add_query_arg( [ 'pg' => $i, 'filter' => $filter, 's' => $search ], $base_url );
                            if ( $i === $paged ) : ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else : ?>
                                <a href="<?php echo esc_url( $pg_url ); ?>"><?php echo $i; ?></a>
                            <?php endif;
                        endfor;
                    endif; ?>
                </div>

                <!-- Save Button -->
                <div class="wcbiu-save-area">
                    <div class="wcbiu-progress-bar" id="wcbiu-progress-bar"><div class="wcbiu-progress-fill" id="wcbiu-progress-fill"></div></div>
                    <span class="wcbiu-bulk-status" id="wcbiu-bulk-status"></span>
                    <button class="button button-primary button-hero" id="wcbiu-save-btn"><span class="dashicons dashicons-upload"></span> Upload & Save</button>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;
            var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;

            var saveBtn     = document.getElementById("wcbiu-save-btn");
            var progressBar = document.getElementById("wcbiu-progress-bar");
            var progressFill= document.getElementById("wcbiu-progress-fill");
            var bulkStatus  = document.getElementById("wcbiu-bulk-status");
            var noticeBox   = document.getElementById("wcbiu-notice");
            var noticeMsg   = document.getElementById("wcbiu-notice-msg");

            function showNotice(msg, type) {
                noticeBox.className = "notice notice-" + type;
                noticeMsg.textContent = msg;
                noticeBox.style.display = "block";
                setTimeout(function(){ noticeBox.style.display = "none"; }, 8000);
            }

            /* Collect all rows with a URL entered */
            function getFilledRows() {
                var rows = [];
                document.querySelectorAll("tr[data-pid]").forEach(function(tr){
                    var pid = tr.getAttribute("data-pid");
                    var input = document.getElementById("url-" + pid);
                    var url = input ? input.value.trim() : "";
                    if (url) rows.push({ pid: pid, url: url });
                });
                return rows;
            }

            /* Upload a single product image */
            function uploadOne(pid, url) {
                var statusEl = document.getElementById("status-" + pid);
                var inputEl  = document.getElementById("url-" + pid);

                statusEl.className = "wcbiu-status uploading";
                statusEl.innerHTML = '<span class="dashicons dashicons-update wcbiu-spin"></span> Uploading…';
                inputEl.classList.remove("wcbiu-done", "wcbiu-error");

                var body = new FormData();
                body.append("action", "wcbiu_upload");
                body.append("nonce", NONCE);
                body.append("product_id", pid);
                body.append("image_url", url);

                return fetch(AJAX_URL, { method: "POST", body: body, credentials: "same-origin" })
                    .then(function(r){
                        if (!r.ok) throw new Error("HTTP " + r.status);
                        return r.json();
                    })
                    .then(function(resp){
                        if (resp.success) {
                            statusEl.className = "wcbiu-status success";
                            statusEl.innerHTML = '<span class="dashicons dashicons-yes-alt"></span> Saved';
                            inputEl.classList.add("wcbiu-done");
                            inputEl.value = "";

                            // Update thumbnail preview.
                            var thumbEl = document.getElementById("thumb-" + pid);
                            if (thumbEl && resp.data.thumb_url) {
                                var img = document.createElement("img");
                                img.className = "wcbiu-thumb";
                                img.id = "thumb-" + pid;
                                img.src = resp.data.thumb_url;
                                thumbEl.replaceWith(img);
                            }
                            return true;
                        } else {
                            statusEl.className = "wcbiu-status error";
                            statusEl.innerHTML = '<span class="dashicons dashicons-dismiss"></span> ' + (resp.data || "Failed");
                            inputEl.classList.add("wcbiu-error");
                            return false;
                        }
                    })
                    .catch(function(err){
                        statusEl.className = "wcbiu-status error";
                        statusEl.innerHTML = '<span class="dashicons dashicons-dismiss"></span> ' + err.message;
                        inputEl.classList.add("wcbiu-error");
                        return false;
                    });
            }

            /* Process all filled rows sequentially */
            saveBtn.addEventListener("click", function(){
                var rows = getFilledRows();
                if (rows.length === 0) {
                    showNotice("No URLs entered. Paste image URLs next to the products you want to update.", "warning");
                    return;
                }

                saveBtn.disabled = true;
                saveBtn.innerHTML = '<span class="dashicons dashicons-update wcbiu-spin"></span> Uploading…';
                progressBar.style.display = "block";
                progressFill.style.width = "0%";

                var total   = rows.length;
                var done    = 0;
                var success = 0;

                function next() {
                    if (done >= total) {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = '<span class="dashicons dashicons-upload"></span> Upload \x26 Save';
                        var msg = success + " of " + total + " images uploaded successfully.";
                        bulkStatus.textContent = msg;
                        showNotice(msg, success === total ? "success" : "warning");
                        return;
                    }

                    var row = rows[done];
                    bulkStatus.textContent = "Uploading " + (done + 1) + " of " + total + "…";

                    uploadOne(row.pid, row.url).then(function(ok){
                        if (ok) success++;
                        done++;
                        progressFill.style.width = Math.round((done / total) * 100) + "%";
                        /* Small delay between uploads to avoid overloading. */
                        setTimeout(next, 500);
                    });
                }

                next();
            });

        })();
        </script>
        <?php
    }

    /* ═══════════════════════════════════════
       Helpers
       ═══════════════════════════════════════ */

    private function get_product_counts() {
        global $wpdb;
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
        $with  = (int) $wpdb->get_var( "
            SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_thumbnail_id' AND pm.meta_value>0
            WHERE p.post_type='product' AND p.post_status='publish'
        " );
        return [ 'total' => $total, 'with' => $with, 'without' => $total - $with ];
    }
}

add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        WC_Bulk_Image_Uploader::instance();
    }
} );
