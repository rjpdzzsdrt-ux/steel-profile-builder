<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Profiilikalkulaator (SVG joonis + mõõtjooned + nurkade suund/poolsus) + administ muudetav mõõtude süsteem + hinnastus + WPForms.
 * Version: 0.4.2
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.4.2';

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_action('save_post', [$this, 'save_meta'], 10, 2);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
    add_shortcode('steel_profile_builder', [$this, 'shortcode']);
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

    wp_enqueue_script(
      'spb-admin',
      plugins_url('assets/admin.js', __FILE__),
      [],
      self::VER,
      true
    );
  }

  public function add_meta_boxes() {
    add_meta_box('spb_preview', 'Joonise eelvaade', [$this, 'mb_preview'], self::CPT, 'normal', 'high');
    add_meta_box('spb_dims', 'Mõõdud', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_pricing', 'Hinnastus (m² + JM + KM)', [$this, 'mb_pricing'], self::CPT, 'side', 'default');
    add_meta_box('spb_wpforms', 'WPForms', [$this, 'mb_wpforms'], self::CPT, 'side', 'default');
  }

  private function get_meta($post_id) {
    return [
      'dims'    => get_post_meta($post_id, '_spb_dims', true),
      'pattern' => get_post_meta($post_id, '_spb_pattern', true),
      'pricing' => get_post_meta($post_id, '_spb_pricing', true),
      'wpforms' => get_post_meta($post_id, '_spb_wpforms', true),
    ];
  }

  private function default_dims() {
    return [
      ['key'=>'s1','type'=>'length','label'=>'s1','min'=>10,'max'=>500,'def'=>15,'dir'=>'L'],
      ['key'=>'a1','type'=>'angle','label'=>'a1','min'=>5,'max'=>215,'def'=>135,'dir'=>'L','pol'=>'inner'],
      ['key'=>'s2','type'=>'length','label'=>'s2','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
      ['key'=>'a2','type'=>'angle','label'=>'a2','min'=>5,'max'=>215,'def'=>135,'dir'=>'L','pol'=>'inner'],
      ['key'=>'s3','type'=>'length','label'=>'s3','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
      ['key'=>'a3','type'=>'angle','label'=>'a3','min'=>5,'max'=>215,'def'=>135,'dir'=>'R','pol'=>'inner'],
      ['key'=>'s4','type'=>'length','label'=>'s4','min'=>10,'max'=>500,'def'=>15,'dir'=>'L'],
    ];
  }

  private function default_pricing() {
    return [
      'vat' => 24,
      // JM hind = (jm_work_eur_jm + sumS_m * jm_per_m_eur_jm) * detailLength_m * qty
      'jm_work_eur_jm' => 0.00,      // töö €/jm
      'jm_per_m_eur_jm' => 0.00,     // lisakomponent €/jm iga Σs meetri kohta
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

  /* ===========================
   *  BACKEND PREVIEW (SVG)
   * =========================== */
  public function mb_preview($post) {
    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];

    $cfg = ['dims'=>$dims,'pattern'=>$pattern];
    $uid = 'spb_admin_prev_' . $post->ID . '_' . wp_generate_uuid4();
    $arrowId = 'spbAdminArrow_' . $uid;
    ?>
    <div id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
      <div style="border:1px solid #e5e5e5;border-radius:12px;padding:10px;background:#fafafa">
        <svg viewBox="0 0 820 460" width="100%" height="360" style="display:block;border-radius:10px;background:#fff;border:1px solid #eee">
          <defs>
            <marker id="<?php echo esc_attr($arrowId); ?>" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
              <path d="M 0 0 L 10 5 L 0 10 z" fill="#111"></path>
            </marker>
          </defs>
          <polyline class="spb-line" fill="none" points="120,360 120,120 520,120 640,210" style="stroke:#111;stroke-width:3"></polyline>
          <g class="spb-dimlayer"></g>
        </svg>
        <div style="font-size:12px;opacity:.7;margin-top:8px">
          Eelvaade uueneb, kui muudad mõõte või patternit.
        </div>
      </div>
    </div>

    <script>
      (function(){
        const root = document.getElementById('<?php echo esc_js($uid); ?>');
        if (!root) return;
        const cfg0 = JSON.parse(root.dataset.spb || '{}');

        const svg = root.querySelector('svg');
        const poly = root.querySelector('.spb-line');
        const dimLayer = root.querySelector('.spb-dimlayer');
        const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

        function toNum(v, f){ const n = Number(v); return Number.isFinite(n) ? n : f; }
        function clamp(n, min, max){ n = toNum(n, min); return Math.max(min, Math.min(max, n)); }
        function deg2rad(d){ return d * Math.PI / 180; }
        function turnFromAngle(aDeg, pol){
          const a = Number(aDeg || 0);
          return (pol === 'outer') ? a : (180 - a);
        }

        function svgEl(tag){ return document.createElementNS('http://www.w3.org/2000/svg', tag); }
        function addLine(g, x1,y1,x2,y2, w, op, arrows){
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
        function computePolyline(dims, pattern, dimMap, state){
          let x = 140, y = 360;
          let heading = -90;
          const pts = [[x,y]];

          const segKeys = pattern.filter(k => dimMap[k] && dimMap[k].type === 'length');
          const totalMm = segKeys.reduce((sum,k)=> sum + Number(state[k] || 0), 0);
          const k = totalMm > 0 ? (520 / totalMm) : 1;

          for (const key of pattern) {
            const meta = dimMap[key];
            if (!meta) continue;

            if (meta.type === 'length') {
              const mm = Number(state[key] || 0);
              const dx = Math.cos(deg2rad(heading)) * (mm * k);
              const dy = Math.sin(deg2rad(heading)) * (mm * k);
              x += dx; y += dy;
              pts.push([x,y]);
            } else {
              const pol = (meta.pol === 'outer') ? 'outer' : 'inner';
              const dir = (meta.dir === 'R') ? -1 : 1;
              const turn = turnFromAngle(state[key], pol);
              heading += dir * turn;
            }
          }

          const pad = 70;
          const xs = pts.map(p=>p[0]), ys = pts.map(p=>p[1]);
          const minX = Math.min(...xs), maxX = Math.max(...xs);
          const minY = Math.min(...ys), maxY = Math.max(...ys);
          const w = (maxX - minX) || 1;
          const h = (maxY - minY) || 1;
          const scale = Math.min((800 - 2*pad)/w, (420 - 2*pad)/h);

          return pts.map(([px,py])=>[
            (px - minX) * scale + pad,
            (py - minY) * scale + pad
          ]);
        }

        function drawDimension(g, A, B, label, offsetPx){
          const v = sub(B,A);
          const vHat = norm(v);
          const nHat = norm(perp(vHat));
          const off = mul(nHat, offsetPx);

          const A2 = add(A, off);
          const B2 = add(B, off);

          addLine(g, A.x, A.y, A2.x, A2.y, 1, .35, false);
          addLine(g, B.x, B.y, B2.x, B2.y, 1, .35, false);

          addLine(g, A2.x, A2.y, B2.x, B2.y, 1.4, 1, true);

          const mid = mul(add(A2,B2), 0.5);
          let ang = Math.atan2(vHat.y, vHat.x) * 180 / Math.PI;
          if (ang > 90) ang -= 180;
          if (ang < -90) ang += 180;

          addText(g, mid.x, mid.y - 6, label, ang);
        }

        function renderDims(dimMap, pattern, pts, state){
          dimLayer.innerHTML = '';
          const OFFSET = 22;
          let segIndex = 0;
          for (const key of pattern) {
            const meta = dimMap[key];
            if (!meta) continue;
            if (meta.type === 'length') {
              const pA = pts[segIndex];
              const pB = pts[segIndex + 1];
              if (pA && pB) {
                const A = vec(pA[0], pA[1]);
                const B = vec(pB[0], pB[1]);
                drawDimension(dimLayer, A, B, `${key} ${state[key]}mm`, OFFSET);
              }
              segIndex += 1;
            }
          }
        }

        function update(){
          const dims = getDims();
          const pattern = getPattern();
          const dimMap = buildDimMap(dims);
          const state = buildState(dims);

          const pts = computePolyline(dims, pattern, dimMap, state);
          poly.setAttribute('points', pts.map(p=>p.join(',')).join(' '));
          renderDims(dimMap, pattern, pts, state);
        }

        document.addEventListener('input', (e)=>{
          const t = e.target;
          if (!t) return;
          if (t.closest && t.closest('#spb-dims-table')) return update();
          if (t.id === 'spb-pattern-textarea' || t.name === 'spb_pattern_json') return update();
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
      <strong>s*</strong> = sirglõik (mm), <strong>a*</strong> = nurk (°). Suund: <strong>L/R</strong>. Nurk: <strong>Seest/Väljast</strong>.
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
    <p style="margin-top:0;opacity:.8">Pane WPForms vormi ID ja väljafield ID-d, kuhu kalkulaator kirjutab väärtused.</p>
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

      $dims_out[] = [
        'key' => $key,
        'type' => $type,
        'label' => sanitize_text_field($d['label'] ?? $key),
        'min' => isset($d['min']) && $d['min'] !== '' ? floatval($d['min']) : null,
        'max' => isset($d['max']) && $d['max'] !== '' ? floatval($d['max']) : null,
        'def' => isset($d['def']) && $d['def'] !== '' ? floatval($d['def']) : null,
        'dir' => $dir,
        'pol' => ($type === 'angle') ? $pol : null,
      ];
    }
    update_post_meta($post_id, '_spb_dims', $dims_out);

    // pattern
    $pattern_json = wp_unslash($_POST['spb_pattern_json'] ?? '[]');
    $pattern = json_decode($pattern_json, true);
    if (!is_array($pattern)) $pattern = [];
    $pattern = array_values(array_map('sanitize_key', $pattern));
    update_post_meta($post_id, '_spb_pattern', $pattern);

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
    $atts = shortcode_atts(['id' => 0], $atts);
    $id = intval($atts['id']);
    if (!$id) return '<div>Steel Profile Builder: puudub id</div>';

    $post = get_post($id);
    if (!$post || $post->post_type !== self::CPT) return '<div>Steel Profile Builder: vale id</div>';

    $m = $this->get_meta($id);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];

    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $wp = (is_array($m['wpforms']) && $m['wpforms']) ? $m['wpforms'] : [];
    $wp = array_merge($this->default_wpforms(), $wp);

    $cfg = [
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
    ];

    $uid = 'spb_front_' . $id . '_' . wp_generate_uuid4();
    $arrowId = 'spbArrow_' . $uid;

    ob_start(); ?>
      <div class="spb-front" id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <div class="spb-card">
          <div class="spb-title"><?php echo esc_html(get_the_title($id)); ?></div>

          <div class="spb-error" style="display:none"></div>

          <div class="spb-grid">
            <div class="spb-left">
              <div class="spb-box">
                <div class="spb-box-title">Joonis</div>
                <div class="spb-drawing">
                  <svg class="spb-svg" viewBox="0 0 820 460" width="100%" height="340">
                    <defs>
                      <marker id="<?php echo esc_attr($arrowId); ?>" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M 0 0 L 10 5 L 0 10 z"></path>
                      </marker>
                    </defs>
                    <polyline class="spb-line" fill="none" points="120,360 120,120 520,120 640,210"></polyline>
                    <g class="spb-dimlayer"></g>
                  </svg>
                </div>
              </div>

              <div class="spb-box">
                <div class="spb-box-title">Mõõdud</div>
                <div class="spb-inputs"></div>
              </div>
            </div>

            <div class="spb-right">
              <div class="spb-box">
                <div class="spb-box-title">Tellimus</div>

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

                <div class="spb-results">
                  <div class="spb-r">
                    <span>JM hind (ilma KM)</span><strong class="spb-price-jm">—</strong>
                  </div>
                  <div class="spb-r">
                    <span>Materjali hind (ilma KM)</span><strong class="spb-price-mat">—</strong>
                  </div>
                  <div class="spb-r spb-t">
                    <span>Kokku (ilma KM)</span><strong class="spb-price-novat">—</strong>
                  </div>
                  <div class="spb-r spb-t">
                    <span>Kokku (koos KM)</span><strong class="spb-price-vat">—</strong>
                  </div>
                </div>

                <button type="button" class="spb-btn spb-open-form">Küsi personaalset hinnapakkumist</button>
              </div>

              <?php if (!empty($cfg['wpforms']['form_id'])): ?>
                <div class="spb-form-wrap" style="display:none">
                  <?php echo do_shortcode('[wpforms id="'.intval($cfg['wpforms']['form_id']).'" title="false" description="false"]'); ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <style>
          .spb-front .spb-card{border:1px solid #e5e5e5;border-radius:14px;padding:16px;background:#fff}
          .spb-front .spb-title{font-size:20px;font-weight:800;margin-bottom:12px}
          .spb-front .spb-grid{display:grid;grid-template-columns:1.25fr 1fr;gap:18px;align-items:start}
          .spb-front .spb-left{display:flex;flex-direction:column;gap:18px}
          .spb-front .spb-right{display:flex;flex-direction:column;gap:18px}
          .spb-front .spb-box{border:1px solid #eee;border-radius:12px;padding:14px}
          .spb-front .spb-box-title{font-weight:700;margin-bottom:10px}
          .spb-front .spb-drawing{border:1px solid #eee;border-radius:12px;padding:10px;background:#fafafa}
          .spb-front .spb-svg{display:block;border-radius:10px;background:#fff;border:1px solid #eee}
          .spb-front .spb-line{stroke:#111;stroke-width:3}
          .spb-front .spb-dimlayer text{font-size:13px;fill:#111;dominant-baseline:middle;text-anchor:middle}
          .spb-front .spb-dimlayer line{stroke:#111}
          .spb-front .spb-inputs{display:grid;grid-template-columns:1fr 170px;gap:10px;align-items:center}
          .spb-front .spb-angle-controls{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:-4px}
          .spb-front .spb-note{grid-column:1/-1;font-size:12px;opacity:.65;margin-top:-6px;margin-bottom:6px}
          .spb-front input,.spb-front select{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px}
          .spb-front .spb-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:center;margin-bottom:10px}
          .spb-front .spb-results{margin-top:12px;border-top:1px solid #eee;padding-top:12px}
          .spb-front .spb-r{display:flex;justify-content:space-between;gap:12px;margin:8px 0}
          .spb-front .spb-r strong{font-size:16px}
          .spb-front .spb-t strong{font-size:18px}
          .spb-front .spb-btn{width:100%;padding:12px 14px;border-radius:12px;border:0;cursor:pointer;font-weight:800;background:#111;color:#fff}
          .spb-front .spb-error{margin:10px 0;padding:10px;border:1px solid #ffb4b4;background:#fff1f1;border-radius:10px}
          @media (max-width: 900px){ .spb-front .spb-grid{grid-template-columns:1fr} }
        </style>

        <script>
          (function(){
            const root = document.getElementById('<?php echo esc_js($uid); ?>');
            if (!root) return;
            const cfg = JSON.parse(root.dataset.spb || '{}');

            const err = root.querySelector('.spb-error');
            function showErr(msg){ err.style.display='block'; err.textContent=msg; }

            if (!cfg.dims || !cfg.dims.length) {
              showErr('Sellel profiilil pole mõõte. Ava profiil adminis ja salvesta uuesti.');
              return;
            }

            const inputsWrap = root.querySelector('.spb-inputs');
            const matSel = root.querySelector('.spb-material');
            const lenEl = root.querySelector('.spb-length');
            const qtyEl = root.querySelector('.spb-qty');

            const jmEl = root.querySelector('.spb-price-jm');
            const matEl = root.querySelector('.spb-price-mat');
            const novatEl = root.querySelector('.spb-price-novat');
            const vatEl = root.querySelector('.spb-price-vat');

            const poly = root.querySelector('.spb-line');
            const dimLayer = root.querySelector('.spb-dimlayer');
            const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

            const formWrap = root.querySelector('.spb-form-wrap');
            const openBtn = root.querySelector('.spb-open-form');

            const stateVal = {};
            const stateMeta = {}; // angle dir/pol (frontendi kontroll)

            function toNum(v,f){ const n = Number(v); return Number.isFinite(n)?n:f; }
            function clamp(n,min,max){ n = toNum(n,min); return Math.max(min, Math.min(max,n)); }
            function deg2rad(d){ return d * Math.PI / 180; }
            function turnFromAngle(aDeg, pol){ const a=Number(aDeg||0); return (pol==='outer')?a:(180-a); }

            function svgEl(tag){ return document.createElementNS('http://www.w3.org/2000/svg', tag); }
            function addLine(g,x1,y1,x2,y2,w,op,arrows){
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
                opt.textContent = (m.label || m.key); // ei näita €/m²
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

                if (d.type === 'angle') {
                  stateMeta[d.key] = stateMeta[d.key] || {
                    dir: (d.dir === 'R') ? 'R' : 'L',
                    pol: (d.pol === 'outer') ? 'outer' : 'inner'
                  };
                }

                const lab = document.createElement('label');
                lab.textContent = (d.label || d.key) + (d.type==='angle' ? ' (°)' : ' (mm)');
                const inp = document.createElement('input');
                inp.type='number';
                inp.value = stateVal[d.key];
                inp.min = min;
                inp.max = max;
                inp.dataset.key = d.key;
                inp.dataset.kind = 'value';

                inputsWrap.appendChild(lab);
                inputsWrap.appendChild(inp);

                if (d.type === 'angle') {
                  const wrap = document.createElement('div');
                  wrap.className='spb-angle-controls';

                  const selDir = document.createElement('select');
                  selDir.dataset.key = d.key;
                  selDir.dataset.kind = 'dir';
                  selDir.innerHTML = '<option value="L">Suund: L</option><option value="R">Suund: R</option>';
                  selDir.value = stateMeta[d.key].dir;

                  const selPol = document.createElement('select');
                  selPol.dataset.key = d.key;
                  selPol.dataset.kind = 'pol';
                  selPol.innerHTML = '<option value="inner">Nurk: Seest</option><option value="outer">Nurk: Väljast</option>';
                  selPol.value = stateMeta[d.key].pol;

                  wrap.appendChild(selDir);
                  wrap.appendChild(selPol);
                  inputsWrap.appendChild(wrap);

                  const note = document.createElement('div');
                  note.className='spb-note';
                  note.textContent='Nurk mõjutab joonist, mitte hinda.';
                  inputsWrap.appendChild(note);
                }
              });
            }

            function computePolyline(dimMap){
              let x=140, y=360;
              let heading=-90;
              const pts=[[x,y]];
              const pattern = Array.isArray(cfg.pattern) ? cfg.pattern : [];

              const segKeys = pattern.filter(k => dimMap[k] && dimMap[k].type==='length');
              const totalMm = segKeys.reduce((s,k)=> s + Number(stateVal[k]||0), 0);
              const kScale = totalMm > 0 ? (520/totalMm) : 1;

              for (const key of pattern) {
                const meta = dimMap[key];
                if (!meta) continue;

                if (meta.type==='length') {
                  const mm = Number(stateVal[key]||0);
                  x += Math.cos(deg2rad(heading)) * (mm*kScale);
                  y += Math.sin(deg2rad(heading)) * (mm*kScale);
                  pts.push([x,y]);
                } else {
                  const a = Number(stateVal[key]||0);
                  const dyn = stateMeta[key] || {};
                  const pol = (dyn.pol === 'outer') ? 'outer' : ((meta.pol==='outer')?'outer':'inner');
                  const dir = (dyn.dir === 'R') ? 'R' : ((meta.dir==='R')?'R':'L');
                  const turn = turnFromAngle(a, pol);
                  heading += (dir==='R' ? -1 : 1) * turn;
                }
              }

              const pad=70;
              const xs = pts.map(p=>p[0]), ys=pts.map(p=>p[1]);
              const minX=Math.min(...xs), maxX=Math.max(...xs);
              const minY=Math.min(...ys), maxY=Math.max(...ys);
              const w=(maxX-minX)||1, h=(maxY-minY)||1;
              const s = Math.min((800-2*pad)/w, (420-2*pad)/h);

              return pts.map(([px,py])=>[(px-minX)*s+pad, (py-minY)*s+pad]);
            }

            function drawDimension(A,B,label,offsetPx){
              const v = sub(B,A);
              const vHat = norm(v);
              const nHat = norm(perp(vHat));
              const off = mul(nHat, offsetPx);

              const A2 = add(A, off);
              const B2 = add(B, off);

              addLine(dimLayer, A.x, A.y, A2.x, A2.y, 1, .35, false);
              addLine(dimLayer, B.x, B.y, B2.x, B2.y, 1, .35, false);
              addLine(dimLayer, A2.x, A2.y, B2.x, B2.y, 1.4, 1, true);

              const mid = mul(add(A2,B2), 0.5);
              let ang = Math.atan2(vHat.y, vHat.x) * 180 / Math.PI;
              if (ang > 90) ang -= 180;
              if (ang < -90) ang += 180;

              addText(dimLayer, mid.x, mid.y - 6, label, ang);
            }

            function renderDims(dimMap, pts){
              dimLayer.innerHTML='';
              const pattern = Array.isArray(cfg.pattern) ? cfg.pattern : [];
              const OFFSET=22;
              let segIndex=0;
              for (const key of pattern) {
                const meta = dimMap[key];
                if (!meta) continue;
                if (meta.type==='length') {
                  const pA = pts[segIndex], pB = pts[segIndex+1];
                  if (pA && pB) drawDimension(vec(pA[0],pA[1]), vec(pB[0],pB[1]), `${key} ${stateVal[key]}mm`, OFFSET);
                  segIndex += 1;
                }
              }
            }

            function calc(){
              // Σ s (mm) ainult length
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
              const jmRate = jmWork + (sumSm * jmPerM);         // €/jm
              const jmNoVat = (Pm * jmRate) * qty;              // €/detail * qty

              const totalNoVat = matNoVat + jmNoVat;
              const vatPct = toNum(cfg.vat, 24);
              const totalVat = totalNoVat * (1 + vatPct/100);

              return { sumSmm, area, qty, matNoVat, jmNoVat, totalNoVat, totalVat, vatPct };
            }

            function dimsPayloadJSON(){
              return JSON.stringify(cfg.dims.map(d=>{
                const o = { key:d.key, type:d.type, label:(d.label||d.key), value:stateVal[d.key] };
                if (d.type==='angle') {
                  const dyn = stateMeta[d.key] || {};
                  o.dir = dyn.dir || d.dir || 'L';
                  o.pol = dyn.pol || d.pol || 'inner';
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

            function render(){
              const dimMap = buildDimMap();
              const pts = computePolyline(dimMap);
              poly.setAttribute('points', pts.map(p=>p.join(',')).join(' '));
              renderDims(dimMap, pts);

              const out = calc();
              jmEl.textContent = out.jmNoVat.toFixed(2) + ' €';
              matEl.textContent = out.matNoVat.toFixed(2) + ' €';
              novatEl.textContent = out.totalNoVat.toFixed(2) + ' €';
              vatEl.textContent = out.totalVat.toFixed(2) + ' €';
            }

            inputsWrap.addEventListener('input', (e)=>{
              const el = e.target;
              if (!el || !el.dataset || !el.dataset.key) return;
              const key = el.dataset.key;
              const kind = el.dataset.kind;

              if (kind === 'value') {
                const meta = cfg.dims.find(x=>x.key===key);
                if (!meta) return;
                const min = (meta.min ?? (meta.type==='angle'?5:10));
                const max = (meta.max ?? (meta.type==='angle'?215:500));
                stateVal[key] = clamp(el.value, min, max);
              } else if (kind === 'dir') {
                stateMeta[key] = stateMeta[key] || {};
                stateMeta[key].dir = (el.value === 'R') ? 'R' : 'L';
              } else if (kind === 'pol') {
                stateMeta[key] = stateMeta[key] || {};
                stateMeta[key].pol = (el.value === 'outer') ? 'outer' : 'inner';
              }
              render();
            });

            matSel.addEventListener('change', render);
            lenEl.addEventListener('input', render);
            qtyEl.addEventListener('input', render);

            if (openBtn) openBtn.addEventListener('click', function(){
              render();
              if (formWrap) {
                fillWpforms();
                formWrap.style.display='block';
                formWrap.scrollIntoView({behavior:'smooth', block:'start'});
              }
            });

            renderDimInputs();
            renderMaterials();
            render();

            // Kui mingi cache/JS error, siis vähemalt ütleb
            window.setTimeout(function(){
              if (!inputsWrap.children.length) showErr('Mõõtude sisestus ei initsialiseerunud. Tee hard refresh (Cmd+Shift+R).');
            }, 700);
          })();
        </script>
      </div>
    <?php
    return ob_get_clean();
  }
}

new Steel_Profile_Builder();
