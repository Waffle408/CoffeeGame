<?php

define('GAME_DIR', __DIR__ . '/games');
if (!is_dir(GAME_DIR)) mkdir(GAME_DIR, 0755, true);


if (empty($_GET['game'])) {
    $id = uniqid();
    $host = bin2hex(random_bytes(8));
    $initial = [
        'participants' => [],
        'visitors'     => [],
        'hostToken'    => $host,
        'payID'        => '',
        'spinResult'   => null
    ];
    file_put_contents(GAME_DIR . "/$id.json", json_encode($initial));
    header('Location: ' . basename(__FILE__) . "?game=$id&host=$host");
    exit;
}

global $state;
$game      = preg_replace('/[^a-zA-Z0-9]/','', $_GET['game']);
$file      = GAME_DIR . "/$game.json";
if (!file_exists($file)) die('Invalid game');
$state     = json_decode(file_get_contents($file), true);
$parts     = $state['participants'];
$visitors  = $state['visitors'];
$hostToken = $state['hostToken'];
$payID     = $state['payID'];
$spinResult= $state['spinResult'];
$isHost    = (!empty($_GET['host']) && hash_equals($hostToken, $_GET['host']));

// Track visitor via cookie
if (!isset($_COOKIE['visitor'])) {
    $vid = bin2hex(random_bytes(4));
    setcookie('visitor', $vid, time() + 86400, '/');
    $visitors[] = $vid;
    $state['visitors'] = array_values(array_unique($visitors));
    file_put_contents($file, json_encode($state));
}

// Pricing config
$coffeePrices = ['Espresso'=>0,'Latte'=>0,'Cappuccino'=>0,'Flat White'=>0,'Long Black'=>0,'Huuuggggeee chocolate'=>0];
$sugarPrices  = ['No sugar'=>0,'1 tsp'=>0,'2 tsp'=>0];
$milkPrices   = ['Whole'=>0,'Skim'=>0,'Soy'=>0,'Almond'=>0, 'Oat Milk'=>0, 'No Milk'=>0, 'Lactose Free'=>0];
$sizePrices   = ['Regular'=>0,'Large'=>0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'submit') {
      setcookie('submitted_'.$game, '1', time()+86400, '/');
      $entry = [
          'name'   => trim($_POST['name'] ?? ''),
          'coffee' => $_POST['coffee'] ?? '',
          'sugar'  => $_POST['sugar'] ?? '',
          'milk'   => $_POST['milk'] ?? '',
          'size'   => $_POST['size'] ?? ''
      ];
      if ($entry['name'] && $entry['coffee']) {
          // allow duplicates:
          $parts[]               = $entry;
          $state['participants'] = array_values($parts);
      }
  } elseif ($action === 'spin' && $isHost) {
        if (count($parts) > 0) {
            $idx = random_int(0, count($parts)-1);
            $angle = 360*5 + $idx*(360/count($parts)) + (360/count($parts)/2);
            $state['spinResult'] = ['index'=>$idx,'angle'=>$angle];
        }
    } elseif ($action === 'payid' && $isHost) {
        $state['payID'] = trim($_POST['payid'] ?? '');
    }
    file_put_contents($file, json_encode($state));
    header('Location: ' . basename(__FILE__) . "?game=$game" . ($isHost?"&host=$hostToken":""));
    exit;
}

$totalPrice = 0;
foreach ($parts as $p) {
    $totalPrice += $coffeePrices[$p['coffee']] + $sugarPrices[$p['sugar']] + $milkPrices[$p['milk']] + $sizePrices[$p['size']];
}

$userSubmitted = isset($_COOKIE['submitted_'.$game]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Coffee Run Roulette</title>
<style>
  body{margin:0;font-family:sans-serif;display:flex;height:100vh;}
  #sidebar{width:30%;padding:1rem;overflow:auto;background:#fffbe6;}
  #main{flex:1;display:flex;align-items:center;justify-content:center;position:relative;background:#f7f2e7;}
  #pointer{position:absolute;top:10%;left:50%;transform:translateX(-50%);border-left:20px solid transparent;border-right:20px solid transparent;border-bottom:30px solid #333;z-index:10;}
  canvas{width:400px;height:400px;}
  #winnerModal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;}
  #modalContent{background:#fff;padding:2rem;border-radius:8px;text-align:center;max-width:90%;max-height:90%;overflow:auto;}
  input,select{width:100%;margin:0.5rem 0;padding:0.5rem;border:2px solid #d3d3d3;border-radius:8px;}
  button{width:100%;padding:0.75rem;border:none;border-radius:8px;background:#d4a373;color:#fff;cursor:pointer;margin:0.5rem 0;}
  table{width:100%;border-collapse:collapse;margin:1rem 0;}
  th,td{border:1px solid #ccc;padding:0.5rem;text-align:left;}
</style>
</head>
<body>
<div id="sidebar">
  <h4>Host Link</h4>
  <input type="text" readonly value="<?=htmlspecialchars((isset($_SERVER['HTTPS'])?'https':'http').'://' .
    $_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']).'/'.basename(__FILE__)."?game=$game&host=$hostToken")?>">
  <h4>Participant Link</h4>
  <input type="text" readonly value="<?=htmlspecialchars((isset($_SERVER['HTTPS'])?'https':'http').'://' .
    $_SERVER['HTTP_HOST'].dirname($_SERVER['REQUEST_URI']).'/'.basename(__FILE__)."?game=$game")?>">
  <?php if($isHost): ?>
    <form method="post"><input type="hidden" name="action" value="spin"><button type="submit">Spin Wheel</button></form>
  <?php endif; ?>

  <h3>Potential: <?=count($visitors)?></h3>
  <?php if(!$userSubmitted): ?>
    <h3>Order</h3>
    <form method="post">
      <input type="hidden" name="action" value="submit">
      <input type="text" name="name" placeholder="Your name" required>
      <select name="coffee" required><option value="">--Coffee--</option><?php foreach($coffeePrices as $c=>$pr):?><option value="<?=htmlspecialchars($c)?>"><?=htmlspecialchars($c)?> ($<?=number_format($pr,2)?>)</option><?php endforeach;?></select>
      <select name="sugar" required><option value="">--Sugar--</option><?php foreach($sugarPrices as $s=>$pr):?><option value="<?=htmlspecialchars($s)?>"><?=htmlspecialchars($s)?><?=$pr?" (+$".number_format($pr,2).")":""?></option><?php endforeach;?></select>
      <select name="milk" required><option value="">--Milk--</option><?php foreach($milkPrices as $m=>$pr):?><option value="<?=htmlspecialchars($m)?>"><?=htmlspecialchars($m)?><?=$pr?" (+$".number_format($pr,2).")":""?></option><?php endforeach;?></select>
      <select name="size" required><option value="">--Size--</option><?php foreach($sizePrices as $sz=>$pr):?><option value="<?=htmlspecialchars($sz)?>"><?=htmlspecialchars($sz)?><?=$pr?" (+$".number_format($pr,2).")":""?></option><?php endforeach;?></select>
      <button type="submit">Submit</button>
    </form>
  <?php else: ?>
    <div id="wait">Waiting for others...</div>
  <?php endif; ?>

  <h4>Submitted (<?=count($parts)?>)</h4>
  <ul><?php foreach($parts as $p):?><li><?=htmlspecialchars($p['name'])?></li><?php endforeach;?></ul>

  <?php if($isHost): ?>
    <form method="post"><input type="hidden" name="action" value="payid"><input type="text" name="payid" value="<?=htmlspecialchars($payID)?>" placeholder="Winner PayID"><button type="submit"><?= $payID?'Update':'Save'?> PayID</button></form>
  <?php endif; ?>
</div>

<div id="main"><div id="pointer"></div><canvas id="wheel" width="400" height="400"></canvas></div>

<!-- Winner Modal -->
<div id="winnerModal">
  <div id="modalContent">
    <h2>Coffee 👑 King</h2>
    <p id="winnerName"></p>
    <h3>Order Breakdown</h3>
    <table id="modalBreakdown"><tr><th>Name</th><th>Order</th><th>Price</th></tr></table>
    <button id="closeModal">Close</button>
  </div>
</div>

<script>
let parts = <?=json_encode($parts)?>,
    prices = {coffee:<?=json_encode($coffeePrices)?>, sugar:<?=json_encode($sugarPrices)?>, milk:<?=json_encode($milkPrices)?>, size:<?=json_encode($sizePrices)?>},
    spinResult = <?=json_encode($spinResult)?>,
    c = document.getElementById('wheel'), ctx = c.getContext('2d'), cntr = c.width/2;

function draw() {
  ctx.clearRect(0,0,c.width,c.height);
  let n = parts.length, step = 2*Math.PI/Math.max(n,1);
  parts.forEach((p,i) => {
    let s = i*step, e = s+step;
    ctx.beginPath(); ctx.moveTo(cntr,cntr); ctx.arc(cntr,cntr,cntr-5,s,e);
    ctx.fillStyle = '#' + Math.floor(Math.random()*16777215).toString(16).padStart(6, '0');
ctx.fill();

    ctx.save(); ctx.translate(cntr,cntr); ctx.rotate((s+e)/2);
    ctx.textAlign='right'; ctx.fillStyle='#2c3e50'; ctx.fillText(p.name,cntr-10,10); ctx.restore();
  });
}

draw();
setInterval(() => {
  fetch('games/<?=$game?>.json')
    .then(r => r.json())
    .then(s => {
      if (s.spinResult && (!spinResult || s.spinResult.angle !== spinResult.angle)) {
        spinResult = s.spinResult;

        // Add full spins + random jitter (up to 15 degrees)
        const baseAngle = spinResult.angle;
        const extraSpins = Math.floor(Math.random() * 4) + 3; // 3 to 6 full spins
        const jitter = Math.random() * 15 - 7.5; // -7.5 to +7.5 degrees jitter
        const finalAngle = (extraSpins * 360) + baseAngle + jitter;

        // Random duration between 3.5s and 5s
        const duration = (Math.random() * 1.5 + 3.5).toFixed(2);

        draw();
        c.style.transition = `transform ${duration}s ease-out`;
        c.style.transform = `rotate(${finalAngle}deg)`;

        setTimeout(() => {
          let wi = spinResult.index;
          document.getElementById('winnerName').textContent = parts[wi].name;

          let tbl = document.getElementById('modalBreakdown');
          tbl.innerHTML = '<tr><th>Name</th><th>Order</th><th>Price</th></tr>';
          parts.forEach(p => {
            let pr = prices.coffee[p.coffee] + prices.sugar[p.sugar] + prices.milk[p.milk] + prices.size[p.size];
            let row = tbl.insertRow();
            row.insertCell().textContent = p.name;
            row.insertCell().textContent = `${p.coffee}/${p.size}/${p.sugar}/${p.milk}`;
            row.insertCell().textContent = `$${pr.toFixed(2)}`;
          });

          document.getElementById('winnerModal').style.display = 'flex';
        }, duration * 1000 * 0.63); // show modal about 63% into the spin time for better timing
      }
    });
}, 30);


document.getElementById('closeModal').onclick = () => document.getElementById('winnerModal').style.display = 'none';
</script>
</body>
</html>