<?PHP
require("../functions.php");

foreach ([
  '0:0' => true,
  '13:00' => false,
  '22:00' => true,
  '22:01' => true,
  '4:00' => true,
  '5:00' => false
] as $k => $v) {
  if (inInterval(strToDT($k), strToDT('22:00'), strToDT('5:00')) !== $v) {
    echo "résultat incorrect pour $k" . PHP_EOL;
  }
}


foreach ([
  ['13:00', '14:00', false],
  ['23:00', '4:00', true],
  ['4:00', '23:00', false],
  ['21:00', '1:00', false]
] as $v) {
  if (intervalInInterval(strToDT($v[0]), strToDT($v[1]), strToDT('22:00'), strToDT('5:00')) !== $v[2]) {
    echo "résultat incorrect pour $v[0] - $v[1]" . PHP_EOL;
  }
}


foreach ([
  ['13:00', '14:00', 60],
  ['0:0', '24:00', 0],
  ['0:0', '23:59', 1439],
  ['13:00', '13:00', 0],
  ['22:00', '5:00', 420] 
] as $v) {
  if (intervalLength(strToDT($v[0]), strToDT($v[1])) !== floatval($v[2])) {
    echo "résultat incorrect pour $v[0] - $v[1]" . PHP_EOL;
  }
}

foreach([
  ['04:00', '6:00', 60, 60],
  ['05:00', '8:00', 180, 0],
  ['14:00', '15:00', 60, 0],
  ['21:00', '23:00', 60, 60],
  ['18:00', '22:00', 240, 0]
] as $v) {
  $r = crossIntervalLength(strToDT($v[0]), strToDT($v[1]), strToDT('5:00'), strToDT('22:00'));
  if ($r[0] !== floatval($v[2]) && $r[1] !== floatval($v[3])) {
    echo "résultat incorrect pour $v[0] - $v[1], $r[0] - $r[1]" .PHP_EOL;
  }
}
?>
