<?php
if (!defined('ABSPATH')) exit;

class SPB_PDF_Generator {

  public function __construct() {
    add_action('wp_ajax_spb_download_pdf', [$this, 'handle_download']);
    add_action('wp_ajax_nopriv_spb_download_pdf', [$this, 'handle_download']);
  }

  private function to_num($v, $fallback = 0.0) {
    if ($v === '' || $v === null) return $fallback;
    $n = floatval($v);
    return is_finite($n) ? $n : $fallback;
  }

  private function clamp($n, $min, $max) {
    $n = $this->to_num($n, $min);
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
  }

  private function deg2rad($d) {
    return $d * M_PI / 180.0;
  }

  // JS loogika: inner -> (180 - a), outer -> a
  private function turn_from_angle($a_deg, $pol) {
    $a = $this->to_num($a_deg, 0.0);
    return ($pol === 'outer') ? $a : (180.0 - $a);
  }

  private function vec($x, $y) { return ['x'=>$x, 'y'=>$y]; }
  private function add($a, $b) { return ['x'=>$a['x']+$b['x'], 'y'=>$a['y']+$b['y']]; }
  private function sub($a, $b) { return ['x'=>$a['x']-$b['x'], 'y'=>$a['y']-$b['y']]; }
  private function mul($a, $k) { return ['x'=>$a['x']*$k, 'y'=>$a['y']*$k]; }
  private function vlen($v) { $l = hypot($v['x'], $v['y']); return ($l > 0) ? $l : 1.0; }
  private function norm($v) { $l = $this->vlen($v); return ['x'=>$v['x']/$l, 'y'=>$v['y']/$l]; }
  private function perp($v) { return ['x'=>-$v['y'], 'y'=>$v['x']]; }

  private function build_dim_map($dims) {
    $map = [];
    foreach ($dims as $d) {
      if (!empty($d['key'])) $map[$d['key']] = $d;
    }
    return $map;
  }

  private function compute_polyline($pattern, $dimMap, $state) {
    $x = 140; $y = 360;
    $heading = -90; // deg
    $pts = [[$x,$y]];
    $segStyle = []; // 'main'|'return'

    // scale by sum of length mm like JS
    $segKeys = [];
    foreach ($pattern as $k) {
      if (!empty($dimMap[$k]) && ($dimMap[$k]['type'] ?? '') === 'length') $segKeys[] = $k;
    }
    $totalMm = 0.0;
    foreach ($segKeys as $k) $totalMm += $this->to_num($state[$k] ?? 0, 0);

    $kScale = ($totalMm > 0) ? (520.0 / $totalMm) : 1.0;

    $pendingReturn = false;

    foreach ($pattern as $key) {
      if (empty($dimMap[$key])) continue;
      $meta = $dimMap[$key];

      if (($meta['type'] ?? '') === 'length') {
        $mm = $this->to_num($state[$key] ?? 0, 0);
        $dx = cos($this->deg2rad($heading)) * ($mm * $kScale);
        $dy = sin($this->deg2rad($heading)) * ($mm * $kScale);
        $x += $dx; $y += $dy;
        $pts[] = [$x,$y];

        $segStyle[] = $pendingReturn ? 'return' : 'main';
        $pendingReturn = false;
      } else {
        $pol = (($meta['pol'] ?? '') === 'outer') ? 'outer' : 'inner';
        $dir = (strtoupper($meta['dir'] ?? 'L') === 'R') ? -1 : 1;
        $turn = $this->turn_from_angle($state[$key] ?? 0, $pol);
        $heading += $dir * $turn;

        if (!empty($meta['ret'])) $pendingReturn = true;
      }
    }

    // fit to viewBox (same as JS)
    $pad = 70;
    $xs = array_map(fn($p)=>$p[0], $pts);
    $ys = array_map(fn($p)=>$p[1], $pts);
    $minX = min($xs); $maxX = max($xs);
    $minY = min($ys); $maxY = max($ys);
    $w = ($maxX - $minX) ?: 1;
    $h = ($maxY - $minY) ?: 1;
    $scale = min((800 - 2*$pad)/$w, (420 - 2*$pad)/$h);

    $pts2 = [];
    foreach ($pts as $p) {
      $pts2[] = [
        ($p[0] - $minX) * $scale + $pad,
        ($p[1] - $minY) * $scale + $pad
      ];
    }

    return ['pts'=>$pts2, 'segStyle'=>$segStyle];
  }

  private function svg_dimension_group($A, $B, $label, $offsetPx, $arrowId) {
    // draw dimension lines like JS, but we can flip side by using negative offsetPx (we will)
    $v = $this->sub($B, $A);
    $vHat = $this->norm($v);
    $nHat = $this->norm($this->perp($vHat));
    $off = $this->mul($nHat, $offsetPx);

    $A2 = $this->add($A, $off);
    $B2 = $this->add($B, $off);

    // angle for label
    $ang = atan2($vHat['y'], $vHat['x']) * 180 / M_PI;
    if ($ang > 90) $ang -= 180;
    if ($ang < -90) $ang += 180;

    $mid = $this->mul($this->add($A2, $B2), 0.5);

    $x1=$A['x']; $y1=$A['y']; $x2=$A2['x']; $y2=$A2['y'];
    $x3=$B['x']; $y3=$B['y']; $x4=$B2['x']; $y4=$B2['y'];

    // extension lines
    $out = '';
    $out .= '<line x1="'.esc_attr($x1).'" y1="'.esc_attr($y1).'" x2="'.esc_attr($x2).'" y2="'.esc_attr($y2).'" stroke="#111" stroke-width="1" opacity="0.35" />';
    $out .= '<line x1="'.esc_attr($x3).'" y1="'.esc_attr($y3).'" x2="'.esc_attr($x4).'" y2="'.esc_attr($y4).'" stroke="#111" stroke-width="1" opacity="0.35" />';

    // dim line with arrows
    $out .= '<line x1="'.esc_attr($A2['x']).'" y1="'.esc_attr($A2['y']).'" x2="'.esc_attr($B2['x']).'" y2="'.esc_attr($B2['y']).'" stroke="#111" stroke-width="1.4" marker-start="url(#'.esc_attr($arrowId).')" marker-end="url(#'.esc_attr($arrowId).')" />';

    // label
    $out .= '<text x="'.esc_attr($mid['x']).'" y="'.esc_attr($mid['y'] - 6).'" fill="#111" font-size="13" dominant-baseline="middle" text-anchor="middle" transform="rotate('.esc_attr($ang).' '.esc_attr($mid['x']).' '.esc_attr($mid['y'] - 6).')">'.esc_html($label).'</text>';

    return $out;
  }

  private function build_svg($cfg, $state, $detailLenMm, $qty) {
    $dims = is_array($cfg['dims'] ?? null) ? $cfg['dims'] : [];
    $pattern = is_array($cfg['pattern'] ?? null) ? $cfg['pattern'] : [];

    $dimMap = $this->build_dim_map($dims);
    $poly = $this->compute_polyline($pattern, $dimMap, $state);
    $pts = $poly['pts'];
    $segStyle = $poly['segStyle'];

    $arrowId = 'spbPdfArrow_' . wp_generate_uuid4();

    $svg = '';
    $svg .= '<svg viewBox="0 0 820 460" width="100%" height="360" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<defs>';
    $svg .= '<marker id="'.esc_attr($arrowId).'" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">';
    $svg .= '<path d="M 0 0 L 10 5 L 0 10 z" fill="#111"></path>';
    $svg .= '</marker>';
    $svg .= '</defs>';

    // segments
    for ($i=0; $i<count($pts)-1; $i++) {
      $A = $pts[$i];
      $B = $pts[$i+1];
      $dash = (($segStyle[$i] ?? 'main') === 'return') ? ' stroke-dasharray="6 6"' : '';
      $svg .= '<line x1="'.esc_attr($A[0]).'" y1="'.esc_attr($A[1]).'" x2="'.esc_attr($B[0]).'" y2="'.esc_attr($B[1]).'" stroke="#111" stroke-width="3"'.$dash.' />';
    }

    // dimensions (flip to the other side => negative offset)
    $OFFSET = -22; // <-- see puts it on the “other side”
    $segIndex = 0;
    foreach ($pattern as $key) {
      $meta = $dimMap[$key] ?? null;
      if (!$meta) continue;
      if (($meta['type'] ?? '') === 'length') {
        $pA = $pts[$segIndex] ?? null;
        $pB = $pts[$segIndex+1] ?? null;
        if ($pA && $pB) {
          $A = $this->vec($pA[0], $pA[1]);
          $B = $this->vec($pB[0], $pB[1]);
          $label = $key.' '.($state[$key] ?? 0).'mm';
          $svg .= $this->svg_dimension_group($A, $B, $label, $OFFSET, $arrowId);
        }
        $segIndex++;
      }
    }

    $svg .= '</svg>';
    return $svg;
  }

  private function build_dims_table_rows($cfg, $state) {
    $dims = is_array($cfg['dims'] ?? null) ? $cfg['dims'] : [];
    $rows = '';
    foreach ($dims as $d) {
      $key = $d['key'] ?? '';
      if (!$key) continue;

      $type = (($d['type'] ?? '') === 'angle') ? 'angle' : 'length';
      $label = $d['label'] ?? $key;
      $val = $state[$key] ?? '';

      $unit = ($type === 'angle') ? '°' : 'mm';

      $dir = ($type === 'angle') ? (strtoupper($d['dir'] ?? 'L') === 'R' ? 'R' : 'L') : '—';
      $pol = ($type === 'angle') ? (($d['pol'] ?? 'inner') === 'outer' ? 'Väljast' : 'Seest') : '—';
      $ret = ($type === 'angle') ? (!empty($d['ret']) ? 'Jah' : 'Ei') : '—';

      $rows .= '<tr>';
      $rows .= '<td>'.esc_html($key).'</td>';
      $rows .= '<td>'.esc_html($label).'</td>';
      $rows .= '<td style="text-align:right">'.esc_html($val).'</td>';
      $rows .= '<td>'.esc_html($unit).'</td>';
      $rows .= '<td>'.esc_html($dir).'</td>';
      $rows .= '<td>'.esc_html($pol).'</td>';
      $rows .= '<td>'.esc_html($ret).'</td>';
      $rows .= '</tr>';
    }
    return $rows;
  }

  public function handle_download() {
    // Basic required fields
    $nonce = sanitize_text_field($_POST['nonce'] ?? '');
    $payload_json = wp_unslash($_POST['payload'] ?? '');
    $payload = json_decode($payload_json, true);
    if (!is_array($payload)) {
      wp_die('Bad payload', 400);
    }

    $profileId = intval($payload['profileId'] ?? 0);
    if (!$profileId) wp_die('Missing profileId', 400);

    // Nonce per profile
    if (!$nonce || !wp_verify_nonce($nonce, 'spb_pdf_'.$profileId)) {
      wp_die('Invalid nonce', 403);
    }

    $post = get_post($profileId);
    if (!$post || $post->post_type !== Steel_Profile_Builder::CPT) {
      wp_die('Invalid profile', 404);
    }

    // load cfg from post meta (source of truth)
    $dims    = get_post_meta($profileId, '_spb_dims', true);
    $pattern = get_post_meta($profileId, '_spb_pattern', true);

    if (!is_array($dims) || !$dims) wp_die('No dims', 400);
    if (!is_array($pattern) || !$pattern) $pattern = [];

    $cfg = [
      'profileId' => $profileId,
      'profileName' => get_the_title($profileId),
      'dims' => $dims,
      'pattern' => $pattern,
    ];

    // sanitize state values: use cfg dims min/max
    $state_in = is_array($payload['state'] ?? null) ? $payload['state'] : [];
    $state = [];

    foreach ($dims as $d) {
      $key = $d['key'] ?? '';
      if (!$key) continue;

      $type = (($d['type'] ?? '') === 'angle') ? 'angle' : 'length';
      $min = isset($d['min']) && $d['min'] !== null ? floatval($d['min']) : ($type === 'angle' ? 5 : 10);
      $max = isset($d['max']) && $d['max'] !== null ? floatval($d['max']) : ($type === 'angle' ? 215 : 500);
      $def = isset($d['def']) && $d['def'] !== null ? floatval($d['def']) : $min;

      $val = $state_in[$key] ?? $def;
      $state[$key] = $this->clamp($val, $min, $max);
    }

    $detailLenMm = $this->clamp($payload['detail_length_mm'] ?? 2000, 50, 8000);
    $qty = intval($this->clamp($payload['qty'] ?? 1, 1, 999));

    // Build SVG + HTML
    $svg = $this->build_svg($cfg, $state, $detailLenMm, $qty);
    $rows = $this->build_dims_table_rows($cfg, $state);

    $date = date_i18n('Y-m-d H:i');

    $html = '<!doctype html><html><head><meta charset="utf-8">
      <style>
        body{font-family: DejaVu Sans, Arial, sans-serif; font-size:12px; color:#111;}
        .wrap{padding:10px 8px;}
        .h1{font-size:18px; font-weight:800; margin:0 0 6px;}
        .meta{opacity:.75; margin:0 0 12px;}
        .grid{display:block;}
        .box{border:1px solid #ddd; border-radius:10px; padding:10px; margin-bottom:10px;}
        .note{font-size:11px; opacity:.7; margin-top:8px;}
        table{width:100%; border-collapse:collapse;}
        th,td{border:1px solid #ddd; padding:6px 7px;}
        th{background:#f5f5f5; text-align:left;}
        .r{text-align:right;}
      </style>
      </head><body><div class="wrap">

      <div class="h1">'.esc_html($cfg['profileName']).' — Tootmisjoonis</div>
      <div class="meta">Kuupäev: '.esc_html($date).' &nbsp;|&nbsp; Detaili pikkus: '.esc_html($detailLenMm).' mm &nbsp;|&nbsp; Kogus: '.esc_html($qty).'</div>

      <div class="box">
        '.$svg.'
        <div class="note">Pidev joon = värvitud pool. Katkendjoon = tagasipööre (krunditud pool).</div>
      </div>

      <div class="box">
        <div style="font-weight:800; margin-bottom:8px;">Mõõdud</div>
        <table>
          <thead>
            <tr>
              <th style="width:60px;">Key</th>
              <th>Silt</th>
              <th style="width:80px;" class="r">Väärtus</th>
              <th style="width:50px;">Ühik</th>
              <th style="width:50px;">L/R</th>
              <th style="width:80px;">Nurk</th>
              <th style="width:90px;">Tagasipööre</th>
            </tr>
          </thead>
          <tbody>'.$rows.'</tbody>
        </table>
      </div>

    </div></body></html>';

    // DOMPDF
    $autoload = trailingslashit(plugin_dir_path(__FILE__)) . 'vendor/autoload.php';
    if (!file_exists($autoload)) {
      wp_die('vendor/autoload.php missing', 500);
    }
    require_once $autoload;

    if (!class_exists('\Dompdf\Dompdf')) {
      wp_die('DOMPDF missing', 500);
    }

    $dompdf = new \Dompdf\Dompdf([
      'isRemoteEnabled' => false,
      'isHtml5ParserEnabled' => true,
    ]);

    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'tootmisjoonis-' . sanitize_title($cfg['profileName']) . '-' . date_i18n('Ymd-His') . '.pdf';

    // Output as download
    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo $dompdf->output();
    exit;
  }
}

new SPB_PDF_Generator();

