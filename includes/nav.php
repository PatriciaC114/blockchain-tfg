<?php
// Usa $active = 'blockchain'|'firmas'|'red'|'halving'
$active = $active ?? '';

function item(string $key, string $text, string $href, string $active): void {
  $cls = ($key === $active) ? 'active' : '';
  echo '<a class="'.$cls.'" href="'.$href.'">'.$text.'</a>';
}
?>
<div class="card menu">
  <?php item('blockchain', 'Cadena de bloques', 'index.php',  $active); ?>
  <?php item('firmas',     'Firmas digitales',  'firmas.php', $active); ?>
  <?php item('red',        'Red de usuarios',   'red.php',    $active); ?>
  <?php item('halving',    'Halving',           'halving.php',$active); ?>
  <?php item('halving2',    'Crea tu halving',           'halving2.php',$active); ?>
</div>
