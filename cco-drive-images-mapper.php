<?php
/**
 * Plugin Name: CCO Drive Images Mapper (Woo Import)
 * Description: Replace Google Drive image links with local CCO uploads URL using Google Drive API (fileId -> filename).
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;
define( 'MON_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MON_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

class CCO_Drive_Images_Mapper {
    private bool $audit_only = false;
    private string $log_dir;
    private string $log_file;
    private string $csv_file;

    // IMPORTANT: adapte le dossier WP uploads (année/mois)
    private string $uploads_base = 'https://cco-237.shop/wp-content/uploads/2025/05/';

    // Mets ici le chemin vers ton JSON de service account (stocke-le hors webroot si possible)
    // Exemple: /home/bridge/secure/google/service-account.json
    private string $service_account_json_path = MON_PLUGIN_DIR . 'key.json';

    // Cache (évite de re-caller l’API pour le même fileId)
    private int $cache_ttl = 86400; // 24h

    public function __construct() {
        $this->log_dir  = WP_CONTENT_DIR . '/uploads/cco-import-logs';
        $this->log_file = $this->log_dir . '/missing-images-' . date('Y-m') . '.log';
        $this->csv_file = $this->log_dir . '/missing-images-' . date('Y-m') . '.csv';
        $this->audit_only = (bool) get_option('cco_drive_audit_only', false);


        if (!is_dir($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }

        if (!file_exists($this->csv_file)) {
            file_put_contents(
                $this->csv_file,
                "date,sku,product_name,drive_file_id,drive_name,expected_url,reason\n"
            );
        }

        // Hook WooCommerce Product CSV Importer
        add_filter('woocommerce_product_importer_pre_process_csv_row', [$this, 'filter_import_row'], 10, 2);

        // Optionnel : page d’admin pour config rapide
        add_action('admin_menu', [$this, 'admin_menu']);

        add_action('admin_init', function () {
            register_setting('cco_drive_settings', 'cco_drive_audit_only');
        });
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'CCO Drive Images Mapper',
            'Drive Images Mapper',
            'manage_woocommerce',
            'cco-drive-images-mapper',
            [$this, 'admin_page']
        );
    }

    public function admin_page() {
        echo '<div class="wrap"><h1>CCO Drive Images Mapper</h1>';
        echo '<p>Ce plugin remplace pendant l’import WooCommerce les liens Google Drive par les URLs locales CCO, en lisant le nom réel via Google Drive API.</p>';
        echo '<p><strong>Service account JSON attendu :</strong><br><code>' . esc_html($this->service_account_json_path) . '</code></p>';
        echo '<p><strong>Uploads base :</strong><br><code>' . esc_html($this->uploads_base) . '</code></p>';
        echo '</div>';

        echo '<form method="post" action="options.php">';
        settings_fields('cco_drive_settings');
        do_settings_sections('cco_drive_settings');

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">Mode audit uniquement</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="cco_drive_audit_only" value="1" ' . checked(1, get_option('cco_drive_audit_only'), false) . ' />';
        echo ' Activer le mode audit (aucune image ne sera modifiée lors de l’import)';
        echo '</label>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        submit_button();
        echo '</form>';
    }

    /**
     * Intercepte une ligne CSV Woo.
     * $data est un tableau associatif : colonne => valeur
     */
    public function filter_import_row(array $data, $importer) : array {

        // Tente de détecter la colonne images (adapte si ton template a un nom différent)
        $possible_keys = ['images', 'Images', 'Images (URL)', 'image', 'Image', 'image_urls'];
        $key = null;

        foreach ($possible_keys as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') {
                $key = $k;
                break;
            }
        }

        if (!$key) return $data;

        //$data[$key] = $this->transform_images_field($data[$key]);
        $data[$key] = $this->transform_images_field($data[$key], $data);


        return $data;
    }

    private function transform_images_field(string $value, array $data = []) : string{
        // MODE AUDIT : on ne modifie rien, on analyse seulement
        if ($this->audit_only) {
            $this->audit_images($value, $data);
            return $value; // ⚠️ CRUCIAL : on retourne la valeur d’origine
        }

        $urls = $this->split_urls($value);
        if (!$urls) return $value;

        $out = [];
        $seen = [];

        foreach ($urls as $u) {
            $u = trim($u);
            if ($u === '') continue;

            // Si ce n’est pas du Drive, on laisse tel quel
            $fileId = $this->extract_drive_file_id($u);
            if (!$fileId) {
                if (!isset($seen[$u])) {
                    $out[] = $u;
                    $seen[$u] = true;
                }
                continue;
            }

            // fileId -> filename via Drive API
            $filename = $this->drive_get_filename($fileId);
            if (!$filename) {
                $this->log_missing_image([
                    'sku'      => $data['sku'] ?? '',
                    'product'  => $data['name'] ?? '',
                    'file_id'  => $fileId,
                    'filename' => '',
                    'url'      => '',
                    'reason'   => 'drive_filename_not_resolved',
                ]);

                if (!isset($seen[$u])) {
                    $out[] = $u;
                    $seen[$u] = true;
                }
                continue;
            }

            // Nom sans extension (ex: 238)
            $base = pathinfo($filename, PATHINFO_FILENAME);

            // Extensions tolérées
            $extensions = ['png', 'jpg', 'jpeg', 'webp'];
            $found = false;

            foreach ($extensions as $ext) {

                $candidate = $this->uploads_base . $base . '.' . $ext;

                if ($this->image_exists($candidate)) {
                    if (!isset($seen[$candidate])) {
                        $out[] = $candidate;
                        $seen[$candidate] = true;
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->log_missing_image([
                    'sku'      => $data['sku'] ?? '',
                    'product'  => $data['name'] ?? '',
                    'file_id'  => $fileId,
                    'filename' => $filename,
                    'url'      => $this->uploads_base . $filename,
                    'reason'   => 'image_not_found_on_cco',
                ]);
            }


            $local = $this->uploads_base . rawurlencode($filename);
            if (!isset($seen[$local])) {
                $out[] = $local;
                $seen[$local] = true;
            }
        }

        return implode(',', $out);
    }

    private function audit_images(string $value, array $data = []): void {
        $urls = $this->split_urls($value);
        if (!$urls) return;

        foreach ($urls as $u) {

            $fileId = $this->extract_drive_file_id($u);
            if (!$fileId) continue;

            $filename = $this->drive_get_filename($fileId);

            if (!$filename) {
                $this->log_missing_image([
                    'sku'      => $data['sku'] ?? '',
                    'product'  => $data['name'] ?? '',
                    'file_id'  => $fileId,
                    'filename' => '',
                    'url'      => '',
                    'reason'   => 'drive_filename_not_resolved (audit)',
                ]);
                continue;
            }

            $base = pathinfo($filename, PATHINFO_FILENAME);
            $extensions = ['png', 'jpg', 'jpeg', 'webp'];
            $found = false;

            foreach ($extensions as $ext) {
                $candidate = $this->uploads_base . $base . '.' . $ext;
                if ($this->image_exists($candidate)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->log_missing_image([
                    'sku'      => $data['sku'] ?? '',
                    'product'  => $data['name'] ?? '',
                    'file_id'  => $fileId,
                    'filename' => $filename,
                    'url'      => $this->uploads_base . $filename,
                    'reason'   => 'image_not_found_on_cco (audit)',
                ]);
            }
        }
    }


    private function split_urls(string $value) : array {
        $parts = preg_split('/[,\n;]+/', $value);
        $parts = array_map('trim', $parts ?: []);
        return array_values(array_filter($parts, fn($x) => $x !== ''));
    }

    private function extract_drive_file_id(string $url) : ?string {
        // formats courants:
        // https://drive.google.com/file/d/FILEID/view?...
        // https://drive.google.com/open?id=FILEID
        // https://drive.google.com/uc?id=FILEID&export=download
        if (preg_match('~/file/d/([^/]+)~', $url, $m)) return $m[1];
        if (preg_match('~[?&]id=([^&]+)~', $url, $m)) return $m[1];
        return null;
    }

    /**
     * Cache + call Drive API files.get(fields=name)
     */
    private function drive_get_filename(string $fileId) : ?string {

        $cache_key = 'cco_drive_name_' . md5($fileId);
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') return $cached;

        $token = $this->get_service_account_access_token();
        if (!$token) return null;

        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?fields=name';

        $resp = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) return null;
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        if ($code !== 200) return null;

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['name'])) return null;

        $name = (string)$json['name'];
        set_transient($cache_key, $name, $this->cache_ttl);
        return $name;
    }

    /**
     * Service account JWT -> OAuth token (no external libs)
     */
    private function get_service_account_access_token() : ?string {

        $cache_key = 'cco_drive_sa_token';
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') return $cached;

        if (!file_exists($this->service_account_json_path)) return null;

        $sa = json_decode(file_get_contents($this->service_account_json_path), true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['token_uri'])) {
            return null;
        }

        $now = time();
        $header = $this->base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64url_encode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'aud'   => $sa['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $unsigned = $header . '.' . $claims;

        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $sa['private_key'], 'sha256');
        if (!$ok) return null;

        $jwt = $unsigned . '.' . $this->base64url_encode($signature);

        $resp = wp_remote_post($sa['token_uri'], [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);

        if (is_wp_error($resp)) return null;

        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code !== 200) return null;

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['access_token'])) return null;

        $token = (string)$json['access_token'];
        $expires_in = !empty($json['expires_in']) ? (int)$json['expires_in'] : 3600;

        // cache un peu moins que l’expiration
        set_transient($cache_key, $token, max(60, $expires_in - 120));
        return $token;
    }

    private function base64url_encode(string $data) : string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function image_exists(string $url): bool {
        $resp = wp_remote_head($url, ['timeout' => 10]);
        if (is_wp_error($resp)) return false;
        return wp_remote_retrieve_response_code($resp) === 200;
    }

    private function log_missing_image(array $context): void {
        $line = sprintf(
            "[%s] SKU=%s | PRODUCT=%s | DRIVE_ID=%s | NAME=%s | URL=%s | REASON=%s\n",
            date('Y-m-d H:i:s'),
            $context['sku'] ?? '',
            $context['product'] ?? '',
            $context['file_id'] ?? '',
            $context['filename'] ?? '',
            $context['url'] ?? '',
            $context['reason'] ?? ''
        );

        file_put_contents($this->log_file, $line, FILE_APPEND);

        $csv = sprintf(
            "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
            date('Y-m-d H:i:s'),
            $context['sku'] ?? '',
            $context['product'] ?? '',
            $context['file_id'] ?? '',
            $context['filename'] ?? '',
            $context['url'] ?? '',
            $context['reason'] ?? ''
        );

        file_put_contents($this->csv_file, $csv, FILE_APPEND);
    }

}

new CCO_Drive_Images_Mapper();
