<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Profiilikalkulaator (SVG joonis + mõõtjooned + 2D/3D + auto-fit + library mode + WPForms) + administ muudetavad mõõdud + hinnastus.
 * Version: 0.4.20
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.4.20';
  const OPT = 'spb_settings';

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_action('save_post', [$this, 'save_meta'], 10, 2);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
    add_shortcode('steel_profile_builder', [$this, 'shortcode']);

    // REST for library mode
    add_action('rest_api_init', [$this, 'register_rest']);

    // Settings page for library WPForms
    add_action('admin_menu', [$this, 'register_settings_page']);
    add_action('admin_init', [$this, 'register_settings']);
  }

  /* ===========================
   *  CPT
   * =========================== */
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

    wp_enqueue_script(
      'spb-admin',
      plugins_url('assets/admin.js', __FILE__),
      [],
      self::VER,
      true
    );
  }

  /* ===========================
   *  SETTINGS: Library WPForms
   * =========================== */
  public function register_settings_page() {
    add_options_page(
      'Steel Profile Builder',
      'Steel Profile Builder',
      'manage_options',
      'spb-settings',
      [$this, 'render_settings_page']
    );
  }

  public function register_settings() {
    register_setting('spb_settings_group', self::OPT, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_settings'],
      'default' => [],
    ]);
  }

  public function sanitize_settings($in) {
    $out = is_array($in) ? $in : [];
    $out['library_wpforms_form_id'] = intval($out['library_wpforms_form_id'] ?? 0);

    // mapping is key=>fieldId
    $map = $out['library_wpforms_map'] ?? [];
    if (!is_array($map)) $map = [];
    $clean = [];
    foreach ($map as $k => $v) {
      $clean[sanitize_key($k)] = intval($v);
    }
    $out['library_wpforms_map'] = $clean;

    return $out;
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $opt = get_option(self::OPT, []);
    $form_id = intval($opt['library_wpforms_form_id'] ?? 0);
    $map = is_array($opt['library_wpforms_map'] ?? null) ? $opt['library_wpforms_map'] : [];

    $fields = [
      'profile_name' => 'Profiili nimi',
      'dims_json' => 'Mõõdud JSON',
      'material' => 'Materjal',
      'detail_length_mm' => 'Detaili pikkus (mm)',
      'qty' => 'Kogus',
      'sum_s_mm' => 'Σ s (mm)',
      'area_m2' => 'Pindala (m²)',
      'price_material_no_vat' => 'Materjali hind ilma KM',
      'price_jm_no_vat' => 'JM hind ilma KM',
      'price_total_no_vat' => 'Kokku ilma KM',
      'price_total_vat' => 'Kokku koos KM',
      'vat_pct' => 'KM %',
    ];
    ?>
    <div class="wrap">
      <h1>Steel Profile Builder – Settings</h1>

      <form method="post" action="options.php">
        <?php settings_fields('spb_settings_group'); ?>
        <?php do_settings_sections('spb_settings_group'); ?>

        <h2>Library mode – WPForms</h2>
        <p style="opacity:.8">
          Seda kasutatakse ainult siis, kui shortcode on <code>[steel_profile_builder]</code> ilma id-ta (library mode).
          Kui siin on 0, siis library mode ei ava vormi.
        </p>

        <table class="form-table">
          <tr>
            <th scope="row">WPForms Form ID</th>
            <td>
              <input type="number" name="<?php echo esc_attr(self::OPT); ?>[library_wpforms_form_id]" value="<?php echo esc_attr($form_id); ?>" />
            </td>
          </tr>
        </table>

        <h3>Field mapping</h3>
        <p style="opacity:.8">0 = ei täida.</p>
        <table class="widefat" style="max-width:900px">
          <thead><tr><th>Väli</th><th style="width:240px">WPForms Field ID</th></tr></thead>
          <tbody>
          <?php foreach ($fields as $k => $label): ?>
            <tr>
              <td><?php echo esc_html($label); ?> <code><?php echo esc_html($k); ?></code></td>
              <td>
                <input type="number" name="<?php echo esc_attr(self::OPT); ?>[library_wpforms_map][<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr(intval($map[$k] ?? 0)); ?>" />
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  private function get_library_wpforms_cfg() {
    $opt = get_option(self::OPT, []);
    $form_id = intval($opt['library_wpforms_form_id'] ?? 0);
    $map = is_array($opt['library_wpforms_map'] ?? null) ? $opt['library_wpforms_map'] : $this->default_wpforms()['map'];
    return [
      'form_id' => $form_id,
      'map' => $map,
    ];
  }

  /* ===========================
   *  REST
   * =========================== */
  public function register_rest() {
    register_rest_route('spb/v1', '/profiles', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'rest_profiles'],
    ]);

    register_rest_route('spb/v1', '/profile/(?P<id>\d+)', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => [$this, 'rest_profile'],
      'args' => [
        'id' => ['validate_callback' => function($v){ return is_numeric($v) && intval($v) > 0; }]
      ]
    ]);
  }

  public function rest_profiles($req) {
    $q = new WP_Query([
      'post_type' => self::CPT,
      'post_status' => 'publish',
      'posts_per_page' => 200,
      'orderby' => 'title',
      'order' => 'ASC',
      'no_found_rows' => true,
    ]);
    $out = [];
    foreach ($q->posts as $p) {
      $out[] = [
        'id' => $p->ID,
        'title' => get_the_title($p->ID),
      ];
    }
    return rest_ensure_response($out);
  }

  public function rest_profile($req) {
    $id = intval($req['id']);
    $post = get_post($id);
    if (!$post || $post->post_type !== self::CPT) {
      return new WP_REST_Response(['error' => 'not_found'], 404);
    }

    $m = $this->get_meta($id);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];

    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();

    $cfg = [
      'profileId' => $id,
      'profileName' => get_the_title($id),
      'dims' => $dims,
      'pattern' => $pattern,
      'vat' => floatval($pricing['vat'] ?? 24),
      'jm_work_eur_jm' => floatval($pricing['jm_work_eur_jm'] ?? 0),
      'jm_per_m_eur_jm' => floatval($pricing['jm_per_m_eur_jm'] ?? 0),
      'materials' => is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'],
      'view' => [
        'rot' => floatval($view['rot'] ?? 0.0),
        'scale' => floatval($view['scale'] ?? 1.0),
        'x' => intval($view['x'] ?? 0),
        'y' => intval($view['y'] ?? 0),
        'debug' => !empty($view['debug']) ? 1 : 0,
        'pad' => intval($view['pad'] ?? 40),
      ],
    ];

    return rest_ensure_response($cfg);
  }

  /* ===========================
   *  META BOXES
   * =========================== */
  public function add_meta_boxes() {
    add_meta_box('spb_preview', 'Joonise eelvaade', [$this, 'mb_preview'], self::CPT, 'normal', 'high');
    add_meta_box('spb_dims', 'Mõõdud', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_view', 'Vaate seaded (pööramine)', [$this, 'mb_view'], self::CPT, 'side', 'default');
    add_meta_box('spb_pricing', 'Hinnastus (m² + JM + KM)', [$this, 'mb_pricing'], self::CPT, 'side', 'default');
    add_meta_box('spb_wpforms', 'WPForms', [$this, 'mb_wpforms'], self::CPT, 'side', 'default');
  }

  private function get_meta($post_id) {
    return [
      'dims'    => get_post_meta($post_id, '_spb_dims', true),
      'pattern' => get_post_meta($post_id, '_spb_pattern', true),
      'pricing' => get_post_meta($post_id, '_spb_pricing', true),
      'wpforms' => get_post_meta($post_id, '_spb_wpforms', true),
      'view'    => get_post_meta($post_id, '_spb_view', true),
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
      'jm_work_eur_jm' => 0.00,
      'jm_per_m_eur_jm' => 0.00,
      'materials' => [
        ['key'=>'POL','label'=>'POL','eur_m2'=>7.5],
        ['key'=>'PUR','label'=>'PUR','eur_m2'=>8.5],
        ['key'=>'PUR_MATT','label'=>'PUR Matt','eur_m2'=>11.5],
        ['key'=>'TSINK','label'=>'Tsink','eur_m2'=>6.5],
      ]
    ];
  }

  private function default_wpforms() {
    return [
      'form_id' => 0,
      'map' => [
        'profile_name' => 0,
        'dims_json' => 0,
        'material' => 0,
        'detail_length_mm' => 0,
        'qty' => 0,
        'sum_s_mm' => 0,
        'area_m2' => 0,
        'price_material_no_vat' => 0,
        'price_jm_no_vat' => 0,
        'price_total_no_vat' => 0,
        'price_total_vat' => 0,
        'vat_pct' => 0,
      ]
    ];
  }

  private function default_view() {
    return [
      'rot' => 0.0,
      'scale' => 1.0,
      'x' => 0,
      'y' => 0,
      'debug' => 0,
      'pad' => 40,
    ];
  }

  /* ===========================
   *  BACKEND PREVIEW (SVG)
   * =========================== */
  public function mb_preview($post) {
    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];
    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();

    $cfg = ['dims'=>$dims,'pattern'=>$pattern,'view'=>$view];
    $uid = 'spb_admin_prev_' . $post->ID . '_' . wp_generate_uuid4();
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
        const root = document.getElementById('<?php echo esc_js($uid); ?>');
        if (!root) return;
        const cfg0 = JSON.parse(root.dataset.spb || '{}');

        const fitWrap = root.querySelector('.spb-fit');
        const world = root.querySelector('.spb-world');
        const segs = root.querySelector('.spb-segs');
        const dimLayer = root.querySelector('.spb-dimlayer');
        const debugLayer = root.querySelector('.spb-debug');
        const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

        const VB_W = 820, VB_H = 460;
        const CX = 410, CY = 230;
        let lastBBox = null;

        function toNum(v, f){ const n = Number(v); return Number.isFinite(n) ? n : f; }
        function clamp(n, min, max){ n = toNum(n, min); return Math.max(min, Math.min(max, n)); }
        function deg2rad(d){ return d * Math.PI / 180; }
        function turnFromAngle(aDeg, pol){ const a = Number(aDeg || 0); return (pol === 'outer') ? a : (180 - a); }

        function svgEl(tag){ return document.createElementNS('http://www.w3.org/2000/svg', tag); }
        function addLine(g, x1,y1,x2,y2, w, dash){
          const l = svgEl('line');
          l.setAttribute('x1', x1); l.setAttribute('y1', y1);
          l.setAttribute('x2', x2); l.setAttribute('y2', y2);
          l.setAttribute('stroke', '#111');
          l.setAttribute('stroke-width', w || 3);
          if (dash) l.setAttribute('stroke-dasharray', dash);
          g.appendChild(l);
          return l;
        }
        function addDimLine(g, x1,y1,x2,y2, w, op, arrows){
          const l = svgEl('line');
          l.setAttribute('x1', x1); l.setAttribute('y1', y1);
          l.setAttribute('x2', x2); l.setAttribute('y2', y2);
          l.setAttribute('stroke', '#111');
          l.setAttribute('stroke-width', w || 1);
          if (op != null) l.setAttribute('opacity', op);
          if (arrows) {
            l.setAttribute('marker-start', `url(#${ARROW_ID})`);
            l.setAttribute('marker-end', `url(#${ARROW_ID})`);
          }
          g.appendChild(l);
          return l;
        }
        function addText(g, x,y, text, rot){
          const t = svgEl('text');
          t.setAttribute('x', x); t.setAttribute('y', y);
          t.textContent = text;
          t.setAttribute('fill', '#111');
          t.setAttribute('font-size', '13');
          t.setAttribute('dominant-baseline', 'middle');
          t.setAttribute('text-anchor', 'middle');
          t.setAttribute('paint-order', 'stroke');
          t.setAttribute('stroke', '#fff');
          t.setAttribute('stroke-width', '4');
          if (typeof rot === 'number') t.setAttribute('transform', `rotate(${rot} ${x} ${y})`);
          g.appendChild(t);
          return t;
        }

        function vec(x,y){ return {x,y}; }
        function sub(a,b){ return {x:a.x-b.x,y:a.y-b.y}; }
        function add(a,b){ return {x:a.x+b.x,y:a.y+b.y}; }
        function mul(a,k){ return {x:a.x*k,y:a.y*k}; }
        function vlen(v){ return Math.hypot(v.x, v.y) || 1; }
        function norm(v){ const l=vlen(v); return {x:v.x/l,y:v.y/l}; }
        function perp(v){ return {x:-v.y,y:v.x}; }

        function parseJSON(s, fallback){ try { return JSON.parse(s); } catch(e){ return fallback; } }
        function getDims(){
          const hidden = document.getElementById('spb_dims_json');
          const dims = hidden ? parseJSON(hidden.value || '[]', []) : (cfg0.dims || []);
          return Array.isArray(dims) ? dims : [];
        }
        function getPattern(){
          const ta = document.getElementById('spb-pattern-textarea') || document.querySelector('textarea[name="spb_pattern_json"]');
          const pat = ta ? parseJSON(ta.value || '[]', []) : (cfg0.pattern || []);
          return Array.isArray(pat) ? pat : [];
        }
        function buildDimMap(dims){
          const map = {};
          dims.forEach(d => { if (d && d.key) map[d.key] = d; });
          return map;
        }
        function buildState(dims){
          const st = {};
          dims.forEach(d=>{
            const min = (d.min ?? (d.type === 'angle' ? 5 : 10));
            const max = (d.max ?? (d.type === 'angle' ? 215 : 500));
            const def = (d.def ?? min);
            st[d.key] = clamp(def, min, max);
          });
          return st;
        }
        function getView(){
          const v = (cfg0.view || {});
          return {
            rot: toNum(v.rot, 0),
            scale: clamp(toNum(v.scale, 1), 0.6, 1.3),
            x: clamp(toNum(v.x, 0), -200, 200),
            y: clamp(toNum(v.y, 0), -200, 200),
            debug: !!toNum(v.debug, 0),
            pad: clamp(toNum(v.pad, 40), 20, 80),
          };
        }

        function computePolyline(pattern, dimMap, state){
          let x = 140, y = 360;
          let heading = -90;
          const pts = [[x,y]];
          const segStyle = [];

          const segKeys = pattern.filter(k => dimMap[k] && dimMap[k].type === 'length');
          const totalMm = segKeys.reduce((sum,k)=> sum + Number(state[k] || 0), 0);
          const totalMmSafe = Math.max(totalMm, 50);
          const kScale = totalMmSafe > 0 ? (520 / totalMmSafe) : 1;

          let pendingReturn = false;

          for (const key of pattern) {
            const meta = dimMap[key];
            if (!meta) continue;

            if (meta.type === 'length') {
              const mm = Number(state[key] || 0);
              const dx = Math.cos(deg2rad(heading)) * (mm * kScale);
              const dy = Math.sin(deg2rad(heading)) * (mm * kScale);
              x += dx; y += dy;
              pts.push([x,y]);

              segStyle.push(pendingReturn ? 'return' : 'main');
              pendingReturn = false;
            } else {
              const pol = (meta.pol === 'outer') ? 'outer' : 'inner';
              const dir = (meta.dir === 'R') ? -1 : 1;
              const turn = turnFromAngle(state[key], pol);
              heading += dir * turn;

              if (meta.ret) pendingReturn = true;
            }
          }

          const pad = 70;
          const xs = pts.map(p=>p[0]), ys = pts.map(p=>p[1]);
          const minX = Math.min(...xs), maxX = Math.max(...xs);
          const minY = Math.min(...ys), maxY = Math.max(...ys);
          const w = (maxX - minX) || 1;
          const h = (maxY - minY) || 1;
          const scale = Math.min((800 - 2*pad)/w, (420 - 2*pad)/h);

          const pts2 = pts.map(([px,py])=>[
            (px - minX) * scale + pad,
            (py - minY) * scale + pad
          ]);

          return { pts: pts2, segStyle };
        }

        function renderSegments(pts, segStyle){
          segs.innerHTML = '';
          for (let i=0;i<pts.length-1;i++){
            const A = pts[i], B = pts[i+1];
            const style = segStyle[i] || 'main';
            addLine(segs, A[0],A[1], B[0],B[1], 3, style==='return' ? '6 6' : null);
          }
        }

        function drawDimensionSmart(parentG, A, B, label, baseOffsetPx, viewRotDeg){
          const v = sub(B,A);
          const vHat = norm(v);
          const nHat = norm(perp(vHat));
          const offBase = mul(nHat, baseOffsetPx);

          const A2 = add(A, offBase);
          const B2 = add(B, offBase);

          addDimLine(parentG, A.x, A.y, A2.x, A2.y, 1, .35, false);
          addDimLine(parentG, B.x, B.y, B2.x, B2.y, 1, .35, false);
          addDimLine(parentG, A2.x, A2.y, B2.x, B2.y, 1.4, 1, true);

          let ang = Math.atan2(vHat.y, vHat.x) * 180 / Math.PI;
          if (ang > 90) ang -= 180;
          if (ang < -90) ang += 180;
          const textRot = ang - viewRotDeg;

          const midBase = mul(add(A2,B2), 0.5);
          const textEl = addText(parentG, midBase.x, midBase.y - 6, label, textRot);

          function fits(){
            try{
              const segLen = Math.hypot(B2.x - A2.x, B2.y - A2.y);
              const bb = textEl.getBBox();
              const need = bb.width + 16;
              return need <= segLen;
            }catch(e){
              return true;
            }
          }

          if (!fits()){
            const offText = mul(nHat, baseOffsetPx * 1.9);
            const A3 = add(A, offText);
            const B3 = add(B, offText);
            const mid2 = mul(add(A3,B3), 0.5);

            textEl.setAttribute('x', mid2.x);
            textEl.setAttribute('y', mid2.y - 6);
            textEl.setAttribute('transform', `rotate(${textRot} ${mid2.x} ${mid2.y - 6})`);

            if (!fits()){
              textEl.setAttribute('display', 'none');
            }
          }
        }

        function renderDims(dimMap, pattern, pts, state){
          dimLayer.innerHTML = '';
          const v = getView();
          const OFFSET = -22;
          let segIndex = 0;

          for (const key of pattern) {
            const meta = dimMap[key];
            if (!meta) continue;
            if (meta.type === 'length') {
              const pA = pts[segIndex];
              const pB = pts[segIndex + 1];
              if (pA && pB) {
                drawDimensionSmart(
                  dimLayer,
                  vec(pA[0], pA[1]),
                  vec(pB[0], pB[1]),
                  `${key} ${state[key]}mm`,
                  OFFSET,
                  v.rot
                );
              }
              segIndex += 1;
            }
          }
        }

        function applyViewTweak(){
          const v = getView();
          world.setAttribute('transform',
            `translate(${v.x} ${v.y}) translate(${CX} ${CY}) rotate(${v.rot}) scale(${v.scale}) translate(${-CX} ${-CY})`
          );
        }

        function applyPointViewTransform(px, py, view){
          let x = px - CX;
          let y = py - CY;

          x *= view.scale;
          y *= view.scale;

          const r = deg2rad(view.rot);
          const xr = x * Math.cos(r) - y * Math.sin(r);
          const yr = x * Math.sin(r) + y * Math.cos(r);

          x = xr + CX + view.x;
          y = yr + CY + view.y;

          return [x,y];
        }

        function calcBBoxFromPts(pts, view){
          let minX=Infinity, minY=Infinity, maxX=-Infinity, maxY=-Infinity;

          for (const p of pts) {
            if (!p) continue;
            const [tx, ty] = applyPointViewTransform(p[0], p[1], view);
            if (!Number.isFinite(tx) || !Number.isFinite(ty)) continue;
            minX = Math.min(minX, tx);
            minY = Math.min(minY, ty);
            maxX = Math.max(maxX, tx);
            maxY = Math.max(maxY, ty);
          }

          if (!Number.isFinite(minX) || !Number.isFinite(minY)) return null;

          const w = Math.max(0, maxX - minX);
          const h = Math.max(0, maxY - minY);

          return {
            x: minX, y: minY, w, h,
            cx: (minX + maxX)/2,
            cy: (minY + maxY)/2
          };
        }

        function applyAutoFit(){
          const v = getView();
          if (!lastBBox || lastBBox.w < 2 || lastBBox.h < 2) {
            fitWrap.setAttribute('transform', '');
            return;
          }

          const pad = v.pad;
          const s = Math.min((VB_W - 2*pad) / lastBBox.w, (VB_H - 2*pad) / lastBBox.h);
          const sC = Math.max(0.25, Math.min(10, s));

          const cx = VB_W/2, cy = VB_H/2;
          const tx = cx - lastBBox.cx * sC;
          const ty = cy - lastBBox.cy * sC;

          fitWrap.setAttribute('transform', `translate(${tx} ${ty}) scale(${sC})`);
        }

        function renderDebug(){
          const v = getView();
          debugLayer.innerHTML = '';
          if (!v.debug) return;

          const r1 = svgEl('rect');
          r1.setAttribute('x', 0);
          r1.setAttribute('y', 0);
          r1.setAttribute('width', VB_W);
          r1.setAttribute('height', VB_H);
          r1.setAttribute('fill', 'none');
          r1.setAttribute('stroke', '#1e90ff');
          r1.setAttribute('stroke-width', '2');
          r1.setAttribute('opacity', '0.9');
          debugLayer.appendChild(r1);

          if (lastBBox && lastBBox.w > 0 && lastBBox.h > 0) {
            const r2 = svgEl('rect');
            r2.setAttribute('x', lastBBox.x);
            r2.setAttribute('y', lastBBox.y);
            r2.setAttribute('width', lastBBox.w);
            r2.setAttribute('height', lastBBox.h);
            r2.setAttribute('fill', 'none');
            r2.setAttribute('stroke', '#ff3b30');
            r2.setAttribute('stroke-width', '2');
            r2.setAttribute('opacity', '0.9');
            debugLayer.appendChild(r2);
          }
        }

        function update(){
          const dims = getDims();
          const pattern = getPattern();
          const dimMap = buildDimMap(dims);
          const state = buildState(dims);

          fitWrap.setAttribute('transform', '');
          world.setAttribute('transform', '');

          const out = computePolyline(pattern, dimMap, state);
          renderSegments(out.pts, out.segStyle);
          renderDims(dimMap, pattern, out.pts, state);

          applyViewTweak();

          const v = getView();
          lastBBox = calcBBoxFromPts(out.pts, v);

          applyAutoFit();
          renderDebug();
        }

        document.addEventListener('input', (e)=>{
          const t = e.target;
          if (!t) return;
          if (t.closest && t.closest('#spb-dims-table')) return update();
          if (t.id === 'spb-pattern-textarea' || t.name === 'spb_pattern_json') return update();
          if (t.name && t.name.indexOf('spb_view_') === 0) return update();
        });
        document.addEventListener('change', (e)=>{
          const t = e.target;
          if (!t) return;
          if (t.name && t.name.indexOf('spb_view_') === 0) return update();
        });
        document.addEventListener('click', (e)=>{
          const t = e.target;
          if (!t) return;
          if (t.id === 'spb-add-length' || t.id === 'spb-add-angle' || (t.classList && t.classList.contains('spb-del'))) {
            setTimeout(update, 0);
          }
        });

        update();
      })();
    </script>
    <?php
  }

  /* ===========================
   *  BACKEND: DIMS
   * =========================== */
  public function mb_dims($post) {
    wp_nonce_field('spb_save', 'spb_nonce');

    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    ?>
    <p style="margin-top:0;opacity:.8">
      <strong>s*</strong> = sirglõik (mm), <strong>a*</strong> = nurk (°). Suund: <strong>L/R</strong>. Nurk: <strong>Seest/Väljast</strong>. Tagasipööre märgib, et <em>järgmine sirglõik</em> on “krunditud poole” peale.
    </p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
      <button type="button" class="button" id="spb-add-length">+ Lisa sirglõik (s)</button>
      <button type="button" class="button" id="spb-add-angle">+ Lisa nurk (a)</button>
      <label style="display:flex;align-items:center;gap:8px;opacity:.85">
        <input type="checkbox" id="spb-auto-append-pattern" checked>
        lisa uus mõõt automaatselt patterni lõppu
      </label>
    </div>

    <div id="spb-admin-warning" style="display:none;margin:10px 0;padding:10px;border:1px solid #f2c94c;background:#fff7d6;border-radius:10px">
      Mõõtude tabel ei laadinud (admin.js). Kontrolli, et fail <code>assets/admin.js</code> on olemas ja plugin on aktiveeritud.
    </div>

    <table class="widefat" id="spb-dims-table">
      <thead>
        <tr>
          <th style="width:110px">Key</th>
          <th style="width:110px">Tüüp</th>
          <th>Silt</th>
          <th style="width:80px">Min</th>
          <th style="width:80px">Max</th>
          <th style="width:90px">Default</th>
          <th style="width:90px">Suund</th>
          <th style="width:110px">Nurk</th>
          <th style="width:110px">Tagasipööre</th>
          <th style="width:70px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <input type="hidden" id="spb_dims_json" name="spb_dims_json"
           value="<?php echo esc_attr(wp_json_encode($dims)); ?>">

    <script>
      window.setTimeout(function(){
        var tbody = document.querySelector('#spb-dims-table tbody');
        if (!tbody) return;
        if (tbody.children.length === 0) {
          var w = document.getElementById('spb-admin-warning');
          if (w) w.style.display = 'block';
        }
      }, 600);
    </script>
    <?php
  }

  public function mb_pattern($post) {
    $m = $this->get_meta($post->ID);
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];
    ?>
    <p style="margin-top:0;opacity:.8">
      Pattern on JSON massiiv. Näide: <code>["s1","a1","s2","a2","s3","a3","s4"]</code>
    </p>
    <textarea id="spb-pattern-textarea" name="spb_pattern_json" style="width:100%;min-height:90px;"><?php echo esc_textarea(wp_json_encode($pattern)); ?></textarea>
    <?php
  }

  public function mb_view($post) {
    $m = $this->get_meta($post->ID);
    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();

    $rot = floatval($view['rot'] ?? 0.0);
    $scale = floatval($view['scale'] ?? 1.0);
    if ($scale < 0.6) $scale = 0.6;
    if ($scale > 1.3) $scale = 1.3;

    $x = intval($view['x'] ?? 0);
    $y = intval($view['y'] ?? 0);
    $debug = !empty($view['debug']) ? 1 : 0;

    $pad = intval($view['pad'] ?? 40);
    if ($pad < 20) $pad = 20;
    if ($pad > 80) $pad = 80;

    $uid = 'spb_view_' . $post->ID . '_' . wp_generate_uuid4();
    ?>
    <div id="<?php echo esc_attr($uid); ?>">
      <p style="margin-top:0;opacity:.8">
        Frontendi joonise pööramine/sättimine (visuaalne). Ei muuda arvutust.<br>
        <strong>NB!</strong> Auto-fit hoiab joonise alati nähtaval.
      </p>

      <p><label>Pöördenurk (°)<br>
        <input type="number" step="1" min="-360" max="360" name="spb_view_rot" value="<?php echo esc_attr($rot); ?>" style="width:100%">
      </label></p>

      <p><label>Scale (0.6–1.3)<br>
        <input type="number" step="0.01" min="0.6" max="1.3" name="spb_view_scale" value="<?php echo esc_attr($scale); ?>" style="width:100%">
      </label></p>

      <p><label>Nihutus X (px)<br>
        <input type="number" step="1" min="-200" max="200" name="spb_view_x" value="<?php echo esc_attr($x); ?>" style="width:100%">
      </label></p>

      <p><label>Nihutus Y (px)<br>
        <input type="number" step="1" min="-200" max="200" name="spb_view_y" value="<?php echo esc_attr($y); ?>" style="width:100%">
      </label></p>

      <p><label>Auto-fit padding (20–80px)<br>
        <input type="number" step="1" min="20" max="80" name="spb_view_pad" value="<?php echo esc_attr($pad); ?>" style="width:100%">
      </label></p>

      <p style="margin-top:10px">
        <label style="display:flex;gap:8px;align-items:center">
          <input type="checkbox" name="spb_view_debug" value="1" <?php checked($debug, 1); ?>>
          <span>Debug overlay (bbox + viewBox)</span>
        </label>
      </p>

      <p style="margin-top:12px">
        <button type="button" class="button" id="<?php echo esc_attr($uid); ?>_reset">Reset view</button>
        <span style="display:block;margin-top:6px;opacity:.65;font-size:12px">Seab rot/scale/x/y/padding tagasi defaulti.</span>
      </p>
    </div>

    <script>
      (function(){
        const box = document.getElementById('<?php echo esc_js($uid); ?>');
        if (!box) return;

        const btn = document.getElementById('<?php echo esc_js($uid); ?>_reset');
        if (!btn) return;

        btn.addEventListener('click', function(){
          const rot = box.querySelector('input[name="spb_view_rot"]');
          const scale = box.querySelector('input[name="spb_view_scale"]');
          const x = box.querySelector('input[name="spb_view_x"]');
          const y = box.querySelector('input[name="spb_view_y"]');
          const pad = box.querySelector('input[name="spb_view_pad"]');
          const dbg = box.querySelector('input[name="spb_view_debug"]');

          if (rot) rot.value = 0;
          if (scale) scale.value = 1;
          if (x) x.value = 0;
          if (y) y.value = 0;
          if (pad) pad.value = 40;
          if (dbg) dbg.checked = false;

          const ev1 = new Event('input', {bubbles:true});
          if (rot) rot.dispatchEvent(ev1);
          if (scale) scale.dispatchEvent(ev1);
          if (x) x.dispatchEvent(ev1);
          if (y) y.dispatchEvent(ev1);
          if (pad) pad.dispatchEvent(ev1);
          if (dbg) dbg.dispatchEvent(new Event('change', {bubbles:true}));
        });
      })();
    </script>
    <?php
  }

  public function mb_pricing($post) {
    $m = $this->get_meta($post->ID);
    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $vat = floatval($pricing['vat'] ?? 24);
    $jm_work = floatval($pricing['jm_work_eur_jm'] ?? 0);
    $jm_per_m = floatval($pricing['jm_per_m_eur_jm'] ?? 0);
    $materials = is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'];
    ?>
    <p style="margin-top:0;opacity:.8">
      JM hind = <strong>(töö €/jm + Σs(m) * lisakomponent €/jm)</strong> * detaili jm * kogus.
    </p>

    <p><label>KM %<br>
      <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($vat); ?>" style="width:100%;">
    </label></p>

    <p><label>JM töö (€/jm)<br>
      <input type="number" step="0.01" name="spb_jm_work_eur_jm" value="<?php echo esc_attr($jm_work); ?>" style="width:100%;">
    </label></p>

    <p><label>JM lisakomponent (€/jm per Σs meetrit)<br>
      <input type="number" step="0.01" name="spb_jm_per_m_eur_jm" value="<?php echo esc_attr($jm_per_m); ?>" style="width:100%;">
    </label></p>

    <p style="margin:10px 0 6px;"><strong>Materjalid (€/m²)</strong></p>

    <table class="widefat" id="spb-materials-table">
      <thead>
        <tr>
          <th style="width:140px">Key</th>
          <th>Silt</th>
          <th style="width:120px">€/m²</th>
          <th style="width:70px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <p><button type="button" class="button" id="spb-add-material">+ Lisa materjal</button></p>

    <input type="hidden" id="spb_materials_json" name="spb_materials_json"
           value="<?php echo esc_attr(wp_json_encode($materials)); ?>">
    <?php
  }

  public function mb_wpforms($post) {
    $m = $this->get_meta($post->ID);
    $wp = (is_array($m['wpforms']) && $m['wpforms']) ? $m['wpforms'] : [];
    $wp = array_merge($this->default_wpforms(), $wp);

    $form_id = intval($wp['form_id'] ?? 0);
    $map = is_array($wp['map'] ?? null) ? $wp['map'] : $this->default_wpforms()['map'];

    $fields = [
      'profile_name' => 'Profiili nimi',
      'dims_json' => 'Mõõdud JSON',
      'material' => 'Materjal',
      'detail_length_mm' => 'Detaili pikkus (mm)',
      'qty' => 'Kogus',
      'sum_s_mm' => 'Σ s (mm)',
      'area_m2' => 'Pindala (m²)',
      'price_material_no_vat' => 'Materjali hind ilma KM',
      'price_jm_no_vat' => 'JM hind ilma KM',
      'price_total_no_vat' => 'Kokku ilma KM',
      'price_total_vat' => 'Kokku koos KM',
      'vat_pct' => 'KM %',
    ];
    ?>
    <p style="margin-top:0;opacity:.8">Pane WPForms vormi ID ja väljafield ID-d, kuhu kalkulaator kirjutab väärtused. (ID-režiim)</p>
    <p><label>WPForms Form ID<br>
      <input type="number" name="spb_wpforms_id" value="<?php echo esc_attr($form_id); ?>" style="width:100%;">
    </label></p>

    <details>
      <summary><strong>Field mapping</strong></summary>
      <p style="opacity:.8">0 = ei täida.</p>
      <?php foreach ($fields as $k => $label): ?>
        <p><label><?php echo esc_html($label); ?><br>
          <input type="number" name="spb_wpforms_map[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr(intval($map[$k] ?? 0)); ?>" style="width:100%;">
        </label></p>
      <?php endforeach; ?>
    </details>
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

    $dims_out = [];
    foreach ($dims as $d) {
      $key = sanitize_key($d['key'] ?? '');
      if (!$key) continue;

      $type = (($d['type'] ?? '') === 'angle') ? 'angle' : 'length';
      $dir  = (strtoupper($d['dir'] ?? 'L') === 'R') ? 'R' : 'L';
      $pol  = (($d['pol'] ?? '') === 'outer') ? 'outer' : 'inner';
      $ret  = !empty($d['ret']);

      $dims_out[] = [
        'key' => $key,
        'type' => $type,
        'label' => sanitize_text_field($d['label'] ?? $key),
        'min' => isset($d['min']) && $d['min'] !== '' ? floatval($d['min']) : null,
        'max' => isset($d['max']) && $d['max'] !== '' ? floatval($d['max']) : null,
        'def' => isset($d['def']) && $d['def'] !== '' ? floatval($d['def']) : null,
        'dir' => $dir,
        'pol' => ($type === 'angle') ? $pol : null,
        'ret' => ($type === 'angle') ? $ret : false,
      ];
    }
    update_post_meta($post_id, '_spb_dims', $dims_out);

    // pattern
    $pattern_json = wp_unslash($_POST['spb_pattern_json'] ?? '[]');
    $pattern = json_decode($pattern_json, true);
    if (!is_array($pattern)) $pattern = [];
    $pattern = array_values(array_map('sanitize_key', $pattern));
    update_post_meta($post_id, '_spb_pattern', $pattern);

    // view
    $view = $this->default_view();
    $rot = floatval($_POST['spb_view_rot'] ?? 0.0);
    if ($rot < -360) $rot = -360;
    if ($rot > 360) $rot = 360;

    $scale = floatval($_POST['spb_view_scale'] ?? 1.0);
    if ($scale < 0.6) $scale = 0.6;
    if ($scale > 1.3) $scale = 1.3;

    $x = intval($_POST['spb_view_x'] ?? 0);
    $y = intval($_POST['spb_view_y'] ?? 0);
    if ($x < -200) $x = -200; if ($x > 200) $x = 200;
    if ($y < -200) $y = -200; if ($y > 200) $y = 200;

    $debug = !empty($_POST['spb_view_debug']) ? 1 : 0;

    $pad = intval($_POST['spb_view_pad'] ?? 40);
    if ($pad < 20) $pad = 20;
    if ($pad > 80) $pad = 80;

    $view['rot'] = $rot;
    $view['scale'] = $scale;
    $view['x'] = $x;
    $view['y'] = $y;
    $view['debug'] = $debug;
    $view['pad'] = $pad;

    update_post_meta($post_id, '_spb_view', $view);

    // pricing
    $m = $this->default_pricing();
    $m['vat'] = floatval($_POST['spb_vat'] ?? 24);
    $m['jm_work_eur_jm'] = floatval($_POST['spb_jm_work_eur_jm'] ?? 0);
    $m['jm_per_m_eur_jm'] = floatval($_POST['spb_jm_per_m_eur_jm'] ?? 0);

    $materials_json = wp_unslash($_POST['spb_materials_json'] ?? '[]');
    $materials = json_decode($materials_json, true);
    $materials_out = [];
    if (is_array($materials)) {
      foreach ($materials as $mat) {
        $k = sanitize_key($mat['key'] ?? '');
        if (!$k) continue;
        $materials_out[] = [
          'key' => $k,
          'label' => sanitize_text_field($mat['label'] ?? $k),
          'eur_m2' => floatval($mat['eur_m2'] ?? 0),
        ];
      }
    }
    $m['materials'] = $materials_out ?: $this->default_pricing()['materials'];
    update_post_meta($post_id, '_spb_pricing', $m);

    // wpforms
    $wp = $this->default_wpforms();
    $wp['form_id'] = intval($_POST['spb_wpforms_id'] ?? 0);
    $map_in = $_POST['spb_wpforms_map'] ?? [];
    if (is_array($map_in)) {
      foreach ($wp['map'] as $k => $_) {
        $wp['map'][$k] = intval($map_in[$k] ?? 0);
      }
    }
    update_post_meta($post_id, '_spb_wpforms', $wp);
  }

  /* ===========================
   *  FRONTEND SHORTCODE
   * =========================== */
  public function shortcode($atts) {
    $atts = shortcode_atts([
      'id' => 0,
      'library' => 0, // if 1, force library mode
    ], $atts);

    $id = intval($atts['id']);
    $library = intval($atts['library']);

    // Library mode if no id or library=1
    if (!$id || $library === 1) {
      return $this->shortcode_library();
    }

    $post = get_post($id);
    if (!$post || $post->post_type !== self::CPT) return '<div>Steel Profile Builder: vale id</div>';

    $m = $this->get_meta($id);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];

    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $wp = (is_array($m['wpforms']) && $m['wpforms']) ? $m['wpforms'] : [];
    $wp = array_merge($this->default_wpforms(), $wp);

    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();

    $cfg = [
      'mode' => 'single',
      'profileId' => $id,
      'profileName' => get_the_title($id),
      'dims' => $dims,
      'pattern' => $pattern,
      'vat' => floatval($pricing['vat'] ?? 24),
      'jm_work_eur_jm' => floatval($pricing['jm_work_eur_jm'] ?? 0),
      'jm_per_m_eur_jm' => floatval($pricing['jm_per_m_eur_jm'] ?? 0),
      'materials' => is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'],
      'wpforms' => [
        'form_id' => intval($wp['form_id'] ?? 0),
        'map' => is_array($wp['map'] ?? null) ? $wp['map'] : $this->default_wpforms()['map'],
      ],
      'view' => [
        'rot' => floatval($view['rot'] ?? 0.0),
        'scale' => floatval($view['scale'] ?? 1.0),
        'x' => intval($view['x'] ?? 0),
        'y' => intval($view['y'] ?? 0),
        'debug' => !empty($view['debug']) ? 1 : 0,
        'pad' => intval($view['pad'] ?? 40),
      ],
    ];

    return $this->render_front_ui($cfg);
  }

  private function shortcode_library() {
    $wp = $this->get_library_wpforms_cfg();

    $cfg = [
      'mode' => 'library',
      'rest' => [
        'list' => rest_url('spb/v1/profiles'),
        'profile' => rest_url('spb/v1/profile/'), // append ID
      ],
      'wpforms' => [
        'form_id' => intval($wp['form_id'] ?? 0),
        'map' => is_array($wp['map'] ?? null) ? $wp['map'] : $this->default_wpforms()['map'],
      ],
    ];

    ob_start();
    $uid = 'spb_library_' . wp_generate_uuid4();
    ?>
      <div class="spb-library" id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <div class="spb-libbar">
          <div class="spb-librow">
            <label style="font-weight:800">Vali profiil</label>
            <select class="spb-lib-select"></select>
          </div>
          <div class="spb-libnote">Vali profiil dropdownist — kalkulaator laeb profiili REST kaudu.</div>
        </div>
        <div class="spb-lib-mount"></div>
      </div>

      <style>
        .spb-library{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
        .spb-library .spb-libbar{border:1px solid #eee;border-radius:16px;padding:14px;background:#fff;margin:0 0 14px 0}
        .spb-library .spb-librow{display:grid;grid-template-columns:140px 1fr;gap:12px;align-items:center}
        .spb-library select{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:12px}
        .spb-library .spb-libnote{margin-top:10px;opacity:.65}
      </style>

      <script>
        (function(){
          const root = document.getElementById('<?php echo esc_js($uid); ?>');
          if (!root) return;
          const cfg = JSON.parse(root.dataset.spb || '{}');
          const sel = root.querySelector('.spb-lib-select');
          const mount = root.querySelector('.spb-lib-mount');

          function escHtml(s){
            return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
          }

          async function loadList(){
            const res = await fetch(cfg.rest.list, {credentials:'same-origin'});
            if (!res.ok) throw new Error('REST list error');
            return await res.json();
          }

          async function loadProfile(id){
            const url = cfg.rest.profile + encodeURIComponent(id);
            const res = await fetch(url, {credentials:'same-origin'});
            if (!res.ok) throw new Error('REST profile error');
            return await res.json();
          }

          function renderPlaceholder(msg){
            mount.innerHTML = '<div style="padding:14px;border:1px solid #eee;border-radius:16px;background:#fff;opacity:.85">'+escHtml(msg)+'</div>';
          }

          async function boot(){
            renderPlaceholder('Laen profiile…');
            let list = [];
            try{
              list = await loadList();
            }catch(e){
              renderPlaceholder('Ei saa profiilide listi (REST). Kontrolli permalinkid ja cache.');
              return;
            }

            sel.innerHTML = '';
            const opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = '— vali —';
            sel.appendChild(opt0);

            (Array.isArray(list)?list:[]).forEach(p=>{
              const o = document.createElement('option');
              o.value = p.id;
              o.textContent = p.title || ('#'+p.id);
              sel.appendChild(o);
            });

            renderPlaceholder('Vali profiil dropdownist.');

            sel.addEventListener('change', async function(){
              const id = Number(sel.value || 0);
              if (!id) return renderPlaceholder('Vali profiil dropdownist.');

              renderPlaceholder('Laen profiili…');
              let prof;
              try{
                prof = await loadProfile(id);
              }catch(e){
                renderPlaceholder('Profiili laadimine ebaõnnestus.');
                return;
              }

              // inject library wpforms cfg (separate)
              prof.mode = 'library';
              prof.wpforms = cfg.wpforms || {form_id:0,map:{}};

              // render via global helper created by front UI (below)
              if (typeof window.__SPB_RENDER_SINGLE !== 'function'){
                renderPlaceholder('SPB renderer puudub. Kontrolli, et plugin on uuendatud (0.4.20).');
                return;
              }
              mount.innerHTML = '';
              window.__SPB_RENDER_SINGLE(mount, prof);
            });
          }

          boot();
        })();
      </script>
    <?php
    return ob_get_clean();
  }

  private function render_front_ui($cfg) {
    $uid = 'spb_front_' . wp_generate_uuid4();
    $arrowId = 'spbArrow_' . $uid;

    ob_start(); ?>
      <div class="spb-front" id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <div class="spb-hint">Pidev joon = värvitud pool · Katkendjoon = tagasipööre (krunditud pool)</div>
        <div class="spb-card"></div>

        <style>
          .spb-front{--spb-accent:#111; font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
          .spb-front .spb-hint{opacity:.75;margin:0 0 12px 0;font-weight:600}
          .spb-front .spb-card{border:1px solid #eaeaea;border-radius:18px;padding:18px;background:#fff}
          .spb-front .spb-title{font-size:18px;font-weight:800;margin-bottom:12px}
          .spb-front .spb-error{margin:12px 0;padding:10px;border:1px solid #ffb4b4;background:#fff1f1;border-radius:12px}

          .spb-front .spb-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;align-items:start}
          .spb-front .spb-box{border:1px solid #eee;border-radius:16px;padding:14px;background:#fff}
          .spb-front .spb-box-h{font-weight:800;margin:0 0 10px 0}
          .spb-front .spb-box-h-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}

          .spb-front .spb-mini{
            border:1px solid #ddd;background:#fff;border-radius:999px;
            padding:6px 10px;font-weight:800;cursor:pointer;
            font-size:12px;line-height:1;
            display:inline-flex;align-items:center;gap:6px;
          }
          .spb-front .spb-mini[aria-pressed="true"]{border-color:#bbb; box-shadow:0 0 0 2px rgba(0,0,0,.05)}
          .spb-front .spb-mini:active{transform:translateY(1px)}

          .spb-front .spb-draw{display:grid;gap:12px}
          .spb-front .spb-svg-wrap{
            height:420px;
            border:1px solid #eee;border-radius:14px;
            background:linear-gradient(180deg,#fafafa,#fff);
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;
            touch-action:none; /* for 3D drag */
          }
          .spb-front .spb-svg{max-width:100%;max-height:100%}
          .spb-front .spb-segs line{stroke:#111;stroke-width:3}
          .spb-front .spb-dimlayer text{
            font-size:13px;fill:#111;dominant-baseline:middle;text-anchor:middle;
            paint-order: stroke; stroke: #fff; stroke-width: 4;
          }
          .spb-front .spb-dimlayer line{stroke:#111}
          .spb-front .spb-3d polygon{stroke:#bdbdbd}
          .spb-front .spb-3d path{stroke-linecap:round}

          .spb-front .spb-titleblock{
            border-top:1px solid #eee;
            padding-top:12px;
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:10px;
            opacity:.92;
          }
          .spb-front .spb-titleblock span{display:block;font-size:12px;opacity:.65}
          .spb-front .spb-titleblock strong{display:block;font-size:14px}

          .spb-front .spb-inputs{display:grid;grid-template-columns:1fr 170px;gap:10px;align-items:center}
          .spb-front input,.spb-front select{
            width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:12px;outline:none;
          }
          .spb-front input:focus,.spb-front select:focus{border-color:#bbb}
          .spb-front .spb-note{margin-top:10px;opacity:.65;font-size:13px;line-height:1.4}

          .spb-front .spb-order{margin-top:18px}
          .spb-front .spb-row3{display:grid;grid-template-columns:1fr 1fr .7fr;gap:12px;align-items:end}
          .spb-front .spb-row label{display:block;font-weight:700;margin:0 0 6px 0;font-size:13px;opacity:.85}

          .spb-front .spb-results{margin-top:14px;border-top:1px solid #eee;padding-top:12px;display:grid;gap:8px}
          .spb-front .spb-results > div{display:flex;justify-content:space-between;gap:12px}
          .spb-front .spb-results strong{font-size:16px}
          .spb-front .spb-total strong{font-size:18px}

          .spb-front .spb-btn{
            width:100%;margin-top:12px;padding:12px 14px;border-radius:14px;border:0;cursor:pointer;
            font-weight:900;background:var(--spb-accent);color:#fff;
          }
          .spb-front .spb-foot{margin-top:10px;opacity:.6;font-size:13px}

          @media (max-width: 980px){
            .spb-front .spb-grid{grid-template-columns:1fr}
            .spb-front .spb-svg-wrap{height:360px}
            .spb-front .spb-titleblock{grid-template-columns:repeat(2,1fr)}
            .spb-front .spb-row3{grid-template-columns:1fr}
          }
        </style>

        <script>
          (function(){
            // Renderer is exported for library mode as well:
            function renderCard(rootEl, cfg){
              const card = rootEl.querySelector('.spb-card');
              card.innerHTML = `
                <div class="spb-title">${(cfg.profileName||'Profiil')}</div>
                <div class="spb-error" style="display:none"></div>

                <div class="spb-grid">
                  <div class="spb-box">
                    <div class="spb-box-h spb-box-h-row">
                      <span>Joonis</span>
                      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                        <button type="button" class="spb-mini spb-toggle-3d" aria-pressed="false">3D vaade</button>
                        <button type="button" class="spb-mini spb-reset-3d" style="display:none">Reset 3D</button>
                        <button type="button" class="spb-mini spb-save-svg">Salvesta SVG</button>
                        <button type="button" class="spb-mini spb-print">Print / PDF</button>
                      </div>
                    </div>

                    <div class="spb-draw">
                      <div class="spb-svg-wrap">
                        <svg class="spb-svg" viewBox="0 0 820 460" preserveAspectRatio="xMidYMid meet" width="100%" height="100%">
                          <defs>
                            <marker id="<?php echo esc_attr($arrowId); ?>" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                              <path d="M 0 0 L 10 5 L 0 10 z"></path>
                            </marker>
                          </defs>

                          <g class="spb-fit">
                            <g class="spb-world">
                              <g class="spb-2d">
                                <g class="spb-segs"></g>
                                <g class="spb-dimlayer"></g>
                              </g>
                              <g class="spb-3d" style="display:none"></g>
                            </g>
                          </g>
                          <g class="spb-debug"></g>
                        </svg>
                      </div>

                      <div class="spb-titleblock">
                        <div><span>Profiil</span><strong class="spb-tb-name"></strong></div>
                        <div><span>Kuupäev</span><strong class="spb-tb-date"></strong></div>
                        <div><span>Skaala</span><strong>auto</strong></div>
                        <div><span>Materjal</span><strong class="spb-tb-mat"></strong></div>
                        <div><span>Detaili pikkus</span><strong class="spb-tb-len"></strong></div>
                        <div><span>Kogus</span><strong class="spb-tb-qty"></strong></div>
                        <div><span>Σ s</span><strong class="spb-tb-sum"></strong></div>
                      </div>
                    </div>
                  </div>

                  <div class="spb-box">
                    <div class="spb-box-h">Mõõdud</div>
                    <div class="spb-inputs"></div>
                    <div class="spb-note">Sisesta mm / kraadid. Suund, nurga poolsus ja tagasipööre tulevad administ.</div>
                  </div>
                </div>

                <div class="spb-box spb-order">
                  <div class="spb-box-h">Tellimus</div>

                  <div class="spb-row3">
                    <div class="spb-row">
                      <label>Materjal</label>
                      <select class="spb-material"></select>
                    </div>
                    <div class="spb-row">
                      <label>Detaili pikkus (mm)</label>
                      <input type="number" class="spb-length" min="50" max="8000" value="2000">
                    </div>
                    <div class="spb-row">
                      <label>Kogus</label>
                      <input type="number" class="spb-qty" min="1" max="999" value="1">
                    </div>
                  </div>

                  <div class="spb-results">
                    <div><span>JM hind (ilma KM)</span><strong class="spb-price-jm">—</strong></div>
                    <div><span>Materjali hind (ilma KM)</span><strong class="spb-price-mat">—</strong></div>
                    <div class="spb-total"><span>Kokku (ilma KM)</span><strong class="spb-price-novat">—</strong></div>
                    <div class="spb-total"><span>Kokku (koos KM)</span><strong class="spb-price-vat">—</strong></div>
                  </div>

                  <button type="button" class="spb-btn spb-open-form">Küsi personaalset hinnapakkumist</button>
                  <div class="spb-foot">Hind on orienteeruv. Täpne pakkumine sõltub materjalist, töömahust ja kogusest.</div>
                </div>

                ${cfg.wpforms && Number(cfg.wpforms.form_id||0) ? `
                  <div class="spb-form-wrap" style="display:none">
                    <?php echo do_shortcode('[wpforms id="'.'__FORMID__'.'" title="false" description="false"]'); ?>
                  </div>
                ` : ``}
              `;

              // Replace placeholder form id inside the inserted HTML if form exists
              const formWrap = card.querySelector('.spb-form-wrap');
              if (formWrap && cfg.wpforms && Number(cfg.wpforms.form_id||0)){
                // WPForms shortcode already rendered server-side only for PHP paths.
                // In JS template, we can't render shortcode. So for library mode we will NOT server-render.
                // We'll instead load the WPForms shortcode HTML by inserting an iframe-like fallback not possible.
                // Therefore: for library mode we render a normal button + user places WPForms on page separately OR we server-render only in PHP render (single mode).
              }
            }

            // export a helper for library mode: it creates a new spb-front skeleton and runs the same logic
            window.__SPB_RENDER_SINGLE = function(mount, cfg){
              const wrap = document.createElement('div');
              wrap.className = 'spb-front';
              wrap.style.setProperty('--spb-accent', '#111');
              wrap.innerHTML = '<div class="spb-hint">Pidev joon = värvitud pool · Katkendjoon = tagasipööre (krunditud pool)</div><div class="spb-card"></div>';
              mount.appendChild(wrap);
              // reuse CSS from the first instance on the page (already injected by PHP)
              run(wrap, cfg, true);
            };

            function run(root, cfg, isLibraryInstance){
              const card = root.querySelector('.spb-card');
              // Render UI (single or library instance)
              // Note: WPForms shortcode rendering only works for PHP-rendered instances.
              // For library mode we still support filling fields IF the form exists on page (same field names), you can place WPForms block below.
              card.innerHTML = `
                <div class="spb-title">${(cfg.profileName||'Profiil')}</div>
                <div class="spb-error" style="display:none"></div>

                <div class="spb-grid">
                  <div class="spb-box">
                    <div class="spb-box-h spb-box-h-row">
                      <span>Joonis</span>
                      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                        <button type="button" class="spb-mini spb-toggle-3d" aria-pressed="false">3D vaade</button>
                        <button type="button" class="spb-mini spb-reset-3d" style="display:none">Reset 3D</button>
                        <button type="button" class="spb-mini spb-save-svg">Salvesta SVG</button>
                        <button type="button" class="spb-mini spb-print">Print / PDF</button>
                      </div>
                    </div>

                    <div class="spb-draw">
                      <div class="spb-svg-wrap">
                        <svg class="spb-svg" viewBox="0 0 820 460" preserveAspectRatio="xMidYMid meet" width="100%" height="100%">
                          <defs>
                            <marker id="<?php echo esc_attr($arrowId); ?>" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                              <path d="M 0 0 L 10 5 L 0 10 z"></path>
                            </marker>
                          </defs>

                          <g class="spb-fit">
                            <g class="spb-world">
                              <g class="spb-2d">
                                <g class="spb-segs"></g>
                                <g class="spb-dimlayer"></g>
                              </g>
                              <g class="spb-3d" style="display:none"></g>
                            </g>
                          </g>

                          <g class="spb-debug"></g>
                        </svg>
                      </div>

                      <div class="spb-titleblock">
                        <div><span>Profiil</span><strong class="spb-tb-name"></strong></div>
                        <div><span>Kuupäev</span><strong class="spb-tb-date"></strong></div>
                        <div><span>Skaala</span><strong>auto</strong></div>
                        <div><span>Materjal</span><strong class="spb-tb-mat"></strong></div>
                        <div><span>Detaili pikkus</span><strong class="spb-tb-len"></strong></div>
                        <div><span>Kogus</span><strong class="spb-tb-qty"></strong></div>
                        <div><span>Σ s</span><strong class="spb-tb-sum"></strong></div>
                      </div>
                    </div>
                  </div>

                  <div class="spb-box">
                    <div class="spb-box-h">Mõõdud</div>
                    <div class="spb-inputs"></div>
                    <div class="spb-note">Sisesta mm / kraadid. Suund, nurga poolsus ja tagasipööre tulevad administ.</div>
                  </div>
                </div>

                <div class="spb-box spb-order">
                  <div class="spb-box-h">Tellimus</div>

                  <div class="spb-row3">
                    <div class="spb-row">
                      <label>Materjal</label>
                      <select class="spb-material"></select>
                    </div>
                    <div class="spb-row">
                      <label>Detaili pikkus (mm)</label>
                      <input type="number" class="spb-length" min="50" max="8000" value="2000">
                    </div>
                    <div class="spb-row">
                      <label>Kogus</label>
                      <input type="number" class="spb-qty" min="1" max="999" value="1">
                    </div>
                  </div>

                  <div class="spb-results">
                    <div><span>JM hind (ilma KM)</span><strong class="spb-price-jm">—</strong></div>
                    <div><span>Materjali hind (ilma KM)</span><strong class="spb-price-mat">—</strong></div>
                    <div class="spb-total"><span>Kokku (ilma KM)</span><strong class="spb-price-novat">—</strong></div>
                    <div class="spb-total"><span>Kokku (koos KM)</span><strong class="spb-price-vat">—</strong></div>
                  </div>

                  <button type="button" class="spb-btn spb-open-form">Küsi personaalset hinnapakkumist</button>
                  <div class="spb-foot">Hind on orienteeruv. Täpne pakkumine sõltub materjalist, töömahust ja kogusest.</div>
                </div>

                <div class="spb-form-wrap" style="display:none"></div>
              `;

              const err = card.querySelector('.spb-error');
              function showErr(msg){ err.style.display='block'; err.textContent=msg; }

              if (!cfg.dims || !cfg.dims.length) {
                showErr('Sellel profiilil pole mõõte. Ava profiil adminis ja salvesta uuesti.');
                return;
              }

              // Accent color from Elementor button (best effort)
              (function setAccent(){
                try{
                  const btn = document.querySelector('.elementor a.elementor-button, .elementor button, a.elementor-button, button.elementor-button');
                  if (!btn) return;
                  const cs = getComputedStyle(btn);
                  const bg = cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? cs.backgroundColor : null;
                  if (bg) root.style.setProperty('--spb-accent', bg);
                }catch(e){}
              })();

              const inputsWrap = card.querySelector('.spb-inputs');
              const matSel = card.querySelector('.spb-material');
              const lenEl = card.querySelector('.spb-length');
              const qtyEl = card.querySelector('.spb-qty');

              const jmEl = card.querySelector('.spb-price-jm');
              const matEl = card.querySelector('.spb-price-mat');
              const novatEl = card.querySelector('.spb-price-novat');
              const vatEl = card.querySelector('.spb-price-vat');

              const toggle3dBtn = card.querySelector('.spb-toggle-3d');
              const reset3dBtn = card.querySelector('.spb-reset-3d');
              const saveSvgBtn = card.querySelector('.spb-save-svg');
              const printBtn = card.querySelector('.spb-print');

              const svgWrap = card.querySelector('.spb-svg-wrap');
              const svg = card.querySelector('.spb-svg');

              const fitWrap = card.querySelector('.spb-fit');
              const world = card.querySelector('.spb-world');

              const g2d = card.querySelector('.spb-2d');
              const segs = card.querySelector('.spb-segs');
              const dimLayer = card.querySelector('.spb-dimlayer');
              const g3d = card.querySelector('.spb-3d');
              const debugLayer = card.querySelector('.spb-debug');

              const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

              const VB_W = 820, VB_H = 460;
              const CX = 410, CY = 230;
              let lastBBox = null;

              const tbName = card.querySelector('.spb-tb-name');
              const tbDate = card.querySelector('.spb-tb-date');
              const tbMat = card.querySelector('.spb-tb-mat');
              const tbLen = card.querySelector('.spb-tb-len');
              const tbQty = card.querySelector('.spb-tb-qty');
              const tbSum = card.querySelector('.spb-tb-sum');

              const formWrap = card.querySelector('.spb-form-wrap');
              const openBtn = card.querySelector('.spb-open-form');

              const stateVal = {};
              let mode3d = false;

              // 3D camera (drag)
              const cam = {
                yaw: 28,   // degrees
                pitch: 18, // degrees
                depth: 90, // px
              };
              const camDefault = {yaw:28, pitch:18, depth:90};

              function toNum(v,f){ const n = Number(v); return Number.isFinite(n)?n:f; }
              function clamp(n,min,max){ n = toNum(n,min); return Math.max(min, Math.min(max,n)); }
              function deg2rad(d){ return d * Math.PI / 180; }
              function turnFromAngle(aDeg, pol){ const a=Number(aDeg||0); return (pol==='outer')?a:(180-a); }

              function svgEl(tag){ return document.createElementNS('http://www.w3.org/2000/svg', tag); }

              function addSegLine(x1,y1,x2,y2, dash){
                const l = svgEl('line');
                l.setAttribute('x1',x1); l.setAttribute('y1',y1);
                l.setAttribute('x2',x2); l.setAttribute('y2',y2);
                l.setAttribute('stroke','#111'); l.setAttribute('stroke-width','3');
                if (dash) l.setAttribute('stroke-dasharray', dash);
                segs.appendChild(l);
                return l;
              }

              function addDimLine(g,x1,y1,x2,y2,w,op,arrows){
                const l = svgEl('line');
                l.setAttribute('x1',x1); l.setAttribute('y1',y1);
                l.setAttribute('x2',x2); l.setAttribute('y2',y2);
                l.setAttribute('stroke','#111'); l.setAttribute('stroke-width', w||1);
                if (op!=null) l.setAttribute('opacity', op);
                if (arrows){
                  l.setAttribute('marker-start', `url(#${ARROW_ID})`);
                  l.setAttribute('marker-end', `url(#${ARROW_ID})`);
                }
                g.appendChild(l);
                return l;
              }

              function addText(g,x,y,text,rot){
                const t = svgEl('text');
                t.setAttribute('x',x); t.setAttribute('y',y);
                t.textContent = text;
                t.setAttribute('paint-order','stroke');
                t.setAttribute('stroke','#fff');
                t.setAttribute('stroke-width','4');
                if (typeof rot === 'number') t.setAttribute('transform', `rotate(${rot} ${x} ${y})`);
                g.appendChild(t);
                return t;
              }

              function vec(x,y){ return {x,y}; }
              function sub(a,b){ return {x:a.x-b.x,y:a.y-b.y}; }
              function add(a,b){ return {x:a.x+b.x,y:a.y+b.y}; }
              function mul(a,k){ return {x:a.x*k,y:a.y*k}; }
              function vlen(v){ return Math.hypot(v.x,v.y)||1; }
              function norm(v){ const l=vlen(v); return {x:v.x/l,y:v.y/l}; }
              function perp(v){ return {x:-v.y,y:v.x}; }

              function getView(){
                const v = cfg.view || {};
                return {
                  rot: toNum(v.rot, 0),
                  scale: clamp(toNum(v.scale, 1), 0.6, 1.3),
                  x: clamp(toNum(v.x, 0), -200, 200),
                  y: clamp(toNum(v.y, 0), -200, 200),
                  debug: !!toNum(v.debug, 0),
                  pad: clamp(toNum(v.pad, 40), 20, 80),
                };
              }

              function buildDimMap(){
                const map = {};
                cfg.dims.forEach(d=>{ if (d && d.key) map[d.key]=d; });
                return map;
              }

              function renderMaterials(){
                matSel.innerHTML='';
                (cfg.materials||[]).forEach(m=>{
                  const opt = document.createElement('option');
                  opt.value = m.key;
                  opt.textContent = (m.label || m.key);
                  opt.dataset.eur = toNum(m.eur_m2, 0);
                  matSel.appendChild(opt);
                });
                if (matSel.options.length) matSel.selectedIndex = 0;
              }
              function currentMaterialEurM2(){
                const opt = matSel.options[matSel.selectedIndex];
                return opt ? toNum(opt.dataset.eur,0) : 0;
              }
              function currentMaterialLabel(){
                const opt = matSel.options[matSel.selectedIndex];
                return opt ? opt.textContent : '';
              }

              function renderDimInputs(){
                inputsWrap.innerHTML='';
                cfg.dims.forEach(d=>{
                  const min = (d.min ?? (d.type==='angle'?5:10));
                  const max = (d.max ?? (d.type==='angle'?215:500));
                  const def = (d.def ?? min);

                  stateVal[d.key] = toNum(stateVal[d.key], def);

                  const lab = document.createElement('label');
                  lab.textContent = (d.label || d.key) + (d.type==='angle' ? ' (°)' : ' (mm)');

                  const inp = document.createElement('input');
                  inp.type='number';
                  inp.value = stateVal[d.key];
                  inp.min = min;
                  inp.max = max;
                  inp.dataset.key = d.key;

                  inputsWrap.appendChild(lab);
                  inputsWrap.appendChild(inp);
                });
              }

              function computePolyline(dimMap){
                let x=140, y=360;
                let heading=-90;
                const pts=[[x,y]];
                const segStyle=[];
                const pattern = Array.isArray(cfg.pattern) ? cfg.pattern : [];

                const segKeys = pattern.filter(k => dimMap[k] && dimMap[k].type==='length');
                const totalMm = segKeys.reduce((s,k)=> s + Number(stateVal[k]||0), 0);
                const totalMmSafe = Math.max(totalMm, 50);
                const kScale = totalMmSafe > 0 ? (520/totalMmSafe) : 1;

                let pendingReturn = false;

                for (const key of pattern) {
                  const meta = dimMap[key];
                  if (!meta) continue;

                  if (meta.type==='length') {
                    const mm = Number(stateVal[key]||0);
                    x += Math.cos(deg2rad(heading)) * (mm*kScale);
                    y += Math.sin(deg2rad(heading)) * (mm*kScale);
                    pts.push([x,y]);

                    segStyle.push(pendingReturn ? 'return' : 'main');
                    pendingReturn = false;
                  } else {
                    const a = Number(stateVal[key]||0);
                    const pol = (meta.pol === 'outer') ? 'outer' : 'inner';
                    const dir = (meta.dir === 'R') ? 'R' : 'L';
                    const turn = turnFromAngle(a, pol);
                    heading += (dir==='R' ? -1 : 1) * turn;

                    if (meta.ret) pendingReturn = true;
                  }
                }

                const pad=70;
                const xs = pts.map(p=>p[0]), ys=pts.map(p=>p[1]);
                const minX=Math.min(...xs), maxX=Math.max(...xs);
                const minY=Math.min(...ys), maxY=Math.max(...ys);
                const w=(maxX-minX)||1, h=(maxY-minY)||1;
                const s = Math.min((800-2*pad)/w, (420-2*pad)/h);

                const pts2 = pts.map(([px,py])=>[(px-minX)*s+pad, (py-minY)*s+pad]);
                return { pts: pts2, segStyle };
              }

              function renderSegments(pts, segStyle){
                segs.innerHTML='';
                for (let i=0;i<pts.length-1;i++){
                  const A=pts[i], B=pts[i+1];
                  const style = segStyle[i] || 'main';
                  addSegLine(A[0],A[1],B[0],B[1], style==='return' ? '6 6' : null);
                }
              }

              function drawDimensionSmart(A,B,label,baseOffsetPx, viewRotDeg){
                const v = sub(B,A);
                const vHat = norm(v);
                const nHat = norm(perp(vHat));

                const offBase = mul(nHat, baseOffsetPx);
                const A2 = add(A, offBase);
                const B2 = add(B, offBase);

                addDimLine(dimLayer, A.x, A.y, A2.x, A2.y, 1, .35, false);
                addDimLine(dimLayer, B.x, B.y, B2.x, B2.y, 1, .35, false);
                addDimLine(dimLayer, A2.x, A2.y, B2.x, B2.y, 1.4, 1, true);

                let ang = Math.atan2(vHat.y, vHat.x) * 180 / Math.PI;
                if (ang > 90) ang -= 180;
                if (ang < -90) ang += 180;
                const textRot = ang - viewRotDeg;

                const midBase = mul(add(A2,B2), 0.5);
                const textEl = addText(dimLayer, midBase.x, midBase.y - 6, label, textRot);

                function fits(){
                  try{
                    const segLen = Math.hypot(B2.x - A2.x, B2.y - A2.y);
                    const bb = textEl.getBBox();
                    const need = bb.width + 16;
                    return need <= segLen;
                  }catch(e){
                    return true;
                  }
                }

                if (!fits()){
                  const offText = mul(nHat, baseOffsetPx * 1.9);
                  const A3 = add(A, offText);
                  const B3 = add(B, offText);
                  const mid2 = mul(add(A3,B3), 0.5);

                  textEl.setAttribute('x', mid2.x);
                  textEl.setAttribute('y', mid2.y - 6);
                  textEl.setAttribute('transform', `rotate(${textRot} ${mid2.x} ${mid2.y - 6})`);

                  if (!fits()){
                    textEl.setAttribute('display', 'none');
                  }
                }
              }

              function renderDims(dimMap, pts){
                dimLayer.innerHTML='';
                const pattern = Array.isArray(cfg.pattern) ? cfg.pattern : [];
                const v = getView();
                const OFFSET=-22;
                let segIndex=0;
                for (const key of pattern) {
                  const meta = dimMap[key];
                  if (!meta) continue;
                  if (meta.type==='length') {
                    const pA=pts[segIndex], pB=pts[segIndex+1];
                    if (pA && pB) drawDimensionSmart(vec(pA[0],pA[1]), vec(pB[0],pB[1]), `${key} ${stateVal[key]}mm`, OFFSET, v.rot);
                    segIndex += 1;
                  }
                }
              }

              function applyViewTweak(){
                const v = getView();
                world.setAttribute('transform',
                  `translate(${v.x} ${v.y}) translate(${CX} ${CY}) rotate(${v.rot}) scale(${v.scale}) translate(${-CX} ${-CY})`
                );
              }

              function applyPointViewTransform(px, py, view){
                let x = px - CX;
                let y = py - CY;

                x *= view.scale;
                y *= view.scale;

                const r = deg2rad(view.rot);
                const xr = x * Math.cos(r) - y * Math.sin(r);
                const yr = x * Math.sin(r) + y * Math.cos(r);

                x = xr + CX + view.x;
                y = yr + CY + view.y;

                return [x,y];
              }

              function calcBBoxFromPts(pts, view){
                let minX=Infinity, minY=Infinity, maxX=-Infinity, maxY=-Infinity;

                for (const p of pts) {
                  if (!p) continue;
                  const [tx, ty] = applyPointViewTransform(p[0], p[1], view);
                  if (!Number.isFinite(tx) || !Number.isFinite(ty)) continue;
                  minX = Math.min(minX, tx);
                  minY = Math.min(minY, ty);
                  maxX = Math.max(maxX, tx);
                  maxY = Math.max(maxY, ty);
                }

                if (!Number.isFinite(minX) || !Number.isFinite(minY)) return null;

                const w = Math.max(0, maxX - minX);
                const h = Math.max(0, maxY - minY);

                return { x: minX, y: minY, w, h, cx: (minX + maxX)/2, cy: (minY + maxY)/2 };
              }

              function applyAutoFit(){
                const v = getView();
                if (!lastBBox || lastBBox.w < 2 || lastBBox.h < 2) {
                  fitWrap.setAttribute('transform', '');
                  return;
                }

                const pad = v.pad;
                const s = Math.min((VB_W - 2*pad) / lastBBox.w, (VB_H - 2*pad) / lastBBox.h);
                const sC = Math.max(0.25, Math.min(10, s));

                const cx = VB_W/2, cy = VB_H/2;
                const tx = cx - lastBBox.cx * sC;
                const ty = cy - lastBBox.cy * sC;

                fitWrap.setAttribute('transform', `translate(${tx} ${ty}) scale(${sC})`);
              }

              function renderDebug(){
                const v = getView();
                debugLayer.innerHTML='';
                if (!v.debug) return;

                const r1 = svgEl('rect');
                r1.setAttribute('x', 0);
                r1.setAttribute('y', 0);
                r1.setAttribute('width', VB_W);
                r1.setAttribute('height', VB_H);
                r1.setAttribute('fill', 'none');
                r1.setAttribute('stroke', '#1e90ff');
                r1.setAttribute('stroke-width', '2');
                r1.setAttribute('opacity', '0.9');
                debugLayer.appendChild(r1);

                if (lastBBox && lastBBox.w > 0 && lastBBox.h > 0) {
                  const r2 = svgEl('rect');
                  r2.setAttribute('x', lastBBox.x);
                  r2.setAttribute('y', lastBBox.y);
                  r2.setAttribute('width', lastBBox.w);
                  r2.setAttribute('height', lastBBox.h);
                  r2.setAttribute('fill', 'none');
                  r2.setAttribute('stroke', '#ff3b30');
                  r2.setAttribute('stroke-width', '2');
                  r2.setAttribute('opacity', '0.9');
                  debugLayer.appendChild(r2);
                }
              }

              // ---------- 3D (pseudo with yaw/pitch) ----------
              function polyPtsStr(pts){ return pts.map(p => `${p[0]},${p[1]}`).join(' '); }
              function addPoly(g, pts, fill, stroke, op){
                const el = svgEl('polygon');
                el.setAttribute('points', polyPtsStr(pts));
                el.setAttribute('fill', fill || 'none');
                if (stroke) el.setAttribute('stroke', stroke);
                if (op != null) el.setAttribute('opacity', String(op));
                g.appendChild(el);
                return el;
              }
              function addPath(g, d, stroke, w, fill, op, dash){
                const p = svgEl('path');
                p.setAttribute('d', d);
                p.setAttribute('fill', fill || 'none');
                p.setAttribute('stroke', stroke || '#111');
                p.setAttribute('stroke-width', String(w || 2));
                if (op != null) p.setAttribute('opacity', String(op));
                if (dash) p.setAttribute('stroke-dasharray', dash);
                g.appendChild(p);
                return p;
              }

              function backOffset(){
                // yaw affects X, pitch affects Y; clamp pitch to avoid flipping too much
                const yaw = deg2rad(cam.yaw);
                const pit = deg2rad(cam.pitch);
                const dx = cam.depth * Math.cos(yaw);
                const dy = -cam.depth * Math.sin(pit);
                return {dx, dy};
              }

              function render3D(pts, segStyle){
                g3d.innerHTML = '';

                const o = backOffset();
                const back = pts.map(p => [p[0] + o.dx, p[1] + o.dy]);

                for (let i=0;i<pts.length-1;i++){
                  const A = pts[i], B = pts[i+1];
                  const A2 = back[i], B2 = back[i+1];

                  const isReturn = (segStyle[i] === 'return');
                  const face = [A, B, B2, A2];

                  const vx = B[0]-A[0], vy=B[1]-A[1];
                  const shade = (Math.abs(vx) > Math.abs(vy)) ? '#d9d9d9' : '#cfcfcf';
                  const fill = isReturn ? '#e6e6e6' : shade;

                  addPoly(g3d, face, fill, '#bdbdbd', 1);
                }

                let dBack = '';
                for (let i=0;i<back.length;i++){
                  dBack += (i===0 ? 'M ' : ' L ') + back[i][0] + ' ' + back[i][1];
                }
                addPath(g3d, dBack, '#7a7a7a', 2, 'none', 0.9);

                let dFront = '';
                for (let i=0;i<pts.length;i++){
                  dFront += (i===0 ? 'M ' : ' L ') + pts[i][0] + ' ' + pts[i][1];
                }
                addPath(g3d, dFront, '#111', 3, 'none', 1);

                for (let i=0;i<pts.length;i++){
                  const A = pts[i], A2 = back[i];
                  addPath(g3d, `M ${A[0]} ${A[1]} L ${A2[0]} ${A2[1]}`, '#9a9a9a', 1.6, 'none', 0.9);
                }

                return { backPts: back };
              }

              function setMode3d(on){
                mode3d = !!on;
                if (toggle3dBtn){
                  toggle3dBtn.setAttribute('aria-pressed', mode3d ? 'true' : 'false');
                  toggle3dBtn.textContent = mode3d ? '2D vaade' : '3D vaade';
                }
                if (reset3dBtn) reset3dBtn.style.display = mode3d ? '' : 'none';
                if (g2d) g2d.style.display = mode3d ? 'none' : '';
                if (g3d) g3d.style.display = mode3d ? '' : 'none';
                render();
              }

              if (toggle3dBtn){
                toggle3dBtn.addEventListener('click', function(){
                  setMode3d(!mode3d);
                });
              }
              if (reset3dBtn){
                reset3dBtn.addEventListener('click', function(){
                  cam.yaw = camDefault.yaw;
                  cam.pitch = camDefault.pitch;
                  cam.depth = camDefault.depth;
                  render();
                });
              }

              // 3D drag events
              (function enable3dDrag(){
                let dragging = false;
                let lastX = 0, lastY = 0;

                function onDown(e){
                  if (!mode3d) return;
                  dragging = true;
                  const pt = (e.touches && e.touches[0]) ? e.touches[0] : e;
                  lastX = pt.clientX; lastY = pt.clientY;
                  try{ svgWrap.setPointerCapture && svgWrap.setPointerCapture(e.pointerId); }catch(_){}
                  e.preventDefault();
                }
                function onMove(e){
                  if (!dragging || !mode3d) return;
                  const pt = (e.touches && e.touches[0]) ? e.touches[0] : e;
                  const dx = pt.clientX - lastX;
                  const dy = pt.clientY - lastY;
                  lastX = pt.clientX; lastY = pt.clientY;

                  cam.yaw = cam.yaw + dx * 0.25;
                  cam.pitch = cam.pitch + dy * 0.20;

                  cam.pitch = Math.max(-60, Math.min(60, cam.pitch));
                  render();
                  e.preventDefault();
                }
                function onUp(){
                  dragging = false;
                }

                // Pointer events (desktop + many mobiles)
                svgWrap.addEventListener('pointerdown', onDown, {passive:false});
                svgWrap.addEventListener('pointermove', onMove, {passive:false});
                window.addEventListener('pointerup', onUp);

                // Touch fallback
                svgWrap.addEventListener('touchstart', onDown, {passive:false});
                svgWrap.addEventListener('touchmove', onMove, {passive:false});
                window.addEventListener('touchend', onUp);
              })();

              // ---------- calc + wpforms ----------
              function calc(){
                let sumSmm=0;
                cfg.dims.forEach(d=>{
                  if (d.type !== 'length') return;
                  const min = (d.min ?? 10);
                  const max = (d.max ?? 500);
                  sumSmm += clamp(stateVal[d.key], min, max);
                });

                const sumSm = sumSmm / 1000.0;
                const Pm = clamp(lenEl.value, 50, 8000) / 1000.0;
                const qty = clamp(qtyEl.value, 1, 999);

                const area = sumSm * Pm;
                const matNoVat = area * currentMaterialEurM2() * qty;

                const jmWork = toNum(cfg.jm_work_eur_jm, 0);
                const jmPerM = toNum(cfg.jm_per_m_eur_jm, 0);
                const jmRate = jmWork + (sumSm * jmPerM);
                const jmNoVat = (Pm * jmRate) * qty;

                const totalNoVat = matNoVat + jmNoVat;
                const vatPct = toNum(cfg.vat, 24);
                const totalVat = totalNoVat * (1 + vatPct/100);

                return { sumSmm, area, qty, matNoVat, jmNoVat, totalNoVat, totalVat, vatPct };
              }

              function dimsPayloadJSON(){
                return JSON.stringify(cfg.dims.map(d=>{
                  const o = { key:d.key, type:d.type, label:(d.label||d.key), value:stateVal[d.key] };
                  if (d.type==='angle') {
                    o.dir = d.dir || 'L';
                    o.pol = d.pol || 'inner';
                    o.ret = !!d.ret;
                  }
                  return o;
                }));
              }

              function fillWpforms(){
                const wp = cfg.wpforms || {};
                const formId = Number(wp.form_id || 0);
                if (!formId) return;

                const map = wp.map || {};
                const out = calc();

                const values = {
                  profile_name: cfg.profileName || '',
                  dims_json: dimsPayloadJSON(),
                  material: currentMaterialLabel(),
                  detail_length_mm: String(clamp(lenEl.value, 50, 8000)),
                  qty: String(clamp(qtyEl.value, 1, 999)),
                  sum_s_mm: String(out.sumSmm),
                  area_m2: String(out.area.toFixed(4)),
                  price_material_no_vat: String(out.matNoVat.toFixed(2)),
                  price_jm_no_vat: String(out.jmNoVat.toFixed(2)),
                  price_total_no_vat: String(out.totalNoVat.toFixed(2)),
                  price_total_vat: String(out.totalVat.toFixed(2)),
                  vat_pct: String(out.vatPct),
                };

                function setField(fieldId, val){
                  fieldId = Number(fieldId || 0);
                  if (!fieldId) return;
                  const el = document.querySelector(`[name="wpforms[fields][${fieldId}]"]`);
                  if (!el) return;
                  el.value = val;
                  el.dispatchEvent(new Event('input', {bubbles:true}));
                  el.dispatchEvent(new Event('change', {bubbles:true}));
                }

                Object.keys(map).forEach(k => setField(map[k], values[k] ?? ''));
              }

              function setTitleBlock(){
                const d = new Date();
                const dd = String(d.getDate()).padStart(2,'0');
                const mm = String(d.getMonth()+1).padStart(2,'0');
                const yyyy = String(d.getFullYear());
                tbName.textContent = cfg.profileName || '—';
                tbDate.textContent = `${dd}.${mm}.${yyyy}`;
              }

              function fmtFileSafe(s){
                return String(s||'profile').toLowerCase().replace(/[^a-z0-9\-_]+/g,'-').replace(/-+/g,'-').replace(/(^-|-$)/g,'');
              }

              function saveSvg(){
                try{
                  const clone = svg.cloneNode(true);
                  // remove debug for export
                  const dbg = clone.querySelector('.spb-debug');
                  if (dbg) dbg.innerHTML = '';
                  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');

                  const xml = new XMLSerializer().serializeToString(clone);
                  const blob = new Blob([xml], {type:'image/svg+xml;charset=utf-8'});
                  const url = URL.createObjectURL(blob);

                  const a = document.createElement('a');
                  a.href = url;
                  a.download = `spb-${fmtFileSafe(cfg.profileName)}.svg`;
                  document.body.appendChild(a);
                  a.click();
                  a.remove();
                  setTimeout(()=>URL.revokeObjectURL(url), 500);
                }catch(e){
                  showErr('SVG salvestamine ebaõnnestus.');
                }
              }

              function printPdf(){
                try{
                  const clone = svg.cloneNode(true);
                  const dbg = clone.querySelector('.spb-debug');
                  if (dbg) dbg.innerHTML = '';

                  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
                  clone.setAttribute('width', '100%');
                  clone.setAttribute('height', 'auto');

                  const xml = new XMLSerializer().serializeToString(clone);

                  const w = window.open('', '_blank');
                  if (!w) return showErr('Print akna avamine blokeeritud (popup blocker).');

                  w.document.open();
                  w.document.write(`
                    <!doctype html>
                    <html>
                      <head>
                        <meta charset="utf-8" />
                        <title>${cfg.profileName ? cfg.profileName : 'Steel Profile'}</title>
                        <style>
                          body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px}
                          .h{font-size:18px;font-weight:800;margin:0 0 10px 0}
                          .meta{opacity:.75;margin:0 0 14px 0}
                          .box{border:1px solid #ddd;border-radius:14px;padding:14px}
                          @media print{ body{padding:0} .box{border:0} }
                        </style>
                      </head>
                      <body>
                        <div class="h">${cfg.profileName ? cfg.profileName : 'Steel Profile'}</div>
                        <div class="meta">Print → Save as PDF</div>
                        <div class="box">${xml}</div>
                        <script>setTimeout(()=>{window.print();}, 250);</script>
                      </body>
                    </html>
                  `);
                  w.document.close();
                }catch(e){
                  showErr('Print/PDF ebaõnnestus.');
                }
              }

              if (saveSvgBtn) saveSvgBtn.addEventListener('click', saveSvg);
              if (printBtn) printBtn.addEventListener('click', printPdf);

              function render(){
                const dimMap = buildDimMap();

                fitWrap.setAttribute('transform', '');
                world.setAttribute('transform', '');

                const out = computePolyline(dimMap);

                if (!mode3d){
                  renderSegments(out.pts, out.segStyle);
                  renderDims(dimMap, out.pts);
                  g3d.innerHTML = '';
                } else {
                  dimLayer.innerHTML = '';
                  segs.innerHTML = '';
                  const three = render3D(out.pts, out.segStyle);

                  applyViewTweak();
                  const v = getView();
                  const ptsForBBox = out.pts.concat(three.backPts);
                  lastBBox = calcBBoxFromPts(ptsForBBox, v);
                  applyAutoFit();
                  renderDebug();
                }

                if (!mode3d){
                  applyViewTweak();
                  const v = getView();
                  lastBBox = calcBBoxFromPts(out.pts, v);
                  applyAutoFit();
                  renderDebug();
                }

                const price = calc();
                jmEl.textContent = price.jmNoVat.toFixed(2) + ' €';
                matEl.textContent = price.matNoVat.toFixed(2) + ' €';
                novatEl.textContent = price.totalNoVat.toFixed(2) + ' €';
                vatEl.textContent = price.totalVat.toFixed(2) + ' €';

                tbMat.textContent = currentMaterialLabel() || '—';
                tbLen.textContent = String(clamp(lenEl.value, 50, 8000)) + ' mm';
                tbQty.textContent = String(clamp(qtyEl.value, 1, 999));
                tbSum.textContent = String(price.sumSmm) + ' mm';
              }

              inputsWrap.addEventListener('input', (e)=>{
                const el = e.target;
                if (!el || !el.dataset || !el.dataset.key) return;
                const key = el.dataset.key;

                const meta = cfg.dims.find(x=>x.key===key);
                if (!meta) return;

                const min = (meta.min ?? (meta.type==='angle'?5:10));
                const max = (meta.max ?? (meta.type==='angle'?215:500));
                stateVal[key] = clamp(el.value, min, max);

                render();
              });

              matSel.addEventListener('change', render);
              lenEl.addEventListener('input', render);
              qtyEl.addEventListener('input', render);

              if (openBtn) openBtn.addEventListener('click', function(){
                render();
                fillWpforms();
                // in library mode: WPForms should be on the page already (user places it)
                // in single mode: it might be below (if you insert it separately) — we just scroll to nearest form on page.
                const anyForm = document.querySelector('.wpforms-container');
                if (anyForm) anyForm.scrollIntoView({behavior:'smooth', block:'start'});
              });

              function setModeDefault(){
                if (cfg.mode === 'library') setMode3d(false);
                else setMode3d(false);
              }

              renderDimInputs();
              renderMaterials();
              setTitleBlock();
              setModeDefault();
              render();
            }

            const root = document.getElementById('<?php echo esc_js($uid); ?>');
            if (!root) return;
            const cfg = JSON.parse(root.dataset.spb || '{}');

            // init
            run(root, cfg, false);
          })();
        </script>
      </div>
    <?php
    return ob_get_clean();
  }
}

new Steel_Profile_Builder();
