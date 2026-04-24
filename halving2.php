<?php
$active = 'halving2';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if (!function_exists('gmp_init')) {
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Crea tu propio halving</title>
    <link rel="stylesheet" href="assets/style.css">
  </head>
  <body>
    <div class="titulo-imagen-contenedor">
      <h1>Crea tu propio halving</h1>
      <img src="assets/img/cadena-bloques.jpg" alt="Imagen blockchain">
    </div>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="card">
      <h2>Falta la extensión GMP</h2>
      <p>Activa <strong>php_gmp</strong> en WampServer (PHP → PHP extensions) y reinicia servicios.</p>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ======== Utilidades exactas (fracciones y decimales) ======== */

// Parsea un decimal "12.5" o "50" a fracción exacta num/den (GMP)
function parse_decimal_to_fraction(string $s): array {
  $s = trim($s);
  $s = str_replace(',', '.', $s);
  if ($s === '') $s = '0';

  $sign = 1;
  if ($s[0] === '-') { $sign = -1; $s = substr($s, 1); }

  if (strpos($s, '.') === false) {
    $num = gmp_init($s === '' ? '0' : $s, 10);
    $den = gmp_init(1, 10);
  } else {
    [$a, $b] = explode('.', $s, 2);
    $a = ($a === '') ? '0' : $a;
    $b = preg_replace('/\D/', '', $b);
    $d = strlen($b);

    $den = gmp_pow(gmp_init(10, 10), $d);
    $num = gmp_add(gmp_mul(gmp_init($a, 10), $den), gmp_init($b === '' ? '0' : $b, 10));
  }

  if ($sign < 0) $num = gmp_neg($num);
  return [$num, $den];
}

// Convierte num/den (GMP) a string decimal exacto (termina si den solo tiene factores 2 y 5; aquí sí)
function fraction_to_decimal_string($num, $den, int $maxDigits = 300): string {
  if (gmp_cmp($den, 0) == 0) return "NaN";
  if (gmp_cmp($num, 0) == 0) return "0";

  $sign = '';
  if (gmp_cmp($num, 0) < 0) { $sign='-'; $num = gmp_abs($num); }

  [$q, $r] = gmp_div_qr($num, $den);
  $intPart = gmp_strval($q, 10);

  if (gmp_cmp($r, 0) == 0) return $sign.$intPart;

  $digits = '';
  $count = 0;
  while (gmp_cmp($r, 0) != 0 && $count < $maxDigits) {
    $r = gmp_mul($r, 10);
    [$dq, $r] = gmp_div_qr($r, $den);
    $digits .= gmp_strval($dq, 10);
    $count++;
  }

  // recorta ceros finales (no cambian D, pero mejora presentación)
  $digits = rtrim($digits, '0');
  if ($digits === '') return $sign.$intPart;

  return $sign.$intPart.'.'.$digits;
}

// Raíz digital de un número decimal (string): suma dígitos ignorando punto hasta quedar en 0..9
function digital_root_decimal_string(string $dec): int {
  $dec = trim($dec);
  if ($dec === '' || $dec === 'NaN') return 0;
  $dec = str_replace(['.', '-', '+'], '', $dec);
  $dec = preg_replace('/\D/', '', $dec);
  if ($dec === '') return 0;

  $sum = 0;
  for ($i=0; $i<strlen($dec); $i++) $sum += (int)$dec[$i];

  while ($sum >= 10) {
    $tmp = 0;
    foreach (str_split((string)$sum) as $ch) $tmp += (int)$ch;
    $sum = $tmp;
  }
  return $sum;
}

// Detecta período mínimo p (si existe) tal que seq[n] = seq[n mod p] para todos n
function find_period(array $seq, int $maxP = 20): ?int {
  $L = count($seq);
  if ($L < 2) return null;
  $maxP = min($maxP, $L-1);

  for ($p=1; $p<=$maxP; $p++) {
    $ok = true;
    for ($i=0; $i<$L; $i++) {
      if ($seq[$i] !== $seq[$i % $p]) { $ok = false; break; }
    }
    if ($ok) return $p;
  }
  return null;
}

/* ======== Poner puntos suspensivos ======== */
function short_decimal(string $s, int $decimals = 15): string {
  $s = trim($s);
  if ($s === '' || $s === 'NaN') return $s;

  $sign = '';
  if ($s[0] === '-') { $sign = '-'; $s = substr($s, 1); }

  if (strpos($s, '.') === false) return $sign.$s;

  [$int, $frac] = explode('.', $s, 2);
  if (strlen($frac) <= $decimals) return $sign.$int.'.'.$frac;

  return $sign.$int.'.'.substr($frac, 0, $decimals).'…';
}



/* ======== Gráfica SVG (no solapar textos) ======== */
function chart_svg(array $seq, string $title, string $yLabel): string {
  $maxN = count($seq)-1;
  $W = 940; $H = 320;
  $padL=70; $padR=30; $padT=38; $padB=74;

  $x0=$padL; $x1=$W-$padR;
  $y0=$padT; $y1=$H-$padB;

  $mapX = fn($x) => $x0 + ($x1-$x0) * ($x / max(1,$maxN));
  $mapY = fn($y) => $y1 - ($y1-$y0) * ($y / 9.0); // 0..9

  $d = "";
  for ($N=0; $N<=$maxN; $N++) {
    $px=$mapX($N); $py=$mapY($seq[$N]);
    $d .= ($N===0 ? "M" : " L")." $px $py";
  }

  $svg = '<svg width="'.$W.'" height="'.$H.'" style="background:#fff;border-radius:12px">';
  $svg .= '<text x="'.$x0.'" y="22" font-size="16" font-weight="700" fill="#111">'.h($title).'</text>';

  // ejes
  $svg .= '<line x1="'.$x0.'" y1="'.$y0.'" x2="'.$x0.'" y2="'.$y1.'" stroke="#333"/>';
  $svg .= '<line x1="'.$x0.'" y1="'.$y1.'" x2="'.$x1.'" y2="'.$y1.'" stroke="#333"/>';

  // ticks Y 0..9
  for ($yy=0; $yy<=9; $yy++) {
    $ty = $mapY($yy);
    $svg .= '<line x1="'.($x0-6).'" y1="'.$ty.'" x2="'.$x0.'" y2="'.$ty.'" stroke="#333"/>';
    $svg .= '<text x="'.($x0-12).'" y="'.($ty+4).'" font-size="12" text-anchor="end" fill="#111">'.$yy.'</text>';
  }

  // ticks X cada 5 y el final
  for ($t=0; $t<=$maxN; $t++) {
    if ($t % 5 !== 0 && $t !== $maxN) continue;
    $tx = $mapX($t);
    $svg .= '<line x1="'.$tx.'" y1="'.$y1.'" x2="'.$tx.'" y2="'.($y1+6).'" stroke="#333"/>';
    $svg .= '<text x="'.$tx.'" y="'.($y1+26).'" font-size="12" text-anchor="middle" fill="#111">'.$t.'</text>';
  }

  // curva
  $svg .= '<path d="'.$d.'" fill="none" stroke="#6e9c8c" stroke-width="3"/>';

  // puntos
  for ($N=0; $N<=$maxN; $N++) {
    $px=$mapX($N); $py=$mapY($seq[$N]);
    $svg .= '<circle cx="'.$px.'" cy="'.$py.'" r="4" fill="#6e9c8c"/>';
  }

  // labels
  $svg .= '<text x="'.(($W)/2).'" y="'.($H-18).'" font-size="12" text-anchor="middle" fill="#111">Halving (N)</text>';
  $svg .= '<text x="18" y="'.(($H)/2).'" font-size="12" text-anchor="middle" fill="#111" transform="rotate(-90 18 '.(($H)/2).')">'.h($yLabel).'</text>';

  $svg .= '</svg>';
  return $svg;
}

/* ======== Formulario / Cálculos ======== */
$blocks = (int)($_POST["blocks"] ?? 210000);
$blocks = max(1, min(2000000, $blocks));

$R0_str = (string)($_POST["R0"] ?? "50");     // recompensa inicial (BTC)
$maxN = (int)($_POST["maxN"] ?? 35);
$maxN = max(1, min(80, $maxN));

$calc = isset($_POST["calc"]);

$rows = [];
$seqD_reward = [];
$seqD_total  = [];
$period_reward = null;
$period_total  = null;

if ($calc) {
  [$num0, $den0] = parse_decimal_to_fraction($R0_str); // R0 = num0/den0

  for ($N=0; $N<=$maxN; $N++) {
    $pow2 = gmp_pow(gmp_init(2, 10), $N);
    $denN = gmp_mul($den0, $pow2);

    // BTC por bloque en N: R_N = R0 / 2^N = num0 / (den0*2^N)
    $rewardDec = fraction_to_decimal_string($num0, $denN);
    $D_reward = digital_root_decimal_string($rewardDec);

    // BTC totales en intervalo N: blocks * R_N
    $numTotal = gmp_mul($num0, gmp_init((string)$blocks, 10));
    $totalDec = fraction_to_decimal_string($numTotal, $denN);
    $D_total = digital_root_decimal_string($totalDec);

    $rows[] = [
      "N" => $N,
      "reward" => $rewardDec,
      "D_reward" => $D_reward,
      "total" => $totalDec,
      "D_total" => $D_total,
    ];

    $seqD_reward[] = $D_reward;
    $seqD_total[]  = $D_total;
  }

  $period_reward = find_period($seqD_reward, 24);
  $period_total  = find_period($seqD_total, 24);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Crea tu propio halving</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <div class="titulo-imagen-contenedor">
    <h1>Crea tu propio halving</h1>
    <img src="assets/img/cadena-bloques.jpg" alt="Imagen blockchain">
  </div>

  <?php require __DIR__ . '/includes/nav.php'; ?>
  

  <div class="card">
    <h2>Parámetros de tu halving</h2>
    <form method="post" class="row" style="align-items:end">
      <div>
        <label>Bloques por halving</label>
        <input type="text" name="blocks" value="<?= h((string)$blocks) ?>">
      </div>
      <div>
        <label>Recompensa inicial (BTC) en N=0</label>
        <input type="text" name="R0" value="<?= h($R0_str) ?>" placeholder="Ej.: 50">
      </div>
      <div>
        <label>Visualizar hasta N =</label>
        <input type="text" name="maxN" value="<?= h((string)$maxN) ?>">
      </div>
      <div style="display:flex;align-items:end;gap:8px;flex-wrap:wrap">
        <button class="primary" name="calc" value="1">🔍 Calcular</button>
      </div>
    </form>
    <div class="card" style="font-size:14px;background:#f0f3f8;margin-top:10px">
      Con los parámetros que metas vas a poder visualizar el comportamiento de tu propio halving.
    </div>
  </div>

  <?php if ($calc): ?>
    <div class="panel">
      <div class="panel-title">Tabla de resultados</div>
	  <div style="background:#fff; padding:10px 12px; border-bottom:1px solid #eee; border-radius:0 0 0 0; font-size:14px;">
		  Nota: denotamos como recompensa por bloque a <span class="mono">R_N</span> y a recompensas totales por bloque a <span class="mono">RT_N</span>.
		</div>

      <div class="table-scroll">
        <table class="tabla">
          <thead>
            <tr>
              <th>N</th>
              <th>R_N</th>
              <th>D(R_N)</th>
              <th>RT_N</th>
              <th>D(RT_N)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h((string)$r["N"]) ?></td>
                <td class="mono" title="<?= h($r["reward"]) ?>"><?= h(short_decimal($r["reward"], 15)) ?></td>
                <td><strong><?= h((string)$r["D_reward"]) ?></strong></td>
                <td class="mono" title="<?= h($r["total"]) ?>"><?= h(short_decimal($r["total"], 15)) ?></td>
                <td><strong><?= h((string)$r["D_total"]) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
	  
    </div>

    <div class="row">
      <div class="card">
        <h2>¿Parece haber patrón?</h2>

        <div style="font-size:14px;line-height:1.6">
          <strong>En D(R_N):</strong><br>
          <?php if ($period_reward !== null): ?>
            ✅ Se detecta periodicidad con período <span class="mono"><?= h((string)$period_reward) ?></span> 
          <?php else: ?>
            ❌ No se detecta un período “simple” (probado hasta 24).
          <?php endif; ?>
          <br><br>

          <strong>En D(RT_N)):</strong><br>
          <?php if ($period_total !== null): ?>
            ✅ Se detecta periodicidad con período <span class="mono"><?= h((string)$period_total) ?></span> 
          <?php else: ?>
            ❌ No se detecta un período “simple” (probado hasta 24).
          <?php endif; ?>

          <div class="card" style="margin-top:12px;background:#f0f3f8">
            Ojo: si eliges pocos N (por ejemplo hasta 8), el “patrón” puede ser engañoso. Cuanto mayor sea el rango, más fiable.
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Resumen de secuencias</h2>
        <div style="font-size:14px;line-height:1.6">
          <?php
			  $pR = $period_reward ?? null;
			  $pT = $period_total ?? null;

			  $showR = ($pR !== null) ? array_slice($seqD_reward, 0, $pR) : array_slice($seqD_reward, 0, min(18, count($seqD_reward)));
			  $showT = ($pT !== null) ? array_slice($seqD_total, 0, $pT) : array_slice($seqD_total, 0, min(18, count($seqD_total)));
			?>
			<strong>Ciclo de D(R_N): </strong>
			<?= h(implode(', ', $showR)) ?>
			<?= ($pR !== null) ? ' … ' : (count($seqD_reward)>18 ? ' …' : '') ?>
			<br>
			<strong>Ciclo de D(RT_N):</strong>
			<?= h(implode(', ', $showT)) ?>
			<?= ($pT !== null) ? ' … ' : (count($seqD_total)>18 ? ' …' : '') ?>

        </div>
      </div>
    </div>

    <?php if ($period_reward !== null || $period_total !== null): ?>
      <div class="card">
        <h2>Representamos los ciclos</h2>

        <div style="background:#fff;border-radius:12px;padding:10px">
          <?= chart_svg($seqD_reward, "Gráfica: D(R_N) vs N", "D(R_N)") ?>
        </div>

        <div style="margin-top:14px;background:#fff;border-radius:12px;padding:10px">
          <?= chart_svg($seqD_total, "Gráfica: D(RT_N) vs N", "D(RT_N)") ?>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</body>
</html>
