<?php
$active = 'halving';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* === Parámetros “Bitcoin” (según tu TFG) === */
$EPOCH_BLOCKS = 210000;      // 210.000
$CURRENT_N = 4;              // “estamos en el cuarto ciclo”: N = 4 (hipótesis de tu demo)
$R0_SATS = 50 * 100000000;   // 50 BTC en satoshis (para evitar floats)

/* f(i) del patrón 6-periódico (según tu TFG) */
function f_i(int $i): int {
  return match($i) {
    1 => 5,
    2 => 7,
    3 => 8,
    4 => 4,
    5 => 2,
    6 => 1,
    default => 5,
  };
}

function reward_sats(int $N, int $R0_SATS): int {
  // R_N = 50/2^N BTC -> en satoshis es un shift
  return ($N >= 63) ? 0 : ($R0_SATS >> $N);
}

function format_btc(int $sats): string {
  $btc = $sats / 100000000.0;
  $s = number_format($btc, 8, '.', '');
  $s = rtrim($s, '0');
  $s = rtrim($s, '.');
  return $s === '' ? '0' : $s;
}

/* === Entrada === */
$n_in = $_POST['block_n'] ?? '';
$n = null;
$result = null;

if (isset($_POST['calc'])) {
  $n = (int)trim($n_in);
  if ($n < 0) $n = 0;

  // N = floor(n / 210000)
  $N = intdiv($n, $EPOCH_BLOCKS);
  $low = $EPOCH_BLOCKS * $N;
  $high = $EPOCH_BLOCKS * ($N + 1);

  $status = '';
  if ($N < $CURRENT_N) {
    $status = "✅ Este bloque ya ha sido minado (porque N = $N < $CURRENT_N).";
  } elseif ($N === $CURRENT_N) {
    $status = "⚠️ Este bloque pertenece al ciclo actual (N = $CURRENT_N). Quizá ya haya sido minado (depende de la altura actual exacta).";
  } else {
    $status = "⏳ Este bloque aún NO se ha minado (porque N = $N > $CURRENT_N).";
  }

  $rewardSats = reward_sats($N, $R0_SATS);
  $rewardBTC  = format_btc($rewardSats);

  $i = ($N % 6) + 1;      // i = (N mod 6)+1
  $fi = f_i($i);

  $result = [
    "n" => $n,
    "N" => $N,
    "low" => $low,
    "high" => $high,
    "status" => $status,
    "rewardBTC" => $rewardBTC,
    "i" => $i,
    "fi" => $fi,
  ];
}

/* === Gráfica f(i) vs N === */
$maxN = 35; // como tu ejemplo (puedes cambiar si quieres)
$points = [];
for ($N=0; $N<=$maxN; $N++) {
  $i = ($N % 6) + 1;
  $points[] = [$N, f_i($i)];
}

function build_chart_svg(array $points, int $maxN, ?int $highlightN): string {
  $W = 940; $H = 320;
  $padL = 70; $padR = 30; $padT = 30; $padB = 70; // más margen abajo para que no se pisen textos

  $x0 = $padL; $x1 = $W - $padR;
  $y0 = $padT; $y1 = $H - $padB;

  $mapX = function(int $x) use ($x0, $x1, $maxN) {
    return $x0 + ($x1 - $x0) * ($x / max(1, $maxN));
  };
  $mapY = function(int $y) use ($y0, $y1) {
    // f(i) vive en {1..9} (aquí solo {1,2,4,5,7,8})
    return $y1 - ($y1 - $y0) * (($y - 1) / 8.0);
  };

  // path
  $d = "";
  foreach ($points as $idx => [$x,$y]) {
    $px = $mapX($x);
    $py = $mapY($y);
    $d .= ($idx === 0 ? "M" : " L") . " $px $py";
  }

  $svg = '<svg width="'.$W.'" height="'.$H.'" style="background:#fff;border-radius:12px">';

  // ejes
  $svg .= '<line x1="'.$x0.'" y1="'.$y0.'" x2="'.$x0.'" y2="'.$y1.'" stroke="#333"/>';
  $svg .= '<line x1="'.$x0.'" y1="'.$y1.'" x2="'.$x1.'" y2="'.$y1.'" stroke="#333"/>';

  // ticks Y (1..9)
  for ($yy=1; $yy<=9; $yy++) {
    $ty = $mapY($yy);
    $svg .= '<line x1="'.($x0-6).'" y1="'.$ty.'" x2="'.$x0.'" y2="'.$ty.'" stroke="#333"/>';
    $svg .= '<text x="'.($x0-12).'" y="'.($ty+4).'" font-size="12" text-anchor="end" fill="#111">'.$yy.'</text>';
  }

  // ticks X: cada 5 y también múltiplos de 6 para ver periodicidad
  for ($t=0; $t<=$maxN; $t++) {
    if ($t % 5 !== 0 && $t % 6 !== 0 && $t !== $maxN) continue;
    $tx = $mapX($t);
    $svg .= '<line x1="'.$tx.'" y1="'.$y1.'" x2="'.$tx.'" y2="'.($y1+6).'" stroke="#333"/>';
    // etiqueta abajo, sin solapar
    $svg .= '<text x="'.$tx.'" y="'.($y1+26).'" font-size="12" text-anchor="middle" fill="#111">'.$t.'</text>';
  }

  // línea vertical destacada en N
  if ($highlightN !== null && $highlightN >= 0 && $highlightN <= $maxN) {
    $hx = $mapX($highlightN);
    $svg .= '<line x1="'.$hx.'" y1="'.$y0.'" x2="'.$hx.'" y2="'.$y1.'" stroke="#6e9c8c" stroke-width="2" stroke-dasharray="6,6"/>';
    $svg .= '<text x="'.$hx.'" y="'.($y0-8).'" font-size="12" text-anchor="middle" fill="#6e9c8c">N = '.$highlightN.'</text>';
  }

  // curva
  $svg .= '<path d="'.$d.'" fill="none" stroke="#6e9c8c" stroke-width="3"/>';

  // puntos
  foreach ($points as [$x,$y]) {
    $px = $mapX($x);
    $py = $mapY($y);
    $svg .= '<circle cx="'.$px.'" cy="'.$py.'" r="4" fill="#6e9c8c"/>';
  }

  // labels
  $svg .= '<text x="'.(($W)/2).'" y="'.($H-18).'" font-size="12" text-anchor="middle" fill="#111">Halving (N)</text>';
  $svg .= '<text x="18" y="'.(($H)/2).'" font-size="12" text-anchor="middle" fill="#111" transform="rotate(-90 18 '.(($H)/2).')">f(i)</text>';

  $svg .= '</svg>';
  return $svg;
}

$highlightN = $result ? $result["N"] : null;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Halving</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <div class="titulo-imagen-contenedor">
    <h1>Halving de Bitcoin</h1>
    <img src="assets/img/cadena-bloques.jpg" alt="Imagen blockchain">
  </div>

  <?php require __DIR__ . '/includes/nav.php'; ?>
  

  <div class="card">
    <h2>Comprobar bloque</h2>
    <form method="post" class="row" style="align-items:end">
      <div>
        <label>Introduce un número de bloque para obtener su información con respeto al halving.</label>
        <input type="text" name="block_n" value="<?= h((string)$n_in) ?>" placeholder="Ej.: 215000" required>
      </div>
      <div style="display:flex;align-items:end;gap:8px;flex-wrap:wrap">
        <button class="primary" name="calc" value="1">🔍 Calcular</button>
      </div>
    </form>
  </div>

  <?php if ($result): ?>
    
  <div class="card">
	<h2>Resultado</h2>
	<table>
	  <tbody>
		<tr><td><strong>n</strong></td><td><?= h((string)$result["n"]) ?></td></tr>
		<tr><td><strong>Halving (N)</strong></td><td><?= h((string)$result["N"]) ?></td></tr>
		<tr>
		  <td><strong>Desigualdad</strong></td>
		  <td class="mono">
			<?= h((string)$result["low"]) ?> ≤ <?= h((string)$result["n"]) ?> &lt; <?= h((string)$result["high"]) ?>
		  </td>
		</tr>
		<tr><td><strong>Estado</strong></td><td><?= h($result["status"]) ?></td></tr>
		<tr><td><strong>Recompensa del bloque</strong></td><td><?= h($result["rewardBTC"]) ?> BTC</td></tr>
		<tr><td><strong>i = (N mod 6)+1</strong></td><td><?= h((string)$result["i"]) ?></td></tr>
		<tr><td><strong>f(i)</strong></td><td><strong><?= h((string)$result["fi"]) ?></strong></td></tr>
	  </tbody>
	</table>

	<div class="card" style="font-size:14px;background:#f0f3f8">
	  Nota: la recompensa se calcula como <span class="mono">R_N = 50 / 2^N</span>.
	</div>
  </div>

  <div class="card">
	<h2>Gráfica: f(i) vs N</h2>
	<?= build_chart_svg($points, $maxN, ($highlightN !== null ? min($highlightN, $maxN) : null)) ?>
	<div style="font-size:14px;margin-top:10px">
	  La línea discontinua marca el <strong>N</strong> obtenido a partir del bloque <span class="mono">B_n</span>.
	  (La gráfica muestra N hasta <?= h((string)$maxN) ?>.)
	</div>
  </div>
    
  <?php else: ?>
    <div class="card">
      <h2>Gráfica: f(i) vs N</h2>
      <?= build_chart_svg($points, $maxN, null) ?>
      <div style="font-size:14px;margin-top:10px">
        Introduce un <span class="mono">n</span> para marcar su halving <span class="mono">N</span> en la gráfica.
      </div>
    </div>
  <?php endif; ?>

</body>
</html>



