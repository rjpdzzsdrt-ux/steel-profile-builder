(function(){
  function q(root, sel){ return root.querySelector(sel); }
  function qa(root, sel){ return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function toNum(v,f){ const n = Number(v); return Number.isFinite(n)?n:f; }
  function clamp(n,min,max){ n = toNum(n,min); return Math.max(min, Math.min(max,n)); }
  function deg2rad(d){ return d * Math.PI / 180; }
  function turnFromAngle(aDeg, pol){ const a=Number(aDeg||0); return (pol==='outer')?a:(180-a); }
  function svgEl(tag){ return document.createElementNS('http://www.w3.org/2000/svg', tag); }
  function nowDateStr(){
    const d = new Date();
    const dd = String(d.getDate()).padStart(2,'0');
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const yyyy = String(d.getFullYear());
    return `${dd}.${mm}.${yyyy}`;
  }

  // Vector helpers (2D)
  function vec(x,y){ return {x,y}; }
  function sub(a,b){ return {x:a.x-b.x,y:a.y-b.y}; }
  function add(a,b){ return {x:a.x+b.x,y:a.y+b.y}; }
  function mul(a,k){ return {x:a.x*k,y:a.y*k}; }
  function vlen(v){ return Math.hypot(v.x,v.y)||1; }
  function norm(v){ const l=vlen(v); return {x:v.x/l,y:v.y/l}; }
  function perp(v){ return {x:-v.y,y:v.x}; }

  // 3D helpers
  function rotX(p, a){
    const r = deg2rad(a);
    const c = Math.cos(r), s = Math.sin(r);
    return {x:p.x, y:p.y*c - p.z*s, z:p.y*s + p.z*c};
  }
  function rotY(p, a){
    const r = deg2rad(a);
    const c = Math.cos(r), s = Math.sin(r);
    return {x:p.x*c + p.z*s, y:p.y, z:-p.x*s + p.z*c};
  }
  function project(p, cam){
    // Simple perspective
    const z = (cam.persp + p.z);
    const k = cam.persp / Math.max(50, z);
    return {x: p.x * k, y: p.y * k, k};
  }
  function shade(hex, k){
    // hex like #111 or #bdbdbd etc; we'll just return fixed greys depending on k
    // Keep it simple: darker when k smaller
    const g = Math.round(160 + (k*80)); // 160..240
    return `rgb(${g},${g},${g})`;
  }

  function initOne(root){
    const cfg = JSON.parse(root.getAttribute('data-spb') || '{}');

    const err = q(root, '.spb-error');
    function showErr(msg){ if(!err) return; err.style.display='block'; err.textContent=msg; }
    function hideErr(){ if(!err) return; err.style.display='none'; err.textContent=''; }

    if (!cfg.dims || !cfg.dims.length) { showErr('Sellel profiilil pole mõõte.'); return; }

    // Accent color best-effort from Elementor button
    try{
      const btn = document.querySelector('.elementor a.elementor-button, .elementor button, a.elementor-button, button.elementor-button');
      if (btn){
        const cs = getComputedStyle(btn);
        const bg = cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? cs.backgroundColor : null;
        if (bg) root.style.setProperty('--spb-accent', bg);
      }
    }catch(e){}

    const inputsWrap = q(root, '.spb-inputs');
    const matSel = q(root, '.spb-material');
    const lenEl = q(root, '.spb-length');
    const qtyEl = q(root, '.spb-qty');

    const jmEl = q(root, '.spb-price-jm');
    const matEl = q(root, '.spb-price-mat');
    const novatEl = q(root, '.spb-price-novat');
    const vatEl = q(root, '.spb-price-vat');

    const toggle3dBtn = q(root, '.spb-toggle-3d');
    const reset3dBtn = q(root, '.spb-reset-3d');
    const saveSvgBtn = q(root, '.spb-save-svg');
    const printBtn = q(root, '.spb-print-pdf');

    const svgWrap = q(root, '.spb-svg-wrap');
    const svg = q(root, '.spb-svg');
    const defs = q(root, '.spb-defs');

    const fitWrap = q(root, '.spb-fit');
    const world = q(root, '.spb-world');

    const g2d = q(root, '.spb-2d');
    const segs = q(root, '.spb-segs');
    const dimLayer = q(root, '.spb-dimlayer');
    const g3d = q(root, '.spb-3d');
    const debugLayer = q(root, '.spb-debug');

    const tbName = q(root, '.spb-tb-name');
    const tbDate = q(root, '.spb-tb-date');
    const tbMat = q(root, '.spb-tb-mat');
    const tbLen = q(root, '.spb-tb-len');
    const tbQty = q(root, '.spb-tb-qty');
    const tbSum = q(root, '.spb-tb-sum');

    const formWrap = q(root, '.spb-form-wrap');
    const openBtn = q(root, '.spb-open-form');

    const VB_W = 820, VB_H = 460;
    const CX = 410, CY = 230;
    let lastBBox = null;

    // Arrow marker
    const arrowId = 'spbArrow_' + Math.random().toString(16).slice(2);
    (function ensureMarker(){
      defs.innerHTML = '';
      const marker = svgEl('marker');
      marker.setAttribute('id', arrowId);
      marker.setAttribute('viewBox', '0 0 10 10');
      marker.setAttribute('refX', '5');
      marker.setAttribute('refY', '5');
      marker.setAttribute('markerWidth', '7');
      marker.setAttribute('markerHeight', '7');
      marker.setAttribute('orient', 'auto-start-reverse');
      const path = svgEl('path');
      path.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
      path.setAttribute('fill', '#111');
      marker.appendChild(path);
      defs.appendChild(marker);
    })();

    const stateVal = {};
    let mode3d = false;

    // 3D camera (interactive)
    const cam = {
      rotX: -22,
      rotY: 28,
      depth: 120,   // extrusion depth
      persp: 900,   // perspective distance
      zoom: 1.0
    };

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
      (cfg.dims||[]).forEach(d=>{ if (d && d.key) map[d.key]=d; });
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

    function addSegLine(x1,y1,x2,y2, dash){
      const l = svgEl('line');
      l.setAttribute('x1',x1); l.setAttribute('y1',y1);
      l.setAttribute('x2',x2); l.setAttribute('y2',y2);
      l.setAttribute('stroke','#111');
      l.setAttribute('stroke-width','3');
      if (dash) l.setAttribute('stroke-dasharray', dash);
      segs.appendChild(l);
      return l;
    }
    function addDimLine(g,x1,y1,x2,y2,w,op,arrows){
      const l = svgEl('line');
      l.setAttribute('x1',x1); l.setAttribute('y1',y1);
      l.setAttribute('x2',x2); l.setAttribute('y2',y2);
      l.setAttribute('stroke','#111');
      l.setAttribute('stroke-width', w||1);
      if (op!=null) l.setAttribute('opacity', op);
      if (arrows){
        l.setAttribute('marker-start', `url(#${arrowId})`);
        l.setAttribute('marker-end', `url(#${arrowId})`);
      }
      g.appendChild(l);
      return l;
    }
    function addText(g,x,y,text,rot){
      const t = svgEl('text');
      t.setAttribute('x',x); t.setAttribute('y',y);
      t.textContent = text;
      t.setAttribute('fill','#111');
      t.setAttribute('font-size','13');
      t.setAttribute('dominant-baseline','middle');
      t.setAttribute('text-anchor','middle');
      t.setAttribute('paint-order','stroke');
      t.setAttribute('stroke','#fff');
      t.setAttribute('stroke-width','4');
      if (typeof rot === 'number') t.setAttribute('transform', `rotate(${rot} ${x} ${y})`);
      g.appendChild(t);
      return t;
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
        const t = applyPointViewTransform(p[0], p[1], view);
        const tx=t[0], ty=t[1];
        if (!Number.isFinite(tx) || !Number.isFinite(ty)) continue;
        minX = Math.min(minX, tx);
        minY = Math.min(minY, ty);
        maxX = Math.max(maxX, tx);
        maxY = Math.max(maxY, ty);
      }

      if (!Number.isFinite(minX) || !Number.isFinite(minY)) return null;
      const w = Math.max(0, maxX - minX);
      const h = Math.max(0, maxY - minY);
      return { x:minX, y:minY, w, h, cx:(minX+maxX)/2, cy:(minY+maxY)/2 };
    }

    function applyAutoFit(){
      const v = getView();
      if (!lastBBox || lastBBox.w < 2 || lastBBox.h < 2) { fitWrap.setAttribute('transform', ''); return; }

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
      r1.setAttribute('x',0); r1.setAttribute('y',0);
      r1.setAttribute('width',VB_W); r1.setAttribute('height',VB_H);
      r1.setAttribute('fill','none'); r1.setAttribute('stroke','#1e90ff');
      r1.setAttribute('stroke-width','2'); r1.setAttribute('opacity','0.9');
      debugLayer.appendChild(r1);

      if (lastBBox && lastBBox.w>0 && lastBBox.h>0){
        const r2 = svgEl('rect');
        r2.setAttribute('x',lastBBox.x); r2.setAttribute('y',lastBBox.y);
        r2.setAttribute('width',lastBBox.w); r2.setAttribute('height',lastBBox.h);
        r2.setAttribute('fill','none'); r2.setAttribute('stroke','#ff3b30');
        r2.setAttribute('stroke-width','2'); r2.setAttribute('opacity','0.9');
        debugLayer.appendChild(r2);
      }
    }

    function calc(){
      let sumSmm=0;
      (cfg.dims||[]).forEach(d=>{
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
      return JSON.stringify((cfg.dims||[]).map(d=>{
        const o = { key:d.key, type:d.type, label:(d.label||d.key), value:stateVal[d.key] };
        if (d.type==='angle') { o.dir=d.dir||'L'; o.pol=d.pol||'inner'; o.ret=!!d.ret; }
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
      if (tbName) tbName.textContent = cfg.profileName || '—';
      if (tbDate) tbDate.textContent = nowDateStr();
    }

    function serializeSvgForExport(){
      // Always export 2D for clean PDF/SVG
      const was3d = mode3d;
      if (was3d) setMode3d(false);

      const clone = svg.cloneNode(true);
      clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
      clone.setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');

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
        const data = serializeSvgForExport();
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

    // ===== PDF LAYOUT (A4 like your sample) =====
    function buildPdfHtml(svgData, price){
      const mat = currentMaterialLabel() || '—';
      const lenMm = String(clamp(lenEl.value,50,8000));
      const qty = String(clamp(qtyEl.value,1,999));
      const name = (cfg.profileName || '—');
      const date = nowDateStr();

      // If you later want Color/WorkNo etc from backend, we can add cfg fields.
      const color = '—';
      const workNo = '—';

      return `
<!doctype html><html><head><meta charset="utf-8">
<title>${name}</title>
<style>
@page { size: A4 portrait; margin: 0; }
html,body{height:100%; margin:0; padding:0; background:#fff;}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; color:#111;}

.sheet{
  width:210mm; height:297mm;
  box-sizing:border-box;
  padding:10mm;
}
.frame{
  width:100%; height:100%;
  border:1.4px solid #111;
  box-sizing:border-box;
  position:relative;
  padding:8mm;
}
.top-right{
  position:absolute; right:8mm; top:8mm;
  border:1px solid #111;
  padding:4mm 5mm;
  width:62mm;
  font-size:11px;
  line-height:1.35;
}
.top-right .title{font-weight:800; font-size:12px; margin-bottom:2mm;}
.top-right .row{display:flex; justify-content:space-between; gap:6mm;}
.top-right .row span{opacity:.7}

.drawing-area{
  position:absolute;
  left:8mm; right:8mm;
  top:32mm;
  bottom:42mm;
  display:flex;
  align-items:center;
  justify-content:center;
}
.drawing-area .svgbox{
  width:100%;
  height:100%;
  display:flex;
  align-items:center;
  justify-content:center;
}
.drawing-area svg{
  width:100%;
  height:100%;
}

.notes{
  position:absolute;
  left:8mm;
  bottom:42mm;
  font-size:11px;
  line-height:1.4;
}
.notes .box{
  border:1px solid #111;
  padding:2mm 3mm;
  display:inline-block;
  margin-top:2mm;
}
.notes .lab{font-weight:800; margin-right:2mm;}

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
  width:62mm;
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
      <div class="row"><span>Värvitoon</span><strong>${color}</strong></div>
      <div class="row"><span>Materjal</span><strong>${mat}</strong></div>
      <div class="row"><span>Work no.</span><strong>${workNo}</strong></div>
    </div>

    <div class="drawing-area">
      <div class="svgbox">
        ${svgData}
      </div>
    </div>

    <div class="notes">
      <div class="box"><span class="lab">MÄRKUSED:</span></div><br>
      <div class="box">t — serva tagasipööre 10mm</div><br>
      <div class="box">▶ — nähtav pool</div>
    </div>

    <div class="pricebox">
      <div class="row"><span>JM (ilma KM)</span><strong>${price.jmNoVat.toFixed(2)} €</strong></div>
      <div class="row"><span>Materjal (ilma KM)</span><strong>${price.matNoVat.toFixed(2)} €</strong></div>
      <div class="hr"></div>
      <div class="row"><span>Kokku (ilma KM)</span><strong>${price.totalNoVat.toFixed(2)} €</strong></div>
      <div class="row"><span>Kokku (KM-ga)</span><strong>${price.totalVat.toFixed(2)} €</strong></div>
    </div>

    <div class="bottom">
      <div class="cell">
        <div class="big">Steel.ee</div>
        <div class="muted">Tootmine / plekitööd</div>
        <div class="muted">info@steel.ee</div>
      </div>
      <div class="cell">
        <div class="muted">Drawing no.</div>
        <div class="big">—</div>
        <div class="muted">Scale</div>
        <div class="big">auto</div>
      </div>
      <div class="cell">
        <div class="muted">Date</div>
        <div class="big">${date}</div>
        <div class="muted">Profile</div>
        <div class="big">${name}</div>
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

        // Print always 2D (clean technical drawing)
        const was3d = mode3d;
        if (was3d) setMode3d(false);

        render();

        const svgData = serializeSvgForExport();
        const price = calc();
        const html = buildPdfHtml(svgData, price);

        const w = window.open('', '_blank');
        if (!w) { showErr('Print/PDF: pop-up blokk. Luba pop-up ja proovi uuesti.'); if (was3d) setMode3d(true); return; }
        w.document.open();
        w.document.write(html);
        w.document.close();

        if (was3d) setMode3d(true);
      }catch(e){
        showErr('Print/PDF ebaõnnestus.');
      }
    }

    // ===== 3D rendering + interaction =====
    function render3D(pts, segStyle){
      g3d.innerHTML='';

      // Center around canvas center, scale based on fitWrap already (we render in same coords)
      // We build 3D points from 2D points in local space around center
      const v = getView();

      // Transform 2D points into "world-space" before fitWrap. We'll keep them as-is,
      // but recenter to (CX,CY) so camera orbit looks natural.
      const P = pts.map(p => ({x: (p[0]-CX), y:(p[1]-CY), z:0}));

      // Build front/back points (extrusion on +z)
      const depth = cam.depth;
      const F = P.map(p => ({x:p.x, y:p.y, z:0}));
      const B = P.map(p => ({x:p.x, y:p.y, z:depth}));

      // Apply camera rotations and zoom
      function camTransform(p){
        let t = {x:p.x*cam.zoom, y:p.y*cam.zoom, z:p.z*cam.zoom};
        t = rotX(t, cam.rotX);
        t = rotY(t, cam.rotY);
        return t;
      }

      // Project to 2D SVG coords, back to (CX,CY)
      function projToSvg(p){
        const t = camTransform(p);
        const pr = project(t, cam);
        return {x: pr.x + CX, y: pr.y + CY, k: pr.k, z: t.z};
      }

      const Fp = F.map(projToSvg);
      const Bp = B.map(projToSvg);

      // Build faces for each segment (quad between front/back)
      const faces = [];
      for (let i=0;i<Fp.length-1;i++){
        const a = Fp[i], b = Fp[i+1];
        const c = Bp[i+1], d = Bp[i];
        const style = segStyle[i] || 'main';
        // z-sort proxy: average transformed z of original points
        const zAvg = (camTransform(F[i]).z + camTransform(F[i+1]).z + camTransform(B[i]).z + camTransform(B[i+1]).z)/4;
        faces.push({a,b,c,d,style,zAvg, kAvg:(a.k+b.k+c.k+d.k)/4});
      }

      // Sort far to near
      faces.sort((u,v)=> u.zAvg - v.zAvg);

      // Draw side faces
      faces.forEach(f=>{
        const poly = svgEl('polygon');
        poly.setAttribute('points', `${f.a.x},${f.a.y} ${f.b.x},${f.b.y} ${f.c.x},${f.c.y} ${f.d.x},${f.d.y}`);
        poly.setAttribute('fill', shade('#ccc', Math.max(0, Math.min(1, (f.kAvg-0.6)/0.6))));
        poly.setAttribute('opacity', '0.96');
        poly.setAttribute('stroke', '#bdbdbd');
        poly.setAttribute('stroke-width', '1');
        g3d.appendChild(poly);

        // Return segments dashed overlay
        if (f.style === 'return'){
          const l = svgEl('line');
          l.setAttribute('x1', f.a.x); l.setAttribute('y1', f.a.y);
          l.setAttribute('x2', f.b.x); l.setAttribute('y2', f.b.y);
          l.setAttribute('stroke', '#666');
          l.setAttribute('stroke-width', '2');
          l.setAttribute('stroke-dasharray', '6 6');
          l.setAttribute('opacity', '0.9');
          g3d.appendChild(l);
        }
      });

      // Draw front outline on top
      for (let i=0;i<Fp.length-1;i++){
        const a = Fp[i], b = Fp[i+1];
        const style = segStyle[i] || 'main';
        const l = svgEl('line');
        l.setAttribute('x1', a.x); l.setAttribute('y1', a.y);
        l.setAttribute('x2', b.x); l.setAttribute('y2', b.y);
        l.setAttribute('stroke', '#111');
        l.setAttribute('stroke-width', '3');
        if (style === 'return') l.setAttribute('stroke-dasharray', '6 6');
        g3d.appendChild(l);
      }

      // Update lastBBox to include both front/back projected points
      const allPts = [];
      Fp.forEach(p=>allPts.push([p.x,p.y]));
      Bp.forEach(p=>allPts.push([p.x,p.y]));
      const vv = getView();
      lastBBox = calcBBoxFromPts(allPts, vv);
    }

    function setMode3d(on){
      mode3d = !!on;
      if (toggle3dBtn) toggle3dBtn.setAttribute('aria-pressed', mode3d ? 'true' : 'false');

      if (mode3d){
        g2d.style.display = 'none';
        g3d.style.display = '';
        if (reset3dBtn) reset3dBtn.style.display = '';
        svgWrap.classList.add('spb-is-3d');
      } else {
        g2d.style.display = '';
        g3d.style.display = 'none';
        if (reset3dBtn) reset3dBtn.style.display = 'none';
        svgWrap.classList.remove('spb-is-3d');
      }
      render();
    }

    function reset3d(){
      cam.rotX = -22;
      cam.rotY = 28;
      cam.zoom = 1.0;
      cam.depth = 120;
      cam.persp = 900;
      render();
    }

    // Drag orbit on svgWrap
    let isDrag = false;
    let lastX=0, lastY=0;

    function onDown(e){
      if (!mode3d) return;
      isDrag = true;
      svgWrap.classList.add('spb-grabbing');
      const p = getPoint(e);
      lastX = p.x; lastY = p.y;
      e.preventDefault();
    }
    function onMove(e){
      if (!mode3d || !isDrag) return;
      const p = getPoint(e);
      const dx = p.x - lastX;
      const dy = p.y - lastY;
      lastX = p.x; lastY = p.y;

      cam.rotY += dx * 0.25;
      cam.rotX += dy * 0.25;
      cam.rotX = clamp(cam.rotX, -85, 85);

      render();
      e.preventDefault();
    }
    function onUp(){
      if (!mode3d) return;
      isDrag = false;
      svgWrap.classList.remove('spb-grabbing');
    }
    function onWheel(e){
      if (!mode3d) return;
      const delta = Math.sign(e.deltaY);
      cam.zoom *= (delta > 0 ? 0.92 : 1.08);
      cam.zoom = clamp(cam.zoom, 0.55, 2.2);
      render();
      e.preventDefault();
    }
    function getPoint(e){
      if (e.touches && e.touches[0]) return {x:e.touches[0].clientX, y:e.touches[0].clientY};
      return {x:e.clientX, y:e.clientY};
    }

    function render(){
      hideErr();
      const dimMap = buildDimMap();

      fitWrap.setAttribute('transform','');
      world.setAttribute('transform','');

      const out = computePolyline(dimMap);

      if (!mode3d){
        // 2D
        g3d.innerHTML = '';
        renderSegments(out.pts, out.segStyle);
        renderDims(dimMap, out.pts);

        applyViewTweak();
        const v = getView();
        lastBBox = calcBBoxFromPts(out.pts, v);
      } else {
        // 3D
        segs.innerHTML = '';
        dimLayer.innerHTML = '';
        applyViewTweak();
        render3D(out.pts, out.segStyle);
      }

      applyAutoFit();
      renderDebug();

      const price = calc();
      jmEl.textContent = price.jmNoVat.toFixed(2) + ' €';
      matEl.textContent = price.matNoVat.toFixed(2) + ' €';
      novatEl.textContent = price.totalNoVat.toFixed(2) + ' €';
      vatEl.textContent = price.totalVat.toFixed(2) + ' €';

      tbMat.textContent = currentMaterialLabel() || '—';
      tbLen.textContent = String(clamp(lenEl.value, 50, 8000)) + ' mm';
      tbQty.textContent = String(clamp(qtyEl.value, 1, 999));
      tbSum.textContent = String(calc().sumSmm) + ' mm';
    }

    // listeners
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

    matSel.addEventListener('change', render);
    lenEl.addEventListener('input', render);
    qtyEl.addEventListener('input', render);

    if (saveSvgBtn) saveSvgBtn.addEventListener('click', saveSvg);
    if (printBtn) printBtn.addEventListener('click', printPdf);

    if (toggle3dBtn) toggle3dBtn.addEventListener('click', function(){
      setMode3d(!mode3d);
    });
    if (reset3dBtn) reset3dBtn.addEventListener('click', reset3d);

    if (openBtn) openBtn.addEventListener('click', function(){
      render();
      if (formWrap){
        fillWpforms();
        formWrap.style.display='block';
        formWrap.scrollIntoView({behavior:'smooth', block:'start'});
      }
    });

    // 3D interaction events
    svgWrap.addEventListener('mousedown', onDown);
    window.addEventListener('mousemove', onMove);
    window.addEventListener('mouseup', onUp);

    svgWrap.addEventListener('touchstart', onDown, {passive:false});
    window.addEventListener('touchmove', onMove, {passive:false});
    window.addEventListener('touchend', onUp);

    svgWrap.addEventListener('wheel', onWheel, {passive:false});

    // init
    renderDimInputs();
    renderMaterials();
    setTitleBlock();
    setMode3d(false);
    render();
  }

  function boot(){
    const roots = document.querySelectorAll('.spb-front[data-spb]');
    roots.forEach(initOne);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
