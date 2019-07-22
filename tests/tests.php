<?PHP
require("../functions.php");

foreach ([
  '0:0' => true,
  '13:00' => false,
  '22:00' => true,
  '22:01' => true,
  '4:00' => true,
  '5:00' => true,
  '5:01' => false
] as $k => $v) {
  $interval = new ClockInterval('22:00', '5:00');
  if ($interval->isIn($k) !== boolval($v)) {
    echo "inInterval: résultat incorrect pour $k" . PHP_EOL;
  }
}


foreach ([
  ['13:00', '14:00', false],
  ['23:00', '3:59', true],
  ['4:00', '23:01', false],
  ['21:00', '1:00', false]
] as $v) {
  $i1 = new ClockInterval('22:00', '5:00');
  $i2 = new ClockInterval($v[0], $v[1]);
  if ($i1->isIn($i2) !== $v[2]) {
    echo "ClockInterval::isIn: résultat incorrect pour $v[0] - $v[1]" . PHP_EOL;
  }
  if (intervalInInterval($v[0], $v[1], '22:00', '5:00') !== $v[2]) {
    echo "intervalInInterval: résultat incorrect pour $v[0] - $v[1]" . PHP_EOL;
  }
}

foreach ([
  ['0:0', '0:1', 1],
  ['13:00', '14:00', 60],
  ['0:0', '24:00', 1440],
  ['0:0', '23:59', 1439],
  ['13:00', '13:00', 0],
  ['22:00', '5:00', 420] 
] as $v) {
  $interval = new ClockInterval($v[0], $v[1]);
  if ($interval->length()->toMin() !== floatval($v[2])) {
    echo "ClockInterval::length > résultat incorrect pour $v[0] - $v[1] : $v[2]" . PHP_EOL;
  }
  if (intervalLength($v[0], $v[1]) !== floatval($v[2])) {
    echo "intervalLength > résultat incorrect pour $v[0] - $v[1] : $v[2]" . PHP_EOL;   
  }
}

foreach([
  ['19:30', '0:00', 150, 120],
  ['04:00', '6:00', 60, 60],
  ['05:00', '8:00', 180, 0],
  ['14:00', '15:00', 60, 0],
  ['21:00', '23:00', 60, 60],
  ['18:00', '22:00', 240, 0],
  ['21:00', '0:00', 60, 120],
  ['00:00', '1:00', 0, 60],
  ['6:00', '12:00', 360, 0]
] as $v) {

  $i1 = new ClockInterval('22:00', '5:00');
  $i2 = new ClockInterval($v[0], $v[1]);
  $inLen = $i1->overlap_length($i2);

  if (($i2->length()->toMin()) - ($inLen->toMin()) !== floatval($v[2]) && ($inLen->toMin()) !== floatval($v[3])) {
    echo "ClockInterval::overlap_length: résultat incorrect pour $v[0] - $v[1], " . ($i2->length()->toMin() - $inLen->toMin())  . ' - ' . $inLen->toMin() .PHP_EOL;
  }
  
  $chBegin = new ClockHour($v[0]);
  $chEnd = new ClockHour($v[1]);
  $chEnd->isThe24th();
  $r = crossIntervalLength($chBegin, $chEnd, new ClockHour('5:00'), new ClockHour('22:00'));
  if ($r[0] !== floatval($v[2]) && $r[1] !== floatval($v[3])) {
    echo "crossIntervalLength: résultat incorrect pour $v[0] - $v[1], $r[0] - $r[1]" .PHP_EOL;
  }
}
?>
