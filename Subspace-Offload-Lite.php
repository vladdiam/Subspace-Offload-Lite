<?php
/**
 * Plugin Name: Subspace Offload Lite
 * Description: Useful for test-versions of your site. Plugin retrieves your /uploads files & directories from a chosen production URL. This allows you to save space on your test server and view original content at the same time — without copying any files.
 * Version: 0.1.1
 * Author: vladd_i_am
 */

 if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access via URL
}

// ============================================================
// In-request cache: avoids calling file_exists() more than once
// for the same file within a single page load.
// Only files rendered on the current page are ever checked.
// ============================================================
$GLOBALS['pmp_file_cache'] = [];

function pmp_file_exists_cached( string $path ): bool {
    if ( ! isset( $GLOBALS['pmp_file_cache'][ $path ] ) ) {
        $GLOBALS['pmp_file_cache'][ $path ] = file_exists( $path );
    }
    return $GLOBALS['pmp_file_cache'][ $path ];
}

// ============================================================
// Read saved production URL from DB
// ============================================================
function pmp_get_prod_url(): string {
    return rtrim( (string) get_option( 'pmp_prod_url', '' ), '/' );
}

// ============================================================
// Filter 1: single attachment URL
// ============================================================
add_filter( 'wp_get_attachment_url', 'pmp_proxy_attachment_url', 10, 2 );

function pmp_proxy_attachment_url( string $url, int $attachment_id ): string {

    $prod_url = pmp_get_prod_url();
    if ( empty( $prod_url ) ) {
        return $url; // Plugin not configured — do nothing
    }

    $relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
    if ( empty( $relative_path ) ) {
        return $url;
    }

    $upload_dir = wp_upload_dir();
    $local_path = $upload_dir['basedir'] . '/' . $relative_path;

    if ( pmp_file_exists_cached( $local_path ) ) {
        return $url; // File exists locally — leave URL untouched
    }

    return $prod_url . '/wp-content/uploads/' . $relative_path;
}

// ============================================================
// Filter 2: srcset (responsive image sizes for mobile)
// ============================================================
add_filter( 'wp_calculate_image_srcset', 'pmp_proxy_image_srcset', 10, 5 );

function pmp_proxy_image_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {

    $prod_url = pmp_get_prod_url();
    if ( empty( $prod_url ) ) {
        return $sources;
    }

    $upload_dir = wp_upload_dir();

    foreach ( $sources as $width => $source ) {

        $filename    = basename( $source['url'] );
        $found_local = false;

        if ( isset( $image_meta['sizes'] ) ) {
            foreach ( $image_meta['sizes'] as $size_data ) {
                if ( $size_data['file'] === $filename ) {
                    $subdir     = dirname( $image_meta['file'] );
                    $local_path = $upload_dir['basedir'] . '/' . $subdir . '/' . $filename;

                    if ( pmp_file_exists_cached( $local_path ) ) {
                        $found_local = true;
                    }
                    break;
                }
            }
        }

        if ( ! $found_local ) {
            $local_base               = $upload_dir['baseurl'];
            $prod_base                = $prod_url . '/wp-content/uploads';
            $sources[ $width ]['url'] = str_replace( $local_base, $prod_base, $source['url'] );
        }
    }

    return $sources;
}

// ============================================================
// Settings page under Settings → Subspace-Offload Lite
// ============================================================
add_action( 'admin_menu', 'pmp_add_settings_page' );

function pmp_add_settings_page(): void {
    add_options_page(
        'Subspace-Offload Lite',
        'Subspace Offload Lite',
        'manage_options',
        'prod-media-proxy',
        'pmp_render_settings_page'
    );
}

add_action( 'admin_init', 'pmp_register_settings' );

function pmp_register_settings(): void {
    register_setting(
        'pmp_settings_group',
        'pmp_prod_url',
        [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]
    );
}

function pmp_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $prod_url  = pmp_get_prod_url();
    $is_active = ! empty( $prod_url );

    $status = $is_active
        ? '<span style="color:#00a32a;">&#10003; Active — media is proxied from <strong>' . esc_html( $prod_url ) . '</strong></span>'
        : '<span style="color:#d63638;">&#10007; Not configured — please enter your production URL below</span>';
    ?>
    <div class="wrap">
        <h1>Subspace Offload Lite</h1>
        <p><?php echo $status; ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'pmp_settings_group' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="pmp_prod_url">Production site URL</label>
                    </th>
                    <td>
                        <input
                            type="url"
                            id="pmp_prod_url"
                            name="pmp_prod_url"
                            value="<?php echo esc_attr( $prod_url ); ?>"
                            class="regular-text"
                            placeholder="https://site.com"
                        />
                        <p class="description">
                            No trailing slash. Example: <code>https://site.com</code><br>
                            <strong>Notice:</strong> If a file exists locally it will be served locally. If not — its URL will point to production.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save' ); ?>
        </form>

        <hr>
        <h2>How it works</h2>
        <ol>
            <li>WordPress tries to render a media file on the current page</li>
            <li>The plugin checks whether that file exists on this server</li>
            <li>If "yes" — the local URL is used (no change)</li>
            <li>If "no" — the domain is swapped to your production site</li>
        </ol>
        <p>
            <strong>No files are copied. Production is not burdened.</strong><br>
            Only files rendered on the current page are ever checked — not the entire uploads folder.
        </p>
    </div>
    <?php
}

// ============================================================
// Admin notice — yellow banner on Media Library page
// ============================================================
add_action( 'admin_notices', 'pmp_admin_notice' );

function pmp_admin_notice(): void {
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'upload' ) {
        return; // Show only on Media Library page
    }

    $prod_url = pmp_get_prod_url();
    if ( empty( $prod_url ) ) {
        return; // Plugin not configured — nothing to warn about
    }
    ?>
    <div class="notice notice-warning">
        <p>
            <strong>Subspace Offload Lite:</strong>
            Media files missing locally are being served from
            <a href="<?php echo esc_url( $prod_url ); ?>" target="_blank"><?php echo esc_html( $prod_url ); ?></a>.
            Files you see here may not exist on this server. Please, be careful
        </p>
    </div>
    <?php
}

// ============================================================
// Media Library grid — caption under each attachment thumbnail
// ============================================================
add_filter( 'wp_prepare_attachment_for_js', 'pmp_attachment_js_notice', 10, 3 );

function pmp_attachment_js_notice( array $response, WP_Post $attachment, $meta ): array {

    $prod_url = pmp_get_prod_url();
    if ( empty( $prod_url ) ) {
        return $response;
    }

    $relative_path = get_post_meta( $attachment->ID, '_wp_attached_file', true );
    if ( empty( $relative_path ) ) {
        return $response;
    }

    $upload_dir = wp_upload_dir();
    $local_path = $upload_dir['basedir'] . '/' . $relative_path;

    if ( ! pmp_file_exists_cached( $local_path ) ) {
        // Append a note to the caption shown in grid view
        $response['caption'] = '⚠️🔁 Served from production: ' . esc_html( $prod_url );
    }

    return $response;
}