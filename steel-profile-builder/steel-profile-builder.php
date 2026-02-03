<?php
/**
 * Plugin Name: Steel Profile Builder
 * Description: Profiilikalkulaator (SVG 2D + mõõtjooned + 3D drag + SVG export + Print/PDF) + adminis mõõdud/pattern/hinnastus + WPForms.
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
    add_shortcode('steel_profile_builder', [$this, 'shortcode']);
  }

  /* -------------------------
   * Helpers
   * ------------------------- */
  private function uuid4() {
    if (function_exists('wp_generate_uuid4')) return wp_generate_uuid4();

    $data = null;
    if (function_exists('random_bytes')) {
      try { $data = random_bytes(16); } catch (\Throwable $e) { $data = null; }
    }
    if ($data === null && function_exists('openssl_random_pseudo_bytes')) {
      $data = openssl_random_pseudo_bytes(16);
    }
    if ($data === null) {
      $data = '';
      for ($i=0;$i<16;$i++) $data .= chr(mt_rand(0,255));
    }

    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s',
      substr($hex, 0, 8),
      substr($hex, 8, 4),
      substr($hex, 12, 4),
      substr($hex, 16, 4),
      substr($hex, 20, 12)
    );
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

  private function default_pricing() {
    return [
      'vat' => 24,
      'work_eur_jm' => 0.00,
      'materials' => [
        ['key'=>'POL','label'=>'POL','eur_m2'=>7.5,'tones'=>['RR2H3'],'widths_mm'=>[208,250]],
        ['key'=>'PUR','label'=>'PUR','eur_m2'=>8.5,'tones'=>['RR2H3'],'widths_mm'=>[208,250]],
        ['key'=>'PUR_MATT','label'=>'PUR Matt','eur_m2'=>11.5,'tones'=>['RR2H3'],'widths_mm'=>[208,250]],
        ['key'=>'TSINK','label'=>'Tsink','eur_m2'=>6.5,'tones'=>[],'widths_mm'=>[208,250]],
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
        'tone' => 0,
        'detail_length_mm' => 0,
        'qty' => 0,
        'need_width_mm' => 0,
        'pick_width_mm' => 0,
        'area_m2' => 0,
        'price_total_no_vat' => 0,
        'price_total_vat' => 0,
        'vat_pct' => 0,
        'drawing_no' => 0,
        'work_no' => 0,
      ]
    ];
  }

  private function default_flags(){
    return [
      'show_pdf_btn' => 1,
      'show_svg_btn' => 1,
      'show_3d_btn'  => 1,
      'show_prices_in_pdf' => 1,
    ];
  }

  /* -------------------------
   * CPT + Admin metaboxes
   * ------------------------- */
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
    add_meta_box('spb_dims', 'Mõõdud (tabel + liigutamine)', [$this, 'mb_dims'], self::CPT, 'normal', 'high');
    add_meta_box('spb_pattern', 'Pattern (järjestus)', [$this, 'mb_pattern'], self::CPT, 'normal', 'default');
    add_meta_box('spb_pricing', 'Hinnastus + materjalid (tabel + toonid + standardlaiused)', [$this, 'mb_pricing'], self::CPT, 'normal', 'default');
    add_meta_box('spb_wpforms', 'WPForms', [$this, 'mb_wpforms'], self::CPT, 'side', 'default');
    add_meta_box('spb_flags', 'Front nupud / PDF', [$this, 'mb_flags'], self::CPT, 'side', 'default');
  }

  /* -------------------------
   * Admin UI: Dims
   * ------------------------- */
  public function mb_dims($post){
    wp_nonce_field('spb_save', 'spb_nonce');
    $m = $this->get_meta($post->ID);
    $dims = (is_array($m['dims']) && $m['dims']) ? $m['dims'] : $this->default_dims();

    $uid = 'spb_dims_ui_' . $post->ID . '_' . $this->uuid4();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="spb-admin">
      <p style="margin-top:0;opacity:.8">
        Read on liigutatavad. “Tagasipööre” kehtib nurga reale (a*), ja mõjutab järgmist sirglõiku.
      </p>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
        <button type="button" class="button button-primary spb-add-length">+ Lisa sirglõik (s)</button>
        <button type="button" class="button spb-add-angle">+ Lisa nurk (a)</button>
        <label style="display:flex;align-items:center;gap:8px;opacity:.9">
          <input type="checkbox" class="spb-auto-append" checked>
          lisa uus mõõt automaatselt patterni lõppu
        </label>
      </div>

      <table class="widefat spb-table spb-dims-table">
        <thead>
          <tr>
            <th style="width:46px">↕</th>
            <th style="width:110px">Key</th>
            <th style="width:110px">Tüüp</th>
            <th>Silt</th>
            <th style="width:80px">Min</th>
            <th style="width:80px">Max</th>
            <th style="width:90px">Default</th>
            <th style="width:90px">Suund</th>
            <th style="width:110px">Nurk</th>
            <th style="width:110px">Tagasipööre</th>
            <th style="width:120px">Tegevus</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <input type="hidden" class="spb-dims-json" name="spb_dims_json" value="<?php echo esc_attr(wp_json_encode($dims)); ?>">

      <details style="margin-top:10px">
        <summary><strong>Advanced: toor JSON</strong></summary>
        <textarea class="spb-dims-raw" style="width:100%;min-height:160px;"><?php echo esc_textarea(wp_json_encode($dims)); ?></textarea>
        <p style="opacity:.75;margin:6px 0 0">Kui muudad toorest JSON-i, vajuta “Apply JSON”, et tabel uueneks.</p>
        <button type="button" class="button spb-apply-dims-json" style="margin-top:6px">Apply JSON</button>
      </details>

      <style>
        .spb-admin .spb-table td, .spb-admin .spb-table th{vertical-align:middle}
        .spb-admin .spb-handle{cursor:grab;font-size:18px;opacity:.7}
        .spb-admin .spb-actions{display:flex;gap:6px;flex-wrap:wrap}
        .spb-admin .spb-small{padding:4px 8px;font-size:12px;line-height:1.2}
        .spb-admin input[type="number"], .spb-admin input[type="text"], .spb-admin select{width:100%}
        .spb-admin .spb-row-disabled{opacity:.65}
      </style>

      <script>
        (function(){
          const root = document.getElementById('<?php echo esc_js($uid); ?>');
          if(!root) return;

          const tbody = root.querySelector('.spb-dims-table tbody');
          const hidden = root.querySelector('.spb-dims-json');
          const raw = root.querySelector('.spb-dims-raw');
          const btnApply = root.querySelector('.spb-apply-dims-json');
          const autoAppend = root.querySelector('.spb-auto-append');

          function safeParse(s, fb){ try{ return JSON.parse(s); }catch(e){ return fb; } }
          function toNum(v,f){ const n=Number(v); return Number.isFinite(n)?n:f; }
          function clamp(n,min,max){ n=toNum(n,min); return Math.max(min, Math.min(max,n)); }

          function readDims(){
            const arr = safeParse(hidden.value || '[]', []);
            return Array.isArray(arr) ? arr : [];
          }
          function writeDims(arr){
            hidden.value = JSON.stringify(arr);
            if (raw) raw.value = JSON.stringify(arr, null, 2);
          }

          function findPatternTA(){
            return document.querySelector('textarea[name="spb_pattern_json"]');
          }
          function readPattern(){
            const ta = findPatternTA();
            const arr = ta ? safeParse(ta.value || '[]', []) : [];
            return Array.isArray(arr) ? arr : [];
          }
          function writePattern(arr){
            const ta = findPatternTA();
            if (!ta) return;
            ta.value = JSON.stringify(arr);
          }

          function nextKey(prefix, dims){
            let max = 0;
            dims.forEach(d=>{
              const k = String(d.key||'');
              const m = k.match(new RegExp('^'+prefix+'(\\d+)$'));
              if (m) max = Math.max(max, parseInt(m[1],10));
            });
            return prefix + String(max+1);
          }

          function normalizeDim(d){
            const type = (d.type==='angle') ? 'angle' : 'length';
            const dir = (String(d.dir||'L').toUpperCase()==='R') ? 'R' : 'L';
            const pol = (d.pol==='outer') ? 'outer' : 'inner';
            const min = (d.min!==null && d.min!=='' && d.min!==undefined) ? toNum(d.min, (type==='angle'?5:10)) : null;
            const max = (d.max!==null && d.max!=='' && d.max!==undefined) ? toNum(d.max, (type==='angle'?215:500)) : null;
            const def = (d.def!==null && d.def!=='' && d.def!==undefined) ? toNum(d.def, (type==='angle'?135:100)) : null;

            return {
              key: String(d.key||'').trim(),
              type,
              label: String(d.label||d.key||'').trim() || String(d.key||'').trim(),
              min,
              max,
              def,
              dir,
              pol: type==='angle' ? pol : null,
              ret: type==='angle' ? !!d.ret : false
            };
          }

          function render(){
            const dims = readDims().map(normalizeDim).filter(d=>d.key);
            tbody.innerHTML = '';

            dims.forEach((d, idx)=>{
              const tr = document.createElement('tr');

              tr.innerHTML = `
                <td><span class="spb-handle" title="Drag handle">↕</span></td>
                <td><input type="text" class="spb-key" value="${escapeHtml(d.key)}"></td>
                <td>
                  <select class="spb-type">
                    <option value="length" ${d.type==='length'?'selected':''}>length</option>
                    <option value="angle" ${d.type==='angle'?'selected':''}>angle</option>
                  </select>
                </td>
                <td><input type="text" class="spb-label" value="${escapeHtml(d.label)}"></td>
                <td><input type="number" class="spb-min" value="${d.min===null?'':d.min}"></td>
                <td><input type="number" class="spb-max" value="${d.max===null?'':d.max}"></td>
                <td><input type="number" class="spb-def" value="${d.def===null?'':d.def}"></td>
                <td>
                  <select class="spb-dir">
                    <option value="L" ${d.dir==='L'?'selected':''}>L</option>
                    <option value="R" ${d.dir==='R'?'selected':''}>R</option>
                  </select>
                </td>
                <td>
                  <select class="spb-pol">
                    <option value="inner" ${(d.pol||'inner')==='inner'?'selected':''}>Seest</option>
                    <option value="outer" ${(d.pol||'inner')==='outer'?'selected':''}>Väljast</option>
                  </select>
                </td>
                <td style="text-align:center">
                  <input type="checkbox" class="spb-ret" ${d.ret?'checked':''}>
                </td>
                <td>
                  <div class="spb-actions">
                    <button type="button" class="button spb-small spb-up">Üles</button>
                    <button type="button" class="button spb-small spb-down">Alla</button>
                    <button type="button" class="button spb-small spb-del">Kustuta</button>
                  </div>
                </td>
              `;

              // Disable angle-only fields for length
              if (d.type !== 'angle') {
                tr.querySelector('.spb-pol').disabled = true;
                tr.querySelector('.spb-ret').disabled = true;
              }

              // Bind up/down/del
              tr.querySelector('.spb-up').addEventListener('click', ()=>{
                const arr = readDims().map(normalizeDim);
                if (idx <= 0) return;
                const tmp = arr[idx-1]; arr[idx-1] = arr[idx]; arr[idx] = tmp;
                writeDims(arr); render();
              });
              tr.querySelector('.spb-down').addEventListener('click', ()=>{
                const arr = readDims().map(normalizeDim);
                if (idx >= arr.length-1) return;
                const tmp = arr[idx+1]; arr[idx+1] = arr[idx]; arr[idx] = tmp;
                writeDims(arr); render();
              });
              tr.querySelector('.spb-del').addEventListener('click', ()=>{
                const arr = readDims().map(normalizeDim);
                arr.splice(idx,1);
                writeDims(arr); render();
              });

              // Bind change -> save
              tr.querySelectorAll('input,select').forEach(el=>{
                el.addEventListener('input', saveFromTable);
                el.addEventListener('change', saveFromTable);
              });

              tbody.appendChild(tr);
            });

            // Also keep raw in sync
            if (raw) raw.value = JSON.stringify(readDims(), null, 2);
          }

          function saveFromTable(){
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const arr = rows.map(tr=>{
              const type = tr.querySelector('.spb-type').value;
              const key = String(tr.querySelector('.spb-key').value||'').trim();
              const label = String(tr.querySelector('.spb-label').value||key).trim() || key;

              const min = tr.querySelector('.spb-min').value;
              const max = tr.querySelector('.spb-max').value;
              const def = tr.querySelector('.spb-def').value;
              const dir = tr.querySelector('.spb-dir').value;
              const pol = tr.querySelector('.spb-pol').value;
              const ret = tr.querySelector('.spb-ret').checked;

              return normalizeDim({
                key,
                type,
                label,
                min: min===''?null:toNum(min,null),
                max: max===''?null:toNum(max,null),
                def: def===''?null:toNum(def,null),
                dir,
                pol,
                ret
              });
            }).filter(d=>d.key);

            writeDims(arr);
          }

          function escapeHtml(s){
            return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
          }

          // Add buttons
          root.querySelector('.spb-add-length').addEventListener('click', ()=>{
            const dims = readDims().map(normalizeDim);
            const key = nextKey('s', dims);
            dims.push(normalizeDim({key, type:'length', label:key, min:10, max:500, def:100, dir:'L'}));
            writeDims(dims);

            // auto append pattern
            if (autoAppend && autoAppend.checked) {
              const pat = readPattern();
              pat.push(key);
              writePattern(pat);
            }
            render();
          });

          root.querySelector('.spb-add-angle').addEventListener('click', ()=>{
            const dims = readDims().map(normalizeDim);
            const key = nextKey('a', dims);
            dims.push(normalizeDim({key, type:'angle', label:key, min:5, max:215, def:135, dir:'L', pol:'inner', ret:false}));
            writeDims(dims);

            if (autoAppend && autoAppend.checked) {
              const pat = readPattern();
              pat.push(key);
              writePattern(pat);
            }
            render();
          });

          // Raw apply
          if (btnApply){
            btnApply.addEventListener('click', ()=>{
              const arr = safeParse(raw.value || '[]', []);
              if (!Array.isArray(arr)) return alert('Dims JSON pole massiiv.');
              writeDims(arr);
              render();
            });
          }

          // Init
          // if raw differs, keep hidden as source of truth
          if (raw) raw.value = JSON.stringify(readDims(), null, 2);
          render();
        })();
      </script>
    </div>
    <?php
  }

  /* -------------------------
   * Admin UI: Pattern
   * ------------------------- */
  public function mb_pattern($post){
    $m = $this->get_meta($post->ID);
    $pattern = (is_array($m['pattern']) && $m['pattern']) ? $m['pattern'] : ["s1","a1","s2","a2","s3","a3","s4"];
    ?>
      <p style="margin-top:0;opacity:.8">
        Pattern on JSON massiiv. Mõõtude tabelis “auto-append” lisab uue key automaatselt siia lõppu.
      </p>
      <textarea name="spb_pattern_json" style="width:100%;min-height:110px;"><?php echo esc_textarea(wp_json_encode($pattern)); ?></textarea>
    <?php
  }

  /* -------------------------
   * Admin UI: Pricing + Materials
   * ------------------------- */
  public function mb_pricing($post){
    $m = $this->get_meta($post->ID);
    $pricing = (is_array($m['pricing']) && $m['pricing']) ? $m['pricing'] : [];
    $pricing = array_merge($this->default_pricing(), $pricing);

    $vat = floatval($pricing['vat'] ?? 24);
    $work = floatval($pricing['work_eur_jm'] ?? 0);
    $materials = is_array($pricing['materials'] ?? null) ? $pricing['materials'] : $this->default_pricing()['materials'];

    $uid = 'spb_mat_ui_' . $post->ID . '_' . $this->uuid4();
    ?>
      <div id="<?php echo esc_attr($uid); ?>" class="spb-admin">
        <p style="margin-top:0;opacity:.8">
          Hinnastus: <strong>koguhind = töö + materjalikulu</strong>. Materjalikulu arvutatakse standardlaiuse järgi.
          Vajalik lõikuslaius = Σ s(mm). Arvestuslaius = lähim standard, mis on ≥ vajalik.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:520px">
          <p style="margin:0"><label>KM %<br>
            <input type="number" step="0.1" name="spb_vat" value="<?php echo esc_attr($vat); ?>" style="width:100%;">
          </label></p>
          <p style="margin:0"><label>Töö (€/jm)<br>
            <input type="number" step="0.01" name="spb_work_eur_jm" value="<?php echo esc_attr($work); ?>" style="width:100%;">
          </label></p>
        </div>

        <hr style="margin:14px 0">

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
          <button type="button" class="button button-primary spb-add-material">+ Lisa materjal</button>
        </div>

        <table class="widefat spb-table spb-mat-table">
          <thead>
            <tr>
              <th style="width:46px">↕</th>
              <th style="width:140px">Key</th>
              <th>Silt</th>
              <th style="width:120px">€/m²</th>
              <th style="width:230px">Toonid</th>
              <th style="width:220px">Standardlaiused (mm)</th>
              <th style="width:120px">Tegevus</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>

        <input type="hidden" class="spb-materials-json" name="spb_materials_json" value="<?php echo esc_attr(wp_json_encode($materials)); ?>">

        <details style="margin-top:10px">
          <summary><strong>Advanced: toor materjalide JSON</strong></summary>
          <textarea class="spb-mat-raw" style="width:100%;min-height:200px;"><?php echo esc_textarea(wp_json_encode($materials)); ?></textarea>
          <p style="opacity:.75;margin:6px 0 0">Muuda JSON ja vajuta “Apply JSON”.</p>
          <button type="button" class="button spb-apply-mat-json" style="margin-top:6px">Apply JSON</button>
        </details>

        <style>
          .spb-admin .spb-table td, .spb-admin .spb-table th{vertical-align:middle}
          .spb-admin .spb-handle{cursor:grab;font-size:18px;opacity:.7}
          .spb-admin .spb-actions{display:flex;gap:6px;flex-wrap:wrap}
          .spb-admin .spb-small{padding:4px 8px;font-size:12px;line-height:1.2}
          .spb-admin input[type="number"], .spb-admin input[type="text"]{width:100%}
          .spb-admin .spb-csv{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size:12px}
        </style>

        <script>
          (function(){
            const root = document.getElementById('<?php echo esc_js($uid); ?>');
            if(!root) return;

            const tbody = root.querySelector('.spb-mat-table tbody');
            const hidden = root.querySelector('.spb-materials-json');
            const raw = root.querySelector('.spb-mat-raw');
            const btnApply = root.querySelector('.spb-apply-mat-json');

            function safeParse(s, fb){ try{ return JSON.parse(s); }catch(e){ return fb; } }
            function toNum(v,f){ const n=Number(v); return Number.isFinite(n)?n:f; }

            function readMats(){
              const arr = safeParse(hidden.value || '[]', []);
              return Array.isArray(arr) ? arr : [];
            }
            function writeMats(arr){
              hidden.value = JSON.stringify(arr);
              if (raw) raw.value = JSON.stringify(arr, null, 2);
            }

            function normalizeMat(m){
              const key = String((m&&m.key)||'').trim();
              if (!key) return null;
              const label = String(m.label||key).trim() || key;
              const eur = toNum(m.eur_m2, 0);

              const tones = Array.isArray(m.tones) ? m.tones.map(String).map(x=>x.trim()).filter(Boolean) : [];
              const widths = Array.isArray(m.widths_mm) ? m.widths_mm.map(x=>toNum(x,0)).filter(x=>x>0) : [];
              widths.sort((a,b)=>a-b);

              return { key, label, eur_m2: eur, tones, widths_mm: widths };
            }

            function parseCSVList(str){
              return String(str||'')
                .split(/[,\n;]/g)
                .map(s=>s.trim())
                .filter(Boolean);
            }
            function parseCSVNums(str){
              return parseCSVList(str)
                .map(s=>toNum(s,0))
                .filter(n=>n>0)
                .sort((a,b)=>a-b);
            }

            function escapeHtml(s){
              return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }

            function render(){
              const mats = readMats().map(normalizeMat).filter(Boolean);
              tbody.innerHTML = '';

              mats.forEach((m, idx)=>{
                const tr = document.createElement('tr');
                const tonesStr = (m.tones||[]).join(', ');
                const widthsStr = (m.widths_mm||[]).join(', ');

                tr.innerHTML = `
                  <td><span class="spb-handle">↕</span></td>
                  <td><input type="text" class="spb-k" value="${escapeHtml(m.key)}"></td>
                  <td><input type="text" class="spb-l" value="${escapeHtml(m.label)}"></td>
                  <td><input type="number" step="0.01" class="spb-e" value="${escapeHtml(m.eur_m2)}"></td>
                  <td><input type="text" class="spb-t spb-csv" value="${escapeHtml(tonesStr)}" placeholder="nt RR2H3, RAL7016"></td>
                  <td><input type="text" class="spb-w spb-csv" value="${escapeHtml(widthsStr)}" placeholder="nt 208, 250, 312"></td>
                  <td>
                    <div class="spb-actions">
                      <button type="button" class="button spb-small spb-up">Üles</button>
                      <button type="button" class="button spb-small spb-down">Alla</button>
                      <button type="button" class="button spb-small spb-del">Kustuta</button>
                    </div>
                  </td>
                `;

                tr.querySelector('.spb-up').addEventListener('click', ()=>{
                  const arr = readMats().map(normalizeMat).filter(Boolean);
                  if (idx<=0) return;
                  const tmp = arr[idx-1]; arr[idx-1]=arr[idx]; arr[idx]=tmp;
                  writeMats(arr); render();
                });
                tr.querySelector('.spb-down').addEventListener('click', ()=>{
                  const arr = readMats().map(normalizeMat).filter(Boolean);
                  if (idx>=arr.length-1) return;
                  const tmp = arr[idx+1]; arr[idx+1]=arr[idx]; arr[idx]=tmp;
                  writeMats(arr); render();
                });
                tr.querySelector('.spb-del').addEventListener('click', ()=>{
                  const arr = readMats().map(normalizeMat).filter(Boolean);
                  arr.splice(idx,1);
                  writeMats(arr); render();
                });

                tr.querySelectorAll('input').forEach(el=>{
                  el.addEventListener('input', saveFromTable);
                  el.addEventListener('change', saveFromTable);
                });

                tbody.appendChild(tr);
              });

              if (raw) raw.value = JSON.stringify(readMats(), null, 2);
            }

            function saveFromTable(){
              const rows = Array.from(tbody.querySelectorAll('tr'));
              const arr = rows.map(tr=>{
                const key = String(tr.querySelector('.spb-k').value||'').trim();
                if (!key) return null;
                const label = String(tr.querySelector('.spb-l').value||key).trim() || key;
                const eur = toNum(tr.querySelector('.spb-e').value, 0);

                const tones = parseCSVList(tr.querySelector('.spb-t').value);
                const widths = parseCSVNums(tr.querySelector('.spb-w').value);

                return normalizeMat({key, label, eur_m2: eur, tones, widths_mm: widths});
              }).filter(Boolean);

              writeMats(arr);
            }

            function nextKey(mats){
              let i = mats.length + 1;
              let k = 'MAT' + i;
              const keys = new Set(mats.map(x=>x.key));
              while(keys.has(k)){ i++; k='MAT'+i; }
              return k;
            }

            root.querySelector('.spb-add-material').addEventListener('click', ()=>{
              const mats = readMats().map(normalizeMat).filter(Boolean);
              const key = nextKey(mats);
              mats.push({key, label:key, eur_m2: 0, tones: [], widths_mm: [208]});
              writeMats(mats);
              render();
            });

            if (btnApply){
              btnApply.addEventListener('click', ()=>{
                const arr = safeParse(raw.value || '[]', []);
                if (!Array.isArray(arr)) return alert('Materials JSON pole massiiv.');
                writeMats(arr);
                render();
              });
            }

            if (raw) raw.value = JSON.stringify(readMats(), null, 2);
            render();
          })();
        </script>
      </div>
    <?php
  }

  /* -------------------------
   * Admin UI: WPForms + flags
   * ------------------------- */
  public function mb_wpforms($post){
    $m = $this->get_meta($post->ID);
    $wp = (is_array($m['wpforms']) && $m['wpforms']) ? $m['wpforms'] : [];
    $wp = array_merge($this->default_wpforms(), $wp);

    $form_id = intval($wp['form_id'] ?? 0);
    $map = is_array($wp['map'] ?? null) ? $wp['map'] : $this->default_wpforms()['map'];

    $fields = [
      'profile_name' => 'Profiili nimi',
      'dims_json' => 'Mõõdud JSON',
      'material' => 'Materjal',
      'tone' => 'Toon',
      'detail_length_mm' => 'Detaili pikkus (mm)',
      'qty' => 'Kogus',
      'need_width_mm' => 'Vajalik lõikuslaius (mm)',
      'pick_width_mm' => 'Arvestuslaius (standard) (mm)',
      'area_m2' => 'Materjalikulu pindala (m²)',
      'price_total_no_vat' => 'Kokku ilma KM',
      'price_total_vat' => 'Kokku koos KM',
      'vat_pct' => 'KM %',
      'drawing_no' => 'Drawing no',
      'work_no' => 'Work no',
    ];
    ?>
    <p style="margin-top:0;opacity:.8">Pane WPForms Form ID ja field ID-d. 0 = ei täida.</p>
    <p><label>WPForms Form ID<br>
      <input type="number" name="spb_wpforms_id" value="<?php echo esc_attr($form_id); ?>" style="width:100%;">
    </label></p>

    <details>
      <summary><strong>Field mapping</strong></summary>
      <?php foreach ($fields as $k => $label): ?>
        <p><label><?php echo esc_html($label); ?><br>
          <input type="number" name="spb_wpforms_map[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr(intval($map[$k] ?? 0)); ?>" style="width:100%;">
        </label></p>
      <?php endforeach; ?>
    </details>
    <?php
  }

  public function mb_flags($post){
    $m = $this->get_meta($post->ID);
    $flags = (is_array($m['flags']) && $m['flags']) ? array_merge($this->default_flags(), $m['flags']) : $this->default_flags();

    $pdf = !empty($flags['show_pdf_btn']) ? 1 : 0;
    $svg = !empty($flags['show_svg_btn']) ? 1 : 0;
    $d3  = !empty($flags['show_3d_btn']) ? 1 : 0;
    $ppr = !empty($flags['show_prices_in_pdf']) ? 1 : 0;
    ?>
      <label style="display:flex;gap:8px;align-items:center;margin:8px 0">
        <input type="checkbox" name="spb_flag_show_pdf" value="1" <?php checked($pdf,1); ?>>
        <span>Näita Print/PDF nuppu</span>
      </label>

      <label style="display:flex;gap:8px;align-items:center;margin:8px 0">
        <input type="checkbox" name="spb_flag_show_svg" value="1" <?php checked($svg,1); ?>>
        <span>Näita Salvesta SVG nuppu</span>
      </label>

      <label style="display:flex;gap:8px;align-items:center;margin:8px 0">
        <input type="checkbox" name="spb_flag_show_3d" value="1" <?php checked($d3,1); ?>>
        <span>Näita 3D nuppu</span>
      </label>

      <hr>

      <label style="display:flex;gap:8px;align-items:center;margin:8px 0">
        <input type="checkbox" name="spb_flag_pdf_prices" value="1" <?php checked($ppr,1); ?>>
        <span>Näita PDF-is hinnad</span>
      </label>
    <?php
  }

  /* -------------------------
   * Save meta
   * ------------------------- */
  public function save_meta($post_id, $post) {
    if ($post->post_type !== self::CPT) return;
    if (!isset($_POST['spb_nonce']) || !wp_verify_nonce($_POST['spb_nonce'], 'spb_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // dims (from hidden)
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
    $p = $this->default_pricing();
    $p['vat'] = floatval($_POST['spb_vat'] ?? 24);
    $p['work_eur_jm'] = floatval($_POST['spb_work_eur_jm'] ?? 0);

    $materials_json = wp_unslash($_POST['spb_materials_json'] ?? '[]');
    $materials = json_decode($materials_json, true);
    if (is_array($materials)) $p['materials'] = $materials;
    update_post_meta($post_id, '_spb_pricing', $p);

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

    // flags
    $flags = $this->default_flags();
    $flags['show_pdf_btn'] = !empty($_POST['spb_flag_show_pdf']) ? 1 : 0;
    $flags['show_svg_btn'] = !empty($_POST['spb_flag_show_svg']) ? 1 : 0;
    $flags['show_3d_btn']  = !empty($_POST['spb_flag_show_3d']) ? 1 : 0;
    $flags['show_prices_in_pdf'] = !empty($_POST['spb_flag_pdf_prices']) ? 1 : 0;
    update_post_meta($post_id, '_spb_flags', $flags);
  }

  /* -------------------------
   * Frontend shortcode
   * (Same as v0.5.0 base; unchanged functionality)
   * ------------------------- */
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

    $flags = (is_array($m['flags']) && $m['flags']) ? array_merge($this->default_flags(), $m['flags']) : $this->default_flags();

    $cfg = [
      'profileId' => $id,
      'profileName' => get_the_title($id),
      'dims' => $dims,
      'pattern' => $pattern,
      'vat' => floatval($pricing['vat'] ?? 24),
      'work_eur_jm' => floatval($pricing['work_eur_jm'] ?? 0),
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
      'flags' => [
        'show_pdf_btn' => !empty($flags['show_pdf_btn']) ? 1 : 0,
        'show_svg_btn' => !empty($flags['show_svg_btn']) ? 1 : 0,
        'show_3d_btn'  => !empty($flags['show_3d_btn']) ? 1 : 0,
        'show_prices_in_pdf' => !empty($flags['show_prices_in_pdf']) ? 1 : 0,
      ]
    ];

    $uid = 'spb_front_' . $id . '_' . $this->uuid4();
    $arrowId = 'spbArrow_' . $uid;

    ob_start(); ?>
      <div class="spb-front" id="<?php echo esc_attr($uid); ?>" data-spb="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
        <div class="spb-card">
          <div class="spb-title"><?php echo esc_html(get_the_title($id)); ?></div>
          <div class="spb-error" style="display:none"></div>

          <div class="spb-grid">
            <div class="spb-box">
              <div class="spb-box-h spb-row-head">
                <span>Joonis</span>
                <div class="spb-tools">
                  <button type="button" class="spb-mini spb-toggle-3d" aria-pressed="false">3D</button>
                  <button type="button" class="spb-mini spb-reset-3d">Reset 3D</button>
                  <button type="button" class="spb-mini spb-save-svg">Salvesta SVG</button>
                  <button type="button" class="spb-mini spb-print">Print / PDF</button>
                </div>
              </div>

              <div class="spb-canvas" aria-label="Drawing canvas">
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
            </div>

            <div class="spb-box">
              <div class="spb-box-h">Mõõdud</div>
              <div class="spb-inputs"></div>
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
              <div class="spb-sub"><span>Materjali arvestuslaius</span><strong class="spb-pick-width">—</strong></div>
            </div>

            <button type="button" class="spb-btn spb-open-form">Küsi personaalset hinnapakkumist</button>

            <?php if (!empty($cfg['wpforms']['form_id'])): ?>
              <div class="spb-form-wrap" style="display:none;margin-top:14px">
                <?php echo do_shortcode('[wpforms id="'.intval($cfg['wpforms']['form_id']).'" title="false" description="false"]'); ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <style>
          .spb-front{--spb-accent:#111; font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
          .spb-front .spb-card{border:1px solid #eaeaea;border-radius:18px;padding:18px;background:#fff}
          .spb-front .spb-title{font-size:18px;font-weight:800;margin-bottom:12px}
          .spb-front .spb-error{margin:12px 0;padding:10px;border:1px solid #ffb4b4;background:#fff1f1;border-radius:12px}

          .spb-front .spb-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;align-items:start}
          .spb-front .spb-box{border:1px solid #eee;border-radius:16px;padding:14px;background:#fff}
          .spb-front .spb-box-h{font-weight:800;margin:0 0 10px 0}
          .spb-front .spb-row-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
          .spb-front .spb-tools{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}

          .spb-front .spb-mini{
            border:1px solid #ddd;background:#fff;border-radius:999px;
            padding:6px 10px;font-weight:800;cursor:pointer;
            font-size:12px;line-height:1;
          }
          .spb-front .spb-mini[aria-pressed="true"]{border-color:#bbb; box-shadow:0 0 0 2px rgba(0,0,0,.05)}

          .spb-front .spb-canvas{
            height:420px;
            border:1px solid #eee;border-radius:14px;
            background:linear-gradient(180deg,#fafafa,#fff);
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;
            touch-action:none;
            user-select:none;
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

          .spb-front .spb-inputs{display:grid;grid-template-columns:1fr 170px;gap:10px;align-items:center}
          .spb-front input,.spb-front select{
            width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:12px;outline:none;
          }

          .spb-front .spb-order{margin-top:18px}
          .spb-front .spb-row4{display:grid;grid-template-columns:1fr 1fr 1fr .7fr;gap:12px;align-items:end}
          .spb-front .spb-row label{display:block;font-weight:700;margin:0 0 6px 0;font-size:13px;opacity:.85}

          .spb-front .spb-results{margin-top:14px;border-top:1px solid #eee;padding-top:12px;display:grid;gap:8px}
          .spb-front .spb-results > div{display:flex;justify-content:space-between;gap:12px}
          .spb-front .spb-results strong{font-size:16px}
          .spb-front .spb-total strong{font-size:18px}
          .spb-front .spb-sub{opacity:.85}

          .spb-front .spb-btn{
            width:100%;margin-top:12px;padding:12px 14px;border-radius:14px;border:0;cursor:pointer;
            font-weight:900;background:var(--spb-accent);color:#fff;
          }

          @media (max-width: 980px){
            .spb-front .spb-grid{grid-template-columns:1fr}
            .spb-front .spb-canvas{height:360px}
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

            if (!cfg.dims || !cfg.dims.length) { showErr('Sellel profiilil pole mõõte.'); return; }

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
            const pickWidthEl = root.querySelector('.spb-pick-width');

            const btn3d = root.querySelector('.spb-toggle-3d');
            const btnReset3d = root.querySelector('.spb-reset-3d');
            const btnSvg = root.querySelector('.spb-save-svg');
            const btnPrint = root.querySelector('.spb-print');

            const canvas = root.querySelector('.spb-canvas');
            const svg = root.querySelector('.spb-svg');

            const fitWrap = root.querySelector('.spb-fit');
            const world = root.querySelector('.spb-world');

            const g2d = root.querySelector('.spb-2d');
            const segs = root.querySelector('.spb-segs');
            const dimLayer = root.querySelector('.spb-dimlayer');
            const g3d = root.querySelector('.spb-3d');
            const debugLayer = root.querySelector('.spb-debug');

            const openBtn = root.querySelector('.spb-open-form');
            const formWrap = root.querySelector('.spb-form-wrap');

            const flags = cfg.flags || {};
            if (!flags.show_3d_btn) { btn3d.style.display='none'; btnReset3d.style.display='none'; }
            if (!flags.show_svg_btn) btnSvg.style.display='none';
            if (!flags.show_pdf_btn) btnPrint.style.display='none';

            const ARROW_ID = '<?php echo esc_js($arrowId); ?>';

            const VB_W = 820, VB_H = 460;
            const CX = 410, CY = 230;
            let lastBBox = null;
            let mode3d = false;

            let camYaw = 0.25;
            let camPit = -0.22;
            let camZoom = 1.0;
            let dragging = false;
            let dragStart = {x:0,y:0,yaw:0,pit:0};

            const stateVal = {};

            function toNum(v,f){ const n=Number(v); return Number.isFinite(n)?n:f; }
            function clamp(n,min,max){ n=toNum(n,min); return Math.max(min, Math.min(max,n)); }
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
              t.setAttribute('fill','#111');
              t.setAttribute('font-size','13');
              t.setAttribute('dominant-baseline','middle');
              t.setAttribute('text-anchor','middle');
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

            function sanitizeMaterials(list){
              const out = [];
              (Array.isArray(list)?list:[]).forEach(m=>{
                const key = (m && m.key) ? String(m.key) : '';
                if (!key) return;
                out.push({
                  key,
                  label: m.label ? String(m.label) : key,
                  eur_m2: toNum(m.eur_m2, 0),
                  tones: Array.isArray(m.tones) ? m.tones.map(String) : [],
                  widths_mm: Array.isArray(m.widths_mm) ? m.widths_mm.map(x=>toNum(x,0)).filter(x=>x>0).sort((a,b)=>a-b) : []
                });
              });
              return out;
            }

            const MAT = sanitizeMaterials(cfg.materials || []);

            function currentMaterial(){
              const key = matSel.value;
              return MAT.find(x=>x.key===key) || MAT[0] || null;
            }

            function renderMaterials(){
              matSel.innerHTML='';
              MAT.forEach(m=>{
                const opt = document.createElement('option');
                opt.value = m.key;
                opt.textContent = m.label;
                opt.dataset.eur = String(m.eur_m2);
                matSel.appendChild(opt);
              });
              if (matSel.options.length) matSel.selectedIndex = 0;
              renderTonesForMaterial();
            }

            function renderTonesForMaterial(){
              const m = currentMaterial();
              toneSel.innerHTML='';
              const tones = (m && m.tones && m.tones.length) ? m.tones : ['—'];
              tones.forEach(t=>{
                const opt = document.createElement('option');
                opt.value = t;
                opt.textContent = t;
                toneSel.appendChild(opt);
              });
              toneSel.selectedIndex = 0;
            }

            function buildDimMap(){
              const map = {};
              (cfg.dims||[]).forEach(d=>{ if (d && d.key) map[d.key]=d; });
              return map;
            }

            function renderDimInputs(){
              inputsWrap.innerHTML='';
              (cfg.dims||[]).forEach(d=>{
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
                  const dir = (meta.dir === 'R') ? -1 : 1;
                  const turn = turnFromAngle(a, pol);
                  heading += dir * turn;

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
                  return (bb.width + 16) <= segLen;
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
              let x = px - CX, y = py - CY;
              x *= view.scale; y *= view.scale;
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
                minX = Math.min(minX, tx); minY = Math.min(minY, ty);
                maxX = Math.max(maxX, tx); maxY = Math.max(maxY, ty);
              }
              if (!Number.isFinite(minX) || !Number.isFinite(minY)) return null;
              const w = Math.max(0, maxX - minX);
              const h = Math.max(0, maxY - minY);
              return { x:minX, y:minY, w, h, cx:(minX+maxX)/2, cy:(minY+maxY)/2 };
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

            function sumLengthsMm(){
              let sum = 0;
              (cfg.dims||[]).forEach(d=>{
                if (d.type !== 'length') return;
                const min = (d.min ?? 10);
                const max = (d.max ?? 500);
                sum += clamp(stateVal[d.key], min, max);
              });
              return sum;
            }

            function pickWidthMm(need, widths){
              widths = Array.isArray(widths) ? widths.map(x=>toNum(x,0)).filter(x=>x>0).sort((a,b)=>a-b) : [];
              if (!widths.length) return need;
              for (const w of widths) if (w >= need) return w;
              return widths[widths.length-1];
            }

            function makeDrawingNo(){
              const d = new Date();
              const p2 = n => String(n).padStart(2,'0');
              return `SPB-${d.getFullYear()}${p2(d.getMonth()+1)}${p2(d.getDate())}-${p2(d.getHours())}${p2(d.getMinutes())}${p2(d.getSeconds())}`;
            }
            function makeWorkNo(){
              const d = new Date();
              const p2 = n => String(n).padStart(2,'0');
              const name = (cfg.profileName || 'PROFILE').toUpperCase().replace(/[^A-Z0-9]+/g,'-').slice(0,24);
              return `${name}-${d.getFullYear()}${p2(d.getMonth()+1)}${p2(d.getDate())}`;
            }

            function calcTotals(){
              const m = currentMaterial();
              const eur_m2 = m ? m.eur_m2 : 0;
              const widths = m ? m.widths_mm : [];

              const needW = sumLengthsMm();
              const pickW = pickWidthMm(needW, widths);

              const lenM = clamp(lenEl.value, 50, 8000) / 1000.0;
              const qty = clamp(qtyEl.value, 1, 999);

              const area = (pickW/1000.0) * lenM * qty;
              const matCost = area * eur_m2;

              const workRate = toNum(cfg.work_eur_jm, 0);
              const workCost = lenM * qty * workRate;

              const totalNoVat = matCost + workCost;
              const vatPct = toNum(cfg.vat, 24);
              const totalVat = totalNoVat * (1 + vatPct/100);

              return {
                need_width_mm: Math.round(needW),
                pick_width_mm: Math.round(pickW),
                area_m2: area,
                total_no_vat: totalNoVat,
                total_vat: totalVat,
                vat_pct: vatPct,
                drawing_no: makeDrawingNo(),
                work_no: makeWorkNo(),
              };
            }

            function dimsPayloadJSON(){
              return JSON.stringify((cfg.dims||[]).map(d=>{
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

              const mat = currentMaterial();
              const values = {
                profile_name: cfg.profileName || '',
                dims_json: dimsPayloadJSON(),
                material: mat ? mat.label : '',
                tone: toneSel.value || '',
                detail_length_mm: String(clamp(lenEl.value, 50, 8000)),
                qty: String(clamp(qtyEl.value, 1, 999)),
                need_width_mm: String(out.need_width_mm),
                pick_width_mm: String(out.pick_width_mm),
                area_m2: String(out.area_m2.toFixed(4)),
                price_total_no_vat: String(out.total_no_vat.toFixed(2)),
                price_total_vat: String(out.total_vat.toFixed(2)),
                vat_pct: String(out.vat_pct),
                drawing_no: out.drawing_no,
                work_no: out.work_no,
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

            function render3D(pts, segStyle){
              g3d.innerHTML = '';

              const base = 90 * camZoom;
              const DX = base * Math.cos(camYaw);
              const DY = base * Math.sin(camPit);

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
              if (btn3d){
                btn3d.setAttribute('aria-pressed', mode3d ? 'true' : 'false');
              }
              if (g2d) g2d.style.display = mode3d ? 'none' : '';
              if (g3d) g3d.style.display = mode3d ? '' : 'none';
              render();
            }

            function reset3d(){
              camYaw = 0.25;
              camPit = -0.22;
              camZoom = 1.0;
              if (mode3d) render();
            }

            function getClientXY(e){
              if (e.touches && e.touches[0]) return {x:e.touches[0].clientX, y:e.touches[0].clientY};
              return {x:e.clientX, y:e.clientY};
            }
            function onPointerDown(e){
              if (!mode3d) return;
              dragging = true;
              const pt = getClientXY(e);
              dragStart = {x: pt.x, y: pt.y, yaw: camYaw, pit: camPit};
              try { canvas.setPointerCapture(e.pointerId); } catch(_){}
              e.preventDefault();
            }
            function onPointerMove(e){
              if (!dragging || !mode3d) return;
              const pt = getClientXY(e);
              const dx = (pt.x - dragStart.x);
              const dy = (pt.y - dragStart.y);
              camYaw = dragStart.yaw + dx * 0.01;
              camPit = dragStart.pit + dy * 0.01;
              camPit = Math.max(-1.2, Math.min(1.2, camPit));
              render();
              e.preventDefault();
            }
            function onPointerUp(e){
              dragging = false;
              e.preventDefault();
            }
            function onWheel(e){
              if (!mode3d) return;
              const delta = e.deltaY || 0;
              camZoom *= (delta > 0) ? 0.92 : 1.08;
              camZoom = Math.max(0.55, Math.min(2.2, camZoom));
              render();
              e.preventDefault();
            }

            function buildPrintHTML(svgMarkup, data){
              const esc = (s) => String(s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
              const d = new Date();
              const p2 = n => String(n).padStart(2,'0');
              const dateStr = `${p2(d.getDate())}.${p2(d.getMonth()+1)}.${d.getFullYear()}`;
              const profile = esc(cfg.profileName || '');
              const mat = esc((currentMaterial()||{}).label || '');
              const tone = esc(toneSel.value || '');
              const len = esc(String(clamp(lenEl.value, 50, 8000)));
              const qty = esc(String(clamp(qtyEl.value, 1, 999)));

              const drawingNo = esc(data.drawing_no);
              const workNo = esc(data.work_no);

              const showPrices = !!(cfg.flags && cfg.flags.show_prices_in_pdf);

              const priceBox = showPrices ? `
                <div class="box">
                  <div class="h">Hinnad</div>
                  <div class="row"><span>Kokku (ilma KM)</span><strong>${data.total_no_vat.toFixed(2)} €</strong></div>
                  <div class="row"><span>Kokku (koos KM)</span><strong>${data.total_vat.toFixed(2)} €</strong></div>
                </div>` : '';

              return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>${profile} – Drawing</title>
<style>
  @page { margin: 14mm; }
  body{ font-family: Arial, sans-serif; color:#111; }
  .top{ display:flex; justify-content:space-between; gap:14px; align-items:flex-start; }
  .title{ font-size:18px; font-weight:800; margin:0 0 8px 0; }
  .block{ border:1px solid #ddd; border-radius:10px; padding:10px 12px; }
  .grid{ display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; }
  .cell span{ display:block; font-size:11px; opacity:.7; }
  .cell strong{ display:block; font-size:13px; margin-top:2px; }
  .mid{ margin-top:14px; border:1px solid #ddd; border-radius:12px; padding:10px; }
  .mid svg{ width:100%; height:auto; display:block; }
  .bottom{ margin-top:14px; display:flex; justify-content:space-between; gap:14px; }
  .box{ border:1px solid #ddd; border-radius:10px; padding:10px 12px; min-width:260px;}
  .box .h{ font-weight:800; margin-bottom:8px; }
  .row{ display:flex; justify-content:space-between; gap:10px; margin:4px 0; }
  .meta{ flex:1; }
  .meta .grid{ grid-template-columns: repeat(4, 1fr); }
  .note{ font-size:11px; opacity:.7; margin-top:10px; }
</style>
</head>
<body>
  <div class="top">
    <div style="flex:1">
      <div class="title">${profile}</div>
      <div class="block grid">
        <div class="cell"><span>Materjal</span><strong>${mat}</strong></div>
        <div class="cell"><span>Värvitoon</span><strong>${tone}</strong></div>
        <div class="cell"><span>Detaili pikkus</span><strong>${len} mm</strong></div>
        <div class="cell"><span>Kogus</span><strong>${qty}</strong></div>
        <div class="cell"><span>Arvestuslaius</span><strong>${data.pick_width_mm} mm</strong></div>
        <div class="cell"><span>Materjalikulu</span><strong>${data.area_m2.toFixed(4)} m²</strong></div>
      </div>
    </div>
  </div>

  <div class="mid">${svgMarkup}</div>

  <div class="bottom">
    <div class="box meta">
      <div class="h">Info</div>
      <div class="grid">
        <div class="cell"><span>Date</span><strong>${dateStr}</strong></div>
        <div class="cell"><span>Scale</span><strong>auto</strong></div>
        <div class="cell"><span>Drawing no</span><strong>${drawingNo}</strong></div>
        <div class="cell"><span>Work no</span><strong>${workNo}</strong></div>
      </div>
      <div class="note">Dokument on genereeritud automaatselt Steel Profile Builder moodulist.</div>
    </div>
    ${priceBox}
  </div>

<script>
  window.onload = function(){ window.print(); };
</script>
</body>
</html>`;
            }

            function cleanSvgForPrint(){
              const clone = svg.cloneNode(true);
              const dbg = clone.querySelector('.spb-debug');
              if (dbg) dbg.innerHTML = '';
              const c2d = clone.querySelector('.spb-2d');
              const c3d = clone.querySelector('.spb-3d');
              if (mode3d){
                if (c2d) c2d.style.display = 'none';
                if (c3d) c3d.style.display = '';
              } else {
                if (c2d) c2d.style.display = '';
                if (c3d) c3d.style.display = 'none';
              }
              clone.setAttribute('width','100%');
              clone.setAttribute('height','auto');
              return clone.outerHTML;
            }

            function doPrint(){
              const out = calcTotals();
              const html = buildPrintHTML(cleanSvgForPrint(), out);
              const w = window.open('', '_blank');
              if (!w) { alert('Popup blocker takistab printimist. Luba pop-up ja proovi uuesti.'); return; }
              w.document.open();
              w.document.write(html);
              w.document.close();
            }

            function saveSvg(){
              const out = cleanSvgForPrint();
              const blob = new Blob([out], {type:'image/svg+xml;charset=utf-8'});
              const url = URL.createObjectURL(blob);
              const a = document.createElement('a');
              const name = (cfg.profileName || 'profile').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'');
              a.href = url;
              a.download = `${name || 'steel-profile'}.svg`;
              document.body.appendChild(a);
              a.click();
              a.remove();
              URL.revokeObjectURL(url);
            }

            function render(){
              hideErr();

              const dimMap = buildDimMap();

              fitWrap.setAttribute('transform','');
              world.setAttribute('transform','');

              const out = computePolyline(dimMap);

              if (!mode3d){
                g3d.innerHTML = '';
                renderSegments(out.pts, out.segStyle);
                renderDims(dimMap, out.pts);
              } else {
                segs.innerHTML = '';
                dimLayer.innerHTML = '';
                const three = render3D(out.pts, out.segStyle);

                applyViewTweak();
                const v = getView();
                lastBBox = calcBBoxFromPts(out.pts.concat(three.backPts), v);
                applyAutoFit();
              }

              if (!mode3d){
                applyViewTweak();
                const v = getView();
                lastBBox = calcBBoxFromPts(out.pts, v);
                applyAutoFit();
              }

              const totals = calcTotals();
              novatEl.textContent = totals.total_no_vat.toFixed(2) + ' €';
              vatEl.textContent = totals.total_vat.toFixed(2) + ' €';
              pickWidthEl.textContent = totals.pick_width_mm + ' mm';
            }

            inputsWrap.addEventListener('input', (e)=>{
              const el = e.target;
              if (!el || !el.dataset || !el.dataset.key) return;
              const key = el.dataset.key;

              const meta = (cfg.dims||[]).find(x=>x.key===key);
              if (!meta) return;

              const min = (meta.min ?? (meta.type==='angle'?5:10));
              const max = (meta.max ?? (meta.type==='angle'?215:500));
              stateVal[key] = clamp(el.value, min, max);

              render();
            });

            matSel.addEventListener('change', ()=>{
              renderTonesForMaterial();
              render();
            });
            toneSel.addEventListener('change', render);
            lenEl.addEventListener('input', render);
            qtyEl.addEventListener('input', render);

            if (btn3d) btn3d.addEventListener('click', ()=> setMode3d(!mode3d));
            if (btnReset3d) btnReset3d.addEventListener('click', reset3d);
            if (btnPrint) btnPrint.addEventListener('click', doPrint);
            if (btnSvg) btnSvg.addEventListener('click', saveSvg);

            if (canvas){
              canvas.addEventListener('pointerdown', onPointerDown);
              canvas.addEventListener('pointermove', onPointerMove);
              canvas.addEventListener('pointerup', onPointerUp);
              canvas.addEventListener('pointercancel', onPointerUp);
              canvas.addEventListener('wheel', onWheel, {passive:false});
            }

            if (openBtn) openBtn.addEventListener('click', ()=>{
              render();
              if (formWrap){
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
