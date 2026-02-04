<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Profiilikalkulaator (SVG 2D/3D + auto-fit + label collision) + admin tabel-UI (mõõdud + materjalid/toonid/standardlaiused) + standardlaiuse materjalikulu arvestus + PDF print layout + WPForms.
 * Version: 0.5.1
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.5.1';

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

    // sortable (drag handle)
    wp_enqueue_script('jquery-ui-sortable');
  }

  public function add_meta_boxes() {
    add_meta_box('spb_preview', 'Joonise eelvaade', [$this, 'mb_preview'], self::CPT, 'normal', 'high');
    add_meta_box('spb_dims', 'Mõõdud (tabel + liigutamine)', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');

    add_meta_box('spb_view', 'Vaate seaded (pööramine)', [$this, 'mb_view'], self::CPT, 'side', 'default');
    add_meta_box('spb_pricing', 'Hinnastus + materjalid (toonid + standardlaiused)', [$this, 'mb_pricing'], self::CPT, 'side', 'default');
    add_meta_box('spb_pdf', 'PDF / Print seaded', [$this, 'mb_pdf'], self::CPT, 'side', 'default');
    add_meta_box('spb_wpforms', 'WPForms', [$this, 'mb_wpforms'], self::CPT, 'side', 'default');
  }

  private function get_meta($post_id) {
    return [
      'dims'    => get_post_meta($post_id, '_spb_dims', true),
      'pattern' => get_post_meta($post_id, '_spb_pattern', true),
      'pricing' => get_post_meta($post_id, '_spb_pricing', true),
      'wpforms' => get_post_meta($post_id, '_spb_wpforms', true),
      'view'    => get_post_meta($post_id, '_spb_view', true),
      'pdf'     => get_post_meta($post_id, '_spb_pdf', true),
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
      'unfold_allow_mm' => 0, // lisa varu laiusarvutusele (nt 0..10)
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
        'color_tone' => 0,
        'detail_length_mm' => 0,
        'qty' => 0,

        'need_width_mm' => 0,     // vajaminev laius (mm) (Σs + allow)
        'stock_width_mm' => 0,    // standardlaius (mm) (picked)
        'area_buy_m2' => 0,       // ostetav pindala m2 (stock_width * length)
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

  private function default_pdf() {
    return [
      'enable_print_btn' => 1,
      'show_prices_in_pdf' => 1,
      'company_name' => 'Steel.ee',
      'company_line1' => 'Tamme tn 29, Tõrvandi, Estonia',
      'company_line2' => 'info@steel.ee · steel.ee',
      'paper' => 'A4', // reserved
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

          // Collision variant B: shift; if still not fit => hide
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
          if (t.id === 'spb_dims_json') return update();
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
   *  BACKEND: DIMS TABLE UI
   * =========================== */
  public function mb_dims($post) {
    wp_nonce_field('spb_save', 'spb_nonce');
    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();
    ?>
    <p style="margin-top:0;opacity:.8">
      <strong>s*</strong> = sirglõik (mm), <strong>a*</strong> = nurk (°). Suund: <strong>L/R</strong>. Nurk: <strong>Seest/Väljast</strong>. Tagasipööre märgib, et <em>järgmine sirglõik</em> on “krunditud poole” peale.
    </p>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0;align-items:center">
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
          <th style="width:26px"></th>
          <th style="width:110px">Key</th>
          <th style="width:110px">Tüüp</th>
          <th>Silt</th>
          <th style="width:80px">Min</th>
          <th style="width:80px">Max</th>
          <th style="width:90px">Default</th>
          <th style="width:90px">Suund</th>
          <th style="width:110px">Nurk</th>
          <th style="width:110px">Tagasipööre</th>
          <th style="width:160px">Tegevus</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <input type="hidden" id="spb_dims_json" name="spb_dims_json" value="<?php echo esc_attr(wp_json_encode($dims)); ?>">

    <style>
      #spb-dims-table td{vertical-align:middle}
      .spb-handle{cursor:grab;opacity:.65;font-size:18px;line-height:1}
      .spb-btns{display:flex;gap:6px;flex-wrap:wrap}
      .spb-btns button{padding:2px 8px}
      .spb-small{width:100%;max-width:100%}
      .spb-nowrap{white-space:nowrap}
    </style>

    <script>
      (function(){
        const tbody = document.querySelector('#spb-dims-table tbody');
        const hidden = document.getElementById('spb_dims_json');
        const addLen = document.getElementById('spb-add-length');
        const addAng = document.getElementById('spb-add-angle');
        const autoAppend = document.getElementById('spb-auto-append-pattern');
        const patTa = document.getElementById('spb-pattern-textarea') || document.querySelector('textarea[name="spb_pattern_json"]');

        function parseJSON(s, fallback){ try{return JSON.parse(s);}catch(e){return fallback;} }
        function toNum(v,f){ const n=Number(v); return Number.isFinite(n)?n:f; }

        function getRows(){
          const arr = parseJSON(hidden.value || '[]', []);
          return Array.isArray(arr) ? arr : [];
        }
        function setRows(arr){
          hidden.value = JSON.stringify(arr);
          hidden.dispatchEvent(new Event('input', {bubbles:true}));
          hidden.dispatchEvent(new Event('change', {bubbles:true}));
        }
        function getPattern(){
          if (!patTa) return [];
          const arr = parseJSON(patTa.value || '[]', []);
          return Array.isArray(arr) ? arr : [];
        }
        function setPattern(arr){
          if (!patTa) return;
          patTa.value = JSON.stringify(arr);
          patTa.dispatchEvent(new Event('input', {bubbles:true}));
          patTa.dispatchEvent(new Event('change', {bubbles:true}));
        }

        function mk(tag, attrs){
          const el = document.createElement(tag);
          if (attrs) Object.keys(attrs).forEach(k=>{
            if (k === 'text') el.textContent = attrs[k];
            else el.setAttribute(k, attrs[k]);
          });
          return el;
        }

        function nextKey(prefix, rows){
          let i=1;
          const used = new Set(rows.map(r=>String(r.key||'')));
          while (used.has(prefix+i)) i++;
          return prefix+i;
        }

        function render(){
          const rows = getRows();
          tbody.innerHTML = '';

          rows.forEach((r, idx)=>{
            const tr = document.createElement('tr');
            tr.dataset.idx = String(idx);

            const tdH = mk('td');
            tdH.innerHTML = '<span class="spb-handle">↕</span>';
            tr.appendChild(tdH);

            const tdKey = mk('td');
            const key = mk('input', {type:'text', value:(r.key||''), class:'spb-small'});
            key.addEventListener('input', ()=>{ r.key = key.value.trim(); setRows(rows); });
            tdKey.appendChild(key);
            tr.appendChild(tdKey);

            const tdType = mk('td');
            const selType = mk('select', {class:'spb-small'});
            selType.innerHTML = '<option value="length">length</option><option value="angle">angle</option>';
            selType.value = (r.type === 'angle') ? 'angle' : 'length';
            selType.addEventListener('change', ()=>{
              r.type = (selType.value === 'angle') ? 'angle' : 'length';
              if (r.type !== 'angle') { r.pol = null; r.ret = false; }
              setRows(rows);
              render();
            });
            tdType.appendChild(selType);
            tr.appendChild(tdType);

            const tdLabel = mk('td');
            const label = mk('input', {type:'text', value:(r.label||''), class:'spb-small'});
            label.addEventListener('input', ()=>{ r.label = label.value; setRows(rows); });
            tdLabel.appendChild(label);
            tr.appendChild(tdLabel);

            const tdMin = mk('td');
            const min = mk('input', {type:'number', step:'1', value: (r.min ?? ''), class:'spb-small'});
            min.addEventListener('input', ()=>{ r.min = (min.value === '' ? null : toNum(min.value, null)); setRows(rows); });
            tdMin.appendChild(min);
            tr.appendChild(tdMin);

            const tdMax = mk('td');
            const max = mk('input', {type:'number', step:'1', value: (r.max ?? ''), class:'spb-small'});
            max.addEventListener('input', ()=>{ r.max = (max.value === '' ? null : toNum(max.value, null)); setRows(rows); });
            tdMax.appendChild(max);
            tr.appendChild(tdMax);

            const tdDef = mk('td');
            const def = mk('input', {type:'number', step:'1', value: (r.def ?? ''), class:'spb-small'});
            def.addEventListener('input', ()=>{ r.def = (def.value === '' ? null : toNum(def.value, null)); setRows(rows); });
            tdDef.appendChild(def);
            tr.appendChild(tdDef);

            const tdDir = mk('td');
            const selDir = mk('select', {class:'spb-small'});
            selDir.innerHTML = '<option value="L">L</option><option value="R">R</option>';
            selDir.value = (String(r.dir||'L').toUpperCase()==='R') ? 'R' : 'L';
            selDir.addEventListener('change', ()=>{ r.dir = selDir.value; setRows(rows); });
            tdDir.appendChild(selDir);
            tr.appendChild(tdDir);

            const tdPol = mk('td');
            if ((r.type === 'angle')) {
              const selPol = mk('select', {class:'spb-small'});
              selPol.innerHTML = '<option value="inner">Seest</option><option value="outer">Väljast</option>';
              selPol.value = (r.pol === 'outer') ? 'outer' : 'inner';
              selPol.addEventListener('change', ()=>{ r.pol = selPol.value; setRows(rows); });
              tdPol.appendChild(selPol);
            } else {
              tdPol.innerHTML = '<span style="opacity:.4">—</span>';
            }
            tr.appendChild(tdPol);

            const tdRet = mk('td');
            if ((r.type === 'angle')) {
              const chk = mk('input', {type:'checkbox'});
              chk.checked = !!r.ret;
              chk.addEventListener('change', ()=>{ r.ret = !!chk.checked; setRows(rows); });
              tdRet.appendChild(chk);
            } else {
              tdRet.innerHTML = '<span style="opacity:.4">—</span>';
            }
            tr.appendChild(tdRet);

            const tdAct = mk('td');
            tdAct.className = 'spb-nowrap';
            const btns = mk('div'); btns.className = 'spb-btns';

            const up = mk('button', {type:'button', class:'button', text:'Üles'});
            const down = mk('button', {type:'button', class:'button', text:'Alla'});
            const del = mk('button', {type:'button', class:'button spb-del', text:'Kustuta'});

            up.addEventListener('click', ()=>{
              if (idx<=0) return;
              const t = rows[idx-1]; rows[idx-1]=rows[idx]; rows[idx]=t;
              setRows(rows); render();
            });
            down.addEventListener('click', ()=>{
              if (idx>=rows.length-1) return;
              const t = rows[idx+1]; rows[idx+1]=rows[idx]; rows[idx]=t;
              setRows(rows); render();
            });
            del.addEventListener('click', ()=>{
              const removed = rows.splice(idx,1);
              setRows(rows); render();

              // also remove from pattern
              if (removed && removed[0] && removed[0].key && autoAppend && autoAppend.checked) {
                const p = getPattern().filter(k => k !== removed[0].key);
                setPattern(p);
              }
            });

            btns.appendChild(up);
            btns.appendChild(down);
            btns.appendChild(del);
            tdAct.appendChild(btns);
            tr.appendChild(tdAct);

            tbody.appendChild(tr);
          });

          // sortable drag handle
          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sortable) {
            window.jQuery(tbody).sortable('destroy');
            window.jQuery(tbody).sortable({
              handle: '.spb-handle',
              helper: function(e, ui){ ui.children().each(function(){ window.jQuery(this).width(window.jQuery(this).width()); }); return ui; },
              update: function(){
                const old = getRows();
                const newArr = [];
                tbody.querySelectorAll('tr').forEach(tr=>{
                  const i = Number(tr.dataset.idx);
                  if (Number.isFinite(i) && old[i]) newArr.push(old[i]);
                });
                setRows(newArr);
                render();
              }
            });
          }
        }

        function addRow(type){
          const rows = getRows();
          const key = nextKey(type==='angle'?'a':'s', rows);
          const row = {
            key,
            type: (type==='angle')?'angle':'length',
            label: key,
            min: (type==='angle')?5:10,
            max: (type==='angle')?215:500,
            def: (type==='angle')?135:15,
            dir: 'L',
            pol: (type==='angle')?'inner':null,
            ret: false
          };
          rows.push(row);
          setRows(rows);
          render();

          if (autoAppend && autoAppend.checked && patTa) {
            const p = getPattern();
            p.push(key);
            setPattern(p);
          }
        }

        if (addLen) addLen.addEventListener('click', ()=>addRow('length'));
        if (addAng) addAng.addEventListener('click', ()=>addRow('angle'));

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
        Frontendi joonise pööramine/sättimine (visuaalne). Auto-fit hoiab joonise alati nähtaval.
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
   *  BACKEND: PRICING + MATERIALS TABLE
   * =========================== */
  public function mb_pricing($post) {
    $m0 = $this->get_meta($post->ID);
    $pricing = (is_array($m0['pricing']) && $m0['pricing']) ? $m0['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $vat = floatval($pricing['vat'] ?? 24);
    $jm_work = floatval($pricing['jm_work_eur_jm'] ?? 0);
    $jm_per_m = floatval($pricing['jm_per_m_eur_jm'] ?? 0);
    $allow = intval($pricing['unfold_allow_mm'] ?? 0);

    $materials = is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'];
    ?>
    <p style="margin-top:0;opacity:.8">
      Kokku = <strong>materjalikulu (standardlaiuse järgi)</strong> + <strong>töö (jm)</strong> + KM.
    </p>

    <p><label>KM %<br>
      <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($vat); ?>" style="width:100%;">
    </label></p>

    <p><label>Töö (€/jm)<br>
      <input type="number" step="0.01" name="spb_jm_work_eur_jm" value="<?php echo esc_attr($jm_work); ?>" style="width:100%;">
    </label></p>

    <p><label>Lisakomponent (€/jm per Σs meetrit)<br>
      <input type="number" step="0.01" name="spb_jm_per_m_eur_jm" value="<?php echo esc_attr($jm_per_m); ?>" style="width:100%;">
    </label></p>

    <p><label>Laiuse varu (mm) (lisatakse Σs-le)<br>
      <input type="number" step="1" min="0" max="50" name="spb_unfold_allow_mm" value="<?php echo esc_attr($allow); ?>" style="width:100%;">
    </label></p>

    <hr style="margin:12px 0">

    <p style="margin:0 0 8px;"><strong>Materjalid (toonid + standardlaiused)</strong></p>

    <table class="widefat" id="spb-materials-table">
      <thead>
        <tr>
          <th style="width:26px"></th>
          <th style="width:110px">Key</th>
          <th>Silt</th>
          <th style="width:90px">€/m²</th>
          <th style="width:140px">Toonid</th>
          <th style="width:160px">Standardlaiused (mm)</th>
          <th style="width:160px">Tegevus</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <p style="margin-top:8px"><button type="button" class="button" id="spb-add-material">+ Lisa materjal</button></p>

    <input type="hidden" id="spb_materials_json" name="spb_materials_json" value="<?php echo esc_attr(wp_json_encode($materials)); ?>">

    <style>
      #spb-materials-table td{vertical-align:middle}
      .spb-handle2{cursor:grab;opacity:.65;font-size:18px;line-height:1}
      .spb-btns2{display:flex;gap:6px;flex-wrap:wrap}
      .spb-btns2 button{padding:2px 8px}
      .spb-small2{width:100%;max-width:100%}
    </style>

    <script>
      (function(){
        const tbody = document.querySelector('#spb-materials-table tbody');
        const hidden = document.getElementById('spb_materials_json');
        const addBtn = document.getElementById('spb-add-material');

        function parseJSON(s, fallback){ try{return JSON.parse(s);}catch(e){return fallback;} }
        function toNum(v,f){ const n=Number(v); return Number.isFinite(n)?n:f; }

        function getRows(){
          const arr = parseJSON(hidden.value || '[]', []);
          return Array.isArray(arr) ? arr : [];
        }
        function setRows(arr){
          hidden.value = JSON.stringify(arr);
          hidden.dispatchEvent(new Event('input', {bubbles:true}));
          hidden.dispatchEvent(new Event('change', {bubbles:true}));
        }
        function mk(tag, attrs){
          const el = document.createElement(tag);
          if (attrs) Object.keys(attrs).forEach(k=>{
            if (k === 'text') el.textContent = attrs[k];
            else el.setAttribute(k, attrs[k]);
          });
          return el;
        }

        function nextKey(rows){
          let i=1;
          const used = new Set(rows.map(r=>String(r.key||'').toUpperCase()));
          while (used.has('M'+i)) i++;
          return 'M'+i;
        }

        function render(){
          const rows = getRows();
          tbody.innerHTML = '';

          rows.forEach((r, idx)=>{
            const tr = document.createElement('tr');
            tr.dataset.idx = String(idx);

            const tdH = mk('td');
            tdH.innerHTML = '<span class="spb-handle2">↕</span>';
            tr.appendChild(tdH);

            const tdKey = mk('td');
            const key = mk('input', {type:'text', value:(r.key||''), class:'spb-small2'});
            key.addEventListener('input', ()=>{ r.key = key.value.trim(); setRows(rows); });
            tdKey.appendChild(key);
            tr.appendChild(tdKey);

            const tdLabel = mk('td');
            const label = mk('input', {type:'text', value:(r.label||''), class:'spb-small2'});
            label.addEventListener('input', ()=>{ r.label = label.value; setRows(rows); });
            tdLabel.appendChild(label);
            tr.appendChild(tdLabel);

            const tdE = mk('td');
            const eur = mk('input', {type:'number', step:'0.01', value:(r.eur_m2 ?? 0), class:'spb-small2'});
            eur.addEventListener('input', ()=>{ r.eur_m2 = toNum(eur.value, 0); setRows(rows); });
            tdE.appendChild(eur);
            tr.appendChild(tdE);

            const tdT = mk('td');
            const tones = mk('input', {type:'text', value: Array.isArray(r.tones)? r.tones.join(', '):'', class:'spb-small2', placeholder:'RR2H3, RR11'});
            tones.addEventListener('input', ()=>{
              const arr = tones.value.split(',').map(s=>s.trim()).filter(Boolean);
              r.tones = arr;
              setRows(rows);
            });
            tdT.appendChild(tones);
            tr.appendChild(tdT);

            const tdW = mk('td');
            const widths = mk('input', {type:'text', value: Array.isArray(r.widths_mm)? r.widths_mm.join(', '):'', class:'spb-small2', placeholder:'208, 250, 333'});
            widths.addEventListener('input', ()=>{
              const arr = widths.value.split(',').map(s=>Number(String(s).trim())).filter(n=>Number.isFinite(n) && n>0);
              // sort asc
              arr.sort((a,b)=>a-b);
              r.widths_mm = arr;
              setRows(rows);
            });
            tdW.appendChild(widths);
            tr.appendChild(tdW);

            const tdAct = mk('td');
            const btns = mk('div'); btns.className = 'spb-btns2';
            const up = mk('button', {type:'button', class:'button', text:'Üles'});
            const down = mk('button', {type:'button', class:'button', text:'Alla'});
            const del = mk('button', {type:'button', class:'button', text:'Kustuta'});

            up.addEventListener('click', ()=>{
              if (idx<=0) return;
              const t = rows[idx-1]; rows[idx-1]=rows[idx]; rows[idx]=t;
              setRows(rows); render();
            });
            down.addEventListener('click', ()=>{
              if (idx>=rows.length-1) return;
              const t = rows[idx+1]; rows[idx+1]=rows[idx]; rows[idx]=t;
              setRows(rows); render();
            });
            del.addEventListener('click', ()=>{
              rows.splice(idx,1);
              setRows(rows); render();
            });

            btns.appendChild(up); btns.appendChild(down); btns.appendChild(del);
            tdAct.appendChild(btns);
            tr.appendChild(tdAct);

            tbody.appendChild(tr);
          });

          if (window.jQuery && window.jQuery.fn && window.jQuery.fn.sortable) {
            window.jQuery(tbody).sortable('destroy');
            window.jQuery(tbody).sortable({
              handle: '.spb-handle2',
              helper: function(e, ui){ ui.children().each(function(){ window.jQuery(this).width(window.jQuery(this).width()); }); return ui; },
              update: function(){
                const old = getRows();
                const newArr = [];
                tbody.querySelectorAll('tr').forEach(tr=>{
                  const i = Number(tr.dataset.idx);
                  if (Number.isFinite(i) && old[i]) newArr.push(old[i]);
                });
                setRows(newArr);
                render();
              }
            });
          }
        }

        if (addBtn) addBtn.addEventListener('click', ()=>{
          const rows = getRows();
          const k = nextKey(rows);
          rows.push({key:k, label:k, eur_m2:0, tones:[], widths_mm:[]});
          setRows(rows);
          render();
        });

        render();
      })();
    </script>
    <?php
  }

  public function mb_pdf($post) {
    $m = $this->get_meta($post->ID);
    $pdf = (is_array($m['pdf']) && $m['pdf']) ? array_merge($this->default_pdf(), $m['pdf']) : $this->default_pdf();

    $enable = !empty($pdf['enable_print_btn']) ? 1 : 0;
    $showPrices = !empty($pdf['show_prices_in_pdf']) ? 1 : 0;

    $company = sanitize_text_field($pdf['company_name'] ?? 'Steel.ee');
    $line1 = sanitize_text_field($pdf['company_line1'] ?? '');
    $line2 = sanitize_text_field($pdf['company_line2'] ?? '');
    ?>
    <p style="margin-top:0;opacity:.8">Print/PDF nupu nähtavus ja PDF layouti päise info.</p>

    <p>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="spb_pdf_enable_print_btn" value="1" <?php checked($enable, 1); ?>>
        <span>Näita frontendis “Print / PDF” nuppu</span>
      </label>
    </p>

    <p>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="spb_pdf_show_prices" value="1" <?php checked($showPrices, 1); ?>>
        <span>Näita PDF-is hinnakasti (kokku ilma KM / koos KM)</span>
      </label>
    </p>

    <p><label>Ettevõtte nimi<br>
      <input type="text" name="spb_pdf_company_name" value="<?php echo esc_attr($company); ?>" style="width:100%;">
    </label></p>

    <p><label>Ettevõtte rida 1<br>
      <input type="text" name="spb_pdf_company_line1" value="<?php echo esc_attr($line1); ?>" style="width:100%;">
    </label></p>

    <p><label>Ettevõtte rida 2<br>
      <input type="text" name="spb_pdf_company_line2" value="<?php echo esc_attr($line2); ?>" style="width:100%;">
    </label></p>
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
      'color_tone' => 'Värvitoon',
      'detail_length_mm' => 'Detaili pikkus (mm)',
      'qty' => 'Kogus',
      'need_width_mm' => 'Vajaminev laius (mm)',
      'stock_width_mm' => 'Standardlaius (mm)',
      'area_buy_m2' => 'Materjalikulu (m²)',
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
    $p = $this->default_pricing();
    $p['vat'] = floatval($_POST['spb_vat'] ?? 24);
    $p['jm_work_eur_jm'] = floatval($_POST['spb_jm_work_eur_jm'] ?? 0);
    $p['jm_per_m_eur_jm'] = floatval($_POST['spb_jm_per_m_eur_jm'] ?? 0);
    $p['unfold_allow_mm'] = intval($_POST['spb_unfold_allow_mm'] ?? 0);
    if ($p['unfold_allow_mm'] < 0) $p['unfold_allow_mm'] = 0;
    if ($p['unfold_allow_mm'] > 50) $p['unfold_allow_mm'] = 50;

    $materials_json = wp_unslash($_POST['spb_materials_json'] ?? '[]');
    $materials = json_decode($materials_json, true);
    $materials_out = [];
    if (is_array($materials)) {
      foreach ($materials as $mat) {
        $k = sanitize_key($mat['key'] ?? '');
        if (!$k) continue;

        $tones = [];
        if (!empty($mat['tones']) && is_array($mat['tones'])) {
          foreach ($mat['tones'] as $t) {
            $t = trim(sanitize_text_field($t));
            if ($t !== '') $tones[] = $t;
          }
        }

        $widths = [];
        if (!empty($mat['widths_mm']) && is_array($mat['widths_mm'])) {
          foreach ($mat['widths_mm'] as $w) {
            $n = intval($w);
            if ($n > 0) $widths[] = $n;
          }
          sort($widths);
          $widths = array_values(array_unique($widths));
        }

        $materials_out[] = [
          'key' => strtoupper($k),
          'label' => sanitize_text_field($mat['label'] ?? strtoupper($k)),
          'eur_m2' => floatval($mat['eur_m2'] ?? 0),
          'tones' => $tones,
          'widths_mm' => $widths,
        ];
      }
    }
    $p['materials'] = $materials_out ?: $this->default_pricing()['materials'];
    update_post_meta($post_id, '_spb_pricing', $p);

    // pdf settings
    $pdf = $this->default_pdf();
    $pdf['enable_print_btn'] = !empty($_POST['spb_pdf_enable_print_btn']) ? 1 : 0;
    $pdf['show_prices_in_pdf'] = !empty($_POST['spb_pdf_show_prices']) ? 1 : 0;
    $pdf['company_name'] = sanitize_text_field($_POST['spb_pdf_company_name'] ?? $pdf['company_name']);
    $pdf['company_line1'] = sanitize_text_field($_POST['spb_pdf_company_line1'] ?? $pdf['company_line1']);
    $pdf['company_line2'] = sanitize_text_field($_POST['spb_pdf_company_line2'] ?? $pdf['company_line2']);
    update_post_meta($post_id, '_spb_pdf', $pdf);

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

    $view = (is_array($m['view']) && $m['view']) ? array_merge($this->default_view(), $m['view']) : $this->default_view();
    $pdf = (is_array($m['pdf']) && $m['pdf']) ? array_merge($this->default_pdf(), $m['pdf']) : $this->default_pdf();

    $cfg = [
      'profileId' => $id,
      'profileName' => get_the_title($id),
      'dims' => $dims,
      'pattern' => $pattern,

      'vat' => floatval($pricing['vat'] ?? 24),
      'jm_work_eur_jm' => floatval($pricing['jm_work_eur_jm'] ?? 0),
      'jm_per_m_eur_jm' => floatval($pricing['jm_per_m_eur_jm'] ?? 0),
      'unfold_allow_mm' => intval($pricing['unfold_allow_mm'] ?? 0),

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
      'pdf' => [
        'enable_print_btn' => !empty($pdf['enable_print_btn']) ? 1 : 0,
        'show_prices_in_pdf' => !empty($pdf['show_prices_in_pdf']) ? 1 : 0,
        'company_name' => (string)($pdf['company_name'] ?? 'Steel.ee'),
        'company_line1' => (string)($pdf['company_line1'] ?? ''),
        'company_line2' => (string)($pdf['company_line2'] ?? ''),
      ]
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
                <div class="spb-topbtns">
                  <button type="button" class="spb-mini spb-toggle-3d" aria-pressed="false">3D vaade</button>
                  <button type="button" class="spb-mini spb-reset-3d" style="display:none">Reset 3D</button>
                  <button type="button" class="spb-mini spb-save-svg">Salvesta SVG</button>
                  <?php if (!empty($cfg['pdf']['enable_print_btn'])): ?>
                    <button type="button" class="spb-mini spb-print">Print / PDF</button>
                  <?php endif; ?>
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
          .spb-front .spb-box-h-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
          .spb-front .spb-topbtns{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
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
            touch-action:none;
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
          .spb-front .spb-total strong{font-size:20px}

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
            function showErr(msg){ if(!err) return; err.style.display='block'; err.textContent=msg; }
            function hideErr(){ if(!err) return; err.style.display='none'; err.textContent=''; }

            if (!cfg.dims || !cfg.dims.length) {
              showErr('Sellel profiilil pole mõõte. Ava profiil adminis ja salvesta uuesti.');
              return;
            }

            // Accent (best effort)
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
            const svgWrap = root.querySelector('.spb-svg-wrap');

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

            // 3D drag state (affects pseudo extrusion + world rotate)
            const drag3d = {
              on:false,
              startX:0, startY:0,
              angX:0, angY:0,
              baseAngX:18, baseAngY:-18,
              zoom:1,
              dx:80, dy:-55
            };

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
                opt.value = String(m.key||'');
                opt.textContent = (m.label || m.key || '');
                opt.dataset.eur = toNum(m.eur_m2, 0);
                opt.dataset.tones = JSON.stringify(Array.isArray(m.tones)?m.tones:[]);
                opt.dataset.widths = JSON.stringify(Array.isArray(m.widths_mm)?m.widths_mm:[]);
                matSel.appendChild(opt);
              });
              if (matSel.options.length) matSel.selectedIndex = 0;
              renderTonesForMaterial();
            }

            function renderTonesForMaterial(){
              toneSel.innerHTML='';
              const opt = matSel.options[matSel.selectedIndex];
              const tones = opt ? (function(){ try{return JSON.parse(opt.dataset.tones||'[]');}catch(e){return [];} })() : [];
              const list = Array.isArray(tones) ? tones : [];
              if (!list.length) {
                const o = document.createElement('option');
                o.value = '';
                o.textContent = '—';
                toneSel.appendChild(o);
                toneSel.disabled = true;
              } else {
                toneSel.disabled = false;
                list.forEach(t=>{
                  const o = document.createElement('option');
                  o.value = String(t);
                  o.textContent = String(t);
                  toneSel.appendChild(o);
                });
                toneSel.selectedIndex = 0;
              }
            }

            function currentMaterialEurM2(){
              const opt = matSel.options[matSel.selectedIndex];
              return opt ? toNum(opt.dataset.eur,0) : 0;
            }
            function currentMaterialKey(){
              const opt = matSel.options[matSel.selectedIndex];
              return opt ? (opt.value || '') : '';
            }
            function currentMaterialLabel(){
              const opt = matSel.options[matSel.selectedIndex];
              return opt ? opt.textContent : '';
            }
            function currentTones(){
              const opt = matSel.options[matSel.selectedIndex];
              if (!opt) return [];
              try{ return JSON.parse(opt.dataset.tones||'[]'); }catch(e){ return []; }
            }
            function currentWidths(){
              const opt = matSel.options[matSel.selectedIndex];
              if (!opt) return [];
              try{ return JSON.parse(opt.dataset.widths||'[]'); }catch(e){ return []; }
            }
            function currentTone(){
              if (!toneSel || toneSel.disabled) return '';
              const opt = toneSel.options[toneSel.selectedIndex];
              return opt ? (opt.value || opt.textContent || '') : '';
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

              // collision variant B
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

            function applyViewTweak(extraRot){
              const v = getView();
              const rot = v.rot + (extraRot || 0);
              world.setAttribute('transform',
                `translate(${v.x} ${v.y}) translate(${CX} ${CY}) rotate(${rot}) scale(${v.scale}) translate(${-CX} ${-CY})`
              );
            }

            function applyPointViewTransform(px, py, view, extraRotDeg){
              let x = px - CX;
              let y = py - CY;

              x *= view.scale;
              y *= view.scale;

              const r = deg2rad((view.rot||0) + (extraRotDeg||0));
              const xr = x * Math.cos(r) - y * Math.sin(r);
              const yr = x * Math.sin(r) + y * Math.cos(r);

              x = xr + CX + view.x;
              y = yr + CY + view.y;

              return [x,y];
            }

            function calcBBoxFromPts(pts, view, extraRotDeg){
              let minX=Infinity, minY=Infinity, maxX=-Infinity, maxY=-Infinity;

              for (const p of pts) {
                if (!p) continue;
                const [tx, ty] = applyPointViewTransform(p[0], p[1], view, extraRotDeg);
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

            function renderDebug(extraRotDeg){
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

              // drag3d.dx/dy set by drag
              const DX = drag3d.dx;
              const DY = drag3d.dy;

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
              hideErr();
              if (toggle3dBtn){
                toggle3dBtn.setAttribute('aria-pressed', mode3d ? 'true' : 'false');
                toggle3dBtn.textContent = mode3d ? '2D vaade' : '3D vaade';
              }
              if (reset3dBtn) reset3dBtn.style.display = mode3d ? '' : 'none';
              if (g2d) g2d.style.display = mode3d ? 'none' : '';
              if (g3d) g3d.style.display = mode3d ? '' : 'none';
              render();
            }

            function reset3d(){
              drag3d.angX = 0; drag3d.angY = 0;
              drag3d.zoom = 1;
              drag3d.dx = 80; drag3d.dy = -55;
              render();
            }

            if (toggle3dBtn){
              toggle3dBtn.addEventListener('click', function(){
                setMode3d(!mode3d);
              });
            }
            if (reset3dBtn){
              reset3dBtn.addEventListener('click', reset3d);
            }

            // pointer drag (only in 3D)
            function onDown(e){
              if (!mode3d) return;
              drag3d.on = true;
              const p = (e.touches && e.touches[0]) ? e.touches[0] : e;
              drag3d.startX = p.clientX;
              drag3d.startY = p.clientY;
              e.preventDefault && e.preventDefault();
            }
            function onMove(e){
              if (!mode3d || !drag3d.on) return;
              const p = (e.touches && e.touches[0]) ? e.touches[0] : e;
              const dx = (p.clientX - drag3d.startX);
              const dy = (p.clientY - drag3d.startY);

              drag3d.angY = dx * 0.15;
              drag3d.angX = dy * 0.12;

              // map angles -> extrusion vector
              const ax = drag3d.baseAngX + drag3d.angX;
              const ay = drag3d.baseAngY + drag3d.angY;

              const rAy = deg2rad(ay);
              const rAx = deg2rad(ax);

              // keep magnitude near 95, project to 2D
              const mag = 95 * drag3d.zoom;
              drag3d.dx = Math.cos(rAy) * mag;
              drag3d.dy = Math.sin(rAx) * -mag * 0.65;

              render();
              e.preventDefault && e.preventDefault();
            }
            function onUp(){
              drag3d.on = false;
            }
            function onWheel(e){
              if (!mode3d) return;
              const delta = (e.deltaY || 0);
              drag3d.zoom = clamp(drag3d.zoom + (delta>0?-0.06:0.06), 0.6, 1.6);
              render();
              e.preventDefault && e.preventDefault();
            }

            if (svgWrap){
              svgWrap.addEventListener('mousedown', onDown);
              window.addEventListener('mousemove', onMove);
              window.addEventListener('mouseup', onUp);

              svgWrap.addEventListener('touchstart', onDown, {passive:false});
              window.addEventListener('touchmove', onMove, {passive:false});
              window.addEventListener('touchend', onUp);

              svgWrap.addEventListener('wheel', onWheel, {passive:false});
            }

            // ---------- totals (standard width selection) ----------
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

            function pickStandardWidth(needMm, widths){
              const w = Array.isArray(widths) ? widths.map(n=>Number(n)).filter(n=>Number.isFinite(n)&&n>0).sort((a,b)=>a-b) : [];
              if (!w.length) return {pick: needMm, ok:false, list:[]};

              for (const x of w){
                if (x >= needMm) return {pick:x, ok:true, list:w};
              }
              // if too big, use max, but ok=false
              return {pick: w[w.length-1], ok:false, list:w};
            }

            function calcTotals(){
              const sum = sumSmm();
              const allow = clamp(cfg.unfold_allow_mm, 0, 50);
              const need = sum + allow;

              const widths = currentWidths();
              const picked = pickStandardWidth(need, widths);

              const Pm = clamp(lenEl.value, 50, 8000) / 1000.0;
              const qty = clamp(qtyEl.value, 1, 999);

              // material buy area based on picked standard width
              const areaBuy = (picked.pick/1000.0) * Pm * qty;

              const matEur = currentMaterialEurM2();
              const matCost = areaBuy * matEur;

              // work
              const sumSm = sum / 1000.0;
              const jmWork = toNum(cfg.jm_work_eur_jm, 0);
              const jmPerM = toNum(cfg.jm_per_m_eur_jm, 0);
              const jmRate = jmWork + (sumSm * jmPerM);
              const workCost = (Pm * jmRate) * qty;

              const totalNoVat = matCost + workCost;
              const vatPct = toNum(cfg.vat, 24);
              const totalVat = totalNoVat * (1 + vatPct/100);

              return {
                sum_s_mm: sum,
                need_width_mm: need,
                stock_width_mm: picked.pick,
                pickOk: picked.ok,
                area_buy_m2: areaBuy,
                total_no_vat: totalNoVat,
                total_vat: totalVat,
                vat_pct: vatPct
              };
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
                color_tone: currentTone(),
                detail_length_mm: String(clamp(lenEl.value, 50, 8000)),
                qty: String(clamp(qtyEl.value, 1, 999)),
                need_width_mm: String(out.need_width_mm),
                stock_width_mm: String(out.stock_width_mm),
                area_buy_m2: String(out.area_buy_m2.toFixed(4)),
                price_total_no_vat: String(out.total_no_vat.toFixed(2)),
                price_total_vat: String(out.total_vat.toFixed(2)),
                vat_pct: String(out.vat_pct),
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

            function render(){
              hideErr();
              const dimMap = buildDimMap();

              fitWrap.setAttribute('transform', '');
              world.setAttribute('transform', '');

              const out = computePolyline(dimMap);

              const extraRot = mode3d ? (drag3d.angY * 0.2) : 0;

              if (!mode3d){
                renderSegments(out.pts, out.segStyle);
                renderDims(dimMap, out.pts);
                g3d.innerHTML = '';
              } else {
                dimLayer.innerHTML = '';
                segs.innerHTML = '';
                const three = render3D(out.pts, out.segStyle);

                applyViewTweak(extraRot);
                const v = getView();
                const ptsForBBox = out.pts.concat(three.backPts);
                lastBBox = calcBBoxFromPts(ptsForBBox, v, extraRot);
                applyAutoFit();
                renderDebug(extraRot);
              }

              if (!mode3d){
                applyViewTweak(0);
                const v = getView();
                lastBBox = calcBBoxFromPts(out.pts, v, 0);
                applyAutoFit();
                renderDebug(0);
              }

              const totals = calcTotals();
              if (novatEl) novatEl.textContent = totals.total_no_vat.toFixed(2) + ' €';
              if (vatEl) vatEl.textContent = totals.total_vat.toFixed(2) + ' €';

              if (tbMat) tbMat.textContent = currentMaterialLabel() || '—';
              if (tbTone) tbTone.textContent = currentTone() || '—';
              if (tbLen) tbLen.textContent = String(clamp(lenEl.value, 50, 8000)) + ' mm';
              if (tbQty) tbQty.textContent = String(clamp(qtyEl.value, 1, 999));
              if (tbPick) tbPick.textContent = String(totals.stock_width_mm) + ' mm';
              if (tbArea) tbArea.textContent = totals.area_buy_m2.toFixed(4) + ' m²';
            }

            // ---------- Save SVG ----------
            function serializeSvg(cleanForPrint){
              const clone = svg.cloneNode(true);
              // remove debug layer
              const dbg = clone.querySelector('.spb-debug');
              if (dbg) dbg.innerHTML = '';
              // if want clean: hide 3D or 2D depending on mode3d
              const c2d = clone.querySelector('.spb-2d');
              const c3d = clone.querySelector('.spb-3d');
              if (cleanForPrint){
                if (mode3d) { if (c2d) c2d.style.display = 'none'; if (c3d) c3d.style.display = ''; }
                else { if (c2d) c2d.style.display = ''; if (c3d) c3d.style.display = 'none'; }
              }
              clone.setAttribute('width','100%');
              clone.setAttribute('height','100%');
              return clone.outerHTML;
            }

            function saveSvg(){
              try{
                render(); // ensure latest
                const out = serializeSvg(true);
                const blob = new Blob([out], {type:'image/svg+xml;charset=utf-8'});
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const base = (cfg.profileName || 'steel-profile').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
                a.href = url;
                a.download = (base || 'steel-profile') + '.svg';
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
              }catch(e){
                showErr('SVG salvestamine ebaõnnestus.');
              }
            }

            if (saveSvgBtn) saveSvgBtn.addEventListener('click', saveSvg);

            // ---------- Print / PDF ----------
            function makeIdsForPdf(){
              const d = new Date();
              const dd = String(d.getDate()).padStart(2,'0');
              const mm = String(d.getMonth()+1).padStart(2,'0');
              const yy = String(d.getFullYear()).slice(-2);
              const hh = String(d.getHours()).padStart(2,'0');
              const mi = String(d.getMinutes()).padStart(2,'0');

              const mat = (currentMaterialKey() || 'MAT').toUpperCase().replace(/[^A-Z0-9]/g,'');
              const tone = (currentTone() || '').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,6);

              const drawingNo = `${mat}${tone?('-'+tone):''}-${dd}${mm}${yy}-${hh}${mi}`;
              const workNo = `${(cfg.profileName||'PROFILE').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,8)}-${dd}${mm}${yy}`;

              const date = `${dd}.${mm}.${String(d.getFullYear())}`;
              return { drawingNo, workNo, date };
            }

            function buildPrintHTML(svgMarkup, totals){
              const ids = makeIdsForPdf();

              const showPrices = !!(cfg.pdf && cfg.pdf.show_prices_in_pdf);

              const companyName = (cfg.pdf && cfg.pdf.company_name) ? String(cfg.pdf.company_name) : 'Steel.ee';
              const companyL1 = (cfg.pdf && cfg.pdf.company_line1) ? String(cfg.pdf.company_line1) : '';
              const companyL2 = (cfg.pdf && cfg.pdf.company_line2) ? String(cfg.pdf.company_line2) : '';

              const profile = (cfg.profileName || '—');
              const material = (currentMaterialLabel() || '—');
              const tone = (currentTone() || '—');
              const qty = String(clamp(qtyEl.value, 1, 999));
              const len = String(clamp(lenEl.value, 50, 8000));

              const needW = String(totals.need_width_mm);
              const stockW = String(totals.stock_width_mm);
              const area = totals.area_buy_m2.toFixed(4);

              const priceNoVat = totals.total_no_vat.toFixed(2);
              const priceVat = totals.total_vat.toFixed(2);
              const vatPct = totals.vat_pct;

              return `
<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>${companyName} – ${profile}</title>
<style>
  @page { size: A4; margin: 12mm; }
  html,body{margin:0;padding:0;font-family: Arial, Helvetica, sans-serif;color:#111;}
  .page{border:1px solid #111; padding:10mm; box-sizing:border-box; min-height: 273mm;}
  .top{display:flex;justify-content:space-between;gap:10mm; align-items:flex-start;}
  .brand{font-weight:800;font-size:22px; letter-spacing:.5px;}
  .brandSub{margin-top:4px;font-size:12px;opacity:.75;line-height:1.3}
  .box{border:1px solid #111; padding:6mm; min-width: 72mm;}
  .box h3{margin:0 0 4mm 0;font-size:14px;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:2mm 6mm;font-size:12px;}
  .grid div{display:flex;justify-content:space-between;gap:8mm}
  .grid b{font-weight:700}
  .drawing{margin-top:10mm; border:1px solid #111; height: 150mm; display:flex;align-items:center;justify-content:center; overflow:hidden;}
  .drawing svg{width:100%;height:100%;}
  .footer{margin-top:10mm; display:grid; grid-template-columns: 1fr 1fr; gap:8mm;}
  .footer .box{min-width:auto}
  .prices{display:grid;gap:2mm;font-size:12px;}
  .prices div{display:flex;justify-content:space-between;gap:6mm}
  .prices .big b{font-size:14px}
</style>
</head>
<body>
  <div class="page">
    <div class="top">
      <div>
        <div class="brand">${companyName}</div>
        <div class="brandSub">${companyL1 ? companyL1 : ''}<br>${companyL2 ? companyL2 : ''}</div>
      </div>

      <div class="box">
        <h3>${profile}</h3>
        <div class="grid">
          <div><span>Valmistamise arv</span><b>${qty} tk</b></div>
          <div><span>Pikkus</span><b>${len} mm</b></div>
          <div><span>Materjal</span><b>${material}</b></div>
          <div><span>Värvitoon</span><b>${tone}</b></div>
          <div><span>Vaj. laius</span><b>${needW} mm</b></div>
          <div><span>Standardlaius</span><b>${stockW} mm</b></div>
          <div><span>Materjalikulu</span><b>${area} m²</b></div>
        </div>
      </div>
    </div>

    <div class="drawing">
      ${svgMarkup}
    </div>

    <div class="footer">
      <div class="box">
        <div class="grid">
          <div><span>Drawing no.</span><b>${ids.drawingNo}</b></div>
          <div><span>Work no.</span><b>${ids.workNo}</b></div>
          <div><span>Date</span><b>${ids.date}</b></div>
          <div><span>Scale</span><b>auto</b></div>
        </div>
      </div>

      ${showPrices ? `
      <div class="box">
        <div class="prices">
          <div class="big"><span>Kokku (ilma KM)</span><b>${priceNoVat} €</b></div>
          <div class="big"><span>Kokku (koos KM)</span><b>${priceVat} €</b></div>
          <div><span>KM %</span><b>${vatPct}</b></div>
        </div>
      </div>
      ` : `<div></div>`}
    </div>
  </div>

<script>
  window.onload = function(){
    // slight delay improves print reliability
    setTimeout(function(){ window.focus(); window.print(); }, 250);
  };
</script>
</body>
</html>`;
            }

            function printPdf(){
              try{
                hideErr();

                // Always print clean 2D view (technical)
                const was3d = mode3d;
                if (was3d) setMode3d(false);
                render();

                const totals = calcTotals();
                const svgMarkup = serializeSvg(true);

                const w = window.open('', '_blank');
                if (!w) {
                  showErr('Print/PDF: pop-up blokk. Luba pop-up ja proovi uuesti.');
                  if (was3d) setMode3d(true);
                  return;
                }
                const html = buildPrintHTML(svgMarkup, totals);
                w.document.open();
                w.document.write(html);
                w.document.close();

                if (was3d) setMode3d(true);
              }catch(e){
                showErr('Print/PDF ebaõnnestus.');
              }
            }

            if (printBtn) printBtn.addEventListener('click', printPdf);

            // inputs
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

            if (openBtn) openBtn.addEventListener('click', function(){
              render();
              if (formWrap) {
                fillWpforms();
                formWrap.style.display='block';
                formWrap.scrollIntoView({behavior:'smooth', block:'start'});
              }
            });

            // init
            function init(){
              setTitleBlock();
              renderDimInputs();
              renderMaterials();
              setMode3d(false);
              reset3d();
              render();
            }
            init();
          })();
        </script>
      </div>
    <?php
    return ob_get_clean();
  }
}

new Steel_Profile_Builder();
