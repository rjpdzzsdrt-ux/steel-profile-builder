<?php
if (!defined('ABSPATH')) exit;

class SPB_PDF_Generator {

  public static function uploads_dir() {
    $u = wp_upload_dir();
    $base = trailingslashit($u['basedir']) . 'steel-profile-builder';
    $url  = trailingslashit($u['baseurl']) . 'steel-profile-builder';
    return [$base, $url];
  }

  public static function ensure_dir($dir) {
    if (!file_exists($dir)) {
      wp_mkdir_p($dir);
    }
  }

  public static function sanitize_filename($s) {
    $s = remove_accents($s);
    $s = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $s);
    $s = trim($s, '-');
    if ($s === '') $s = 'profile';
    return strtolower($s);
  }

  public static function mm($v) {
    $n = is_numeric($v) ? floatval($v) : 0.0;
    return max(0.0, $n);
  }

  /** Build a simple 2D production SVG in PHP (no marker defs; dompdf-friendly). */
  public static function build_svg($profile_cfg, $values) {
    // profile_cfg: ['dims'=>[], 'pattern'=>[]]
    $dims = is_array($profile_cfg['dims'] ?? null) ? $profile_cfg['dims'] : [];
    $pattern = is_array($profile_cfg['pattern'] ?? null) ? $profile_cfg['pattern'] : [];

    $dimMap = [];
    foreach ($dims as $d) {
      if (!empty($d['key'])) $dimMap[$d['key']] = $d;
    }

    $state = [];
    foreach ($dims as $d) {
      $k = $d['key'] ?? '';
      if (!$k) continue;
      $min = isset($d['min']) && $d['min'] !== null ? floatval($d['min']) : (($d['type'] ?? '') === 'angle' ? 5 : 10);
      $max = isset($d['max']) && $d['max'] !== null ? floatval($d['max']) : (($d['type'] ?? '') === 'angle' ? 215 : 500);
      $def = isset($d['def']) && $d['def'] !== null ? floatval($d['def']) : $min;

      $val = isset($values[$k]) ? floatval($values[$k]) : $def;
      $val = max($min, min($max, $val));
      $state[$k] = $val;
    }

    $deg2rad = function($d){ return $d * M_PI / 180.0; };
    $turnFromAngle = function($aDeg, $pol){
      $a = floatval($aDeg);
      return ($pol === 'outer') ? $a : (180.0 - $a);
    };

    // Compute polyline in a working coordinate system
    $x = 140.0; $y = 360.0;
    $heading = -90.0;
    $pts = [[$x,$y]];
    $segStyle = [];

    $segKeys = [];
    foreach ($pattern as $k) {
      if (!empty($dimMap[$k]) && (($dimMap[$k]['type'] ?? '') === 'length')) $segKeys[] = $k;
    }
    $totalMm = 0.0;
    foreach ($segKeys as $k) $totalMm += floatval($state[$k] ?? 0);
    $kScale = ($totalMm > 0) ? (520.0 / $totalMm) : 1.0;

    $pendingReturn = false;

    foreach ($pattern as $key) {
      if (empty($dimMap[$key])) continue;
      $meta = $dimMap[$key];

      if (($meta['type'] ?? '') === 'length') {
        $mm = floatval($state[$key] ?? 0);
        $x += cos($deg2rad($heading)) * ($mm * $kScale);
        $y += sin($deg2rad($heading)) * ($mm * $kScale);
        $pts[] = [$x,$y];
        $segStyle[] = $pendingReturn ? 'return' : 'main';
        $pendingReturn = false;
      } else {
        $pol = (($meta['pol'] ?? 'inner') === 'outer') ? 'outer' : 'inner';
        $dir = (strtoupper($meta['dir'] ?? 'L') === 'R') ? -1.0 : 1.0;
        $turn = $turnFromAngle($state[$key] ?? 0, $pol);
        $heading += $dir * $turn;

        if (!empty($meta['ret'])) $pendingReturn = true;
      }
    }

    // Fit to viewBox 820x460
    $pad = 70.0;
    $xs = array_map(fn($p)=>$p[0], $pts);
    $ys = array_map(fn($p)=>$p[1], $pts);
    $minX = min($xs); $maxX = max($xs);
    $minY = min($ys); $maxY = max($ys);
    $w = ($maxX - $minX) ?: 1.0;
    $h = ($maxY - $minY) ?: 1.0;
    $scale = min((800.0 - 2*$pad)/$w, (420.0 - 2*$pad)/$h);

    $pts2 = [];
    foreach ($pts as $p) {
      $pts2[] = [($p[0]-$minX)*$scale + $pad, ($p[1]-$minY)*$scale + $pad];
    }

    // Build SVG lines
    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 820 460" width="820" height="460">';
    $svg[] = '<rect x="0" y="0" width="820" height="460" fill="#fff"/>';
    $svg[] = '<g stroke="#111" fill="none">';

    // Segments
    for ($i=0; $i<count($pts2)-1; $i++) {
      $A = $pts2[$i]; $B = $pts2[$i+1];
      $dash = (($segStyle[$i] ?? 'main') === 'return') ? ' stroke-dasharray="6 6"' : '';
      $svg[] = sprintf(
        '<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke-width="3"%s/>',
        $A[0],$A[1],$B[0],$B[1],$dash
      );
    }
    $svg[] = '</g>';

    // Dimensions (simple: offset line + text, no arrow markers for dompdf stability)
    $svg[] = '<g stroke="#111" fill="#111" font-family="DejaVu Sans, Arial, sans-serif" font-size="13">';
    $OFFSET = 22.0;

    $segIndex = 0;
    foreach ($pattern as $key) {
      if (empty($dimMap[$key])) continue;
      $meta = $dimMap[$key];
      if (($meta['type'] ?? '') !== 'length') continue;

      $pA = $pts2[$segIndex] ?? null;
      $pB = $pts2[$segIndex+1] ?? null;
      if (!$pA || !$pB) { $segIndex++; continue; }

      $Ax = $pA[0]; $Ay = $pA[1];
      $Bx = $pB[0]; $By = $pB[1];
      $vx = $Bx - $Ax; $vy = $By - $Ay;
      $len = hypot($vx,$vy) ?: 1.0;
      $ux = $vx / $len; $uy = $vy / $len;
      $nx = -$uy; $ny = $ux;

      $A2x = $Ax + $nx * $OFFSET; $A2y = $Ay + $ny * $OFFSET;
      $B2x = $Bx + $nx * $OFFSET; $B2y = $By + $ny * $OFFSET;

      // extension lines
      $svg[] = sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke-width="1" opacity="0.35"/>', $Ax,$Ay,$A2x,$A2y);
      $svg[] = sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke-width="1" opacity="0.35"/>', $Bx,$By,$B2x,$B2y);

      // dim line
      $svg[] = sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke-width="1.4"/>', $A2x,$A2y,$B2x,$B2y);

      // little end ticks
      $tick = 6.0;
      $tx = -$nx; $ty = -$ny; // perpendicular to offset
      $svg[] = sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke-width="1"/>', $A2x+$tx*$tick,$A2y+$ty*$tick,$A2x-$tx*$tick,$A2y-$ty*$tick);
      $svg[] = sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f" stroke-width="1"/>', $B2x+$tx*$tick,$B2y+$ty*$tick,$B2x-$tx*$tick,$B2y-$ty*$tick);

      $midX = ($A2x + $B2x)/2.0;
      $midY = ($A2y + $B2y)/2.0 - 6.0;

      $ang = atan2($uy,$ux) * 180.0 / M_PI;
      if ($ang > 90) $ang -= 180;
      if ($ang < -90) $ang += 180;

      $label = htmlspecialchars($key . ' ' . intval(round($state[$key] ?? 0)) . 'mm', ENT_QUOTES, 'UTF-8');
      $svg[] = sprintf(
        '<text x="%.2f" y="%.2f" text-anchor="middle" dominant-baseline="middle" transform="rotate(%.2f %.2f %.2f)">%s</text>',
        $midX,$midY,$ang,$midX,$midY,$label
      );

      $segIndex++;
    }

    $svg[] = '</g>';
    $svg[] = '</svg>';

    return implode("\n", $svg);
  }

  public static function build_pdf_html($title, $meta_lines, $dims_table_html, $svg_markup) {
    $titleEsc = esc_html($title);

    $metaHtml = '';
    foreach ($meta_lines as $line) {
      $metaHtml .= '<div>'.esc_html($line).'</div>';
    }

    return '
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #111; }
  .wrap { width: 100%; }
  .h1 { font-size: 18px; font-weight: 800; margin: 0 0 6px; }
  .meta { margin: 0 0 12px; color:#222; }
  .grid { width:100%; }
  .left { width: 62%; vertical-align: top; }
  .right { width: 38%; vertical-align: top; }
  .box { border:1px solid #ddd; padding:10px; border-radius:10px; }
  .note { font-size: 11px; color:#444; margin-top:8px;}
  table.tbl { width:100%; border-collapse: collapse; font-size: 12px; }
  .tbl th, .tbl td { border:1px solid #ddd; padding:6px 8px; text-align:left; }
  .tbl th { background:#f5f5f5; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="h1">'.$titleEsc.'</div>
    <div class="meta">'.$metaHtml.'</div>

    <table class="grid" cellspacing="10" cellpadding="0">
      <tr>
        <td class="left">
          <div class="box">
            '.$svg_markup.'
            <div class="note">Pidev joon = värvitud pool. Katkendjoon = tagasipööre (krunditud pool).</div>
          </div>
        </td>
        <td class="right">
          <div class="box">
            <div style="font-weight:700;margin-bottom:8px;">Mõõdud</div>
            '.$dims_table_html.'
          </div>
        </td>
      </tr>
    </table>
  </div>
</body>
</html>';
  }

  public static function make_dims_table_html($dims_json_decoded) {
    // $dims_json_decoded: array of {key,label,type,value, ...}
    if (!is_array($dims_json_decoded)) $dims_json_decoded = [];
    $rows = '';
    foreach ($dims_json_decoded as $d) {
      $k = esc_html($d['key'] ?? '');
      $lbl = esc_html($d['label'] ?? $k);
      $type = ($d['type'] ?? '') === 'angle' ? '°' : 'mm';
      $val = esc_html((string)($d['value'] ?? ''));
      if ($k === '') continue;
      $rows .= '<tr><td>'.$lbl.'</td><td style="text-align:right">'.$val.' '.$type.'</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td colspan="2">—</td></tr>';

    return '<table class="tbl"><thead><tr><th>Mõõt</th><th style="text-align:right">Väärtus</th></tr></thead><tbody>'.$rows.'</tbody></table>';
  }

  public static function generate_pdf_file($profile_cfg, $values_map, $dims_payload_arr, $title, $meta_lines) {
    if (!class_exists('\Dompdf\Dompdf')) {
      return new WP_Error('spb_no_dompdf', 'DOMPDF puudub (vendor/autoload.php).');
    }

    $svg = self::build_svg($profile_cfg, $values_map);
    $dims_table = self::make_dims_table_html($dims_payload_arr);
    $html = self::build_pdf_html($title, $meta_lines, $dims_table, $svg);

    $dompdf = new \Dompdf\Dompdf([
      'isRemoteEnabled' => false,
      'isHtml5ParserEnabled' => true,
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $pdf = $dompdf->output();

    [$baseDir, $baseUrl] = self::uploads_dir();
    $sub = date('Y/m');
    $dir = trailingslashit($baseDir) . $sub;
    self::ensure_dir($dir);

    $file = self::sanitize_filename($title) . '-' . date('Ymd-His') . '.pdf';
    $path = trailingslashit($dir) . $file;
    file_put_contents($path, $pdf);

    $url = trailingslashit($baseUrl) . $sub . '/' . $file;
    return ['path'=>$path, 'url'=>$url, 'file'=>$file];
  }

  public static function extract_customer_email_from_wpforms($fields) {
    // Try find email-type field or anything containing '@'
    if (!is_array($fields)) return '';
    foreach ($fields as $f) {
      if (!is_array($f)) continue;
      $type = $f['type'] ?? '';
      $val  = $f['value'] ?? '';
      if ($type === 'email' && is_email($val)) return $val;
    }
    foreach ($fields as $f) {
      if (!is_array($f)) continue;
      $val = $f['value'] ?? '';
      if (is_email($val)) return $val;
    }
    return '';
  }

  public static function extract_customer_line($fields, $needleTypes = []) {
    if (!is_array($fields)) return '';
    foreach ($fields as $f) {
      if (!is_array($f)) continue;
      $type = $f['type'] ?? '';
      $val  = trim((string)($f['value'] ?? ''));
      if ($val === '') continue;
      if (in_array($type, $needleTypes, true)) return $val;
    }
    return '';
  }

  public static function send_pdf_email($pdf_path, $subject, $body_lines, $to_admin = true, $to_customer_email = '') {
    $to = [];

    if ($to_admin) {
      $admin = get_option('admin_email');
      if (is_email($admin)) $to[] = $admin;
    }
    if ($to_customer_email && is_email($to_customer_email)) {
      $to[] = $to_customer_email;
    }

    $to = array_values(array_unique($to));
    if (!$to) return;

    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $body = implode("\n", array_map('sanitize_text_field', $body_lines));

    wp_mail($to, $subject, $body, $headers, [$pdf_path]);
  }
}
