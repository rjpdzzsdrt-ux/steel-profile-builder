<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Profiilikalkulaator (SVG 2D/3D + mõõdud) + admin profiilid + hinnastus (standardlaiuste ümardus) + PDF print layout + WPForms.
 * Version: 0.4.22
 * Author: Steel.ee
 */

if (!defined('ABSPATH')) exit;

class Steel_Profile_Builder {
  const CPT = 'spb_profile';
  const VER = '0.4.22';

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_action('save_post', [$this, 'save_meta'], 10, 2);
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
    add_meta_box('spb_preview', 'Joonise eelvaade', [$this, 'mb_preview'], self::CPT, 'normal', 'high');
    add_meta_box('spb_dims', 'Mõõdud', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_view', 'Vaate seaded (pööramine)', [$this, 'mb_view'], self::CPT, 'side', 'default');
    add_meta_box('spb_pricing', 'Hinnastus (standardlaius + töö + KM)', [$this, 'mb_pricing'], self::CPT, 'side', 'default');
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
      'jm_work_eur_jm' => 0.00,      // töö €/jm
      'jm_per_m_eur_jm' => 0.00,     // lisakomponent €/jm per Σs meetrites
      'materials' => [
        ['key'=>'POL','label'=>'POL','eur_m2'=>7.5],
        ['key'=>'PUR','label'=>'PUR','eur_m2'=>8.5],
        ['key'=>'PUR_MATT','label'=>'PUR Matt','eur_m2'=>11.5],
        ['key'=>'TSINK','label'=>'Tsink','eur_m2'=>6.5],
      ],
      // Materjali lisaseaded eraldi JSON mapping (key => {tones:[], widths_mm:[]})
      'material_meta_json' => '',
      // optional: lisa mm laotuslaiusele (kui vaja)
      'unfold_allow_mm' => 0,
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
        'stock_width_mm' => 0,
        'need_width_mm' => 0,
        'area_buy_m2' => 0,
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
      'show_btn' => 1,
      'show_prices_box' => 1,
      'show_material_cost' => 1, // sa ütlesid: võib PDF-il materjalikulu välja tuua
      'show_svg_btn' => 1,
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

    <p style="opacity:.8;margin-bottom:6px"><strong>NB:</strong> Selle versiooni adminis sisestad mõõdud JSON-ina (stabiilsem).</p>

    <textarea name="spb_dims_json" style="width:100%;min-height:220px;"><?php echo esc_textarea(wp_json_encode($dims, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>

    <p style="opacity:.7;margin-top:8px">Soovi korral saad mõõte hiljem uuesti teha tabeli-UI-ga, aga nii on kindel, et “midagi ei kao ära”.</p>
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
    $allow = intval($pricing['unfold_allow_mm'] ?? 0);
    $materials = is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'];
    $meta_json = (string)($pricing['material_meta_json'] ?? '');
    ?>
    <p style="margin-top:0;opacity:.8">
      Koguhind (ilma KM) = <strong>materjalikulu (standardlaius) + töö</strong><br>
      Materjalikulu arvestus: <strong>W_need → ümardus järgmisele standardlaiusele W_stock</strong> (nt 190 → 208).
    </p>

    <p><label>KM %<br>
      <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($vat); ?>" style="width:100%;">
    </label></p>

    <p><label>JM töö (€/jm)<br>
      <input type="number" step="0.01" name="spb_jm_work_eur_jm" value="<?php echo esc_attr($jm_work); ?>" style="width:100%;">
    </label></p>

    <p><label>Lisakomponent (€/jm per Σs meetrit)<br>
      <input type="number" step="0.01" name="spb_jm_per_m_eur_jm" value="<?php echo esc_attr($jm_per_m); ?>" style="width:100%;">
    </label></p>

    <p><label>Laotus lisa (mm) (valikuline)<br>
      <input type="number" step="1" name="spb_unfold_allow_mm" value="<?php echo esc_attr($allow); ?>" style="width:100%;">
    </label></p>

    <p style="margin:10px 0 6px;"><strong>Materjalid (€/m²)</strong></p>
    <textarea name="spb_materials_json" style="width:100%;min-height:150px;"><?php echo esc_textarea(wp_json_encode($materials, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>

    <p style="margin:10px 0 6px;"><strong>Materjalide toonid + standardlaiused (JSON)</strong></p>
    <p style="opacity:.75;margin-top:0">
      Vorm: <code>{"POL":{"tones":["RR2H3","RR11"],"widths_mm":[208,250,333]}}</code><br>
      Kui widths_mm puudub → arvestus käib W_need järgi (ei ümarda).
    </p>
    <textarea name="spb_material_meta_json" style="width:100%;min-height:160px;" placeholder='{"POL":{"tones":["RR2H3"],"widths_mm":[208,250]}}'><?php echo esc_textarea($meta_json); ?></textarea>
    <?php
  }

  public function mb_pdf($post) {
    $m = $this->get_meta($post->ID);
    $pdf = (is_array($m['pdf']) && $m['pdf']) ? array_merge($this->default_pdf(), $m['pdf']) : $this->default_pdf();
    ?>
    <p style="margin-top:0;opacity:.8">
      Profiilipõhiselt saad otsustada, kas klient näeb PDF/Print nuppu ja kas PDF-il on hinnakast.
    </p>

    <p>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="spb_pdf_show_btn" value="1" <?php checked(!empty($pdf['show_btn']), 1); ?>>
        <span>Näita “Print/PDF” nuppu frontendis</span>
      </label>
    </p>

    <p>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="spb_pdf_show_svg_btn" value="1" <?php checked(!empty($pdf['show_svg_btn']), 1); ?>>
        <span>Näita “Salvesta SVG” nuppu frontendis</span>
      </label>
    </p>

    <p>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="spb_pdf_show_prices_box" value="1" <?php checked(!empty($pdf['show_prices_box']), 1); ?>>
        <span>PDF-il näita “Hind” kasti</span>
      </label>
    </p>

    <p>
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" name="spb_pdf_show_material_cost" value="1" <?php checked(!empty($pdf['show_material_cost']), 1); ?>>
        <span>PDF-il näita “Materjalikulu” eraldi real</span>
      </label>
    </p>
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
      'stock_width_mm' => 'Arvestuslaius W_stock (mm)',
      'need_width_mm' => 'Vajalik laius W_need (mm)',
      'area_buy_m2' => 'Materjali pindala m² (arvestus)',
      'price_total_no_vat' => 'Kokku ilma KM',
      'price_total_vat' => 'Kokku koos KM',
      'vat_pct' => 'KM %',
    ];
    ?>
    <p style="margin-top:0;opacity:.8">Pane WPForms vormi ID ja field ID-d, kuhu kalkulaator kirjutab väärtused.</p>
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

    // dims (textarea JSON)
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
    $p = $this->default_pricing();
    $p['vat'] = floatval($_POST['spb_vat'] ?? 24);
    $p['jm_work_eur_jm'] = floatval($_POST['spb_jm_work_eur_jm'] ?? 0);
    $p['jm_per_m_eur_jm'] = floatval($_POST['spb_jm_per_m_eur_jm'] ?? 0);
    $p['unfold_allow_mm'] = intval($_POST['spb_unfold_allow_mm'] ?? 0);

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
    $p['materials'] = $materials_out ?: $this->default_pricing()['materials'];

    $meta_json = wp_unslash($_POST['spb_material_meta_json'] ?? '');
    $p['material_meta_json'] = is_string($meta_json) ? trim($meta_json) : '';

    update_post_meta($post_id, '_spb_pricing', $p);

    // pdf settings
    $pdf = $this->default_pdf();
    $pdf['show_btn'] = !empty($_POST['spb_pdf_show_btn']) ? 1 : 0;
    $pdf['show_svg_btn'] = !empty($_POST['spb_pdf_show_svg_btn']) ? 1 : 0;
    $pdf['show_prices_box'] = !empty($_POST['spb_pdf_show_prices_box']) ? 1 : 0;
    $pdf['show_material_cost'] = !empty($_POST['spb_pdf_show_material_cost']) ? 1 : 0;
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
      'material_meta_json' => (string)($pricing['material_meta_json'] ?? ''),
      'wpforms' => [
        'form_id' => intval($wp['form_id'] ?? 0),
        'map' => is_array($wp['map'] ?? null) ? $wp['map'] : $this->default_wpforms()['map'],
      ],
      'pdf' => [
        'show_btn' => !empty($pdf['show_btn']) ? 1 : 0,
        'show_svg_btn' => !empty($pdf['show_svg_btn']) ? 1 : 0,
        'show_prices_box' => !empty($pdf['show_prices_box']) ? 1 : 0,
        'show_material_cost' => !empty($pdf['show_material_cost']) ? 1 : 0,
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
        <div class="spb-card">
          <div class="spb-title"><?php echo esc_html(get_the_title($id)); ?></div>
          <div class="spb-error" style="display:none"></div>

          <div class="spb-grid">
            <div class="spb-box">
              <div class="spb-box-h spb-box-h-row">
                <span>Joonis</span>
                <div class="spb-actions">
                  <button type="button" class="spb-mini spb-toggle-3d" aria-pressed="false">3D vaade</button>
                  <button type="button" class="spb-mini spb-reset-3d" style="display:none">Reset 3D</button>

                  <?php if (!empty($cfg['pdf']['show_svg_btn'])): ?>
                    <button type="button" class="spb-mini spb-save-svg">Salvesta SVG</button>
                  <?php endif; ?>

                  <?php if (!empty($cfg['pdf']['show_btn'])): ?>
                    <button type="button" class="spb-mini spb-print-pdf">Print/PDF</button>
                  <?php endif; ?>
                </div>
              </div>

              <div class="spb-draw">
                <div class="spb-svg-wrap">
                  <svg class="spb-svg" viewBox="0 0 820 460" preserveAspectRatio="xMidYMid meet" width="100%" height="100%">
                    <defs class="spb-defs">
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
              </div>
            </div>

            <div class="spb-box">
              <div class="spb-box-h">Mõõdud</div>
              <div class="spb-inputs"></div>
              <div class="spb-note">Sisesta mm / kraadid. Suund ja poolsus tulevad profiili seadetest.</div>
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
          .spb-front .spb-card{border:1px solid #eaeaea;border-radius:18px;padding:18px;background:#fff}
          .spb-front .spb-title{font-size:18px;font-weight:800;margin-bottom:12px}
          .spb-front .spb-error{margin:12px 0;padding:10px;border:1px solid #ffb4b4;background:#fff1f1;border-radius:12px}

          .spb-front .spb-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;align-items:start}
          .spb-front .spb-box{border:1px solid #eee;border-radius:16px;padding:14px;background:#fff}
          .spb-front .spb-box-h{font-weight:800;margin:0 0 10px 0}
          .spb-front .spb-box-h-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
          .spb-front .spb-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}

          .spb-front .spb-mini{
            border:1px solid #ddd;background:#fff;border-radius:999px;
            padding:6px 10px;font-weight:800;cursor:pointer;
            font-size:12px;line-height:1;white-space:nowrap;
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
          .spb-front .spb-svg-wrap.spb-is-3d{cursor:grab}
          .spb-front .spb-svg-wrap.spb-is-3d.spb-grabbing{cursor:grabbing}

          .spb-front .spb-svg{max-width:100%;max-height:100%}
          .spb-front .spb-segs line{stroke:#111;stroke-width:3}
          .spb-front .spb-dimlayer text{
            font-size:13px;fill:#111;dominant-baseline:middle;text-anchor:middle;
            paint-order: stroke; stroke: #fff; stroke-width: 4;
          }
          .spb-front .spb-dimlayer line{stroke:#111}
          .spb-front .spb-3d polygon{stroke:#bdbdbd}
          .spb-front .spb-3d line{stroke-linecap:round}

          .spb-front .spb-inputs{display:grid;grid-template-columns:1fr 170px;gap:10px;align-items:center}
          .spb-front input,.spb-front select{
            width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:12px;outline:none;
          }
          .spb-front input:focus,.spb-front select:focus{border-color:#bbb}
          .spb-front .spb-note{margin-top:10px;opacity:.65;font-size:13px;line-height:1.4}

          .spb-front .spb-order{margin-top:18px}
          .spb-front .spb-row3{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;align-items:end}
          .spb-front .spb-row label{display:block;font-weight:700;margin:0 0 6px 0;font-size:13px;opacity:.85}

          .spb-front .spb-results{margin-top:14px;border-top:1px solid #eee;padding-top:12px;display:grid;gap:8px}
          .spb-front .spb-results > div{display:flex;justify-content:space-between;gap:12px}
          .spb-front .spb-results strong{font-size:18px}
          .spb-front .spb-total strong{font-size:18px}

          .spb-front .spb-btn{
            width:100%;margin-top:12px;padding:12px 14px;border-radius:14px;border:0;cursor:pointer;
            font-weight:900;background:var(--spb-accent);color:#fff;
          }
          .spb-front .spb-foot{margin-top:10px;opacity:.6;font-size:13px}

          @media (max-width: 980px){
            .spb-front .spb-grid{grid-template-columns:1fr}
            .spb-front .spb-svg-wrap{height:360px}
            .spb-front .spb-row3{grid-template-columns:1fr}
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

            // Accent color from Elementor main button (best effort)
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
            const printBtn = root.querySelector('.spb-print-pdf');

            const fitWrap = root.querySelector('.spb-fit');
            const world = root.querySelector('.spb-world');

            const g2d = root.querySelector('.spb-2d');
            const segs = root.querySelector('.spb-segs');
            const dimLayer = root.querySelector('.spb-dimlayer');
            const g3d = root.querySelector('.spb-3d');
            const debugLayer = root.querySelector('.spb-debug');

            const svgWrap = root.querySelector('.spb-svg-wrap');
            const svg = root.querySelector('.spb-svg');

            const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

            const VB_W = 820, VB_H = 460;
            const CX = 410, CY = 230;
            let lastBBox = null;

            const formWrap = root.querySelector('.spb-form-wrap');
            const openBtn = root.querySelector('.spb-open-form');

            const stateVal = {};
            let mode3d = false;

            // 3D camera
            const cam = { rotX:-22, rotY:28, persp:900, depth:120, zoom:1.0 };
            let dragging=false, lastX=0, lastY=0;

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

            function rotX(p, a){
              const r = deg2rad(a), c=Math.cos(r), s=Math.sin(r);
              return {x:p.x, y:p.y*c - p.z*s, z:p.y*s + p.z*c};
            }
            function rotY(p, a){
              const r = deg2rad(a), c=Math.cos(r), s=Math.sin(r);
              return {x:p.x*c + p.z*s, y:p.y, z:-p.x*s + p.z*c};
            }
            function project(p){
              const z = (cam.persp + p.z);
              const k = cam.persp / Math.max(50, z);
              return {x:p.x*k, y:p.y*k, k};
            }

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

            function parseMetaJson(){
              const s = (cfg.material_meta_json || '').trim();
              if (!s) return {};
              try{ const o = JSON.parse(s); return (o && typeof o === 'object') ? o : {}; }
              catch(e){ return {}; }
            }
            const matMeta = parseMetaJson();

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
              renderTonesForMaterial();
            }
            function currentMaterialKey(){
              const opt = matSel.options[matSel.selectedIndex];
              return opt ? opt.value : '';
            }
            function currentMaterialLabel(){
              const opt = matSel.options[matSel.selectedIndex];
              return opt ? opt.textContent : '';
            }
            function currentMaterialEurM2(){
              const opt = matSel.options[matSel.selectedIndex];
              return opt ? toNum(opt.dataset.eur,0) : 0;
            }

            function renderTonesForMaterial(){
              const key = currentMaterialKey();
              const meta = (matMeta && matMeta[key]) ? matMeta[key] : null;
              const tones = (meta && Array.isArray(meta.tones)) ? meta.tones : [];
              toneSel.innerHTML = '';
              if (tones.length){
                tones.forEach(t=>{
                  const o = document.createElement('option');
                  o.value = String(t);
                  o.textContent = String(t);
                  toneSel.appendChild(o);
                });
                toneSel.selectedIndex = 0;
                toneSel.disabled = false;
              } else {
                const o = document.createElement('option');
                o.value = '';
                o.textContent = '—';
                toneSel.appendChild(o);
                toneSel.selectedIndex = 0;
                toneSel.disabled = true;
              }
            }
            function currentTone(){
              const opt = toneSel.options[toneSel.selectedIndex];
              return opt ? opt.value : '';
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

            function sumSmm(){
              let sum=0;
              cfg.dims.forEach(d=>{
                if (d.type !== 'length') return;
                const min = (d.min ?? 10);
                const max = (d.max ?? 500);
                sum += clamp(stateVal[d.key], min, max);
              });
              return sum;
            }

            function pickStockWidth(needMm){
              const key = currentMaterialKey();
              const meta = (matMeta && matMeta[key]) ? matMeta[key] : null;
              const widths = (meta && Array.isArray(meta.widths_mm)) ? meta.widths_mm.map(x=>Number(x)).filter(n=>Number.isFinite(n) && n>0) : [];
              widths.sort((a,b)=>a-b);
              if (!widths.length) return { stockMm: needMm, ok:true, widths:[] };
              for (const w of widths){
                if (w >= needMm) return { stockMm: w, ok:true, widths };
              }
              return { stockMm: widths[widths.length-1], ok:false, widths };
            }

            function calc(){
              const sumS = sumSmm();
              const allow = Number(cfg.unfold_allow_mm || 0);
              const needW = Math.max(0, sumS + allow);

              const pick = pickStockWidth(needW);
              const stockW = pick.stockMm;

              const Pm = clamp(lenEl.value, 50, 8000) / 1000.0;
              const qty = clamp(qtyEl.value, 1, 999);

              const areaBuy = (stockW/1000.0) * Pm * qty;
              const matCost = areaBuy * currentMaterialEurM2();

              const sumSm = sumS / 1000.0;
              const jmWork = toNum(cfg.jm_work_eur_jm, 0);
              const jmPerM = toNum(cfg.jm_per_m_eur_jm, 0);
              const jmRate = jmWork + (sumSm * jmPerM);
              const workCost = (Pm * jmRate) * qty;

              const totalNoVat = matCost + workCost;
              const vatPct = toNum(cfg.vat, 24);
              const totalVat = totalNoVat * (1 + vatPct/100);

              return { sumS, needW, stockW, areaBuy, matCost, workCost, totalNoVat, totalVat, vatPct, pickOk: pick.ok };
            }

            function dimsPayloadJSON(){
              return JSON.stringify(cfg.dims.map(d=>{
                const o = { key:d.key, type:d.type, label:(d.label||d.key), value:stateVal[d.key] };
                if (d.type==='angle') { o.dir = d.dir || 'L'; o.pol = d.pol || 'inner'; o.ret = !!d.ret; }
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
                color_tone: currentTone(),
                detail_length_mm: String(clamp(lenEl.value, 50, 8000)),
                qty: String(clamp(qtyEl.value, 1, 999)),
                stock_width_mm: String(out.stockW),
                need_width_mm: String(out.needW),
                area_buy_m2: String(out.areaBuy.toFixed(4)),
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

            // ---------- 3D render ----------
            function render3D(pts, segStyle){
              g3d.innerHTML = '';

              function camTransform(p){
                let t = {x:p.x*cam.zoom, y:p.y*cam.zoom, z:p.z*cam.zoom};
                t = rotX(t, cam.rotX);
                t = rotY(t, cam.rotY);
                return t;
              }
              function rotX(p,a){
                const r=deg2rad(a), c=Math.cos(r), s=Math.sin(r);
                return {x:p.x, y:p.y*c - p.z*s, z:p.y*s + p.z*c};
              }
              function rotY(p,a){
                const r=deg2rad(a), c=Math.cos(r), s=Math.sin(r);
                return {x:p.x*c + p.z*s, y:p.y, z:-p.x*s + p.z*c};
              }

              // local points around center
              const P = pts.map(p => ({x:(p[0]-CX), y:(p[1]-CY), z:0}));
              const F = P.map(p => ({x:p.x, y:p.y, z:0}));
              const B = P.map(p => ({x:p.x, y:p.y, z:cam.depth}));

              function projToSvg(p){
                const t = camTransform(p);
                const pr = project(t);
                return {x: pr.x + CX, y: pr.y + CY, z: t.z, k: pr.k};
              }

              const Fp = F.map(projToSvg);
              const Bp = B.map(projToSvg);

              const faces = [];
              for (let i=0;i<Fp.length-1;i++){
                const a = Fp[i], b = Fp[i+1], c = Bp[i+1], d = Bp[i];
                const zAvg = (a.z+b.z+c.z+d.z)/4;
                const style = segStyle[i] || 'main';
                faces.push({a,b,c,d,zAvg,style});
              }
              faces.sort((u,v)=>u.zAvg - v.zAvg);

              faces.forEach(f=>{
                const poly = svgEl('polygon');
                poly.setAttribute('points', `${f.a.x},${f.a.y} ${f.b.x},${f.b.y} ${f.c.x},${f.c.y} ${f.d.x},${f.d.y}`);
                poly.setAttribute('fill', '#e5e5e5');
                poly.setAttribute('opacity', '0.96');
                poly.setAttribute('stroke', '#bdbdbd');
                poly.setAttribute('stroke-width', '1');
                g3d.appendChild(poly);
              });

              // outlines
              for (let i=0;i<Fp.length-1;i++){
                const a = Fp[i], b = Fp[i+1];
                const style = segStyle[i] || 'main';
                const l = svgEl('line');
                l.setAttribute('x1',a.x); l.setAttribute('y1',a.y);
                l.setAttribute('x2',b.x); l.setAttribute('y2',b.y);
                l.setAttribute('stroke','#111'); l.setAttribute('stroke-width','3');
                if (style==='return') l.setAttribute('stroke-dasharray','6 6');
                g3d.appendChild(l);
              }
              // bbox include back points
              const allPts = [];
              Fp.forEach(p=>allPts.push([p.x,p.y]));
              Bp.forEach(p=>allPts.push([p.x,p.y]));
              const v = getView();
              lastBBox = calcBBoxFromPts(allPts, v);
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
              if (svgWrap){
                if (mode3d) svgWrap.classList.add('spb-is-3d');
                else svgWrap.classList.remove('spb-is-3d');
              }
              render();
            }

            function reset3d(){
              cam.rotX=-22; cam.rotY=28; cam.zoom=1.0; cam.depth=120; cam.persp=900;
              render();
            }

            function getPoint(e){
              if (e.touches && e.touches[0]) return {x:e.touches[0].clientX, y:e.touches[0].clientY};
              return {x:e.clientX, y:e.clientY};
            }

            function onDown(e){
              if (!mode3d) return;
              dragging = true;
              const p = getPoint(e);
              lastX=p.x; lastY=p.y;
              svgWrap.classList.add('spb-grabbing');
              e.preventDefault();
            }
            function onMove(e){
              if (!mode3d || !dragging) return;
              const p = getPoint(e);
              const dx = p.x - lastX;
              const dy = p.y - lastY;
              lastX=p.x; lastY=p.y;
              cam.rotY += dx * 0.25;
              cam.rotX += dy * 0.25;
              cam.rotX = clamp(cam.rotX, -85, 85);
              render();
              e.preventDefault();
            }
            function onUp(){
              dragging = false;
              if (svgWrap) svgWrap.classList.remove('spb-grabbing');
            }
            function onWheel(e){
              if (!mode3d) return;
              const delta = Math.sign(e.deltaY);
              cam.zoom *= (delta > 0 ? 0.92 : 1.08);
              cam.zoom = clamp(cam.zoom, 0.55, 2.2);
              render();
              e.preventDefault();
            }

            function serializeSvg(){
              // Always export 2D
              const was3d = mode3d;
              if (was3d) setMode3d(false);
              render();

              const clone = svg.cloneNode(true);
              clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
              const style = document.createElementNS('http://www.w3.org/2000/svg', 'style');
              style.textContent = `
                .spb-segs line{stroke:#111;stroke-width:3}
                .spb-dimlayer text{font-size:13px;fill:#111;dominant-baseline:middle;text-anchor:middle;paint-order:stroke;stroke:#fff;stroke-width:4}
                .spb-dimlayer line{stroke:#111}
              `;
              clone.insertBefore(style, clone.firstChild);

              const out = new XMLSerializer().serializeToString(clone);
              if (was3d) setMode3d(true);
              return out;
            }

            function saveSvg(){
              try{
                hideErr();
                const data = serializeSvg();
                const blob = new Blob([data], {type:'image/svg+xml;charset=utf-8'});
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const safeName = (cfg.profileName || 'profiil').toLowerCase().replace(/[^a-z0-9\-]+/g,'-');
                a.href = url;
                a.download = `steel-profile-${safeName}.svg`;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(()=>URL.revokeObjectURL(url), 1500);
              }catch(e){
                showErr('SVG salvestamine ebaõnnestus.');
              }
            }

            function buildPdfHtml(svgData, out){
              const name = (cfg.profileName || '—');
              const mat = currentMaterialLabel() || '—';
              const tone = currentTone() || '—';
              const lenMm = String(clamp(lenEl.value,50,8000));
              const qty = String(clamp(qtyEl.value,1,999));
              const date = (function(){
                const d=new Date();
                const dd=String(d.getDate()).padStart(2,'0');
                const mm=String(d.getMonth()+1).padStart(2,'0');
                const yy=String(d.getFullYear());
                return dd+'.'+mm+'.'+yy;
              })();

              const timeShort = (function(){
                const d=new Date();
                const hh=String(d.getHours()).padStart(2,'0');
                const mi=String(d.getMinutes()).padStart(2,'0');
                const ss=String(d.getSeconds()).padStart(2,'0');
                return hh+mi+ss;
              })();

              // Drawing no: material + date + time
              const drawingNo = (mat.replace(/\s+/g,'').slice(0,6).toUpperCase()) + '-' + date.replace(/\./g,'') + '-' + timeShort;

              // Work no: profile + date + (simple counter per day in localStorage)
              const workKey = 'spb_workno_' + date;
              let c = 0;
              try{ c = Number(localStorage.getItem(workKey) || 0); c++; localStorage.setItem(workKey, String(c)); }catch(e){}
              const profShort = name.toUpperCase().replace(/[^A-Z0-9]+/g,'').slice(0,6) || 'WORK';
              const workNo = profShort + '-' + date.replace(/\./g,'') + '-' + String(c).padStart(3,'0');

              const showPrices = !!(cfg.pdf && Number(cfg.pdf.show_prices_box||0));
              const showMatCost = !!(cfg.pdf && Number(cfg.pdf.show_material_cost||0));

              const matCostLine = showMatCost ? `<div class="row"><span>Materjalikulu</span><strong>${out.matCost.toFixed(2)} €</strong></div>` : ``;

              const pricesBox = showPrices ? `
                <div class="pricebox">
                  ${matCostLine}
                  <div class="row"><span>Töö</span><strong>${out.workCost.toFixed(2)} €</strong></div>
                  <div class="hr"></div>
                  <div class="row"><span>Kokku (ilma KM)</span><strong>${out.totalNoVat.toFixed(2)} €</strong></div>
                  <div class="row"><span>Kokku (KM-ga)</span><strong>${out.totalVat.toFixed(2)} €</strong></div>
                </div>` : ``;

              return `
<!doctype html><html><head><meta charset="utf-8">
<title>${name}</title>
<style>
@page { size: A4 portrait; margin: 0; }
html,body{height:100%; margin:0; padding:0; background:#fff;}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:#111;}
.sheet{ width:210mm; height:297mm; box-sizing:border-box; padding:10mm; }
.frame{ width:100%; height:100%; border:1.4px solid #111; box-sizing:border-box; position:relative; padding:8mm; }
.top-right{
  position:absolute; right:8mm; top:8mm;
  border:1px solid #111; padding:4mm 5mm; width:70mm;
  font-size:11px; line-height:1.35;
}
.top-right .title{font-weight:800; font-size:12px; margin-bottom:2mm;}
.top-right .row{display:flex; justify-content:space-between; gap:6mm;}
.top-right .row span{opacity:.7}

.drawing-area{
  position:absolute;
  left:8mm; right:8mm;
  top:32mm;
  bottom:42mm;
  display:flex; align-items:center; justify-content:center;
}
.drawing-area .svgbox{ width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
.drawing-area svg{ width:100%; height:100%; }

.bottom{
  position:absolute; left:0; right:0; bottom:0;
  height:40mm;
  border-top:1.4px solid #111;
  display:grid;
  grid-template-columns: 1fr 1fr 1fr;
}
.bottom .cell{
  padding:6mm 6mm;
  border-right:1px solid #111;
  font-size:11px;
  line-height:1.35;
  box-sizing:border-box;
}
.bottom .cell:last-child{border-right:0;}
.bottom .big{font-size:13px; font-weight:900}
.bottom .muted{opacity:.75}

.pricebox{
  position:absolute;
  right:8mm;
  bottom:44mm;
  border:1px solid #111;
  padding:3mm 4mm;
  width:70mm;
  font-size:11px;
  line-height:1.4;
}
.pricebox .row{display:flex; justify-content:space-between; gap:6mm;}
.pricebox .row strong{font-size:11.5px;}
.pricebox .hr{border-top:1px solid #111; margin:2mm 0;}
</style>
</head><body>
<div class="sheet">
  <div class="frame">

    <div class="top-right">
      <div class="title">${name}</div>
      <div class="row"><span>Valmistamise arv</span><strong>${qty} tk</strong></div>
      <div class="row"><span>Pikkus</span><strong>${lenMm} mm</strong></div>
      <div class="row"><span>Materjal</span><strong>${mat}</strong></div>
      <div class="row"><span>Värvitoon</span><strong>${tone}</strong></div>
      <div class="row"><span>W_need</span><strong>${out.needW} mm</strong></div>
      <div class="row"><span>W_stock</span><strong>${out.stockW} mm</strong></div>
    </div>

    <div class="drawing-area">
      <div class="svgbox">${svgData}</div>
    </div>

    ${pricesBox}

    <div class="bottom">
      <div class="cell">
        <div class="big">Steel.ee</div>
        <div class="muted">Tootmine / plekitööd</div>
        <div class="muted">info@steel.ee</div>
      </div>
      <div class="cell">
        <div class="muted">Drawing no.</div>
        <div class="big">${drawingNo}</div>
        <div class="muted">Scale</div>
        <div class="big">auto</div>
      </div>
      <div class="cell">
        <div class="muted">Date</div>
        <div class="big">${date}</div>
        <div class="muted">Work no.</div>
        <div class="big">${workNo}</div>
      </div>
    </div>

  </div>
</div>
<script>
  window.focus();
  setTimeout(()=>{ window.print(); }, 250);
</script>
</body></html>`;
            }

            function printPdf(){
              try{
                hideErr();
                // Always print 2D clean
                const was3d = mode3d;
                if (was3d) setMode3d(false);

                render();

                const svgData = serializeSvg();
                const out = calc();

                const w = window.open('', '_blank');
                if (!w) { showErr('Print/PDF: pop-up blokk. Luba pop-up ja proovi uuesti.'); if (was3d) setMode3d(true); return; }

                const html = buildPdfHtml(svgData, out);
                w.document.open(); w.document.write(html); w.document.close();

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
                g3d.innerHTML='';
                renderSegments(out.pts, out.segStyle);
                renderDims(dimMap, out.pts);

                applyViewTweak();
                const v = getView();
                lastBBox = calcBBoxFromPts(out.pts, v);
              } else {
                segs.innerHTML='';
                dimLayer.innerHTML='';
                applyViewTweak();
                render3D(out.pts, out.segStyle);
              }

              applyAutoFit();
              renderDebug();

              const price = calc();
              if (!price.pickOk) {
                // optional warning (silent): standard widths too small; still calculates with max width
              }
              novatEl.textContent = price.totalNoVat.toFixed(2) + ' €';
              vatEl.textContent = price.totalVat.toFixed(2) + ' €';
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

            if (toggle3dBtn) toggle3dBtn.addEventListener('click', function(){ setMode3d(!mode3d); });
            if (reset3dBtn) reset3dBtn.addEventListener('click', reset3d);

            if (svgWrap){
              svgWrap.addEventListener('mousedown', onDown);
              window.addEventListener('mousemove', onMove);
              window.addEventListener('mouseup', onUp);

              svgWrap.addEventListener('touchstart', onDown, {passive:false});
              window.addEventListener('touchmove', onMove, {passive:false});
              window.addEventListener('touchend', onUp);

              svgWrap.addEventListener('wheel', onWheel, {passive:false});
            }

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

            renderDimInputs();
            renderMaterials();
            setMode3d(false);
            render();
          })();
        </script>
      </div>
    <?php
    return ob_get_clean();
  }
}

new Steel_Profile_Builder();
