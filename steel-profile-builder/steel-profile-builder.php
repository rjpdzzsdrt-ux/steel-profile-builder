<?php
/**
 * Plugin Name: Steel Profile Builder
 * Plugin URI: https://steel.ee
 * Description: Administ muudetav plekiprofiilide süsteem + frontend kalkulaator SVG joonise ja mõõtjoontega (m² hinnastus).
 * Version: 0.3.0
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.3.0';

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
    add_meta_box('spb_dims', 'Mõõdud', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
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

  private function default_dims() {
    return [
      ['key'=>'s1','type'=>'length','label'=>'s1','min'=>10,'max'=>500,'def'=>15,'dir'=>'L'],
      ['key'=>'a1','type'=>'angle','label'=>'a1','min'=>45,'max'=>215,'def'=>135,'dir'=>'L'],
      ['key'=>'s2','type'=>'length','label'=>'s2','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
      ['key'=>'a2','type'=>'angle','label'=>'a2','min'=>45,'max'=>215,'def'=>135,'dir'=>'L'],
      ['key'=>'s3','type'=>'length','label'=>'s3','min'=>10,'max'=>500,'def'=>100,'dir'=>'L'],
      ['key'=>'a3','type'=>'angle','label'=>'a3','min'=>45,'max'=>215,'def'=>135,'dir'=>'R'],
      ['key'=>'s4','type'=>'length','label'=>'s4','min'=>10,'max'=>500,'def'=>15,'dir'=>'L'],
    ];
  }

  private function default_pricing() {
    return [
      'vat' => 24,
      'materials' => [
        ['key'=>'POL','label'=>'POL','eur_m2'=>7.5],
        ['key'=>'PUR','label'=>'PUR','eur_m2'=>8.5],
        ['key'=>'PUR_MATT','label'=>'PUR Matt','eur_m2'=>11.5],
        ['key'=>'TSINK','label'=>'Tsink','eur_m2'=>6.5],
      ]
    ];
  }

  public function mb_dims($post) {
    wp_nonce_field('spb_save', 'spb_nonce');

    $m = $this->get_meta($post->ID);
    $dims = is_array($m['dims']) ? $m['dims'] : [];
    if (!$dims) $dims = $this->default_dims();
    ?>
    <p style="margin-top:0;opacity:.8">
      Lisa mõõdud tabelina. <strong>s*</strong> = pikkus (mm), <strong>a*</strong> = sisemine nurk (°).
      Nurgal vali suund: <strong>L</strong>=vasak, <strong>R</strong>=parem (tagasipöörded).
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
          <th style="width:70px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <p><button type="button" class="button" id="spb-add-dim">+ Lisa mõõt</button></p>

    <input type="hidden" id="spb_dims_json" name="spb_dims_json"
           value="<?php echo esc_attr(wp_json_encode($dims)); ?>">
    <?php
  }

  public function mb_pattern($post) {
    $m = $this->get_meta($post->ID);
    $pattern = is_array($m['pattern']) ? $m['pattern'] : [];
    if (!$pattern) $pattern = ["s1","a1","s2","a2","s3","a3","s4"];
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
    $pricing = array_merge($this->default_pricing(), $pricing);

    $vat = isset($pricing['vat']) ? floatval($pricing['vat']) : 24;
    $materials = is_array($pricing['materials']) ? $pricing['materials'] : $this->default_pricing()['materials'];
    ?>
    <p style="margin-top:0;opacity:.8">
      Hinnastus (V1): Σ s_mm (ainult pikkused) → L_m = Σ/1000. Pikkus eraldi → P_m = pikkus_mm/1000.<br>
      A_m2 = L_m * P_m. Hind = A_m2 * €/m² * kogus. Näitame ilma KM ja KM-ga.
    </p>

    <p><label>KM %<br>
      <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($vat); ?>" style="width:100%;">
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

      $dims_out[] = [
        'key' => $key,
        'type' => $type,
        'label' => sanitize_text_field($d['label'] ?? $key),
        'min' => isset($d['min']) && $d['min'] !== '' ? floatval($d['min']) : null,
        'max' => isset($d['max']) && $d['max'] !== '' ? floatval($d['max']) : null,
        'def' => isset($d['def']) && $d['def'] !== '' ? floatval($d['def']) : null,
        'dir' => $dir,
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
    $vat = floatval($_POST['spb_vat'] ?? 24);

    $materials_json = wp_unslash($_POST['spb_materials_json'] ?? '[]');
    $materials = json_decode($materials_json, true);
    if (!is_array($materials)) $materials = [];

    $materials_out = [];
    foreach ($materials as $m) {
      $k = sanitize_key($m['key'] ?? '');
      if (!$k) continue;
      $materials_out[] = [
        'key' => $k,
        'label' => sanitize_text_field($m['label'] ?? $k),
        'eur_m2' => floatval($m['eur_m2'] ?? 0),
      ];
    }

    update_post_meta($post_id, '_spb_pricing', [
      'vat' => $vat,
      'materials' => $materials_out,
    ]);
  }

  public function shortcode($atts) {
    $atts = shortcode_atts([
      'id' => 0,

      // Optional WPForms mapping (kui tahad kohe täita hidden fieldid)
      'form_id' => '',
      'field_profile' => '',
      'field_inputs' => '',
      'field_length' => '',
      'field_qty' => '',
      'field_area' => '',
      'field_price_novat' => '',
      'field_price_vat' => '',
    ], $atts);

    $id = intval($atts['id']);
    if (!$id) return '<div>Steel Profile Builder: puudub id</div>';

    $post = get_post($id);
    if (!$post || $post->post_type !== self::CPT) return '<div>Steel Profile Builder: vale id</div>';

    $m = $this->get_meta($id);

    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];
    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : $this->default_pricing();

    $vat = isset($pricing['vat']) ? floatval($pricing['vat']) : 24;
    $materials = is_array($pricing['materials']) ? $pricing['materials'] : $this->default_pricing()['materials'];

    $cfg = [
      'profileId' => $id,
      'profileName' => get_the_title($id),
      'dims' => $dims,
      'pattern' => $pattern,
      'vat' => $vat,
      'materials' => $materials,
      'wpforms' => [
        'form_id' => trim((string)$atts['form_id']),
        'fields' => [
          'profile' => trim((string)$atts['field_profile']),
          'inputs' => trim((string)$atts['field_inputs']),
          'length' => trim((string)$atts['field_length']),
          'qty' => trim((string)$atts['field_qty']),
          'area' => trim((string)$atts['field_area']),
          'price_novat' => trim((string)$atts['field_price_novat']),
          'price_vat' => trim((string)$atts['field_price_vat']),
        ]
      ]
    ];

    $uid = 'spb_' . $id . '_' . wp_generate_uuid4();
    $arrowId = 'spbArrow_' . $uid;

    ob_start(); ?>
      <div class="spb-front" id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <div class="spb-card">
          <div class="spb-head">
            <div class="spb-title"><?php echo esc_html(get_the_title($id)); ?></div>
            <div class="spb-sub">Arvutus: A(m²) = (Σ s_mm / 1000) × (pikkus_mm / 1000). Hind = A × €/m² × kogus.</div>
          </div>

          <div class="spb-grid">
            <div class="spb-left">
              <div class="spb-section">
                <div class="spb-section-title">Joonis</div>
                <div class="spb-drawing">
                  <svg class="spb-svg" viewBox="0 0 820 460" width="100%" height="340" aria-label="Profiili joonis">
                    <defs>
                      <marker id="<?php echo esc_attr($arrowId); ?>" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                        <path d="M 0 0 L 10 5 L 0 10 z"></path>
                      </marker>
                    </defs>

                    <polyline class="spb-line" fill="none" points="120,360 120,120 520,120 640,210" />
                    <g class="spb-dimlayer"></g>
                  </svg>
                  <div class="spb-drawhint">Mõõtude muutmisel uuenevad kuju ja mõõtjooned.</div>
                </div>
              </div>

              <div class="spb-section">
                <div class="spb-section-title">Mõõdud</div>
                <div class="spb-inputs"></div>
              </div>
            </div>

            <div class="spb-right">
              <div class="spb-section">
                <div class="spb-section-title">Tellimus</div>

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
                  <div class="spb-line-row">
                    <span>Pindala (m²)</span>
                    <strong class="spb-area">—</strong>
                  </div>
                  <div class="spb-line-row">
                    <span>Hind ilma KM</span>
                    <strong class="spb-price-novat">—</strong>
                  </div>
                  <div class="spb-line-row">
                    <span>Hind koos KM (<?php echo esc_html($vat); ?>%)</span>
                    <strong class="spb-price-vat">—</strong>
                  </div>
                </div>

                <div class="spb-small">
                  * Nurgad mõjutavad ainult joonist, mitte hinnakalkulatsiooni.
                </div>
              </div>
            </div>
          </div>
        </div>

        <style>
          .spb-front .spb-card{border:1px solid #e5e5e5;border-radius:14px;padding:16px;background:#fff}
          .spb-front .spb-head{margin-bottom:14px}
          .spb-front .spb-title{font-size:20px;font-weight:800;line-height:1.15}
          .spb-front .spb-sub{font-size:13px;opacity:.75;margin-top:6px}

          .spb-front .spb-grid{display:grid;grid-template-columns:1.25fr 1fr;gap:18px;align-items:start}
          .spb-front .spb-left{display:flex;flex-direction:column;gap:18px}
          .spb-front .spb-right{display:flex;flex-direction:column;gap:18px}

          .spb-front .spb-section{border:1px solid #eee;border-radius:12px;padding:14px}
          .spb-front .spb-section-title{font-weight:700;margin-bottom:10px}

          .spb-front .spb-drawing{border:1px solid #eee;border-radius:12px;padding:10px;background:#fafafa}
          .spb-front .spb-svg{display:block;border-radius:10px;background:#fff;border:1px solid #eee}
          .spb-front .spb-line{stroke:#111;stroke-width:3}

          .spb-front .spb-drawhint{font-size:12px;opacity:.65;margin-top:8px}

          .spb-front .spb-inputs{display:grid;grid-template-columns:1fr 170px;gap:10px;align-items:center}
          .spb-front .spb-note{grid-column:1/-1;font-size:12px;opacity:.65;margin-top:-6px;margin-bottom:6px}

          .spb-front .spb-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:center;margin-bottom:10px}
          .spb-front label{font-size:14px;opacity:.9}
          .spb-front input,.spb-front select{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px}

          .spb-front .spb-results{margin-top:12px;border-top:1px solid #eee;padding-top:12px}
          .spb-front .spb-line-row{display:flex;justify-content:space-between;gap:12px;margin:6px 0}
          .spb-front .spb-line-row strong{font-size:16px}

          .spb-front .spb-small{font-size:12px;opacity:.7;margin-top:10px}

          /* dimension styling */
          .spb-front .spb-dimlayer .spb-ext{stroke:#111;stroke-width:1;opacity:.35}
          .spb-front .spb-dimlayer .spb-dim{stroke:#111;stroke-width:1.4}
          .spb-front .spb-dimlayer .spb-dimtext{font-size:13px;fill:#111;dominant-baseline:middle;text-anchor:middle}
          .spb-front .spb-dimlayer .spb-anglelist{font-size:12px;fill:#111;opacity:.6}

          @media (max-width: 900px){
            .spb-front .spb-grid{grid-template-columns:1fr}
          }
        </style>

        <script>
          (function(){
            const root = document.getElementById('<?php echo esc_js($uid); ?>');
            if (!root) return;

            const cfg = JSON.parse(root.dataset.spb || '{}');

            const inputsWrap = root.querySelector('.spb-inputs');
            const matSel = root.querySelector('.spb-material');
            const lenEl = root.querySelector('.spb-length');
            const qtyEl = root.querySelector('.spb-qty');

            const areaEl = root.querySelector('.spb-area');
            const novatEl = root.querySelector('.spb-price-novat');
            const vatEl = root.querySelector('.spb-price-vat');

            const poly = root.querySelector('.spb-line');
            const dimLayer = root.querySelector('.spb-dimlayer');

            const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

            const state = {};

            function toNum(v, fallback){
              const n = Number(v);
              return Number.isFinite(n) ? n : fallback;
            }
            function clamp(n, min, max){
              n = toNum(n, min);
              return Math.max(min, Math.min(max, n));
            }
            function deg2rad(d){ return d * Math.PI / 180; }
            function getTurnFromInner(innerDeg){ return 180 - innerDeg; }

            // WPForms optional fill
            function wpformsSet(formId, fieldId, val){
              if (!formId || !fieldId) return;
              const sel = `#wpforms-form-${formId} input[name="wpforms[fields][${fieldId}]"], #wpforms-form-${formId} textarea[name="wpforms[fields][${fieldId}]"]`;
              const el = document.querySelector(sel);
              if (!el) return;
              el.value = String(val);
              el.dispatchEvent(new Event('input', {bubbles:true}));
              el.dispatchEvent(new Event('change', {bubbles:true}));
            }

            function buildDimMap(){
              const map = {};
              (cfg.dims || []).forEach(d => { if (d && d.key) map[d.key] = d; });
              return map;
            }

            function svgEl(tag){
              return document.createElementNS('http://www.w3.org/2000/svg', tag);
            }
            function addLine(g, x1,y1,x2,y2, cls){
              const l = svgEl('line');
              l.setAttribute('x1', x1); l.setAttribute('y1', y1);
              l.setAttribute('x2', x2); l.setAttribute('y2', y2);
              if (cls) l.setAttribute('class', cls);
              g.appendChild(l);
              return l;
            }
            function addText(g, x,y, text, cls, rotateDeg){
              const t = svgEl('text');
              t.setAttribute('x', x);
              t.setAttribute('y', y);
              if (cls) t.setAttribute('class', cls);
              t.textContent = text;
              if (typeof rotateDeg === 'number') {
                t.setAttribute('transform', `rotate(${rotateDeg} ${x} ${y})`);
              }
              g.appendChild(t);
              return t;
            }
            function vec(x,y){ return {x,y}; }
            function sub(a,b){ return vec(a.x-b.x, a.y-b.y); }
            function add(a,b){ return vec(a.x+b.x, a.y+b.y); }
            function mul(a,k){ return vec(a.x*k, a.y*k); }
            function vlen(v){ return Math.hypot(v.x, v.y) || 1; }
            function norm(v){ const l=vlen(v); return vec(v.x/l, v.y/l); }
            function perp(v){ return vec(-v.y, v.x); }

            // Compute polyline points based on pattern: length moves, angle turns (inner angles with L/R)
            function computePolyline(dimMap){
              const pattern = Array.isArray(cfg.pattern) ? cfg.pattern : [];

              // Start position and heading (up)
              let x = 140, y = 360;
              let heading = -90;

              const pts = [[x,y]];

              // scale based on total length mm (only length keys in pattern)
              const segKeys = pattern.filter(k => dimMap[k] && dimMap[k].type === 'length');
              const totalMm = segKeys.reduce((sum,k)=> sum + Number(state[k] || 0), 0);
              const k = totalMm > 0 ? (520 / totalMm) : 1; // px/mm base

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
                  const inner = Number(state[key] || 0);
                  const turn = getTurnFromInner(inner);
                  const dir = (meta.dir === 'R') ? -1 : 1; // L default
                  heading += dir * turn;
                }
              }

              // fit into viewBox area with padding
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

              // extension lines
              addLine(g, A.x, A.y, A2.x, A2.y, 'spb-ext');
              addLine(g, B.x, B.y, B2.x, B2.y, 'spb-ext');

              // dim line with arrows
              const dim = addLine(g, A2.x, A2.y, B2.x, B2.y, 'spb-dim');
              dim.setAttribute('marker-start', `url(#${ARROW_ID})`);
              dim.setAttribute('marker-end', `url(#${ARROW_ID})`);

              // text
              const mid = mul(add(A2,B2), 0.5);
              let ang = Math.atan2(vHat.y, vHat.x) * 180 / Math.PI;
              if (ang > 90) ang -= 180;
              if (ang < -90) ang += 180;

              addText(g, mid.x, mid.y - 6, label, 'spb-dimtext', ang);
            }

            function renderDims(dimMap, pts){
              dimLayer.innerHTML = '';

              const pattern = Array.isArray(cfg.pattern) ? cfg.pattern : [];
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

              // small angle list top-left
              const angleLines = (cfg.dims || [])
                .filter(d => d.type === 'angle')
                .map(d => `${d.key}: ${state[d.key]}° (${d.dir === 'R' ? 'R' : 'L'})`);

              let y = 22;
              for (const t of angleLines) {
                addText(dimLayer, 18, y, t, 'spb-anglelist');
                y += 16;
              }
            }

            function renderMaterials(){
              matSel.innerHTML = '';
              (cfg.materials || []).forEach(m => {
                const opt = document.createElement('option');
                opt.value = m.key;
                opt.textContent = (m.label || m.key) + ' — ' + toNum(m.eur_m2, 0).toFixed(2) + ' €/m²';
                opt.dataset.eur = toNum(m.eur_m2, 0);
                matSel.appendChild(opt);
              });
              if (matSel.options.length) matSel.selectedIndex = 0;
            }

            function currentMaterialEurM2(){
              const opt = matSel.options[matSel.selectedIndex];
              return opt ? toNum(opt.dataset.eur, 0) : 0;
            }

            function renderDimInputs(){
              inputsWrap.innerHTML = '';
              (cfg.dims || []).forEach(d => {
                const min = (d.min ?? (d.type === 'angle' ? 45 : 10));
                const max = (d.max ?? (d.type === 'angle' ? 215 : 500));
                const def = (d.def ?? min);

                state[d.key] = toNum(state[d.key], def);

                const lab = document.createElement('label');
                lab.textContent = (d.label || d.key) + (d.type === 'angle' ? ' (°)' : ' (mm)');

                const inp = document.createElement('input');
                inp.type = 'number';
                inp.value = state[d.key];
                inp.min = min;
                inp.max = max;
                inp.dataset.key = d.key;
                inp.dataset.type = d.type;

                inputsWrap.appendChild(lab);
                inputsWrap.appendChild(inp);

                if (d.type === 'angle') {
                  const note = document.createElement('div');
                  note.className = 'spb-note';
                  note.textContent = 'Nurk (sisemine) – hinnas ei kasutata. Suund: ' + ((d.dir === 'R') ? 'R' : 'L');
                  inputsWrap.appendChild(note);
                }
              });
            }

            function calcAndRender(){
              const dimMap = buildDimMap();

              // price: sum ONLY length dims (s*)
              let sumSmm = 0;
              (cfg.dims || []).forEach(d => {
                if (d.type !== 'length') return;
                const min = (d.min ?? 10);
                const max = (d.max ?? 500);
                const v = clamp(state[d.key], min, max);
                sumSmm += v;
              });

              const Lm = sumSmm / 1000.0;
              const Pm = clamp(lenEl.value, 50, 8000) / 1000.0;
              const qty = clamp(qtyEl.value, 1, 999);

              const area = Lm * Pm;
              const eurM2 = currentMaterialEurM2();

              const priceNoVat = area * eurM2 * qty;
              const vatPct = toNum(cfg.vat, 24);
              const priceVat = priceNoVat * (1 + vatPct/100);

              areaEl.textContent = area.toFixed(3) + ' m²';
              novatEl.textContent = priceNoVat.toFixed(2) + ' €';
              vatEl.textContent = priceVat.toFixed(2) + ' €';

              // SVG
              const pts = computePolyline(dimMap);
              poly.setAttribute('points', pts.map(p=>p.join(',')).join(' '));
              renderDims(dimMap, pts);

              // optional WPForms fill
              const wf = cfg.wpforms || {};
              const formId = wf.form_id;
              const fields = (wf.fields || {});
              if (formId) {
                wpformsSet(formId, fields.profile, cfg.profileName || '');
                wpformsSet(formId, fields.inputs, JSON.stringify(state));
                wpformsSet(formId, fields.length, Math.round(clamp(lenEl.value, 50, 8000)));
                wpformsSet(formId, fields.qty, Math.round(qty));
                wpformsSet(formId, fields.area, area.toFixed(3));
                wpformsSet(formId, fields.price_novat, priceNoVat.toFixed(2));
                wpformsSet(formId, fields.price_vat, priceVat.toFixed(2));
              }
            }

            // listeners
            inputsWrap.addEventListener('input', (e) => {
              const el = e.target;
              if (!el || !el.dataset || !el.dataset.key) return;
              const k = el.dataset.key;
              const meta = (cfg.dims || []).find(x => x.key === k);
              if (!meta) return;
              const min = (meta.min ?? (meta.type === 'angle' ? 45 : 10));
              const max = (meta.max ?? (meta.type === 'angle' ? 215 : 500));
              state[k] = clamp(el.value, min, max);
              calcAndRender();
            });

            matSel.addEventListener('change', calcAndRender);
            lenEl.addEventListener('input', calcAndRender);
            qtyEl.addEventListener('input', calcAndRender);

            // init
            renderDimInputs();
            renderMaterials();
            calcAndRender();
          })();
        </script>
      </div>
    <?php
    return ob_get_clean();
  }
}

new Steel_Profile_Builder();
