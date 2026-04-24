<?php
/*
 index.php — MiniBlockchain (PHP + MySQL en WampServer)
 ------------------------------------------------------
 Está guardado en C:\\wamp64\\www\\TFG\\index.php
 
 Requisitos previos una sola vez:
   1) En phpMyAdmin crea la BD "minichain" con cotejamiento utf8mb4_unicode_ci.
   2) Usuario: root  |  Password: (vacío por defecto en Wamp).
   3) Abre http://localhost/TFG/    → este archivo se encarga del resto.

 Qué hace:
   - Guarda transacciones (texto) en mempool.
   - "Minar" crea un bloque (PoW simple) y mueve esas txs a la tabla de transacciones.
   - Calcula Merkle root y hash del bloque.
*/

// ------- Configuración -------
$DB_HOST = '127.0.0.1';
$DB_NAME = 'minichain';
$DB_USER = 'root';
$DB_PASS = '';
$DIFFICULTY_PREFIX = '00000'; // sube a '00000' si tu PC va rápido

// ------- Conexión -------
try {
  $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error de conexión a MySQL</h1><pre>'.htmlspecialchars($e->getMessage()).'</pre>';
  exit;
}

// ------- Esquema -------
function setup_schema(PDO $pdo) {
  $pdo->exec("CREATE TABLE IF NOT EXISTS blocks (
      id INT AUTO_INCREMENT PRIMARY KEY,
      height INT UNIQUE,
      hash CHAR(64) UNIQUE,
      prev_hash CHAR(64),
      created_at DATETIME,
      merkle_root CHAR(64),
      nonce INT,
      tx_count INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tx_hash CHAR(64),
      block_id INT NULL,
      data TEXT,
      created_at DATETIME,
      INDEX(tx_hash),
      INDEX(block_id),
      CONSTRAINT fk_block FOREIGN KEY (block_id) REFERENCES blocks(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS mempool (
      id INT AUTO_INCREMENT PRIMARY KEY,
      data TEXT,
      created_at DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

setup_schema($pdo);


// ------- Funciones -------
function sha256_hex(string $s): string { return hash('sha256', $s); }

function merkle_root(array $hashes): string {
  if (empty($hashes)) return sha256_hex('');
  $layer = $hashes;
  while (count($layer) > 1) {
    if (count($layer) % 2 === 1) $layer[] = end($layer);
    $new = [];
    for ($i = 0; $i < count($layer); $i += 2) {
      $new[] = sha256_hex($layer[$i].$layer[$i+1]);
    }
    $layer = $new;
  }
  return $layer[0];
}

function compute_block_hash(int $index, string $ts, string $prev, string $mroot, int $nonce): string {
  $header = $index.'|'.$ts.'|'.$prev.'|'.$mroot.'|'.$nonce; 
  return sha256_hex($header);
}

function get_chain_tip(PDO $pdo): ?array {
  $stmt = $pdo->query("SELECT * FROM blocks ORDER BY height DESC LIMIT 1");
  $row = $stmt->fetch();
  return $row ?: null;
}

function create_genesis_if_needed(PDO $pdo) {
  if (!get_chain_tip($pdo)) {
    $ts = date('Y-m-d H:i:s');
    $index = 0;
    $prev = str_repeat('0', 64);
    $txhash = sha256_hex('genesis|'.$ts);
    $mroot = merkle_root([$txhash]);
    $nonce = 0;
    $hash = compute_block_hash($index, $ts, $prev, $mroot, $nonce);

    $pdo->prepare("INSERT INTO blocks(height, hash, prev_hash, created_at, merkle_root, nonce, tx_count)
                   VALUES(?,?,?,?,?,?,1)")
        ->execute([$index, $hash, $prev, $ts, $mroot, $nonce]);
    $blockId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO transactions(tx_hash, block_id, data, created_at) VALUES(?,?,?,?)")
        ->execute([$txhash, $blockId, 'genesis', $ts]);
  }
}

create_genesis_if_needed($pdo);

// ====== Acciones (POST) ======
$action = $_POST['action'] ?? null;

if ($action === 'submit_tx') {
  $data = trim($_POST['data'] ?? '');
  if ($data !== '') {
    $pdo->prepare("INSERT INTO mempool(data, created_at) VALUES(?,?)")
        ->execute([$data, date('Y-m-d H:i:s')]);
  }
  header('Location: ./');
  exit;
}

if ($action === 'mine') {
  // coge hasta 5 txs de mempool
  $stmt = $pdo->query("SELECT * FROM mempool ORDER BY id ASC LIMIT 5");
  $mempoolRows = $stmt->fetchAll();
  if (empty($mempoolRows)) {
    // si no hay, creamos una "coinbase" simbólica
    $mempoolRows = [[
      'id' => null,
      'data' => 'coinbase: premio al minero',
      'created_at' => date('Y-m-d H:i:s'),
    ]];
  }
  $tx_hashes = [];
  foreach ($mempoolRows as $r) {
    $tx_hashes[] = sha256_hex(($r['data'] ?? '').'|'.($r['created_at'] ?? ''));
  }

  $tip = get_chain_tip($pdo);
  $index = (int)$tip['height'] + 1;
  $prev = $tip['hash'];
  $ts = date('Y-m-d H:i:s');
  $mroot = merkle_root($tx_hashes);

  $nonce = 0;
  $hash = compute_block_hash($index, $ts, $prev, $mroot, $nonce);
  while (substr($hash, 0, strlen($DIFFICULTY_PREFIX)) !== $DIFFICULTY_PREFIX) {
    $nonce++;
    // actualizar timestamp de vez en cuando para variar el header
    if ($nonce % 5000 === 0) $ts = date('Y-m-d H:i:s');
    $hash = compute_block_hash($index, $ts, $prev, $mroot, $nonce);
  }

  // Inserta bloque
  $pdo->prepare("INSERT INTO blocks(height, hash, prev_hash, created_at, merkle_root, nonce, tx_count)
                 VALUES(?,?,?,?,?,?,?)")
      ->execute([$index, $hash, $prev, $ts, $mroot, $nonce, count($mempoolRows)]);
  $blockId = (int)$pdo->lastInsertId();

  // Mueve txs
  $ins = $pdo->prepare("INSERT INTO transactions(tx_hash, block_id, data, created_at) VALUES(?,?,?,?)");
  $del = $pdo->prepare("DELETE FROM mempool WHERE id = ?");
  foreach ($mempoolRows as $r) {
    $txh = sha256_hex(($r['data'] ?? '').'|'.($r['created_at'] ?? ''));
    $ins->execute([$txh, $blockId, $r['data'], $r['created_at']]);
    if (!empty($r['id'])) $del->execute([$r['id']]);
  }

  header('Location: ./');
  exit;
}

// ====== Datos para pintar la página ======
$tip = get_chain_tip($pdo);
$height = (int)$tip['height'];
$blocks = $pdo->query("SELECT * FROM blocks ORDER BY height DESC")->fetchAll();
$mempool_count = (int)$pdo->query("SELECT COUNT(*) c FROM mempool")->fetch()['c'];
$last_txs = $pdo->query("SELECT * FROM transactions ORDER BY id DESC")->fetchAll();

$active = 'blockchain';


// ------- HTML -------
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Mini‑Blockchain (WAMP)</title>

<link rel="stylesheet" href="assets/style.css"> 

</head>
<body>
  <div class="titulo-imagen-contenedor">
    <h1>Mi cadena de bloques</h1>
    <img src="assets/img/cadena-bloques.jpg" alt="Imagen blockchain">
  </div>
  
  <?php require __DIR__ . '/includes/nav.php'; ?>
  
  
  <div class="card">
    <div class="row">
      <div>
        <strong>Altura:</strong> <?php echo htmlspecialchars((string)$height); ?>
        <br><strong>Dificultad:</strong> <code><?php echo htmlspecialchars($DIFFICULTY_PREFIX); ?></code>
        <br><strong>Nº de transacciones del bloque actual:</strong> <?php echo htmlspecialchars((string)$mempool_count); ?> tx
      </div>
      <div>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="mine">
          <button class="primary">⛏️ Minar bloque</button>
        </form>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Nueva transacción</h2>
    <form method="post">
      <input type="hidden" name="action" value="submit_tx">
      <label>Datos de la transacción a realizar</label>
      <input type="text" name="data" placeholder="Ej.: Pago de 10 euros a Berta" required>
      <div style="margin-top:8px">
        <button class="primary">➕ Añadir</button>
      </div>
    </form>
  </div>



  <div class="panel">
	  <div class="panel-title">Últimos bloques</div>

	  <div class="table-scroll">
		<table class="tabla">
		  <thead>
			<tr>
			  <th>Altura</th>
			  <th>Hash</th>
			  <th>Prev</th>
			  <th>Tx</th>
			  <th>Nonce</th>
			  <th>Fecha</th>
			</tr>
		  </thead>
		  <tbody>
			<?php foreach ($blocks as $b): ?>
				<tr>
				     <td><?php echo (int)$b['height']; ?></td>
				     <td class="mono"><?php echo substr($b['hash'],0,16),'…'; ?></td>
				     <td class="mono"><?php echo substr($b['prev_hash'],0,16),'…'; ?></td>
				     <td><?php echo (int)$b['tx_count']; ?></td>
				     <td><?php echo (int)$b['nonce']; ?></td>
				     <td><?php echo htmlspecialchars($b['created_at']); ?></td>
				</tr>
			<?php endforeach; ?>
		  </tbody>
		</table>
	  </div>
	</div>
	
	
  <br>
  
	<div class="panel">
	  <div class="panel-title">Últimas transacciones</div>

	  <div class="table-scroll">
		<table class="tabla">
		  <thead>
			<tr>
			  <th>Tx Hash</th>
			  <th>Block</th>
			  <th>Data</th>
			  <th>Fecha</th>
			</tr>
		  </thead>
		  <tbody>
			<?php foreach ($last_txs as $t): ?>
			  <tr>
				<td class="mono">
				  <?php echo substr($t['tx_hash'], 0, 20), '…'; ?>
				</td>
				<td><?php echo !empty($t['block_id']) ? (int)$t['block_id'] : '-'; ?></td>
				<td><?php echo htmlspecialchars($t['data'] ?? ''); ?></td>
				<td><?php echo htmlspecialchars($t['created_at'] ?? ''); ?></td>
			  </tr>
			<?php endforeach; ?>
		  </tbody>
		</table>
	  </div>
	</div>


  <div class="card" style="font-size:14px">
    <h2>Notas</h2>
    • Esto es una demo de una cadena de bloques de un <em>nodo único</em>.<br>
    • Puedes subir la dificultad editando <code>$DIFFICULTY_PREFIX</code> al principio del archivo.<br>
    • Para limpiar, borra filas de <code>mempool</code> y <code>transactions</code> o elimina la BD.<br>
  </div>
</body>
</html>
