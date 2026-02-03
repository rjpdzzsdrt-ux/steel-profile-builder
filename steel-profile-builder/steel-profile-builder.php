<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Profiilikalkulaator (SVG 2D/3D + mõõtjooned + admin tabel-UI mõõtudele ja materjalidele + toonid tagidena + standardlaiused + print/PDF + WPForms).
 * Version: 0.4.20
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.4.20';

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

  public function add_meta_boxes() {
    // Järjekord: joonis -> mõõdud -> pattern -> hinnastus/materjalid -> view -> wpforms
    add_meta_box('spb_preview', 'Joonise eelvaade', [$this, 'mb_preview'], self::CPT, 'normal', 'high');
    add_meta_box('spb_dims', 'Mõõdud (tabel + liigutamine)', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_pricing', 'Hinnastus + materjalid (tabel + toonid + standardlaiused)', [$this, 'mb_pricing'], self::CPT, 'normal', 'default');
    add_meta_box('spb_view', 'Vaate seaded (pööramine)', [$this, 'mb_view'], self::CPT, 'side', 'default');
    add_meta_box('spb_wpforms', 'WPForms', [$this, 'mb_wpforms'], self::CPT, 'side', 'default');
  }

  public function enqueue_admin($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->post_type !== self::CPT) return;

    // WordPress built-in styles for buttons etc are fine
    wp_enqueue_style('wp-components'); // harmless
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
      // materials now includes tones + widths_mm in each item
      'materials' => [
        ['key'=>'POL','label'=>'POL','eur_m2'=>7.5,'tones'=>['RR2H3'],'widths_mm'=>[208,312,416]],
        ['key'=>'PUR','label'=>'PUR','eur_m2'=>8.5,'tones'=>[],'widths_mm'=>[208,312,416]],
        ['key'=>'PUR_MATT','label'=>'PUR Matt','eur_m2'=>11.5,'tones'=>[],'widths_mm'=>[208,312,416]],
        ['key'=>'TSINK','label'=>'Tsink','eur_m2'=>6.5,'tones'=>[],'widths_mm'=>[208,312,416]],
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
        'sum_s_mm' => 0,
        'pick_width_mm' => 0,
        'area_m2' => 0,
        'price_total_no_vat' => 0,
        'price_total_vat' => 0,
        'vat_pct' => 0,
      ],
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
   *  BACKEND: PREVIEW
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

        // Read from current admin UI (so preview updates live)
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

        // Label collision “B variant”: if doesn't fit -> offset more; if still doesn't -> hide
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

        // Auto-fit: keeps drawing visible even for “mini dimensions”
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

        // Listen to UI changes (dims table + pattern)
        document.addEventListener('input', (e)=>{
          const t = e.target;
          if (!t) return;
          if (t.closest && (t.closest('#spb-dims-table') || t.closest('#spb-materials-table'))) {
            // dims changes (hidden json updated by admin UI)
            return update();
          }
          if (t.id === 'spb-pattern-textarea' || t.name === 'spb_pattern_json') return update();
        });
        document.addEventListener('click', (e)=>{
          const t = e.target;
          if (!t) return;
          if (
            t.id === 'spb-add-length' || t.id === 'spb-add-angle' ||
            t.id === 'spb-add-material' ||
            (t.classList && (t.classList.contains('spb-del') || t.classList.contains('spb-m-del') || t.classList.contains('spb-m-up') || t.classList.contains('spb-m-down')))
          ) {
            setTimeout(update, 0);
          }
        });

        update();
      })();
    </script>
    <?php
  }

  /* ===========================
   *  BACKEND: DIMS (TABLE UI)
   * =========================== */
  public function mb_dims($post) {
    wp_nonce_field('spb_save', 'spb_nonce');

    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    ?>
    <p style="margin-top:0;opacity:.8">
      Read on liigutatavad. Tagasipööre kehtib nurga reale (<strong>a*</strong>) ja mõjutab järgmist sirglõiku (katkendjoon).
    </p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
      <button type="button" class="button" id="spb-add-length">+ Lisa sirglõik (s)</button>
      <button type="button" class="button" id="spb-add-angle">+ Lisa nurk (a)</button>
      <label style="display:flex;align-items:center;gap:8px;opacity:.85">
        <input type="checkbox" id="spb-auto-append-pattern" checked>
        lisa uus mõõt automaatselt patterni lõppu
      </label>
    </div>

    <table class="widefat" id="spb-dims-table">
      <thead>
        <tr>
          <th style="width:28px">↕</th>
          <th style="width:110px">Key</th>
          <th style="width:120px">Tüüp</th>
          <th>Silt</th>
          <th style="width:90px">Min</th>
          <th style="width:90px">Max</th>
          <th style="width:100px">Default</th>
          <th style="width:90px">Suund</th>
          <th style="width:120px">Nurk</th>
          <th style="width:110px">Tagasipööre</th>
          <th style="width:170px">Tegevus</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <input type="hidden" id="spb_dims_json" name="spb_dims_json"
           value="<?php echo esc_attr(wp_json_encode($dims)); ?>">

    <details style="margin-top:10px">
      <summary><strong>Advanced: toor JSON</strong></summary>
      <p style="opacity:.75;margin:8px 0 6px">Kui midagi läheb sassi, saad siit JSON-i parandada.</p>
      <textarea id="spb_dims_json_adv" style="width:100%;min-height:140px;"><?php echo esc_textarea(wp_json_encode($dims, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>
      <p style="margin-top:8px">
        <button type="button" class="button" id="spb_dims_apply_json">Apply JSON</button>
      </p>
    </details>

    <style>
      #spb-dims-table input, #spb-dims-table select{ width:100%; }
      #spb-dims-table td{ vertical-align:middle; }
      .spb-row-handle{ cursor:grab; opacity:.55; font-weight:900; }
      .spb-actions{ display:flex; gap:6px; flex-wrap:wrap; }
      .spb-actions .button{ padding:2px 8px; height:auto; line-height:1.8; }
    </style>

    <script>
      (function(){
        const table = document.getElementById('spb-dims-table');
        const body = table ? table.querySelector('tbody') : null;
        const hidden = document.getElementById('spb_dims_json');
        const patTa = document.getElementById('spb-pattern-textarea');
        const autoAppend = document.getElementById('spb-auto-append-pattern');

        const advTa = document.getElementById('spb_dims_json_adv');
        const advApply = document.getElementById('spb_dims_apply_json');

        if (!table || !body || !hidden) return;

        function parseJSON(s,f){ try{return JSON.parse(s);}catch(e){return f;} }
        function toNum(v,f){ const n=Number(v); return Number.isFinite(n)?n:f; }
        function sanitizeKey(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9_]/g,''); }

        function getDims(){ return parseJSON(hidden.value||'[]', []); }
        function setDims(d){ hidden.value = JSON.stringify(d||[]); if (advTa) advTa.value = JSON.stringify(d||[], null, 2); hidden.dispatchEvent(new Event('input',{bubbles:true})); }

        function getPattern(){ return patTa ? parseJSON(patTa.value||'[]', []) : []; }
        function setPattern(p){ if (!patTa) return; patTa.value = JSON.stringify(p||[]); patTa.dispatchEvent(new Event('input',{bubbles:true})); }

        function nextKey(prefix, dims){
          let i=1;
          const used = new Set(dims.map(x=>x.key));
          while (used.has(prefix+i)) i++;
          return prefix+i;
        }

        function rowHtml(d){
          const isAngle = d.type === 'angle';
          return `
            <tr>
              <td><span class="spb-row-handle">↕</span></td>
              <td><input type="text" value="${d.key||''}" data-k="key"></td>
              <td>
                <select data-k="type">
                  <option value="length" ${!isAngle?'selected':''}>length</option>
                  <option value="angle" ${isAngle?'selected':''}>angle</option>
                </select>
              </td>
              <td><input type="text" value="${(d.label||d.key||'')}" data-k="label"></td>
              <td><input type="number" value="${d.min ?? ''}" data-k="min"></td>
              <td><input type="number" value="${d.max ?? ''}" data-k="max"></td>
              <td><input type="number" value="${d.def ?? ''}" data-k="def"></td>
              <td>
                <select data-k="dir">
                  <option value="L" ${(String(d.dir||'L').toUpperCase()==='L')?'selected':''}>L</option>
                  <option value="R" ${(String(d.dir||'L').toUpperCase()==='R')?'selected':''}>R</option>
                </select>
              </td>
              <td>
                <select data-k="pol" ${!isAngle?'disabled':''}>
                  <option value="inner" ${(d.pol!=='outer')?'selected':''}>Seest</option>
                  <option value="outer" ${(d.pol==='outer')?'selected':''}>Väljast</option>
                </select>
              </td>
              <td style="text-align:center">
                <input type="checkbox" data-k="ret" ${d.ret?'checked':''} ${!isAngle?'disabled':''}>
              </td>
              <td>
                <div class="spb-actions">
                  <button type="button" class="button spb-up">Üles</button>
                  <button type="button" class="button spb-down">Alla</button>
                  <button type="button" class="button spb-del">Kustuta</button>
                </div>
              </td>
            </tr>
          `;
        }

        function render(){
          const dims = getDims();
          body.innerHTML = dims.map(rowHtml).join('');
        }

        function readFromUI(){
          const rows = Array.from(body.querySelectorAll('tr'));
          const out = [];
          rows.forEach(tr=>{
            const obj = {};
            tr.querySelectorAll('[data-k]').forEach(el=>{
              const k = el.getAttribute('data-k');
              if (el.type === 'checkbox') obj[k] = !!el.checked;
              else obj[k] = el.value;
            });

            const key = sanitizeKey(obj.key||'');
            if (!key) return;

            const type = (obj.type === 'angle') ? 'angle' : 'length';
            const dir = (String(obj.dir||'L').toUpperCase()==='R') ? 'R' : 'L';
            const pol = (obj.pol === 'outer') ? 'outer' : 'inner';
            const ret = !!obj.ret;

            out.push([
              'key', key,
              'type', type,
              'label', String(obj.label||key),
              'min', obj.min!=='' ? toNum(obj.min, null) : null,
              'max', obj.max!=='' ? toNum(obj.max, null) : null,
              'def', obj.def!=='' ? toNum(obj.def, null) : null,
              'dir', dir,
              'pol', (type==='angle') ? pol : null,
              'ret', (type==='angle') ? ret : false
            ].reduce((a,_,i,arr)=> (i%2? (a[arr[i-1]]=arr[i], a):a), {}));
          });
          return out;
        }

        function sync(){
          const dims = readFromUI();
          setDims(dims);
        }

        body.addEventListener('input', function(e){
          const t = e.target;
          if (!t) return;
          // If type changed, rerender to enable/disable angle-only fields
          if (t.getAttribute('data-k') === 'type') {
            const dims = readFromUI();
            setDims(dims);
            render();
            return;
          }
          sync();
        });

        body.addEventListener('click', function(e){
          const btn = e.target;
          if (!(btn instanceof HTMLElement)) return;

          const tr = btn.closest('tr');
          if (!tr) return;

          const rows = Array.from(body.querySelectorAll('tr'));
          const idx = rows.indexOf(tr);

          if (btn.classList.contains('spb-del')) {
            rows[idx].remove();
            sync();
            return;
          }

          if (btn.classList.contains('spb-up') && idx > 0) {
            body.insertBefore(tr, rows[idx-1]);
            sync();
            return;
          }

          if (btn.classList.contains('spb-down') && idx < rows.length-1) {
            body.insertBefore(rows[idx+1], tr);
            sync();
            return;
          }
        });

        // Add buttons
        const btnLen = document.getElementById('spb-add-length');
        const btnAng = document.getElementById('spb-add-angle');

        function addDim(type){
          const dims = getDims();
          const key = nextKey(type==='angle'?'a':'s', dims);
          const obj = {
            key, type,
            label: key,
            min: type==='angle'?5:10,
            max: type==='angle'?215:500,
            def: type==='angle'?135:100,
            dir: 'L',
            pol: type==='angle'?'inner':null,
            ret: false
          };
          dims.push(obj);
          setDims(dims);
          render();

          // auto append to pattern
          if (autoAppend && autoAppend.checked) {
            const pat = getPattern();
            pat.push(key);
            setPattern(pat);
          }
        }

        if (btnLen) btnLen.addEventListener('click', ()=> addDim('length'));
        if (btnAng) btnAng.addEventListener('click', ()=> addDim('angle'));

        // Advanced apply JSON
        if (advApply && advTa) {
          advApply.addEventListener('click', function(){
            const dims = parseJSON(advTa.value||'[]', null);
            if (!Array.isArray(dims)) {
              alert('JSON ei ole massiiv.');
              return;
            }
            setDims(dims);
            render();
          });
        }

        // Initial render from hidden
        render();
      })();
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

  /* ===========================
   *  BACKEND: PRICING + MATERIALS (TABLE UI + TAGS)
   * =========================== */
  public function mb_pricing($post) {
    $m = $this->get_meta($post->ID);
    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $vat = floatval($pricing['vat'] ?? 24);
    $jm_work = floatval($pricing['jm_work_eur_jm'] ?? 0);
    $jm_per_m = floatval($pricing['jm_per_m_eur_jm'] ?? 0);

    $materials = is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'];
    // normalize
    foreach ($materials as &$mm) {
      $mm['tones'] = is_array($mm['tones'] ?? null) ? array_values($mm['tones']) : [];
      $mm['widths_mm'] = is_array($mm['widths_mm'] ?? null) ? array_values($mm['widths_mm']) : [];
    }
    unset($mm);

    $uid = 'spb_pr_' . $post->ID . '_' . wp_generate_uuid4();
    ?>
    <div id="<?php echo esc_attr($uid); ?>">
      <p style="margin-top:0;opacity:.8">
        Hinnastus: <strong>kokkuhind</strong> arvutatakse (materjalikulu standardlaiuse järgi + töö). Materjali hinda eraldi frontendis ei kuvata.
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:640px">
        <p style="margin:0"><label>KM %<br>
          <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($vat); ?>" style="width:100%;">
        </label></p>

        <p style="margin:0"><label>Töö (€/jm)<br>
          <input type="number" step="0.01" name="spb_jm_work_eur_jm" value="<?php echo esc_attr($jm_work); ?>" style="width:100%;">
        </label></p>

        <p style="margin:0"><label>Lisakomponent (€/jm per Σs meetrit)<br>
          <input type="number" step="0.01" name="spb_jm_per_m_eur_jm" value="<?php echo esc_attr($jm_per_m); ?>" style="width:100%;">
        </label></p>
      </div>

      <hr style="margin:14px 0;">

      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <h3 style="margin:0">Materjalid (toonid + standardlaiused)</h3>
        <button type="button" class="button" id="spb-add-material">+ Lisa materjal</button>
      </div>

      <table class="widefat" id="spb-materials-table" style="margin-top:10px">
        <thead>
          <tr>
            <th style="width:28px">↕</th>
            <th style="width:140px">Key</th>
            <th style="width:220px">Silt</th>
            <th style="width:110px">€/m²</th>
            <th>Toonid (tagid)</th>
            <th style="width:260px">Standardlaiused (mm)</th>
            <th style="width:170px">Tegevus</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <input type="hidden" id="spb_materials_json" name="spb_materials_json"
             value="<?php echo esc_attr(wp_json_encode($materials)); ?>">

      <details style="margin-top:10px">
        <summary><strong>Advanced: toor materjalide JSON</strong></summary>
        <p style="opacity:.75;margin:8px 0 6px">Muuda JSON ja vajuta “Apply JSON”.</p>
        <textarea id="spb_materials_json_adv" style="width:100%;min-height:180px;"><?php echo esc_textarea(wp_json_encode($materials, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>
        <p style="margin-top:8px">
          <button type="button" class="button" id="spb_materials_apply_json">Apply JSON</button>
        </p>
      </details>

      <style>
        #spb-materials-table td{ vertical-align:middle; }
        #spb-materials-table input{ width:100%; }
        .spb-m-actions{ display:flex; gap:6px; flex-wrap:wrap; }
        .spb-m-actions .button{ padding:2px 8px; height:auto; line-height:1.8; }

        .spb-tagbox{
          display:flex; flex-wrap:wrap; gap:6px;
          border:1px solid #ddd; border-radius:10px;
          padding:6px 8px; background:#fff;
          min-height:40px;
        }
        .spb-tag{
          display:inline-flex; align-items:center; gap:6px;
          background:#f2f2f2; border:1px solid #e2e2e2;
          border-radius:999px; padding:4px 10px; font-weight:700; font-size:12px;
        }
        .spb-tag button{
          border:0; background:transparent; cursor:pointer;
          font-weight:900; line-height:1;
        }
        .spb-taginput{
          border:0; outline:none; min-width:120px;
          padding:6px 6px; font-size:13px;
          flex:1;
        }
      </style>

      <script>
        (function(){
          const root = document.getElementById('<?php echo esc_js($uid); ?>');
          if (!root) return;

          const table = root.querySelector('#spb-materials-table');
          const body = table ? table.querySelector('tbody') : null;
          const hidden = root.querySelector('#spb_materials_json');

          const advTa = root.querySelector('#spb_materials_json_adv');
          const advApply = root.querySelector('#spb_materials_apply_json');

          const addBtn = root.querySelector('#spb-add-material');

          if (!table || !body || !hidden) return;

          function parseJSON(s,f){ try{return JSON.parse(s);}catch(e){return f;} }
          function toNum(v,f){ const n=Number(v); return Number.isFinite(n)?n:f; }
          function sanitizeKey(s){ return String(s||'').toUpperCase().replace(/[^A-Z0-9_]/g,''); }

          function getMats(){ return parseJSON(hidden.value||'[]', []); }
          function setMats(m){ hidden.value = JSON.stringify(m||[]); if (advTa) advTa.value = JSON.stringify(m||[], null, 2); hidden.dispatchEvent(new Event('input',{bubbles:true})); }

          function makeTag(text){
            const span = document.createElement('span');
            span.className = 'spb-tag';
            span.innerHTML = `<span>${text}</span><button type="button" aria-label="Remove">×</button>`;
            return span;
          }

          function renderRow(mat){
            const tr = document.createElement('tr');

            tr.innerHTML = `
              <td><span class="spb-row-handle">↕</span></td>
              <td><input type="text" data-k="key" value="${(mat.key||'')}"></td>
              <td><input type="text" data-k="label" value="${(mat.label||mat.key||'')}"></td>
              <td><input type="number" step="0.01" data-k="eur_m2" value="${mat.eur_m2 ?? ''}"></td>
              <td>
                <div class="spb-tagbox" data-k="tones_box">
                  <input class="spb-taginput" type="text" placeholder="Lisa toon (Enter)..." />
                </div>
                <input type="hidden" data-k="tones_json" value="${encodeURIComponent(JSON.stringify(Array.isArray(mat.tones)?mat.tones:[]))}">
              </td>
              <td><input type="text" data-k="widths" value="${Array.isArray(mat.widths_mm)?mat.widths_mm.join(', '):''}" placeholder="nt 208, 312, 416"></td>
              <td>
                <div class="spb-m-actions">
                  <button type="button" class="button spb-m-up">Üles</button>
                  <button type="button" class="button spb-m-down">Alla</button>
                  <button type="button" class="button spb-m-del">Kustuta</button>
                </div>
              </td>
            `;

            // inject existing tags
            const tones = Array.isArray(mat.tones) ? mat.tones : [];
            const box = tr.querySelector('[data-k="tones_box"]');
            const inp = tr.querySelector('.spb-taginput');
            const tonesHidden = tr.querySelector('[data-k="tones_json"]');

            tones.forEach(t=>{
              const tag = makeTag(String(t));
              box.insertBefore(tag, inp);
            });

            function getTones(){
              const tags = Array.from(box.querySelectorAll('.spb-tag span'));
              return tags.map(s=>s.textContent.trim()).filter(Boolean);
            }
            function syncTones(){
              const arr = getTones();
              tonesHidden.value = encodeURIComponent(JSON.stringify(arr));
              syncAll();
            }
            function addTone(t){
              t = String(t||'').trim();
              if (!t) return;
              // normalize: allow only letters/numbers/_ and dash
              t = t.toUpperCase().replace(/[^A-Z0-9_\-]/g,'');
              if (!t) return;
              // avoid duplicates
              const existing = new Set(getTones());
              if (existing.has(t)) return;
              const tag = makeTag(t);
              box.insertBefore(tag, inp);
              syncTones();
            }

            // tag remove
            box.addEventListener('click', (e)=>{
              const btn = e.target;
              if (!(btn instanceof HTMLElement)) return;
              if (btn.tagName.toLowerCase() === 'button' && btn.closest('.spb-tag')) {
                btn.closest('.spb-tag').remove();
                syncTones();
              }
            });

            // input enter / comma
            inp.addEventListener('keydown', (e)=>{
              if (e.key === 'Enter' || e.key === ',' ) {
                e.preventDefault();
                addTone(inp.value);
                inp.value = '';
              }
              if (e.key === 'Backspace' && !inp.value) {
                const tags = box.querySelectorAll('.spb-tag');
                const last = tags[tags.length-1];
                if (last) {
                  last.remove();
                  syncTones();
                }
              }
            });

            // paste list -> split
            inp.addEventListener('paste', (e)=>{
              const text = (e.clipboardData || window.clipboardData).getData('text');
              if (!text) return;
              e.preventDefault();
              text.split(/[,;\n\r\t ]+/g).forEach(x=> addTone(x));
              inp.value = '';
            });

            // any input change -> sync
            tr.addEventListener('input', (e)=>{
              const t = e.target;
              if (!(t instanceof HTMLElement)) return;
              if (t.classList.contains('spb-taginput')) return;
              syncAll();
            });

            return tr;
          }

          function render(){
            const mats = getMats();
            body.innerHTML = '';
            mats.forEach(m=>{
              body.appendChild(renderRow(m));
            });
          }

          function readFromUI(){
            const rows = Array.from(body.querySelectorAll('tr'));
            const out = [];
            rows.forEach(tr=>{
              const keyEl = tr.querySelector('[data-k="key"]');
              const labelEl = tr.querySelector('[data-k="label"]');
              const eurEl = tr.querySelector('[data-k="eur_m2"]');
              const widthsEl = tr.querySelector('[data-k="widths"]');
              const tonesHidden = tr.querySelector('[data-k="tones_json"]');

              const key = sanitizeKey(keyEl ? keyEl.value : '');
              if (!key) return;

              const label = (labelEl && labelEl.value) ? String(labelEl.value) : key;
              const eur_m2 = eurEl ? toNum(eurEl.value, 0) : 0;

              let tones = [];
              if (tonesHidden && tonesHidden.value) {
                try{ tones = JSON.parse(decodeURIComponent(tonesHidden.value)); }catch(e){ tones = []; }
              }
              if (!Array.isArray(tones)) tones = [];

              const widthsRaw = widthsEl ? String(widthsEl.value||'') : '';
              const widths = widthsRaw
                .split(/[,;\n\r\t ]+/g)
                .map(x=> Number(String(x).trim()))
                .filter(n=> Number.isFinite(n) && n > 0)
                .map(n=> Math.round(n));

              out.push({ key, label, eur_m2, tones, widths_mm: widths });
            });
            return out;
          }

          function syncAll(){
            const mats = readFromUI();
            setMats(mats);
          }

          body.addEventListener('click', (e)=>{
            const btn = e.target;
            if (!(btn instanceof HTMLElement)) return;

            const tr = btn.closest('tr');
            if (!tr) return;

            const rows = Array.from(body.querySelectorAll('tr'));
            const idx = rows.indexOf(tr);

            if (btn.classList.contains('spb-m-del')) {
              tr.remove();
              syncAll();
              return;
            }
            if (btn.classList.contains('spb-m-up') && idx > 0) {
              body.insertBefore(tr, rows[idx-1]);
              syncAll();
              return;
            }
            if (btn.classList.contains('spb-m-down') && idx < rows.length-1) {
              body.insertBefore(rows[idx+1], tr);
              syncAll();
              return;
            }
          });

          if (addBtn) {
            addBtn.addEventListener('click', ()=>{
              const mats = getMats();
              mats.push({ key: 'NEW', label: 'Uus materjal', eur_m2: 0, tones: [], widths_mm: [208,312,416] });
              setMats(mats);
              render();
            });
          }

          if (advApply && advTa) {
            advApply.addEventListener('click', ()=>{
              const mats = parseJSON(advTa.value||'[]', null);
              if (!Array.isArray(mats)) { alert('JSON ei ole massiiv.'); return; }
              // normalize
              const out = mats.map(m=>({
                key: sanitizeKey(m.key||''),
                label: String(m.label||m.key||''),
                eur_m2: toNum(m.eur_m2, 0),
                tones: Array.isArray(m.tones)?m.tones.map(x=>String(x)) : [],
                widths_mm: Array.isArray(m.widths_mm)? m.widths_mm.map(x=>Math.round(Number(x))).filter(n=>Number.isFinite(n)&&n>0) : []
              })).filter(m=>m.key);
              setMats(out);
              render();
            });
          }

          // init
          render();
        })();
      </script>
    </div>
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
      'tone' => 'Värvitoon',
      'detail_length_mm' => 'Detaili pikkus (mm)',
      'qty' => 'Kogus',
      'sum_s_mm' => 'Σ s (mm)',
      'pick_width_mm' => 'Standardlaius (mm)',
      'area_m2' => 'Materjalikulu (m²)',
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

    // pricing + materials
    $pr = $this->default_pricing();
    $pr['vat'] = floatval($_POST['spb_vat'] ?? 24);
    $pr['jm_work_eur_jm'] = floatval($_POST['spb_jm_work_eur_jm'] ?? 0);
    $pr['jm_per_m_eur_jm'] = floatval($_POST['spb_jm_per_m_eur_jm'] ?? 0);

    $materials_json = wp_unslash($_POST['spb_materials_json'] ?? '[]');
    $materials = json_decode($materials_json, true);
    $materials_out = [];
    if (is_array($materials)) {
      foreach ($materials as $mat) {
        $k = strtoupper(preg_replace('/[^A-Z0-9_]/', '', (string)($mat['key'] ?? '')));
        if (!$k) continue;

        $tones = [];
        if (is_array($mat['tones'] ?? null)) {
          foreach ($mat['tones'] as $t) {
            $t = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', (string)$t));
            if ($t !== '') $tones[] = $t;
          }
          $tones = array_values(array_unique($tones));
        }

        $widths = [];
        if (is_array($mat['widths_mm'] ?? null)) {
          foreach ($mat['widths_mm'] as $w) {
            $w = intval($w);
            if ($w > 0) $widths[] = $w;
          }
          $widths = array_values(array_unique($widths));
          sort($widths);
        }

        $materials_out[] = [
          'key' => $k,
          'label' => sanitize_text_field($mat['label'] ?? $k),
          'eur_m2' => floatval($mat['eur_m2'] ?? 0),
          'tones' => $tones,
          'widths_mm' => $widths,
        ];
      }
    }
    $pr['materials'] = $materials_out ?: $this->default_pricing()['materials'];
    update_post_meta($post_id, '_spb_pricing', $pr);

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
   *  FRONTEND SHORTCODE (single mode)
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
    if (!is_array($pricing['materials'] ?? null)) $pricing['materials'] = $this->default_pricing()['materials'];

    $wp = (is_array($m['wpforms']) && $m['wpforms']) ? $m['wpforms'] : [];
    $wp = array_merge($this->default_wpforms(), $wp);

    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();

    $cfg = [
      'profileId' => $id,
      'profileName' => get_the_title($id),
      'dims' => $dims,
      'pattern' => $pattern,
      'vat' => floatval($pricing['vat'] ?? 24),
      'jm_work_eur_jm' => floatval($pricing['jm_work_eur_jm'] ?? 0),
      'jm_per_m_eur_jm' => floatval($pricing['jm_per_m_eur_jm'] ?? 0),
      'materials' => $pricing['materials'],
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

    $uid = 'spb_front_' . $id . '_' . wp_generate_uuid4();
    $arrowId = 'spbArrow_' . $uid;

    ob_start(); ?>
      <div class="spb-front" id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <div class="spb-hint">Pidev joon = värvitud pool · Katkendjoon = tagasipööre (krunditud pool)</div>

        <div class="spb-card">
          <div class="spb-title"><?php echo esc_html(get_the_title($id)); ?></div>
          <div class="spb-error" style="display:none"></div>

          <div class="spb-grid">
            <div class="spb-box">
              <div class="spb-box-h spb-box-h-row">
                <span>Joonis</span>
                <div class="spb-tools">
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
                  <div><span>Materjal</span><strong class="spb-tb-mat"></strong></div>
                  <div><span>Värvitoon</span><strong class="spb-tb-tone"></strong></div>
                  <div><span>Detaili pikkus</span><strong class="spb-tb-len"></strong></div>
                  <div><span>Kogus</span><strong class="spb-tb-qty"></strong></div>
                  <div><span>Standardlaius</span><strong class="spb-tb-pick"></strong></div>
                  <div><span>Materjalikulu</span><strong class="spb-tb-area"></strong></div>
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

            <div class="spb-row4">
              <div class="spb-row">
                <label>Materjal</label>
                <select class="spb-material"></select>
              </div>
              <div class="spb-row">
                <label>Värvitoon</label>
                <select class="spb-tone"></select>
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
              <div class="spb-total"><span>Kokku (ilma KM)</span><strong class="spb-price-novat">—</strong></div>
              <div class="spb-total"><span>Kokku (koos KM)</span><strong class="spb-price-vat">—</strong></div>
            </div>

            <button type="button" class="spb-btn spb-open-form">Küsi personaalset hinnapakkumist</button>
            <div class="spb-foot">Hind on orienteeruv. Täpne pakkumine sõltub materjalist, töömahust ja kogusest.</div>
          </div>

          <?php if (!empty($cfg['wpforms']['form_id'])): ?>
            <div class="spb-form-wrap" style="display:none">
              <?php echo do_shortcode('[wpforms id="'.intval($cfg['wpforms']['form_id']).'" title="false" description="false"]'); ?>
            </div>
          <?php endif; ?>
        </div>

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
          .spb-front .spb-tools{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
          .spb-front .spb-mini{
            border:1px solid #ddd;background:#fff;border-radius:999px;
            padding:6px 10px;font-weight:800;cursor:pointer;
            font-size:12px;line-height:1;
          }
          .spb-front .spb-mini[aria-pressed="true"]{border-color:#bbb; box-shadow:0 0 0 2px rgba(0,0,0,.05)}

          .spb-front .spb-draw{display:grid;gap:12px}
          .spb-front .spb-svg-wrap{
            height:420px;
            border:1px solid #eee;border-radius:14px;
            background:linear-gradient(180deg,#fafafa,#fff);
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;
            touch-action:none; /* allow pointer drag */
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
          .spb-front .spb-row4{display:grid;grid-template-columns:1fr 1fr 1fr .7fr;gap:12px;align-items:end}
          .spb-front .spb-row label{display:block;font-weight:700;margin:0 0 6px 0;font-size:13px;opacity:.85}

          .spb-front .spb-results{margin-top:14px;border-top:1px solid #eee;padding-top:12px;display:grid;gap:8px}
          .spb-front .spb-results > div{display:flex;justify-content:space-between;gap:12px}
          .spb-front .spb-results strong{font-size:18px}

          .spb-front .spb-btn{
            width:100%;margin-top:12px;padding:12px 14px;border-radius:14px;border:0;cursor:pointer;
            font-weight:900;background:var(--spb-accent);color:#fff;
          }
          .spb-front .spb-foot{margin-top:10px;opacity:.6;font-size:13px}

          @media (max-width: 980px){
            .spb-front .spb-grid{grid-template-columns:1fr}
            .spb-front .spb-svg-wrap{height:360px}
            .spb-front .spb-titleblock{grid-template-columns:repeat(2,1fr)}
            .spb-front .spb-row4{grid-template-columns:1fr}
          }
        </style>

        <script>
          (function(){
            const root = document.getElementById('<?php echo esc_js($uid); ?>');
            if (!root) return;

            const cfg = JSON.parse(root.dataset.spb || '{}');

            const err = root.querySelector('.spb-error');
            function showErr(msg){ err.style.display='block'; err.textContent=msg; }
            function hideErr(){ err.style.display='none'; err.textContent=''; }

            if (!cfg.dims || !cfg.dims.length) {
              showErr('Sellel profiilil pole mõõte. Ava profiil adminis ja salvesta uuesti.');
              return;
            }

            // Accent from Elementor (best effort)
            (function setAccent(){
              try{
                const btn = document.querySelector('.elementor a.elementor-button, .elementor button, a.elementor-button, button.elementor-button');
                if (!btn) return;
                const cs = getComputedStyle(btn);
                const bg = cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? cs.backgroundColor : null;
                if (bg) root.style.setProperty('--spb-accent', bg);
              }catch(e){}
            })();

            const inputsWrap = root.querySelector('.spb-inputs');
            const matSel = root.querySelector('.spb-material');
            const toneSel = root.querySelector('.spb-tone');
            const lenEl = root.querySelector('.spb-length');
            const qtyEl = root.querySelector('.spb-qty');

            const novatEl = root.querySelector('.spb-price-novat');
            const vatEl = root.querySelector('.spb-price-vat');

            const toggle3dBtn = root.querySelector('.spb-toggle-3d');
            const reset3dBtn = root.querySelector('.spb-reset-3d');
            const saveSvgBtn = root.querySelector('.spb-save-svg');
            const printBtn = root.querySelector('.spb-print');

            const svg = root.querySelector('.spb-svg');
            const fitWrap = root.querySelector('.spb-fit');
            const world = root.querySelector('.spb-world');

            const g2d = root.querySelector('.spb-2d');
            const segs = root.querySelector('.spb-segs');
            const dimLayer = root.querySelector('.spb-dimlayer');
            const g3d = root.querySelector('.spb-3d');
            const debugLayer = root.querySelector('.spb-debug');

            const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

            const VB_W = 820, VB_H = 460;
            const CX = 410, CY = 230;
            let lastBBox = null;

            const tbName = root.querySelector('.spb-tb-name');
            const tbDate = root.querySelector('.spb-tb-date');
            const tbMat = root.querySelector('.spb-tb-mat');
            const tbTone = root.querySelector('.spb-tb-tone');
            const tbLen = root.querySelector('.spb-tb-len');
            const tbQty = root.querySelector('.spb-tb-qty');
            const tbPick = root.querySelector('.spb-tb-pick');
            const tbArea = root.querySelector('.spb-tb-area');

            const formWrap = root.querySelector('.spb-form-wrap');
            const openBtn = root.querySelector('.spb-open-form');

            const stateVal = {};
            let mode3d = false;

            // 3D drag state
            let dragOn = false;
            let dragLastX = 0;
            let dragLastY = 0;
            let dragDx = 80;
            let dragDy = -55;
            let zoom = 1;

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
                opt.dataset.tones = JSON.stringify(Array.isArray(m.tones)?m.tones:[]);
                opt.dataset.widths = JSON.stringify(Array.isArray(m.widths_mm)?m.widths_mm:[]);
                matSel.appendChild(opt);
              });
              if (matSel.options.length) matSel.selectedIndex = 0;
              renderTonesForMaterial();
            }

            function renderTonesForMaterial(){
              toneSel.innerHTML = '';
              const opt = matSel.options[matSel.selectedIndex];
              let tones = [];
              try{ tones = opt ? JSON.parse(opt.dataset.tones||'[]') : []; }catch(e){ tones = []; }
              if (!Array.isArray(tones)) tones = [];
              if (tones.length === 0) {
                const o = document.createElement('option');
                o.value = '';
                o.textContent = '—';
                toneSel.appendChild(o);
                toneSel.selectedIndex = 0;
                return;
              }
              tones.forEach(t=>{
                const o = document.createElement('option');
                o.value = t;
                o.textContent = t;
                toneSel.appendChild(o);
              });
              toneSel.selectedIndex = 0;
            }

            function currentMaterialOpt(){ return matSel.options[matSel.selectedIndex] || null; }
            function currentMaterialEurM2(){
              const opt = currentMaterialOpt();
              return opt ? toNum(opt.dataset.eur,0) : 0;
            }
            function currentMaterialLabel(){
              const opt = currentMaterialOpt();
              return opt ? opt.textContent : '';
            }
            function currentTone(){
              const opt = toneSel.options[toneSel.selectedIndex];
              return opt ? opt.value : '';
            }
            function currentWidths(){
              const opt = currentMaterialOpt();
              if (!opt) return [];
              try{
                const a = JSON.parse(opt.dataset.widths||'[]');
                return Array.isArray(a) ? a.map(n=>Number(n)).filter(n=>Number.isFinite(n)&&n>0).sort((a,b)=>a-b) : [];
              }catch(e){ return []; }
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
                }catch(e){ return true; }
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

              x *= view.scale * zoom;
              y *= view.scale * zoom;

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

            // ---------- 3D (pseudo) ----------
            function polyPtsStr(pts){
              return pts.map(p => `${p[0]},${p[1]}`).join(' ');
            }
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

            function render3D(pts, segStyle){
              g3d.innerHTML = '';

              const DX = dragDx;
              const DY = dragDy;

              const back = pts.map(p => [p[0] + DX, p[1] + DY]);

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

            function reset3D(){
              dragDx = 80;
              dragDy = -55;
              zoom = 1;
              render();
            }

            // Drag handlers for 3D
            function onPointerDown(e){
              if (!mode3d) return;
              dragOn = true;
              dragLastX = e.clientX;
              dragLastY = e.clientY;
              try{ svg.setPointerCapture(e.pointerId); }catch(err){}
              e.preventDefault();
            }
            function onPointerMove(e){
              if (!mode3d || !dragOn) return;
              const dx = e.clientX - dragLastX;
              const dy = e.clientY - dragLastY;
              dragLastX = e.clientX;
              dragLastY = e.clientY;

              dragDx += dx * 0.8;
              dragDy += dy * 0.8;
              render();
              e.preventDefault();
            }
            function onPointerUp(e){
              dragOn = false;
              try{ svg.releasePointerCapture(e.pointerId); }catch(err){}
            }
            function onWheel(e){
              if (!mode3d) return;
              e.preventDefault();
              const delta = Math.sign(e.deltaY);
              zoom = clamp(zoom * (delta>0 ? 0.92 : 1.08), 0.6, 2.2);
              render();
            }

            if (toggle3dBtn) toggle3dBtn.addEventListener('click', ()=> setMode3d(!mode3d));
            if (reset3dBtn) reset3dBtn.addEventListener('click', reset3D);

            // ---------- standard width pick ----------
            function pickWidthMm(needMm, widths){
              needMm = Math.max(0, Number(needMm||0));
              widths = Array.isArray(widths) ? widths.map(n=>Number(n)).filter(n=>Number.isFinite(n)&&n>0).sort((a,b)=>a-b) : [];
              if (!widths.length) return Math.round(needMm);
              for (const w of widths){
                if (w >= needMm) return Math.round(w);
              }
              // if none large enough -> pick largest available
              return Math.round(widths[widths.length-1]);
            }

            // ---------- calc totals ----------
            function sumSmm(){
              let s=0;
              cfg.dims.forEach(d=>{
                if (d.type !== 'length') return;
                const min = (d.min ?? 10);
                const max = (d.max ?? 500);
                s += clamp(stateVal[d.key], min, max);
              });
              return s;
            }

            function calcTotals(){
              const sum_mm = sumSmm();
              const widths = currentWidths();
              const pick_mm = pickWidthMm(sum_mm, widths);

              const Lm = clamp(lenEl.value, 50, 8000) / 1000.0;
              const qty = clamp(qtyEl.value, 1, 999);

              // material area uses picked standard width
              const area = (pick_mm/1000.0) * Lm * qty;
              const matCost = area * currentMaterialEurM2();

              // work: same as before but based on length and sum_s meters
              const sum_m = sum_mm / 1000.0;
              const jmWork = toNum(cfg.jm_work_eur_jm, 0);
              const jmPerM = toNum(cfg.jm_per_m_eur_jm, 0);
              const jmRate = jmWork + (sum_m * jmPerM);
              const workCost = (Lm * jmRate) * qty;

              const totalNoVat = matCost + workCost;
              const vatPct = toNum(cfg.vat, 24);
              const totalVat = totalNoVat * (1 + vatPct/100);

              return { sum_mm, pick_mm, area, qty, totalNoVat, totalVat, vatPct };
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
              const out = calcTotals();

              const values = {
                profile_name: cfg.profileName || '',
                dims_json: dimsPayloadJSON(),
                material: currentMaterialLabel(),
                tone: currentTone(),
                detail_length_mm: String(clamp(lenEl.value, 50, 8000)),
                qty: String(clamp(qtyEl.value, 1, 999)),
                sum_s_mm: String(out.sum_mm),
                pick_width_mm: String(out.pick_mm),
                area_m2: String(out.area.toFixed(4)),
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

            // SVG serialize for save/print
            function serializeSvg(clean){
              const clone = svg.cloneNode(true);
              // remove debug
              const dbg = clone.querySelector('.spb-debug');
              if (dbg) dbg.innerHTML = '';
              // if clean -> always print 2D
              const c2d = clone.querySelector('.spb-2d');
              const c3d = clone.querySelector('.spb-3d');
              if (clean){
                if (c2d) c2d.style.display = '';
                if (c3d) c3d.style.display = 'none';
              } else {
                if (mode3d){
                  if (c2d) c2d.style.display = 'none';
                  if (c3d) c3d.style.display = '';
                } else {
                  if (c2d) c2d.style.display = '';
                  if (c3d) c3d.style.display = 'none';
                }
              }
              clone.setAttribute('width','100%');
              clone.setAttribute('height','auto');
              return clone.outerHTML;
            }

            function saveSvg(){
              try{
                const data = serializeSvg(false);
                const blob = new Blob([data], {type:'image/svg+xml;charset=utf-8'});
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const name = (cfg.profileName || 'steel-profile').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
                a.href = url;
                a.download = `${name || 'steel-profile'}.svg`;
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
              }catch(e){
                showErr('SVG salvestus ebaõnnestus.');
              }
            }

            function buildPrintHTML(svgMarkup, out){
              const d = new Date();
              const dd = String(d.getDate()).padStart(2,'0');
              const mm = String(d.getMonth()+1).padStart(2,'0');
              const yyyy = String(d.getFullYear());
              const HH = String(d.getHours()).padStart(2,'0');
              const MI = String(d.getMinutes()).padStart(2,'0');
              const SS = String(d.getSeconds()).padStart(2,'0');

              const profile = (cfg.profileName || '').trim();
              const mat = currentMaterialLabel();
              const tone = currentTone() || '—';

              // auto drawing/work numbers (simple + stable)
              const drawingNo = `${mat}-${yyyy}${mm}${dd}-${HH}${MI}${SS}`.replace(/[^A-Z0-9\-]/gi,'').toUpperCase();
              const workNo = `${profile}-${yyyy}${mm}${dd}`.replace(/[^A-Z0-9\-]/gi,'').toUpperCase();

              const lenMm = clamp(lenEl.value, 50, 8000);
              const qty = clamp(qtyEl.value, 1, 999);

              return `
                <!doctype html>
                <html>
                <head>
                  <meta charset="utf-8">
                  <meta name="viewport" content="width=device-width, initial-scale=1">
                  <title>${profile} – Print</title>
                  <style>
                    @page { size: A4; margin: 12mm; }
                    body{ font-family: Arial, sans-serif; color:#111; }
                    .sheet{
                      position: relative;
                      width: 100%;
                      min-height: 270mm;
                      border: 1px solid #111;
                      padding: 10mm;
                      box-sizing: border-box;
                    }
                    .topline{
                      display:flex; justify-content:space-between; gap:10mm; align-items:flex-start;
                      margin-bottom: 8mm;
                    }
                    .profile{ font-size: 16px; font-weight: 900; }
                    .meta{ font-size: 12px; line-height: 1.4; text-align:right; }
                    .drawing{
                      border:1px solid #111;
                      height: 165mm;
                      display:flex; align-items:center; justify-content:center;
                      overflow:hidden;
                      background:#fff;
                      padding: 4mm;
                      box-sizing:border-box;
                    }
                    .drawing svg{ width: 100%; height: auto; }
                    .bottom{
                      position:absolute; left:10mm; right:10mm; bottom:10mm;
                      border-top:1px solid #111;
                      padding-top:6mm;
                      display:grid;
                      grid-template-columns: 1fr 1fr 1fr 1fr;
                      gap:6mm;
                      font-size:12px;
                    }
                    .cell span{ display:block; opacity:.7; font-size:11px; }
                    .cell strong{ display:block; font-size:13px; }
                    .pricebox{
                      grid-column: 3 / 5;
                      border:1px solid #111;
                      padding:4mm;
                      display:flex;
                      justify-content:space-between;
                      align-items:center;
                      gap:8mm;
                    }
                    .pricebox .p{ font-size:12px; }
                    .pricebox .v{ font-size:16px; font-weight:900; }
                    @media print {
                      .noprint { display:none !important; }
                    }
                  </style>
                </head>
                <body>
                  <div class="sheet">
                    <div class="topline">
                      <div class="profile">${profile}</div>
                      <div class="meta">
                        <div><strong>Kuupäev:</strong> ${dd}.${mm}.${yyyy}</div>
                        <div><strong>Drawing no:</strong> ${drawingNo}</div>
                        <div><strong>Work no:</strong> ${workNo}</div>
                      </div>
                    </div>

                    <div class="drawing">
                      ${svgMarkup}
                    </div>

                    <div class="bottom">
                      <div class="cell"><span>Materjal</span><strong>${mat}</strong></div>
                      <div class="cell"><span>Värvitoon</span><strong>${tone}</strong></div>
                      <div class="cell"><span>Detaili pikkus</span><strong>${lenMm} mm</strong></div>
                      <div class="cell"><span>Kogus</span><strong>${qty} tk</strong></div>

                      <div class="cell"><span>Σ s (vajalik)</span><strong>${out.sum_mm} mm</strong></div>
                      <div class="cell"><span>Standardlaius</span><strong>${out.pick_mm} mm</strong></div>
                      <div class="cell"><span>Materjalikulu</span><strong>${out.area.toFixed(4)} m²</strong></div>
                      <div class="pricebox">
                        <div class="p">Kokku (ilma KM)</div>
                        <div class="v">${out.totalNoVat.toFixed(2)} €</div>
                        <div class="p">Kokku (koos KM)</div>
                        <div class="v">${out.totalVat.toFixed(2)} €</div>
                      </div>
                    </div>
                  </div>

                  <script>
                    window.addEventListener('load', () => {
                      setTimeout(() => { window.print(); }, 250);
                    });
                  </script>
                </body>
                </html>
              `;
            }

            function printPdf(){
              try{
                hideErr();
                const was3d = mode3d;
                if (was3d) setMode3d(false);
                render();
                const out = calcTotals();
                const svgData = serializeSvg(true);
                const w = window.open('', '_blank');
                if (!w) {
                  showErr('Print/PDF: pop-up blokk. Luba pop-up ja proovi uuesti.');
                  if (was3d) setMode3d(true);
                  return;
                }
                w.document.open();
                w.document.write(buildPrintHTML(svgData, out));
                w.document.close();
                if (was3d) setMode3d(true);
              }catch(e){
                showErr('Print/PDF ebaõnnestus.');
              }
            }

            function render(){
              hideErr();
              const dimMap = buildDimMap();

              fitWrap.setAttribute('transform', '');
              world.setAttribute('transform', '');

              const out = computePolyline(dimMap);

              if (!mode3d){
                renderSegments(out.pts, out.segStyle);
                renderDims(dimMap, out.pts);
                g3d.innerHTML = '';

                applyViewTweak();
                const v = getView();
                lastBBox = calcBBoxFromPts(out.pts, v);
                applyAutoFit();
                renderDebug();
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

              const totals = calcTotals();
              novatEl.textContent = totals.totalNoVat.toFixed(2) + ' €';
              vatEl.textContent = totals.totalVat.toFixed(2) + ' €';

              tbMat.textContent = currentMaterialLabel() || '—';
              tbTone.textContent = currentTone() || '—';
              tbLen.textContent = String(clamp(lenEl.value, 50, 8000)) + ' mm';
              tbQty.textContent = String(clamp(qtyEl.value, 1, 999));
              tbPick.textContent = String(totals.pick_mm) + ' mm';
              tbArea.textContent = totals.area.toFixed(4) + ' m²';
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

            matSel.addEventListener('change', function(){
              renderTonesForMaterial();
              render();
            });
            toneSel.addEventListener('change', render);
            lenEl.addEventListener('input', render);
            qtyEl.addEventListener('input', render);

            // 3D drag events
            svg.addEventListener('pointerdown', onPointerDown);
            window.addEventListener('pointermove', onPointerMove);
            window.addEventListener('pointerup', onPointerUp);
            svg.addEventListener('wheel', onWheel, {passive:false});

            if (saveSvgBtn) saveSvgBtn.addEventListener('click', saveSvg);
            if (printBtn) printBtn.addEventListener('click', printPdf);

            if (openBtn) openBtn.addEventListener('click', function(){
              render();
              if (formWrap) {
                fillWpforms();
                formWrap.style.display='block';
                formWrap.scrollIntoView({behavior:'smooth', block:'start'});
              }
            });

            // init
            renderDimInputs();
            renderMaterials();
            setTitleBlock();
            setMode3d(false);
            reset3D();
            render();
          })();
        </script>
      </div>
    <?php
    return ob_get_clean();
  }
}

new Steel_Profile_Builder();
