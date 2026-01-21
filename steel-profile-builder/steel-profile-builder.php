<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Adminis muudetav profiiligeneraator (sisemine nurk + sirglõigud) koos SVG visualiseerimise, jm-hinnastuse ja WPForms hidden-field täitmisega.
 * Version: 0.1.0
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.1.0';

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_action('save_post', [$this, 'save_meta'], 10, 2);

    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);

    add_shortcode('steel_profile_builder', [$this, 'shortcode']);
  }

  public function register_cpt() {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Steel Profiilid (Builder)',
        'singular_name' => 'Steel Profiil',
        'add_new_item' => 'Lisa uus profiil',
        'edit_item' => 'Muuda profiili',
      ],
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-editor-kitchensink',
      'supports' => ['title'],
    ]);
  }

  public function add_meta_boxes() {
    add_meta_box('spb_dims', 'Mõõdud (lisatav tabel)', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_pricing', 'Hinnastus (jm)', [$this, 'mb_pricing'], self::CPT, 'side', 'default');
    add_meta_box('spb_wpforms', 'WPForms mapping', [$this, 'mb_wpforms'], self::CPT, 'side', 'default');
  }

  private function get_meta($post_id) {
    return [
      'dims'    => get_post_meta($post_id, '_spb_dims', true),
      'pattern' => get_post_meta($post_id, '_spb_pattern', true),
      'pricing' => get_post_meta($post_id, '_spb_pricing', true),
      'wpforms' => get_post_meta($post_id, '_spb_wpforms', true),
      'ui'      => get_post_meta($post_id, '_spb_ui', true),
    ];
  }

  public function enqueue_admin($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== self::CPT) return;

    wp_enqueue_style('spb-admin', plugins_url('assets/admin.css', __FILE__), [], self::VER);
    wp_enqueue_script('spb-admin', plugins_url('assets/admin.js', __FILE__), ['jquery'], self::VER, true);
  }

  public function enqueue_front() {
    wp_register_style('spb-front', plugins_url('assets/front.css', __FILE__), [], self::VER);
    wp_register_script('spb-front', plugins_url('assets/front.js', __FILE__), [], self::VER, true);
  }

  public function mb_dims($post) {
    wp_nonce_field('spb_save', 'spb_nonce');

    $m = $this->get_meta($post->ID);
    $dims = is_array($m['dims']) ? $m['dims'] : [];

    // sensible starter if empty
    if (!$dims) {
      $dims = [
        ['key'=>'s1','type'=>'length','label'=>'s1','min'=>10,'max'=>50,'def'=>15,'dir'=>'L'],
        ['key'=>'a1','type'=>'angle','label'=>'a1','min'=>90,'max'=>215,'def'=>135,'dir'=>'L'],
        ['key'=>'s2','type'=>'length','label'=>'s2','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
        ['key'=>'a2','type'=>'angle','label'=>'a2','min'=>45,'max'=>180,'def'=>135,'dir'=>'L'],
        ['key'=>'s3','type'=>'length','label'=>'s3','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
        ['key'=>'a3','type'=>'angle','label'=>'a3','min'=>90,'max'=>180,'def'=>135,'dir'=>'L'],
        ['key'=>'s4','type'=>'length','label'=>'s4','min'=>10,'max'=>50,'def'=>15,'dir'=>'L'],
      ];
    }
    ?>
    <p class="spb-help">
      Lisa mõõte (s* = sirglõik mm, a* = sisemine nurk °). Nurkadel vali suund (L/R) tagasipöörete jaoks.
    </p>

    <table class="widefat" id="spb-dims-table">
      <thead>
        <tr>
          <th style="width:120px">Key</th>
          <th style="width:120px">Tüüp</th>
          <th>Silt</th>
          <th style="width:90px">Min</th>
          <th style="width:90px">Max</th>
          <th style="width:110px">Default</th>
          <th style="width:110px">Suund</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <p>
      <button type="button" class="button button-secondary" id="spb-add-dim">+ Lisa mõõt</button>
    </p>

    <input type="hidden" name="spb_dims_json" id="spb_dims_json" value="<?php echo esc_attr(wp_json_encode($dims)); ?>">
    <?php
  }

  public function mb_pattern($post) {
    $m = $this->get_meta($post->ID);
    $pattern = is_array($m['pattern']) ? $m['pattern'] : [];

    if (!$pattern) {
      $pattern = ["s1","a1","s2","a2","s3","a3","s4"];
    }
    ?>
    <p class="spb-help">
      Pattern on JSON massiiv. Näide: <code>["s1","a1","s2","a2","s3","a3","s4"]</code><br>
      Reegel: s* = liigu; a* = pööra (sisemine nurk → pöördenurk: 180-a, suund L/R).
    </p>
    <textarea name="spb_pattern_json" class="spb-textarea"><?php echo esc_textarea(wp_json_encode($pattern)); ?></textarea>
    <?php
  }

  public function mb_pricing($post) {
    $m = $this->get_meta($post->ID);
    $pricing = is_array($m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge([
      'eur_per_jm' => 12.5,
      'vat' => 24,
      'bend_fee' => 0, // € / bend / piece
    ], $pricing);

    ?>
    <p class="spb-help">JM põhine hind + KM.</p>
    <p><label>€ / jm<br>
      <input type="number" step="0.01" name="spb_eur_per_jm" value="<?php echo esc_attr($pricing['eur_per_jm']); ?>" class="spb-input">
    </label></p>
    <p><label>KM %<br>
      <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($pricing['vat']); ?>" class="spb-input">
    </label></p>
    <p><label>Painutuse tasu (€ / painutus / detail)<br>
      <input type="number" step="0.01" name="spb_bend_fee" value="<?php echo esc_attr($pricing['bend_fee']); ?>" class="spb-input">
    </label></p>
    <?php
  }

  public function mb_wpforms($post) {
    $m = $this->get_meta($post->ID);
    $wpforms = is_array($m['wpforms']) ? $m['wpforms'] : [];
    $wpforms = array_merge([
      'form_id' => '',
      'fields' => [
        'profile' => '',
        'inputs_json' => '',
        'length_mm' => '',
        'qty' => '',
        'jm_total' => '',
        'price_total' => '',
      ],
    ], $wpforms);

    $f = is_array($wpforms['fields']) ? $wpforms['fields'] : [];
    ?>
    <p class="spb-help">
      Sisesta WPForms Form ID ja Hidden field ID-d (numbrid).
    </p>
    <p><label>WPForms Form ID<br>
      <input type="number" name="spb_wpforms_form_id" value="<?php echo esc_attr($wpforms['form_id']); ?>" class="spb-input">
    </label></p>

    <?php
    $map = [
      'profile' => 'Profiili nimi',
      'inputs_json' => 'Inputs JSON',
      'length_mm' => 'Pikkus (mm)',
      'qty' => 'Kogus',
      'jm_total' => 'JM kokku',
      'price_total' => 'Hind kokku (€)',
    ];
    foreach ($map as $k => $label) : ?>
      <p><label><?php echo esc_html($label); ?> field ID<br>
        <input type="number" name="spb_wpforms_field_<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($f[$k] ?? ''); ?>" class="spb-input">
      </label></p>
    <?php endforeach; ?>
    <?php
  }

  public function save_meta($post_id, $post) {
    if ($post->post_type !== self::CPT) return;
    if (!isset($_POST['spb_nonce']) || !wp_verify_nonce($_POST['spb_nonce'], 'spb_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // dims
    $dims_json = wp_unslash($_POST['spb_dims_json'] ?? '[]');
    $dims = json_decode($dims_json, true);
    if (!is_array($dims)) $dims = [];
    // basic sanitize
    $out = [];
    foreach ($dims as $d) {
      $out[] = [
        'key' => sanitize_key($d['key'] ?? ''),
        'type' => ($d['type'] ?? '') === 'angle' ? 'angle' : 'length',
        'label' => sanitize_text_field($d['label'] ?? ''),
        'min' => isset($d['min']) ? floatval($d['min']) : null,
        'max' => isset($d['max']) ? floatval($d['max']) : null,
        'def' => isset($d['def']) ? floatval($d['def']) : null,
        'dir' => (strtoupper($d['dir'] ?? 'L') === 'R') ? 'R' : 'L',
      ];
    }
    update_post_meta($post_id, '_spb_dims', $out);

    // pattern
    $pattern_json = wp_unslash($_POST['spb_pattern_json'] ?? '[]');
    $pattern = json_decode($pattern_json, true);
    if (!is_array($pattern)) $pattern = [];
    $pattern = array_values(array_map('sanitize_key', $pattern));
    update_post_meta($post_id, '_spb_pattern', $pattern);

    // pricing
    $pricing = [
      'eur_per_jm' => floatval($_POST['spb_eur_per_jm'] ?? 0),
      'vat' => floatval($_POST['spb_vat'] ?? 24),
      'bend_fee' => floatval($_POST['spb_bend_fee'] ?? 0),
    ];
    update_post_meta($post_id, '_spb_pricing', $pricing);

    // wpforms
    $fields = [];
    foreach (['profile','inputs_json','length_mm','qty','jm_total','price_total'] as $k) {
      $fields[$k] = sanitize_text_field($_POST['spb_wpforms_field_'.$k] ?? '');
    }
    $wpforms = [
      'form_id' => sanitize_text_field($_POST['spb_wpforms_form_id'] ?? ''),
      'fields' => $fields
    ];
    update_post_meta($post_id, '_spb_wpforms', $wpforms);
  }

  public function shortcode($atts) {
    $atts = shortcode_atts(['id' => 0], $atts);
    $id = intval($atts['id']);
    if (!$id) return '<div>Steel Profile Builder: puudub id</div>';

    $post = get_post($id);
    if (!$post || $post->post_type !== self::CPT) return '<div>Steel Profile Builder: vale id</div>';

    $m = $this->get_meta($id);
    $dims = is_array($m['dims']) ? $m['dims'] : [];
    $pattern = is_array($m['pattern']) ? $m['pattern'] : [];
    $pricing = is_array($m['pricing']) ? $m['pricing'] : ['eur_per_jm'=>0,'vat'=>24,'bend_fee'=>0];
    $wpforms = is_array($m['wpforms']) ? $m['wpforms'] : ['form_id'=>'','fields'=>[]];

    $cfg = [
      'profileId' => $id,
      'profileName' => get_the_title($id),
      'dims' => $dims,
      'pattern' => $pattern,
      'pricing' => $pricing,
      'wpforms' => $wpforms,
      'defaults' => [
        'length_mm' => 1000,
        'qty' => 1,
      ],
      // rendering defaults
      'viewBox' => [0,0,700,420],
    ];

    wp_enqueue_style('spb-front');
    wp_enqueue_script('spb-front');

    $uid = 'spb_' . $id . '_' . wp_generate_uuid4();
    ob_start(); ?>
      <div class="spb-wrap" id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <div class="spb-card spb-visual">
          <svg class="spb-svg" viewBox="0 0 700 420" width="100%" height="auto" aria-label="Profiili joonis">
            <defs>
              <marker id="spbArrow" markerWidth="8" markerHeight="8" refX="4" refY="4" orient="auto">
                <path d="M0,0 L8,4 L0,8 Z"></path>
              </marker>
            </defs>
            <polyline class="spb-line" fill="none" stroke-width="4" points="100,320 100,100 520,100"></polyline>
            <g class="spb-dimlayer"></g>
          </svg>
        </div>

        <div class="spb-card spb-form">
          <div class="spb-title">Otsajoone parameetrid</div>

          <div class="spb-inputs"></div>

          <div class="spb-divider"></div>

          <div class="spb-row">
            <label class="spb-label">Pikkus (mm)</label>
            <input class="spb-len" type="number" min="100" max="3200" value="1000">
          </div>

          <div class="spb-row">
            <label class="spb-label">Kogus</label>
            <input class="spb-qty" type="number" min="1" max="999" value="1">
          </div>

          <div class="spb-result">
            <div>Hind: <strong class="spb-price">—</strong></div>
            <div class="spb-muted">JM: <span class="spb-jm">—</span> • Painutusi: <span class="spb-bends">—</span></div>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }
}

new Steel_Profile_Builder();
