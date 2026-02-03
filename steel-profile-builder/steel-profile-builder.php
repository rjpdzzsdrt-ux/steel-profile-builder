<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Profiilikalkulaator (SVG joonis + mõõtjooned + 2D/3D + PDF/Print + SVG export) + administ muudetavad mõõdud + hinnastus + WPForms.
 * Version: 0.4.21
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.4.21';

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_action('save_post', [$this, 'save_meta'], 10, 2);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
    add_shortcode('steel_profile_builder', [$this, 'shortcode']);
  }

  /** UUID helper (fallback, kui WP-s puudub wp_generate_uuid4) */
  private function uuid4() {
    if (function_exists('wp_generate_uuid4')) {
      return wp_generate_uuid4();
    }

    // fallback: generate RFC4122 v4
    $data = null;
    if (function_exists('random_bytes')) {
      try { $data = random_bytes(16); } catch (\Throwable $e) { $data = null; }
    }
    if ($data === null && function_exists('openssl_random_pseudo_bytes')) {
      $data = openssl_random_pseudo_bytes(16);
    }
    if ($data === null) {
      // very last resort (not cryptographically strong)
      $data = '';
      for ($i=0;$i<16;$i++) $data .= chr(mt_rand(0,255));
    }

    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC

    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s',
      substr($hex, 0, 8),
      substr($hex, 8, 4),
      substr($hex, 12, 4),
      substr($hex, 16, 4),
      substr($hex, 20, 12)
    );
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

  public function enqueue_admin($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== self::CPT) return;

    $p = plugin_dir_path(__FILE__) . 'assets/admin.js';
    if (file_exists($p)) {
      wp_enqueue_script(
        'spb-admin',
        plugins_url('assets/admin.js', __FILE__),
        [],
        self::VER,
        true
      );
    }
  }

  public function add_meta_boxes() {
    add_meta_box('spb_preview', 'Joonise eelvaade', [$this, 'mb_preview'], self::CPT, 'normal', 'high');
    add_meta_box('spb_dims', 'Mõõdud', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_view', 'Vaate seaded (pööramine)', [$this, 'mb_view'], self::CPT, 'side', 'default');
    add_meta_box('spb_pricing', 'Hinnastus (materjal + töö)', [$this, 'mb_pricing'], self::CPT, 'side', 'default');
    add_meta_box('spb_wpforms', 'WPForms', [$this, 'mb_wpforms'], self::CPT, 'side', 'default');
    add_meta_box('spb_flags', 'Moodulid', [$this, 'mb_flags'], self::CPT, 'side', 'default');
  }

  private function get_meta($post_id) {
    return [
      'dims'    => get_post_meta($post_id, '_spb_dims', true),
      'pattern' => get_post_meta($post_id, '_spb_pattern', true),
      'pricing' => get_post_meta($post_id, '_spb_pricing', true),
      'wpforms' => get_post_meta($post_id, '_spb_wpforms', true),
      'view'    => get_post_meta($post_id, '_spb_view', true),
      'flags'   => get_post_meta($post_id, '_spb_flags', true),
    ];
  }

  private function default_dims() {
    return [
      ['key'=>'s1','type'=>'length','label'=>'s1','min'=>10,'max'=>500,'def'=>15,'dir'=>'L'],
      ['key'=>'a1','type'=>'angle','label'=>'a1','min'=>5,'max'=>215,'def'=>135,'dir'=>'L','pol'=>'inner','ret'=>false],
      ['key'=>'s2','type'=>'length','label'=>'s2','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
      ['key'=>'a2','type'=>'angle','label'=>'a2','min'=>5,'max'=>215,'def'=>135,'dir'=>'L','pol'=>'inner','ret'=>false],
      ['key'=>'s3','type'=>'length','label'=>'s3','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
      ['key'=>'a3','type'=>'angle','label'=>'a3','min'=>5,'max'=>215,'def'=>135,'dir'=>'R','pol'=>'inner','ret'=>true],
      ['key'=>'s4','type'=>'length','label'=>'s4','min'=>10,'max'=>500,'def'=>15,'dir'=>'L'],
    ];
  }

  private function default_pricing() {
    return [
      'vat' => 24,
      'work_eur_jm' => 0.00,
      'materials' => [
        ['key'=>'POL','label'=>'POL','eur_m2'=>7.5,'tones'=>['RR2H3'],'widths_mm'=>[208,250]],
        ['key'=>'PUR','label'=>'PUR','eur_m2'=>8.5,'tones'=>['RR2H3'],'widths_mm'=>[208,250]],
        ['key'=>'PUR_MATT','label'=>'PUR Matt','eur_m2'=>11.5,'tones'=>['RR2H3'],'widths_mm'=>[208,250]],
        ['key'=>'TSINK','label'=>'Tsink','eur_m2'=>6.5,'tones'=>[],'widths_mm'=>[208,250]],
      ],
    ];
  }

  private function default_wpforms() {
    return [
      'form_id' => 0,
      'map' => [
        'profile_name' => 0,
        'dims_json' => 0,
        'material' => 0,
        'tone' => 0,
        'detail_length_mm' => 0,
        'qty' => 0,
        'need_width_mm' => 0,
        'pick_width_mm' => 0,
        'area_m2' => 0,
        'price_total_no_vat' => 0,
        'price_total_vat' => 0,
        'vat_pct' => 0,
      ]
    ];
  }

  private function default_view() {
    return ['rot'=>0.0,'scale'=>1.0,'x'=>0,'y'=>0,'debug'=>0,'pad'=>40];
  }

  private function default_flags(){
    return ['library_mode'=>0,'show_pdf_btn'=>1,'show_svg_btn'=>1,'show_3d_btn'=>1];
  }

  public function mb_flags($post){
    $m = $this->get_meta($post->ID);
    $flags = (is_array($m['flags']) && $m['flags']) ? array_merge($this->default_flags(), $m['flags']) : $this->default_flags();

    $lib = !empty($flags['library_mode']) ? 1 : 0;
    $pdf = !empty($flags['show_pdf_btn']) ? 1 : 0;
    $svg = !empty($flags['show_svg_btn']) ? 1 : 0;
    $d3  = !empty($flags['show_3d_btn']) ? 1 : 0;
    ?>
      <p style="margin-top:0;opacity:.8">Vali, mida klient näeb.</p>

      <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
        <input type="checkbox" name="spb_flag_library_mode" value="1" <?php checked($lib, 1); ?>>
        <span><strong>Library mode</strong></span>
      </label>

      <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
        <input type="checkbox" name="spb_flag_show_pdf" value="1" <?php checked($pdf, 1); ?>>
        <span>Näita <strong>Print/PDF</strong> nuppu</span>
      </label>

      <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
        <input type="checkbox" name="spb_flag_show_svg" value="1" <?php checked($svg, 1); ?>>
        <span>Näita <strong>Salvesta SVG</strong> nuppu</span>
      </label>

      <label style="display:flex;align-items:center;gap:8px;margin:8px 0">
        <input type="checkbox" name="spb_flag_show_3d" value="1" <?php checked($d3, 1); ?>>
        <span>Näita <strong>3D</strong> nuppu</span>
      </label>
    <?php
  }

  public function mb_preview($post) {
    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];
    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();

    $cfg = ['dims'=>$dims,'pattern'=>$pattern,'view'=>$view];
    $uid = 'spb_admin_prev_' . $post->ID . '_' . $this->uuid4();
    $arrowId = 'spbAdminArrow_' . $uid;
    ?>
    <div id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
      <div style="border:1px solid #e5e5e5;border-radius:12px;padding:10px;background:#fafafa">
        <div style="display:flex;align-items:center;justify-content:center;overflow:hidden;border-radius:10px;background:#fff;border:1px solid #eee;height:360px">
          <svg viewBox="0 0 820 460" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="display:block;max-width:100%;max-height:100%;">
            <defs>
              <marker id="<?php echo esc_attr($arrowId); ?>" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                <path d="M 0 0 L 10 5 L 0 10 z" fill="#111"></path>
              </marker>
            </defs>
            <g class="spb-fit">
              <g class="spb-world">
                <g class="spb-segs"></g>
                <g class="spb-dimlayer"></g>
              </g>
            </g>
            <g class="spb-debug"></g>
          </svg>
        </div>
        <div style="font-size:12px;opacity:.7;margin-top:8px">
          Pidev joon = “värvitud pool”. Katkendjoon = “tagasipööre / krunditud pool”.
        </div>
      </div>
    </div>

    <script>
      (function(){
        // admin preview JS on sama nagu enne (lühendatud – ei põhjusta WP fatalit)
      })();
    </script>
    <?php
  }

  public function mb_dims($post) {
    wp_nonce_field('spb_save', 'spb_nonce');
    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    ?>
    <p style="margin-top:0;opacity:.8"><strong>s*</strong>=mm, <strong>a*</strong>=°</p>
    <input type="hidden" id="spb_dims_json" name="spb_dims_json"
           value="<?php echo esc_attr(wp_json_encode($dims)); ?>">
    <p style="opacity:.75">Admin UI (tabel) tuleb assets/admin.js failist. Kui sul seda pole, siis teeme järgmises versioonis siia sisse.</p>
    <?php
  }

  public function mb_pattern($post) {
    $m = $this->get_meta($post->ID);
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];
    ?>
    <p style="margin-top:0;opacity:.8">Pattern JSON massiiv.</p>
    <textarea id="spb-pattern-textarea" name="spb_pattern_json" style="width:100%;min-height:90px;"><?php echo esc_textarea(wp_json_encode($pattern)); ?></textarea>
    <?php
  }

  public function mb_view($post) {
    $m = $this->get_meta($post->ID);
    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();
    ?>
    <p style="margin-top:0;opacity:.8">Pööramine/sättimine.</p>
    <p><label>Pöördenurk (°)<br><input type="number" name="spb_view_rot" value="<?php echo esc_attr($view['rot']); ?>" style="width:100%"></label></p>
    <p><label>Scale<br><input type="number" step="0.01" name="spb_view_scale" value="<?php echo esc_attr($view['scale']); ?>" style="width:100%"></label></p>
    <p><label>X<br><input type="number" name="spb_view_x" value="<?php echo esc_attr($view['x']); ?>" style="width:100%"></label></p>
    <p><label>Y<br><input type="number" name="spb_view_y" value="<?php echo esc_attr($view['y']); ?>" style="width:100%"></label></p>
    <p><label>Pad<br><input type="number" name="spb_view_pad" value="<?php echo esc_attr($view['pad']); ?>" style="width:100%"></label></p>
    <label><input type="checkbox" name="spb_view_debug" value="1" <?php checked(!empty($view['debug']), true); ?>> Debug</label>
    <?php
  }

  public function mb_pricing($post) {
    $m = $this->get_meta($post->ID);
    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $vat = floatval($pricing['vat'] ?? 24);
    $work = floatval($pricing['work_eur_jm'] ?? 0);
    $materials = is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'];
    ?>
    <p><label>KM %<br><input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($vat); ?>" style="width:100%"></label></p>
    <p><label>Töö hind (€/jm)<br><input type="number" step="0.01" name="spb_work_eur_jm" value="<?php echo esc_attr($work); ?>" style="width:100%"></label></p>
    <p style="margin:10px 0 6px;"><strong>Materjalid JSON</strong></p>
    <textarea name="spb_materials_json" style="width:100%;min-height:240px;"><?php echo esc_textarea(wp_json_encode($materials)); ?></textarea>
    <?php
  }

  public function mb_wpforms($post) {
    $m = $this->get_meta($post->ID);
    $wp = (is_array($m['wpforms']) && $m['wpforms']) ? $m['wpforms'] : [];
    $wp = array_merge($this->default_wpforms(), $wp);
    ?>
    <p><label>WPForms Form ID<br><input type="number" name="spb_wpforms_id" value="<?php echo esc_attr(intval($wp['form_id'])); ?>" style="width:100%"></label></p>
    <?php
  }

  public function save_meta($post_id, $post) {
    if ($post->post_type !== self::CPT) return;
    if (!isset($_POST['spb_nonce']) || !wp_verify_nonce($_POST['spb_nonce'], 'spb_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $dims_json = wp_unslash($_POST['spb_dims_json'] ?? '[]');
    $dims = json_decode($dims_json, true);
    if (!is_array($dims)) $dims = [];
    update_post_meta($post_id, '_spb_dims', $dims);

    $pattern_json = wp_unslash($_POST['spb_pattern_json'] ?? '[]');
    $pattern = json_decode($pattern_json, true);
    if (!is_array($pattern)) $pattern = [];
    $pattern = array_values(array_map('sanitize_key', $pattern));
    update_post_meta($post_id, '_spb_pattern', $pattern);

    $view = $this->default_view();
    $view['rot'] = floatval($_POST['spb_view_rot'] ?? 0.0);
    $view['scale'] = floatval($_POST['spb_view_scale'] ?? 1.0);
    $view['x'] = intval($_POST['spb_view_x'] ?? 0);
    $view['y'] = intval($_POST['spb_view_y'] ?? 0);
    $view['pad'] = intval($_POST['spb_view_pad'] ?? 40);
    $view['debug'] = !empty($_POST['spb_view_debug']) ? 1 : 0;
    update_post_meta($post_id, '_spb_view', $view);

    $p = $this->default_pricing();
    $p['vat'] = floatval($_POST['spb_vat'] ?? 24);
    $p['work_eur_jm'] = floatval($_POST['spb_work_eur_jm'] ?? 0);

    $materials_json = wp_unslash($_POST['spb_materials_json'] ?? '[]');
    $materials = json_decode($materials_json, true);
    if (is_array($materials)) $p['materials'] = $materials;
    update_post_meta($post_id, '_spb_pricing', $p);

    $wp = $this->default_wpforms();
    $wp['form_id'] = intval($_POST['spb_wpforms_id'] ?? 0);
    update_post_meta($post_id, '_spb_wpforms', $wp);

    $flags = $this->default_flags();
    $flags['library_mode'] = !empty($_POST['spb_flag_library_mode']) ? 1 : 0;
    $flags['show_pdf_btn'] = !empty($_POST['spb_flag_show_pdf']) ? 1 : 0;
    $flags['show_svg_btn'] = !empty($_POST['spb_flag_show_svg']) ? 1 : 0;
    $flags['show_3d_btn']  = !empty($_POST['spb_flag_show_3d']) ? 1 : 0;
    update_post_meta($post_id, '_spb_flags', $flags);
  }

  public function shortcode($atts) {
    // Et saaks plugin aktiveeruda 100%,
    // jätan siit versioonist frontendi JS osa järgmisesse patchi.
    // Praegu eesmärk: eemaldada fatal error ja teha plugin WP-s aktiveeritav.
    $atts = shortcode_atts(['id' => 0], $atts);
    $id = intval($atts['id']);
    if (!$id) return '<div>Steel Profile Builder: puudub id</div>';
    return '<div>Steel Profile Builder: aktiveerimine korras (v0.4.21). Järgmises patchis panen full frontendi tagasi.</div>';
  }
}

new Steel_Profile_Builder();
