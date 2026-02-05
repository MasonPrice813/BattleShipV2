<?php
session_start();

/* =========================
   Settings
========================= */
$size = 10; // 10x10
$fleet = [
  ["name" => "Carrier",    "size" => 5],
  ["name" => "Battleship", "size" => 4],
  ["name" => "Cruiser",    "size" => 3],
  ["name" => "Submarine",  "size" => 3],
  ["name" => "Destroyer",  "size" => 2],
];

/* =========================
   Helpers
========================= */
function rcToIdx(int $r, int $c, int $size): int { return $r * $size + $c; }
function idxToRC(int $idx, int $size): array { return [intdiv($idx, $size), $idx % $size]; }

function neighbors4(int $idx, int $size): array {
  [$r,$c] = idxToRC($idx,$size);
  $out = [];
  if ($r > 0) $out[] = rcToIdx($r-1,$c,$size);
  if ($r < $size-1) $out[] = rcToIdx($r+1,$c,$size);
  if ($c > 0) $out[] = rcToIdx($r,$c-1,$size);
  if ($c < $size-1) $out[] = rcToIdx($r,$c+1,$size);
  return $out;
}

function placeFleet(int $size, array $fleet): array {
  $occupied = [];
  $ships = [];

  foreach ($fleet as $shipDef) {
    $shipName = $shipDef["name"];
    $shipSize = $shipDef["size"];
    $placed = false;
    $attempts = 0;

    while (!$placed) {
      $attempts++;
      if ($attempts > 5000) return placeFleet($size, $fleet);

      $horizontal = (rand(0, 1) === 1);
      $r = rand(0, $size - 1);
      $c = rand(0, $size - 1);

      if ($horizontal) { if ($c + $shipSize > $size) continue; }
      else { if ($r + $shipSize > $size) continue; }

      $cells = [];
      $ok = true;

      for ($i = 0; $i < $shipSize; $i++) {
        $rr = $horizontal ? $r : $r + $i;
        $cc = $horizontal ? $c + $i : $c;
        $idx = rcToIdx($rr, $cc, $size);
        if (isset($occupied[$idx])) { $ok = false; break; }
        $cells[] = $idx;
      }
      if (!$ok) continue;

      foreach ($cells as $cellIdx) $occupied[$cellIdx] = true;

      $ships[] = [
        "name" => $shipName,
        "size" => $shipSize,
        "cells" => $cells,
        "hits" => []
      ];
      $placed = true;
    }
  }

  return $ships;
}

function shipIndexAtCell(array $ships, int $cellIdx): int {
  foreach ($ships as $i => $ship) {
    if (in_array($cellIdx, $ship["cells"], true)) return $i;
  }
  return -1;
}

function registerHit(array &$ships, int $cellIdx): array {
  $si = shipIndexAtCell($ships, $cellIdx);
  if ($si === -1) return ["hit" => false, "sunk" => false, "ship" => null];

  if (!in_array($cellIdx, $ships[$si]["hits"], true)) {
    $ships[$si]["hits"][] = $cellIdx;
  }

  $sunk = (count($ships[$si]["hits"]) >= $ships[$si]["size"]);
  return ["hit" => true, "sunk" => $sunk, "ship" => $ships[$si]["name"]];
}

function allSunk(array $ships): bool {
  foreach ($ships as $ship) {
    if (count($ship["hits"]) < $ship["size"]) return false;
  }
  return true;
}

function randomUnguessedCell(array $shots, int $totalCells): int {
  $available = [];
  for ($i = 0; $i < $totalCells; $i++) {
    if (!array_key_exists($i, $shots)) $available[] = $i;
  }
  if (!$available) return -1;
  return $available[array_rand($available)];
}

function pickFromList(array $candidates, array $shots): int {
  // Return first candidate that hasn't been shot; else -1
  foreach ($candidates as $idx) {
    if (!array_key_exists($idx, $shots)) return $idx;
  }
  return -1;
}

function hardPickShot(array &$ai, array $cpuShots, int $size): int {
  $total = $size * $size;

  // If we have target hits, try to extend line / adjacent
  if (!empty($ai["targetHits"])) {
    $hits = $ai["targetHits"];

    // If >=2 hits, infer orientation
    if (count($hits) >= 2) {
      // Sort by row/col
      $rcs = array_map(fn($h) => idxToRC($h, $size), $hits);

      $sameRow = true; $sameCol = true;
      $r0 = $rcs[0][0]; $c0 = $rcs[0][1];
      foreach ($rcs as [$r,$c]) {
        if ($r !== $r0) $sameRow = false;
        if ($c !== $c0) $sameCol = false;
      }

      if ($sameRow) {
        // Horizontal: extend left/right
        $row = $r0;
        $cols = array_map(fn($x) => $x[1], $rcs);
        sort($cols);
        $minC = $cols[0]; $maxC = $cols[count($cols)-1];

        $left  = ($minC > 0) ? rcToIdx($row, $minC-1, $size) : -1;
        $right = ($maxC < $size-1) ? rcToIdx($row, $maxC+1, $size) : -1;

        $shot = pickFromList(array_filter([$left, $right], fn($v) => $v !== -1), $cpuShots);
        if ($shot !== -1) return $shot;
      }

      if ($sameCol) {
        // Vertical: extend up/down
        $col = $c0;
        $rows = array_map(fn($x) => $x[0], $rcs);
        sort($rows);
        $minR = $rows[0]; $maxR = $rows[count($rows)-1];

        $up   = ($minR > 0) ? rcToIdx($minR-1, $col, $size) : -1;
        $down = ($maxR < $size-1) ? rcToIdx($maxR+1, $col, $size) : -1;

        $shot = pickFromList(array_filter([$up, $down], fn($v) => $v !== -1), $cpuShots);
        if ($shot !== -1) return $shot;
      }
    }

    // Otherwise (single hit or weird), try adjacent cells
    $queue = [];
    foreach ($hits as $h) $queue = array_merge($queue, neighbors4($h, $size));
    // De-dupe
    $queue = array_values(array_unique($queue));
    $shot = pickFromList($queue, $cpuShots);
    if ($shot !== -1) return $shot;
  }

  // Hunt mode: checkerboard parity first (more efficient for ships >=2)
  $parity = [];
  for ($i=0; $i<$total; $i++) {
    if (array_key_exists($i, $cpuShots)) continue;
    [$r,$c] = idxToRC($i, $size);
    if ((($r + $c) % 2) === 0) $parity[] = $i;
  }
  if ($parity) return $parity[array_rand($parity)];

  // Fallback: any unguessed
  return randomUnguessedCell($cpuShots, $total);
}

function mediumPickShot(array &$ai, array $cpuShots, int $size): int {
  // If we have a queue (adjacent checks), use it first
  if (!empty($ai["targetQueue"])) {
    $shot = pickFromList($ai["targetQueue"], $cpuShots);
    if ($shot !== -1) return $shot;
    // if queue exhausted, clear it
    $ai["targetQueue"] = [];
  }
  // otherwise random
  return randomUnguessedCell($cpuShots, $size * $size);
}

function logMsg(array &$log, string $msg): void {
  $log[] = $msg;
  if (count($log) > 7) array_shift($log);
}

/* =========================
   Restart
========================= */
if (isset($_POST["restart"])) {
  session_unset();
  session_destroy();
  header("Location: index.php");
  exit;
}

/* =========================
   Initialize Session State
========================= */
if (!isset($_SESSION["game"]) || !is_array($_SESSION["game"])) $_SESSION["game"] = [];
$game =& $_SESSION["game"];

$totalCells = $size * $size;

// Difficulty default
if (!isset($game["difficulty"])) $game["difficulty"] = "easy";
$validDiff = ["easy","medium","hard"];

// Change difficulty (no reset)
if (isset($_POST["difficulty"]) && in_array($_POST["difficulty"], $validDiff, true)) {
  $game["difficulty"] = $_POST["difficulty"];
}

// Ships
if (!isset($game["playerShips"]) || !is_array($game["playerShips"])) $game["playerShips"] = placeFleet($size, $fleet);
if (!isset($game["cpuShips"]) || !is_array($game["cpuShips"])) $game["cpuShips"] = placeFleet($size, $fleet);

// Shots
if (!isset($game["playerShots"]) || !is_array($game["playerShots"])) $game["playerShots"] = [];
if (!isset($game["cpuShots"]) || !is_array($game["cpuShots"])) $game["cpuShots"] = [];

// Turn / status
if (!isset($game["turn"])) $game["turn"] = "player";
if (!isset($game["statusLog"]) || !is_array($game["statusLog"])) $game["statusLog"] = [];
if (!isset($game["over"])) $game["over"] = false;
if (!isset($game["winner"])) $game["winner"] = null;

// AI state
if (!isset($game["ai"]) || !is_array($game["ai"])) $game["ai"] = [];
if (!isset($game["ai"]["targetQueue"]) || !is_array($game["ai"]["targetQueue"])) $game["ai"]["targetQueue"] = []; // medium
if (!isset($game["ai"]["targetHits"]) || !is_array($game["ai"]["targetHits"])) $game["ai"]["targetHits"] = [];     // hard

/* =========================
   Handle Player Shot + CPU Shot
========================= */
if (!$game["over"] && $game["turn"] === "player" && isset($_POST["shot"])) {
  $shot = (int)$_POST["shot"];

  if ($shot >= 0 && $shot < $totalCells && !array_key_exists($shot, $game["playerShots"])) {

    // Player fires at CPU
    $hitInfo = registerHit($game["cpuShips"], $shot);
    if ($hitInfo["hit"]) {
      $game["playerShots"][$shot] = "hit";
      $msg = "You HIT the computer!";
      if ($hitInfo["sunk"]) $msg = "You SUNK the computer's {$hitInfo['ship']}!";
      logMsg($game["statusLog"], $msg);
    } else {
      $game["playerShots"][$shot] = "miss";
      logMsg($game["statusLog"], "You missed.");
    }

    // Win?
    if (allSunk($game["cpuShips"])) {
      $game["over"] = true;
      $game["winner"] = "player";
      logMsg($game["statusLog"], "🏁 You win! All enemy ships sunk.");
    } else {
      // CPU turn
      $game["turn"] = "cpu";

      // Choose CPU shot based on difficulty
      $cpuShot = -1;
      if ($game["difficulty"] === "easy") {
        $cpuShot = randomUnguessedCell($game["cpuShots"], $totalCells);
      } elseif ($game["difficulty"] === "medium") {
        $cpuShot = mediumPickShot($game["ai"], $game["cpuShots"], $size);
      } else { // hard
        $cpuShot = hardPickShot($game["ai"], $game["cpuShots"], $size);
      }

      if ($cpuShot !== -1) {
        $cpuHit = registerHit($game["playerShips"], $cpuShot);

        if ($cpuHit["hit"]) {
          $game["cpuShots"][$cpuShot] = "hit";
          $cpuMsg = "Computer HIT your ship!";
          if ($cpuHit["sunk"]) $cpuMsg = "Computer SUNK your {$cpuHit['ship']}!";
          logMsg($game["statusLog"], $cpuMsg);

          // Medium: after a hit, queue adjacent cells
          if ($game["difficulty"] === "medium") {
            $game["ai"]["targetQueue"] = array_values(array_unique(array_merge(
              $game["ai"]["targetQueue"],
              neighbors4($cpuShot, $size)
            )));
          }

          // Hard: track hits for target mode; clear if sunk
          if ($game["difficulty"] === "hard") {
            $game["ai"]["targetHits"][] = $cpuShot;
            $game["ai"]["targetHits"] = array_values(array_unique($game["ai"]["targetHits"]));
          }

          // If CPU sunk a ship, reset target state (simple approach)
          if ($cpuHit["sunk"]) {
            if ($game["difficulty"] === "medium") $game["ai"]["targetQueue"] = [];
            if ($game["difficulty"] === "hard") $game["ai"]["targetHits"] = [];
          }

        } else {
          $game["cpuShots"][$cpuShot] = "miss";
          logMsg($game["statusLog"], "Computer missed.");
        }

        // CPU win?
        if (allSunk($game["playerShips"])) {
          $game["over"] = true;
          $game["winner"] = "cpu";
          logMsg($game["statusLog"], "🏁 Computer wins. Your fleet has been sunk.");
        }
      }

      if (!$game["over"]) $game["turn"] = "player";
    }
  }
}

/* =========================
   Render Helpers
========================= */
function cellClassesForTargetBoard(int $idx, array $playerShots): string {
  if (!array_key_exists($idx, $playerShots)) return "cell cell--unknown";
  return $playerShots[$idx] === "hit" ? "cell cell--hit" : "cell cell--miss";
}

function cellClassesForPlayerBoard(int $idx, array $cpuShots, array $playerShips): string {
  $hasShip = (shipIndexAtCell($playerShips, $idx) !== -1);
  $shotHere = array_key_exists($idx, $cpuShots);

  $cls = "cell";
  if ($hasShip) $cls .= " cell--ship";
  else $cls .= " cell--safe";

  if ($shotHere) $cls .= ($cpuShots[$idx] === "hit") ? " cell--hit" : " cell--miss";
  return $cls;
}

function labelForCell(int $idx, array $shots): string {
  if (array_key_exists($idx, $shots)) return $shots[$idx] === "hit" ? "●" : "•";
  return "";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Battleship</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header class="top">
  <div>
    <h1>Battleship</h1>
    <p class="subtitle">10×10 • Standard fleet • Session-based • Refresh won’t reset</p>
  </div>

  <div class="controls">
    <form method="post" class="difficulty">
      <label for="difficulty">AI:</label>
      <select id="difficulty" name="difficulty" onchange="this.form.submit()">
        <option value="easy"   <?php echo $game["difficulty"]==="easy" ? "selected" : ""; ?>>Easy</option>
        <option value="medium" <?php echo $game["difficulty"]==="medium" ? "selected" : ""; ?>>Medium</option>
        <option value="hard"   <?php echo $game["difficulty"]==="hard" ? "selected" : ""; ?>>Hard</option>
      </select>
      <noscript><button type="submit">Set</button></noscript>
    </form>

    <form method="post" class="restart">
      <button type="submit" name="restart">Restart Game</button>
    </form>
  </div>
</header>

<section class="boards">
  <!-- Player board -->
  <div class="panel">
    <div class="panel__head">
      <h2>Your Fleet</h2>
      <div class="hint">Computer shoots after you.</div>
    </div>

    <div class="grid" style="--n: <?php echo (int)$size; ?>">
      <?php for ($i=0; $i<$totalCells; $i++): ?>
        <div class="<?php echo htmlspecialchars(cellClassesForPlayerBoard($i, $game["cpuShots"], $game["playerShips"])); ?>">
          <?php echo htmlspecialchars(labelForCell($i, $game["cpuShots"])); ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <!-- Computer board -->
  <div class="panel">
    <div class="panel__head">
      <h2>Enemy Waters</h2>
      <div class="hint">
        <?php if ($game["over"]): ?>
          Game over.
        <?php else: ?>
          <?php echo ($game["turn"] === "player") ? "Your turn: click to fire." : "Computer is firing..."; ?>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" class="fireForm">
      <div class="grid" style="--n: <?php echo (int)$size; ?>">
        <?php for ($i=0; $i<$totalCells; $i++): ?>
          <?php
            $cls = cellClassesForTargetBoard($i, $game["playerShots"]);
            $disabled = array_key_exists($i, $game["playerShots"]) || $game["over"] || ($game["turn"] !== "player");
          ?>
          <button
            type="submit"
            name="shot"
            value="<?php echo (int)$i; ?>"
            class="<?php echo htmlspecialchars($cls); ?>"
            <?php echo $disabled ? "disabled" : ""; ?>
          ><?php echo htmlspecialchars(labelForCell($i, $game["playerShots"])); ?></button>
        <?php endfor; ?>
      </div>
    </form>
  </div>
</section>

<section class="status">
  <div class="status__card">
    <div class="status__title">
      <?php echo $game["over"] ? (($game["winner"]==="player") ? "✅ Victory" : "❌ Defeat") : "Battle Log"; ?>
    </div>

    <ul class="log">
      <?php foreach ($game["statusLog"] as $line): ?>
        <li><?php echo htmlspecialchars($line); ?></li>
      <?php endforeach; ?>
      <?php if (count($game["statusLog"]) === 0): ?>
        <li class="muted">Fire at the enemy grid to begin.</li>
      <?php endif; ?>
    </ul>

    <div class="legend">
      <span><span class="swatch swatch--ship"></span> Your ship</span>
      <span><span class="swatch swatch--hit"></span> Hit</span>
      <span><span class="swatch swatch--miss"></span> Miss</span>
      <span><span class="swatch swatch--unknown"></span> Unknown</span>
    </div>
  </div>
</section>

</body>
</html>
