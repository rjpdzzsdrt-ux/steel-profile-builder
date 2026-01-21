<?php
/**
 * Plugin Name: Steel Profile Builder
 * Plugin URI: https://steel.ee
 * Description: Administ muudetav plekiprofiilide kalkulaator SVG visualiseerimise ja m²-põhise hinnastusega.
 * Version: 0.1.0
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_action('save_post', [$this, 'save_meta'], 10, 2);
  }

  public function register_cpt() {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Steel Profiilid',
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
    add_meta_box('spb_dims', 'Mõõdud (JSON, ajutine)', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_pricing', 'Hinnastus (m² + KM)', [$this, 'mb_pricing'], self::CPT, 'side', 'default');
  }

  private function get_meta($post_id) {
    return [
      'dims'    => get_post_meta($post_id, '_spb_dims', true),
      'pattern' => get_post_meta($post_id, '_spb_pattern', true),
      'pricing' => get_post_meta($post_id, '_spb_pricing', true),
    ];
  }

  public function mb_dims($post) {
    wp_nonce_field('spb_save', 'spb_nonce');
    $m = $this->get_meta($post->ID);

    // dims: array of {key,type,label,min,max,def,dir}
    $dims = is_array($m['dims']) ? $m['dims'] : [];

    // starter template for you
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
    <p style="margin-top:0;opacity:.8">
      <strong>Ajutine samm:</strong> sisesta mõõdud JSON-ina (järgmises punktis teeme mugava tabeli UI).<br>
      Reegel: <code>type</code> on <code>length</code> või <code>angle</code>. Nurgal <code>dir</code> = L/R. Nurk = sisemine nurk.
    </p>
    <textarea name="spb_dims_json" style="width:100%;min-height:220px;"><?php echo esc_textarea(wp_json_encode($dims, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>
    <?php
  }

  public function mb_pattern($post) {
    $m = $this->get_meta($post->ID);
    $pattern = is_array($m['pattern']) ? $m['pattern'] : [];

    if (!$pattern) {
      $pattern = ["s1","a1","s2","a2","s3","a3","s4"];
    }
    ?>
    <p style="margin-top:0;opacity:.8">
      Pattern on JSON massiiv. Näide: <code>["s1","a1","s2","a2","s3","a3","s4"]</code><br>
      s* = liigu; a* = pööra (sisemine nurk, pöördenurk = 180-a, suund L/R).
    </p>
    <textarea name="spb_pattern_json" style="width:100%;min-height:90px;"><?php echo esc_textarea(wp_json_encode($pattern)); ?></textarea>
    <?php
  }

  public function mb_pricing($post) {
    $m = $this->get_meta($post->ID);
    $pricing = is_array($m['pricing']) ? $m['pricing'] : [];

    $pricing = array_merge([
      'vat' => 24,
      'materials' => [
        ['key'=>'POL','label'=>'POL','eur_m2'=>7.5],
        ['key'=>'PUR','label'=>'PUR','eur_m2'=>8.5],
        ['key'=>'PUR_MATT','label'=>'PUR Matt','eur_m2'=>11.5],
        ['key'=>'TSINK','label'=>'Tsink','eur_m2'=>6.5],
      ]
    ], $pricing);

    ?>
    <p style="margin-top:0;opacity:.8">
      Hinnastus: A_m2 = (Σ s_mm / 1000) * (pikkus_mm / 1000). Hind = A_m2 * materjali €/m² * kogus.
    </p>

    <p><label>KM %<br>
      <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($pricing['vat']); ?>" style="width:100%;">
    </label></p>

    <p style="margin:10px 0 6px;"><strong>Materjalid (JSON, ajutine)</strong></p>
    <textarea name="spb_materials_json" style="width:100%;min-height:160px;"><?php
      echo esc_textarea(wp_json_encode($pricing['materials'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    ?></textarea>

    <p style="font-size:12px;opacity:.75;margin-top:8px">
      Järgmises punktis teeme materjalidele ka mugava tabeli.
    </p>
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
    update_post_meta($post_id, '_spb_dims', $dims);

    // pattern
    $pattern_json = wp_unslash($_POST['spb_pattern_json'] ?? '[]');
    $pattern = json_decode($pattern_json, true);
    if (!is_array($pattern)) $pattern = [];
    $pattern = array_values(array_map('sanitize_key', $pattern));
    update_post_meta($post_id, '_spb_pattern', $pattern);

    // pricing
    $vat = floatval($_POST['spb_vat'] ?? 24);
    $materials_json = wp_unslash($_POST['spb_materials_json'] ?? '[]');
    $materials = json_decode($materials_json, true);
    if (!is_array($materials)) $materials = [];

    $pricing = [
      'vat' => $vat,
      'materials' => $materials,
    ];
    update_post_meta($post_id, '_spb_pricing', $pricing);
  }
}

new Steel_Profile_Builder();
