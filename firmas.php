<?php
session_start();
$active = 'firmas';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function hexpad(string $hex, int $len=64): string {
  $hex = strtolower(ltrim($hex, "0x"));
  return str_pad($hex, $len, "0", STR_PAD_LEFT);
}
function short_hex(string $hex, int $front=28, int $back=12): string {
  return $hex; // sin recorte
  if ($hex === "(infinito)") return $hex;
  $hex = preg_replace('/\s+/', '', $hex);
  if (strlen($hex) <= ($front + $back + 1)) return $hex;
  return substr($hex, 0, $front) . "…" . substr($hex, -$back);
}

if (!function_exists('gmp_init')) {
  ?>
  <!doctype html>
  <html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Firmas digitales (ECDSA)</title>
    <link rel="stylesheet" href="assets/style.css">
  </head>
  <body>
    <div class="titulo-imagen-contenedor">
      <h1>Firmas digitales (ECDSA)</h1>
      <img src="assets/img/cadena-bloques.jpg" alt="Imagen blockchain">
    </div>
    <?php require __DIR__ . '/includes/nav.php'; ?>
    <div class="card">
      <h2>Falta la extensión GMP</h2>
      <p>WampServer → PHP → PHP extensions → activar <strong>php_gmp</strong> y reinicia servicios.</p>
    </div>
  </body>
  </html>
  <?php
  exit;
}

/* ===== secp256k1 ===== */
$p  = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16);
$a  = gmp_init('0', 16);
$b  = gmp_init('7', 16);
$Gx = gmp_init('79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798', 16);
$Gy = gmp_init('483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8', 16);
$q  = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16); // orden (en tu TFG: q)

/* ===== Utils ===== */
function mod($x, $m) {
  $r = gmp_mod($x, $m);
  return (gmp_cmp($r, 0) < 0) ? gmp_add($r, $m) : $r;
}
function inv($x, $m) {
  $x = mod($x, $m);
  $r = gmp_invert($x, $m);
  if ($r === false) return null;
  return mod($r, $m);
}
function point_inf(): array { return ['inf'=>true, 'x'=>null, 'y'=>null]; }
function point($x, $y): array { return ['inf'=>false, 'x'=>$x, 'y'=>$y]; }
function is_inf(array $P): bool { return !empty($P['inf']); }

function point_double(array $P, $p) : array {
  if (is_inf($P)) return $P;
  $x1 = $P['x']; $y1 = $P['y'];
  if (gmp_cmp($y1, 0) == 0) return point_inf();

  $num = gmp_mul(3, gmp_powm($x1, 2, $p)); // a=0
  $den = gmp_mul(2, $y1);
  $lambda = mod(gmp_mul($num, inv($den, $p)), $p);

  $x3 = mod(gmp_sub(gmp_powm($lambda, 2, $p), gmp_mul(2, $x1)), $p);
  $y3 = mod(gmp_sub(gmp_mul($lambda, gmp_sub($x1, $x3)), $y1), $p);

  return point($x3, $y3);
}

function point_add(array $P, array $Q, $p) : array {
  if (is_inf($P)) return $Q;
  if (is_inf($Q)) return $P;

  $x1 = $P['x']; $y1 = $P['y'];
  $x2 = $Q['x']; $y2 = $Q['y'];

  if (gmp_cmp($x1, $x2) == 0) {
    if (gmp_cmp(mod(gmp_add($y1, $y2), $p), 0) == 0) return point_inf();
    return point_double($P, $p);
  }

  $lambda = mod(gmp_mul(gmp_sub($y2, $y1), inv(gmp_sub($x2, $x1), $p)), $p);
  $x3 = mod(gmp_sub(gmp_sub(gmp_powm($lambda, 2, $p), $x1), $x2), $p);
  $y3 = mod(gmp_sub(gmp_mul($lambda, gmp_sub($x1, $x3)), $y1), $p);

  return point($x3, $y3);
}

function scalar_mult($k, array $P, $p): array {
  $N = $P;
  $Q = point_inf();
  while (gmp_cmp($k, 0) > 0) {
    if (gmp_testbit($k, 0)) $Q = point_add($Q, $N, $p);
    $N = point_double($N, $p);
    $k = gmp_div_q($k, 2);
  }
  return $Q;
}

function rand_range($q) {
  // [1, q-1]
  do {
    $bytes = random_bytes(32);
    $x = gmp_import($bytes);
    $x = mod($x, gmp_sub($q, 1));
    $x = gmp_add($x, 1);
  } while (gmp_cmp($x, 0) <= 0);
  return $x;
}

$G = point($Gx, $Gy);

/* ===== Nueva clave (k,K) ===== */
if (isset($_POST['regen']) || empty($_SESSION['k_hex']) || empty($_SESSION['Kx_hex']) || empty($_SESSION['Ky_hex'])) {
  $k_priv = rand_range($q);          // en tu TFG: k (clave privada)
  $K_pub  = scalar_mult($k_priv, $G, $p); // en tu TFG: K = kG

  $_SESSION['k_hex']  = hexpad(gmp_strval($k_priv, 16));
  $_SESSION['Kx_hex'] = hexpad(gmp_strval($K_pub['x'], 16));
  $_SESSION['Ky_hex'] = hexpad(gmp_strval($K_pub['y'], 16));

  unset($_SESSION['last']);
  header("Location: ./firmas.php");
  exit;
}

$k_priv = gmp_init($_SESSION['k_hex'], 16);
$K_pub  = point(gmp_init($_SESSION['Kx_hex'], 16), gmp_init($_SESSION['Ky_hex'], 16));

/* ===== Firmar/Verificar ===== */
$mensaje = $_POST['message'] ?? ($_SESSION['last']['message'] ?? "Hola, blockchain!");
$r_hex = $_POST['r_hex'] ?? ($_SESSION['last']['r_hex'] ?? "");
$s_hex = $_POST['s_hex'] ?? ($_SESSION['last']['s_hex'] ?? "");

$resultado = "";

if (isset($_POST['sign'])) {
  $h_hex = hash('sha256', $mensaje);          // H(mensaje) (hex)
  $h_int = gmp_init($h_hex, 16);
  $m = mod($h_int, $q);                        // en tu TFG: m = H(mensaje) mod q

  // Firma ECDSA (tu notación):
  // t aleatorio en [1,q-1]
  // P = tG = (Px,Py)
  // r = Px mod q
  // s = t^{-1}(m + k r) mod q
  do {
    $t = rand_range($q);
    $P = scalar_mult($t, $G, $p);
    $r = mod($P['x'], $q);
    if (gmp_cmp($r, 0) == 0) continue;

    $tinv = inv($t, $q); // existe porque q es primo y t != 0
    $s = mod(gmp_mul($tinv, gmp_add($m, gmp_mul($k_priv, $r))), $q);
  } while (gmp_cmp($s, 0) == 0);

  $_SESSION['last'] = [
    "message" => $mensaje,
    "H_hex" => hexpad($h_hex),
    "m_hex" => hexpad(gmp_strval($m, 16)),
    "t_hex" => hexpad(gmp_strval($t, 16)),
    "Px_hex" => hexpad(gmp_strval($P['x'], 16)),
    "Py_hex" => hexpad(gmp_strval($P['y'], 16)),
    "r_hex" => hexpad(gmp_strval($r, 16)),
    "s_hex" => hexpad(gmp_strval($s, 16)),
  ];

  $r_hex = $_SESSION['last']["r_hex"];
  $s_hex = $_SESSION['last']["s_hex"];

  $resultado = "✅ Firma generada (r,s).";
}

if (isset($_POST['verify'])) {
  if (!$r_hex || !$s_hex) {
    $resultado = "❌ Falta r o s.";
  } else {
    $h_hex = hash('sha256', $mensaje);
    $h_int = gmp_init($h_hex, 16);
    $m = mod($h_int, $q);

    $r = gmp_init($r_hex, 16);
    $s = gmp_init($s_hex, 16);

    // Verificación (tu notación):
    // w = s^{-1} mod q
    // u1 = m w mod q, u2 = r w mod q
    // R = u1 G + u2 K = (Rx,Ry)
    // v = Rx mod q; válida ⇔ v=r
    $w = inv($s, $q);
    if ($w === null) {
      $resultado = "❌ No existe inverso de s (firma inválida).";
    } else {
      $u1 = mod(gmp_mul($m, $w), $q);
      $u2 = mod(gmp_mul($r, $w), $q);

      $A = scalar_mult($u1, $G, $p);
      $B = scalar_mult($u2, $K_pub, $p);
      $R = point_add($A, $B, $p);

      $v = is_inf($R) ? gmp_init(0) : mod($R['x'], $q);

      $ok = (gmp_cmp($v, $r) == 0);
      $resultado = $ok ? "✅ Firma VÁLIDA" : "❌ Firma NO válida";

      $_SESSION['last'] = [
        "message" => $mensaje,
        "H_hex" => hexpad($h_hex),
        "m_hex" => hexpad(gmp_strval($m, 16)),
        "r_hex" => hexpad(gmp_strval($r, 16)),
        "s_hex" => hexpad(gmp_strval($s, 16)),
        "w_hex" => hexpad(gmp_strval($w, 16)),
        "u1_hex" => hexpad(gmp_strval($u1, 16)),
        "u2_hex" => hexpad(gmp_strval($u2, 16)),
        "Rx_hex" => is_inf($R) ? "(infinito)" : hexpad(gmp_strval($R['x'], 16)),
        "Ry_hex" => is_inf($R) ? "(infinito)" : hexpad(gmp_strval($R['y'], 16)),
        "v_hex"  => hexpad(gmp_strval($v, 16)),
      ];
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Firmas digitales (ECDSA)</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>

  <div class="titulo-imagen-contenedor">
    <h1>Firmas digitales (ECDSA)</h1>
    <img src="assets/img/cadena-bloques.jpg" alt="Imagen blockchain">
  </div>

  <?php require __DIR__ . '/includes/nav.php'; ?>

  <div class="row">
    <div class="card">
      <div class="row">
        <div>
          <h2>Clave privada y pública</h2>
          <div style="font-size:14px">
            <strong>Curva W:</strong> y² = x³ + 7 (mod q) &nbsp;·&nbsp;
			<br>
            <strong>Hash H:</strong> SHA-256 &nbsp;·&nbsp;
			<br>
            <strong>Orden q:</strong> (secp256k1)
			<br>
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;align-items:center">
          <form method="post" style="display:inline">
            <button class="primary" name="regen" value="1">🔁 Generar nuevas claves</button>
          </form>
        </div>
      </div>

	  <br>
      <label style="margin-top:10px"><strong>k</strong> (clave privada)</label>
      <div class="mono tx-scroll" title="<?= h($_SESSION['k_hex']) ?>"><?= h(short_hex($_SESSION['k_hex'])) ?></div>

	  <br>
      <label><strong>K = k·G</strong> (clave pública)</label>
      <div class="mono tx-scroll">
        Kx = <span title="<?= h($_SESSION['Kx_hex']) ?>"><?= h(short_hex($_SESSION['Kx_hex'])) ?></span><br>
		<br>
        Ky = <span title="<?= h($_SESSION['Ky_hex']) ?>"><?= h(short_hex($_SESSION['Ky_hex'])) ?></span>
      </div>

      <div style="font-size:14px;margin-top:10px;background:#f0f3f8;border-radius:12px;padding:10px">
        El botón <strong>Generar nuevas claves</strong> crea otra pareja (k,K). Una firma válida con una clave no verifica con otra.
      </div>
    </div>

    <div class="card">
      <h2>Firmar / Verificar</h2>

      <form method="post">
        <label>Mensaje:</label>
        <textarea name="message" rows="5"><?= h($mensaje) ?></textarea>

        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="primary" name="sign" value="1">✍️ Firmar</button>
          <button class="primary" name="verify" value="1">✅ Verificar</button>
        </div>
		<br>
        
      </form>

      <?php if ($resultado): ?>
        <div class="card" style="margin-top:10px;background:#f0f3f8">
          <strong><?= h($resultado) ?></strong>
        </div>
      <?php endif; ?>
	  

      <div style="font-size:14px;margin-top:10px">
        El nonce <code>t</code> debe ser distinto en cada firma. Si se repite, se puede recuperar la clave privada.
      </div>
    </div>
  </div>

  <?php if (!empty($_SESSION['last'])): $L = $_SESSION['last']; ?>
    <div class="panel">
      <div class="panel-title">Valores de firmar</div>
      <div class="table-scroll">
        <table class="tabla">
          <colgroup>
            <col style="width:240px">
            <col>
          </colgroup>
          <thead><tr><th>Variable</th><th>Valor</th></tr></thead>
          <tbody>
            <tr>
              <td>H(mensaje)</td>
              <td class="mono" title="<?= h($L['H_hex'] ?? '') ?>"><?= h(short_hex($L['H_hex'] ?? '')) ?></td>
            </tr>
            <tr>
              <td>m = H(mensaje)  (mod q)</td>
              <td class="mono" title="<?= h($L['m_hex'] ?? '') ?>"><?= h(short_hex($L['m_hex'] ?? '')) ?></td>
            </tr>

            <?php if (!empty($L['t_hex'])): ?>
              <tr><td>t (nonce)</td><td class="mono" title="<?= h($L['t_hex']) ?>"><?= h(short_hex($L['t_hex'])) ?></td></tr>
              <tr><td>P = t·G (Px)</td><td class="mono" title="<?= h($L['Px_hex']) ?>"><?= h(short_hex($L['Px_hex'])) ?></td></tr>
              <tr><td>P = t·G (Py)</td><td class="mono" title="<?= h($L['Py_hex']) ?>"><?= h(short_hex($L['Py_hex'])) ?></td></tr>
            <?php endif; ?>

            <?php if (!empty($L['r_hex'])): ?>
              <tr><td>r = Px  (mod q)</td><td class="mono" title="<?= h($L['r_hex']) ?>"><?= h(short_hex($L['r_hex'])) ?></td></tr>
            <?php endif; ?>

            <?php if (!empty($L['s_hex'])): ?>
              <tr><td>s = t^{-1}(m + k·r) (mod q)</td><td class="mono" title="<?= h($L['s_hex']) ?>"><?= h(short_hex($L['s_hex'])) ?></td></tr>
            <?php endif; ?>

            
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['last'])): $L = $_SESSION['last']; ?>
    <div class="panel">
      <div class="panel-title">Valores de verificar</div>
      <div class="table-scroll">
        <table class="tabla">
          <colgroup>
            <col style="width:240px">
            <col>
          </colgroup>
          <thead><tr><th>Variable</th><th>Valor</th></tr></thead>
          <tbody>

            <?php if (!empty($L['w_hex'])): ?>
              <tr><td>w = s^{-1}  (mod q)</td><td class="mono" title="<?= h($L['w_hex']) ?>"><?= h(short_hex($L['w_hex'])) ?></td></tr>
              <tr><td>u1 = m·w  (mod q)</td><td class="mono" title="<?= h($L['u1_hex']) ?>"><?= h(short_hex($L['u1_hex'])) ?></td></tr>
              <tr><td>u2 = r·w  (mod q)</td><td class="mono" title="<?= h($L['u2_hex']) ?>"><?= h(short_hex($L['u2_hex'])) ?></td></tr>
              <tr><td>R = u1·G + u2·K (Rx)</td><td class="mono" title="<?= h($L['Rx_hex']) ?>"><?= h(short_hex($L['Rx_hex'])) ?></td></tr>
              <tr><td>R = u1·G + u2·K (Ry)</td><td class="mono" title="<?= h($L['Ry_hex']) ?>"><?= h(short_hex($L['Ry_hex'])) ?></td></tr>
              <tr><td>v = Rx  (mod q)</td><td class="mono" title="<?= h($L['v_hex']) ?>"><?= h(short_hex($L['v_hex'])) ?></td></tr>
              <tr><td>Condición</td><td class="mono">válida ⇔ v = r</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card" style="font-size:14px;background:#f0f3f8">
        Nota: pasa el ratón por encima de un valor para verlo completo.
      </div>
    </div>
  <?php endif; ?>

</body>
</html>

