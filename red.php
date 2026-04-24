<?php
$active = 'red';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function rand01(): float {
  return mt_rand() / mt_getrandmax();
}

function normalize_row(array $w): array {
  $s = array_sum($w);
  if ($s <= 0) return $w;
  return array_map(fn($x) => $x / $s, $w);
}

/**
 * Genera una matriz de transición τ (n×n) de un paseo aleatorio dirigido.
 * - Si $mode="uniform": pesos aleatorios y normaliza cada fila.
 * - Si $mode="sparse": crea pocos arcos por fila y normaliza.
 * - Si $mode="example4": ejemplo fijo 4x4 como tu figura (si n=4).
 * Si $absorb != null: impone nodo absorbente ℓ.
 */
function build_tau(int $n, string $mode, ?int $absorb): array {
  $tau = array_fill(0, $n, array_fill(0, $n, 0.0));

  if ($mode === "example4" && $n === 4) {
    // índice interno 0..3 (tu nodo 4 es índice 3)
    // τ = [ [0, 0.5, 0, 0.5],
    //       [0.5, 0, 0.25, 0.25],
    //       [0, 0.4, 0.4, 0.2],
    //       [0, 0, 0, 1] ]
    $tau = [
      [0.0, 0.5, 0.0, 0.5],
      [0.5, 0.0, 0.25, 0.25],
      [0.0, 0.4, 0.4, 0.2],
      [0.0, 0.0, 0.0, 1.0],
    ];
  } else {
    for ($i=0; $i<$n; $i++) {
      $w = array_fill(0, $n, 0.0);

      if ($mode === "sparse") {
        // 2 o 3 salidas por fila (incluyendo posible bucle)
        $k = ($n <= 6) ? 2 : 3;
        $targets = [];
        while (count($targets) < $k) {
          $t = random_int(0, $n-1);
          $targets[$t] = true;
        }
        foreach (array_keys($targets) as $j) {
          $w[$j] = 0.2 + rand01(); // peso positivo
        }
      } else {
        // uniform: peso a todos (puede incluir self-loop)
        for ($j=0; $j<$n; $j++) $w[$j] = 0.2 + rand01();
      }

      $row = normalize_row($w);
      for ($j=0; $j<$n; $j++) $tau[$i][$j] = $row[$j];
    }
  }

  // Impone absorbente ℓ si procede
  if ($absorb !== null) {
    $l = $absorb;
    for ($j=0; $j<$n; $j++) $tau[$l][$j] = 0.0;
    $tau[$l][$l] = 1.0;
  }
  return $tau;
}

/**
 * Resuelve sistema lineal A x = b con eliminación gaussiana (float).
 * A: matriz NxN, b: vector N.
 */
function solve_linear(array $A, array $b): ?array {
  $n = count($A);
  // matriz aumentada
  $M = [];
  for ($i=0; $i<$n; $i++) {
    $M[$i] = $A[$i];
    $M[$i][] = $b[$i];
  }

  for ($col=0; $col<$n; $col++) {
    // pivote
    $pivot = $col;
    $max = abs($M[$pivot][$col]);
    for ($r=$col+1; $r<$n; $r++) {
      $v = abs($M[$r][$col]);
      if ($v > $max) { $max=$v; $pivot=$r; }
    }
    if ($max < 1e-12) return null;

    if ($pivot !== $col) {
      $tmp = $M[$col]; $M[$col] = $M[$pivot]; $M[$pivot] = $tmp;
    }

    // normaliza fila pivote
    $pv = $M[$col][$col];
    for ($c=$col; $c<=$n; $c++) $M[$col][$c] /= $pv;

    // elimina en resto
    for ($r=0; $r<$n; $r++) {
      if ($r === $col) continue;
      $factor = $M[$r][$col];
      if (abs($factor) < 1e-15) continue;
      for ($c=$col; $c<=$n; $c++) {
        $M[$r][$c] -= $factor * $M[$col][$c];
      }
    }
  }

  $x = array_fill(0, $n, 0.0);
  for ($i=0; $i<$n; $i++) $x[$i] = $M[$i][$n];
  return $x;
}

/** Calcula μ (tiempos esperados de primera llegada a ℓ) */
function compute_mu(array $tau, int $l): ?array {
  $n = count($tau);
  // Para i=l: μ_l=0.
  // Para i!=l: μ_i - sum_j τ_ij μ_j = 1  -> (I - T)μ = 1 con fila l fijada.
  $A = array_fill(0, $n, array_fill(0, $n, 0.0));
  $b = array_fill(0, $n, 0.0);

  for ($i=0; $i<$n; $i++) {
    if ($i === $l) {
      $A[$i][$i] = 1.0;
      $b[$i] = 0.0;
      continue;
    }
    $A[$i][$i] = 1.0;
    for ($j=0; $j<$n; $j++) $A[$i][$j] -= $tau[$i][$j];
    $b[$i] = 1.0;
  }
  return solve_linear($A, $b);
}

/** Calcula a (probabilidad de absorción en ℓ) */
function compute_a(array $tau, int $l): ?array {
  $n = count($tau);
  // a_l=1
  // a_i - sum_j τ_ij a_j = 0 (i!=l)
  $A = array_fill(0, $n, array_fill(0, $n, 0.0));
  $b = array_fill(0, $n, 0.0);

  for ($i=0; $i<$n; $i++) {
    if ($i === $l) {
      $A[$i][$i] = 1.0;
      $b[$i] = 1.0;
      continue;
    }
    $A[$i][$i] = 1.0;
    for ($j=0; $j<$n; $j++) $A[$i][$j] -= $tau[$i][$j];
    $b[$i] = 0.0;
  }
  return solve_linear($A, $b);
}

/* ===== Parámetros del formulario ===== */
$n = (int)($_POST["n"] ?? 4);
$n = max(3, min(12, $n));

$mode = $_POST["mode"] ?? "example4"; // example4 | sparse | uniform
$absorb_on = isset($_POST["absorb_on"]) ? true : true; // por defecto sí
$ell = (int)($_POST["ell"] ?? $n); // 1..n (humano)

if ($ell < 1) $ell = 1;
if ($ell > $n) $ell = $n;
$l = $ell - 1; // índice 0..n-1

$generated = false;
$tau = [];
$mu = null;
$a = null;

if (isset($_POST["gen"])) {
  $generated = true;
  if ($mode === "example4" && $n !== 4) $mode = "sparse";
  $tau = build_tau($n, $mode, $absorb_on ? $l : null);

  // Si no hay absorbente, no tiene sentido "absorción en ℓ": igualmente puedes calcular "hit" si fuerzas ℓ como absorbente.
  // Para ser fiel a tu TFG, solo calculamos si absorbente está activado.
  if ($absorb_on) {
    $mu = compute_mu($tau, $l);
    $a  = compute_a($tau, $l);
  }
}

/* ===== Layout para dibujo (circular) ===== */
function layout_circle(int $n): array {
  $cx=220; $cy=220; $R=170;
  $pos=[];
  for ($i=0; $i<$n; $i++) {
    $ang = 2*pi()*$i/$n - pi()/2;
    $pos[$i]=[
      "x"=>$cx + $R*cos($ang),
      "y"=>$cy + $R*sin($ang),
    ];
  }
  return $pos;
}

$pos = $generated ? layout_circle($n) : [];
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Red de usuarios</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <div class="titulo-imagen-contenedor">
    <h1>Red de usuarios (paseo aleatorio)</h1>
    <img src="assets/img/cadena-bloques.jpg" alt="Imagen blockchain">
  </div>

  <?php require __DIR__ . '/includes/nav.php'; ?>


  <div class="card">
    <h2>Generar red</h2>
    <form method="post" class="row" style="align-items:end">
      <div>
        <label>Número de nodos n (3–12)</label>
        <input type="text" name="n" value="<?= h((string)$n) ?>">
      </div>

      <div>
        <label>Elige el tipo de probabilidades</label>
        <select name="mode" style="width:95%;padding:8px 10px;border:1px solid #ddd;border-radius:8px">
          <option value="example4" <?= $mode==="example4"?"selected":"" ?>>Ejemplo TFG (solo n=4)</option>
          <option value="sparse"   <?= $mode==="sparse"?"selected":""   ?>>Aleatorias (pocas aristas por nodo)</option>
          <option value="uniform"  <?= $mode==="uniform"?"selected":""  ?>>Aleatorias (todas las transiciones > 0)</option>
        </select>
      </div>

      <div>
        <label>¿Quieres que haya un nodo absorbente? ¿Cuál?</label>
        <div style="display:flex;gap:10px;align-items:center">
          <label style="display:flex;gap:6px;align-items:center;margin:0">
            <input type="checkbox" name="absorb_on" <?= $absorb_on ? "checked" : "" ?>>
            Activar
          </label>

          <div style="flex:1">
            <input type="text" name="ell" value="<?= h((string)$ell) ?>" placeholder="ℓ (1..n)">
          </div>
        </div>
        <div style="font-size:12px;color:#444;margin-top:6px">
          Si activas absorbente, se calculan μ<sub>i</sub> (primera llegada) y a<sub>i</sub> (absorción en ℓ).
        </div>
      </div>

      <div style="display:flex;align-items:end;gap:8px;flex-wrap:wrap">
        <button class="primary" name="gen" value="1">🔄 Generar grafo</button>
      </div>
    </form>
  </div>

  <?php if ($generated): ?>
    <div class="row">
      <div class="card">
        <h2>Representación</h2>
        <div style="background:#fff;border-radius:12px;padding:10px">
          <svg width="440" height="440" style="background:#fff;border-radius:12px">
            <?php
              // Flechas: dibujamos línea + texto prob en el medio
              for ($i=0; $i<$n; $i++) {
                for ($j=0; $j<$n; $j++) {
                  $pij = $tau[$i][$j];
                  if ($pij < 0.10) continue; // para que no se llene de flechas pequeñas
                  $x1 = $pos[$i]["x"]; $y1 = $pos[$i]["y"];
                  $x2 = $pos[$j]["x"]; $y2 = $pos[$j]["y"];

                  // acorta un poco para que no tape los nodos
                  $dx = $x2-$x1; $dy = $y2-$y1;
                  $len = sqrt($dx*$dx + $dy*$dy);
                  if ($len < 1) continue;
                  $ux = $dx/$len; $uy = $dy/$len;
                  $sx = $x1 + 14*$ux; $sy = $y1 + 14*$uy;
                  $ex = $x2 - 14*$ux; $ey = $y2 - 14*$uy;

                  $mx = ($sx+$ex)/2; $my = ($sy+$ey)/2;

                  echo '<line x1="'.h((string)$sx).'" y1="'.h((string)$sy).'" x2="'.h((string)$ex).'" y2="'.h((string)$ey).'" stroke="#9aa" stroke-width="2"/>';
                  echo '<text x="'.h((string)$mx).'" y="'.h((string)$my).'" font-size="12" fill="#111">'.h(number_format($pij,2)).'</text>';
                }
              }
            ?>

            <?php for ($i=0; $i<$n; $i++): ?>
              <circle
                cx="<?= h((string)$pos[$i]["x"]) ?>" cy="<?= h((string)$pos[$i]["y"]) ?>" r="18"
                fill="<?= ($absorb_on && $i===$l) ? "#6e9c8c" : "#3d76b6" ?>"
              />
              <text
                x="<?= h((string)$pos[$i]["x"]) ?>" y="<?= h((string)($pos[$i]["y"]+5)) ?>"
                font-size="14" fill="#fff" text-anchor="middle"
              ><?= h((string)($i+1)) ?></text>
            <?php endfor; ?>
          </svg>
        </div>
        <div style="font-size:14px;margin-top:10px">
          Se muestran aristas con probabilidad ≥ 0.10 para que sea legible.
          <?php if ($absorb_on): ?>
            El nodo absorbente ℓ = <strong><?= h((string)$ell) ?></strong> está en verde.
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <h2>Matriz de transición τ</h2>
        <div class="table-scroll" style="max-height:420px">
          <table class="tabla">
            <thead>
              <tr>
                <th>i\j</th>
                <?php for ($j=1; $j<=$n; $j++): ?>
                  <th><?= $j ?></th>
                <?php endfor; ?>
              </tr>
            </thead>
            <tbody>
              <?php for ($i=0; $i<$n; $i++): ?>
                <tr>
                  <td><strong><?= $i+1 ?></strong></td>
                  <?php for ($j=0; $j<$n; $j++): ?>
                    <td><?= h(number_format($tau[$i][$j], 2)) ?></td>
                  <?php endfor; ?>
                </tr>
              <?php endfor; ?>
            </tbody>
          </table>
        </div>

        <?php if ($mode==="example4" && $n===4): ?>
          <div class="card" style="font-size:14px;background:#f0f3f8">
            Este bloque usa exactamente una matriz estilo tu ejemplo (con nodo 4 absorbente si lo activas).
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($absorb_on): ?>
      <div class="row">
        <div class="card">
          <h2>Tiempos esperados de primera llegada (μ)</h2>
          <div style="font-size:14px">
            Para el nodo absorbente ℓ:
            <span class="mono">μ<sub>ℓ</sub>=0</span> y para <span class="mono">i≠ℓ</span>:
            <span class="mono">μ<sub>i</sub> = 1 + ∑<sub>j</sub> τ<sub>ij</sub> μ<sub>j</sub></span>.
          </div>

          <div class="table-scroll" style="max-height:280px;margin-top:10px">
            <table class="tabla">
              <thead><tr><th>i</th><th>μ<sub>i</sub></th></tr></thead>
              <tbody>
                <?php for ($i=0; $i<$n; $i++): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= $mu ? h(number_format($mu[$i], 4)) : "-" ?></td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <h2>Probabilidad de absorción (a)</h2>
          <div style="font-size:14px">
            <span class="mono">a<sub>ℓ</sub>=1</span> y para <span class="mono">i≠ℓ</span>:
            <span class="mono">a<sub>i</sub> = ∑<sub>j</sub> τ<sub>ij</sub> a<sub>j</sub></span>.
          </div>

          <div class="table-scroll" style="max-height:280px;margin-top:10px">
            <table class="tabla">
              <thead><tr><th>i</th><th>a<sub>i</sub></th></tr></thead>
              <tbody>
                <?php for ($i=0; $i<$n; $i++): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= $a ? h(number_format($a[$i], 4)) : "-" ?></td>
                  </tr>
                <?php endfor; ?>
              </tbody>
            </table>
          </div>

          <div class="card" style="font-size:14px;background:#f0f3f8">
            Interpretación: a<sub>i</sub> es la probabilidad de que la información termine “absorbiéndose” en ℓ empezando en i.
          </div>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</body>
</html>


