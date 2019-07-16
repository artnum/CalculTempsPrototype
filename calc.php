<?PHP
require('functions.php');

/* Ouvrir la base de donnée, sélectionner toutes les entrées d'une personne
   donnée */
$DB = new SQLite3('airtime.sqlite');

$res = $DB->query('SELECT * FROM atEntity WHERE atEntity_id = "' . $argv[1] . '"');
$person = $res->fetchArray();

/* Pararmètres ou initialisation par défaut */
$countYear = isset($argv[2]) ? intval($argv[2]) :
             intval((new DateTime())->format('Y'));

$conditions = load_conditions($argv[3], $countYear);

$to = new DateTime("$countYear-12-31");
if (isset($argv[4])) {
  list ($d, $m) = explode('.', $argv[4], 2);
  $to = new DateTime("$countYear-$m-$d");
}
$from = new DateTime("$countYear-01-01");
if (isset($argv[5])) {
  list ($d, $m) = explode('.', $argv[5], 2);
  $from = new DateTime("$countYear-$m-$d");
}

$holidays = file('holiday.txt');
$date = $from;
$int = new DateInterval('P1D');
$HoursToDo = 0;
$HoursInYear = [];

$p[0] = $from->format('d.m.Y');
$p[1] = $to->format('d.m.Y');

echo "===== HEURES =====\n\n";
echo "Pour :\t\t$person[atEntity_commonName]\n";
echo "Période:\t$p[0] - $p[1]\n";


while (cmp_date($date, $to) <= 0) {
  $HoursInYear[$date->format("Y-m-d")] = ['todo' => 0,'ratio' => 0, 'reason' => NORMAL, 'done' => 0, 'overtime' => 0, 'days' => 0, 'driving' => 0, 'overtime_ratio' => 1.5, 'entries' => []];
  $workday = NORMAL;
  if ($date->format('N') === '6' || $date->format('N') === '7') {
    $workday = WEEKEND;

  }
  if (in_array($date->format("Y-m-d\n"), $holidays)) {
    $workday = HOLIDAY;
  }
  $group = null;
  foreach($conditions as $k => $v) {
    if (!isset($v['begin']) || !isset($v['end'])) {
      error($begin, $end);
      break;
    }
    if (cmp_date($v['begin'], $date) <= 0 && cmp_date($v['end'], $date) >= 0) {
      $group = $k;
      break;
    }
  }
  if (is_null($group)) {
    $workday = NO_CONDITION;
  }
  $overtime_ratio = isset($conditions[$group]['overtime']) ? floatval($conditions[$group]['overtime']) / 100 : 1.5;
  $work_ratio = isset($conditions[$group]['workratio']) ? floatval($conditions[$group]['workratio']) / 100 : 1;
  $day_hours = (isset($conditions[$group]['workweek']) ? floatval($conditions[$group]['workweek']) / 5 : 9.6) * $work_ratio; 
  if ($workday != NORMAL) {
    $day_hours = 0;
  }
  
  $HoursInYear[$date->format("Y-m-d")] = [
    /* heure à faire pour ce jour */
    'todo' => $day_hours,
    'ratio' => $work_ratio, /* ratio de travail pour ce jour */
    'reason' => $workday, /* raison pour avoir ce nombre d'heure */
    /* nombre d'heures total pour le jour = done + overtime */
    'done' => 0, /* heure effectivement effectuée */
    'overtime' => 0, /* surplus d'heure pour la part faite de nuit */
    'days' => 0, /* nombre de jour complet ou demi jour entré dans la base (si > 1 chance que ce soit problèmatique) */
    'driving' => 0,
    'overtime_ratio' => 1.5,
    'entries' => []
    ];
  $HoursToDo += $day_hours;

  $date->add($int);
}

$res = $DB->query('SELECT * FROM atTemp WHERE atTemp_target = "' .
                  $argv[1] . '"');

while (($row = $res->fetchArray())) {
  $time = 0;
  $overtime = 0;
  $notime = false;

  /* Convertit en objet php le temps et l'heure */
  $begin = new DateTime($row['atTemp_begin']);
  $end = new DateTime($row['atTemp_end']);

  /* Exclu les années qui ne nous intéressent pas en se basant sur la date de
     début */
  $year = $begin->format('Y');
  if (intval($year) !== $countYear) { continue; }

  /* Pas dans l'intervalle de calcul */
  if (cmp_date($from, $begin) < 0 || cmp_date($begin, $to) > 0) { continue; }   
  
  $group = null;
  foreach($conditions as $k => $v) {
    if (!isset($v['begin']) || !isset($v['end'])) {
      error($begin, $end);
      break;
    }
    if (cmp_date($v['begin'], $begin) < 0 && cmp_date($v['end'], $begin) >= 0) {
      $group = $k;
      break;
    }
  }
  if (is_null($group)) { continue; }
  list ($early_hour, $early_minute) = isset($conditions[$group]['early']) ? explode(':', $conditions[$group]['early'], 2) : [5, 0];
  list ($late_hour, $late_minute) = isset($conditions[$group]['late']) ? explode(':', $conditions[$group]['late'], 2) : [22, 0];
  $overtime_ratio = isset($conditions[$group]['overtime']) ? floatval($conditions[$group]['overtime']) / 100 : 1.5;
  $work_ratio = isset($conditions[$group]['workratio']) ? floatval($conditions[$group]['workratio']) / 100 : 1;
  $day_hours = (isset($conditions[$group]['workweek']) ? floatval($conditions[$group]['workweek']) / 5 : 9.6) * $work_ratio; 
 
  if ($row['atTemp_type'] !== 'time') {
    /* depuis php 5.3 goto est dispo, donc on fait un truc bien mal vu par tous
       les développeurs du monde */
    goto notTimeType;
  }

  /* Pas possible de travailler + que 24 */
  if ($begin->diff($end,true)->h >= 24) {
    error($begin, $end);
    continue;
  }
  /* Fin du travail avant le début impossible */
  if ($begin->diff($end)->h < 0 && $begin->diff($end)->m < 0) {
    error($begin, $end);
    continue;
  }

  $full_overtime = false;
  /* si le travail est un dimanche ou un jour férié, le temps intégral est en temps supplémentaire */
  if ($begin->format('N') === '7' || in_array($begin->format("Y-m-d\n"), $holidays)) {
    $full_overtime = true;
  }

  /* le début et la fin sont dans l'intervalle des heures supp */
  if (intervalInInterval($begin, $end, strToDT("$late_hour:$late_minute"), strToDT("$early_hour:$early_minute"))) {
    $full_overtime = true;
  }
  
  if ($full_overtime) {
    $diff = $begin->diff($end);
    $overtime = iToH($diff);
    $time = 0;
  } else {
    $result = crossIntervalLength($begin, $end, strToDT("$early_hour:$early_minute"), strToDT("$late_hour:$late_minute"));
    $overtime = $result[1] / 60;
    $time = $result[0] / 60;
  }
notTimeType:

  $currentDay = $begin->format('Y-m-d');
  /* Composition du tableau final, en prenant en compte les cas où le type et
     "jour entier" ou "demi-jour" */
  if ($row['atTemp_type'] === 'halfday' || $row['atTemp_type'] === 'wholeday') {
    $HoursInYear[$currentDay]['entries'][] = $row['atTemp_type'] === 'wholeday' ? 'Jour entier' : 'Demi-jour';
  } else {
    $HoursInYear[$currentDay]['entries'][] = $begin->format('G:i') . '-' . $end->format('G:i');
  }
  $reason = 0;
  switch ($row['atTemp_reason']) {
    default:
    case 'work': 
      if ($row['atTemp_type'] === 'halfday') {
        $HoursInYear[$currentDay]['done'] += $HoursInYear[$currentDay]['todo'] / 2;
        $HoursInYear[$currentDay]['days'] += 0.5;
      } else if ($row['atTemp_type'] === 'wholeday') {
        $HoursInYear[$currentDay]['done'] += $HoursInYear[$currentDay]['todo'];
        $HoursInYear[$currentDay]['days'] += 1;
      } else {
        $HoursInYear[$currentDay]['done'] += $time;
        $HoursInYear[$currentDay]['overtime'] += $overtime;
      }
      break;
    case 'driving':
      $HoursInYear[$currentDay]['driving'] += $time; 
      break;
    case 'holiday':
      if ($reason === 0) { $reason = VACANCY; }
    case 'learning':
      if ($reason === 0) { $reason = LEARNING; }
    case 'accident':
      if ($reason === 0) { $reason = ACCIDENT; }
    case 'army':
      if ($reason === 0) { $reason = ARMY; }
    case 'health':
      if ($reason === 0) { $reason = ACCIDENT; }
      $HoursInYear[$currentDay]['reason'] |= $reason;
      if ($row['atTemp_type'] === 'halfday') {
        $HoursInYear[$currentDay]['todo'] = $HoursInYear[$currentDay]['todo'] / 2;
        $HoursInYear[$currentDay]['days'] += 0.5;
      } else if ($row['atTemp_type'] === 'wholeday') {
        $HoursInYear[$currentDay]['todo'] = 0;
        $HoursInYear[$currentDay]['days'] += 1;
      } else {
        $HoursInYear[$currentDay]['done'] += $time;
        $HoursInYear[$currentDay]['overtime'] += $overtime;
      }
      break;
  }
}

$header = "Date     \t           \t           \tÀ faire\t Fait\tMajorer\tTotal\t Diff\t Solde\tTaux\tJours\tRaison\n";
$todo = 0;
$totalDone = 0;
$month = -1;
$vacancy = 0;
foreach($HoursInYear as $k => $entry) {
  $reason = [];
  foreach ([NORMAL, WEEKEND, HOLIDAY, NO_CONDITION, VACANCY, HEALTH, ARMY, ACCIDENT, LEARNING] as $t) {
    if ($t & $entry['reason']) {
      switch($t) {
        case NORMAL: $reason[] = 'normal'; break;
        case WEEKEND: $reason[] = 'weekend'; break;
        case HOLIDAY: $reason[] = 'ferié'; break;
        case NO_CONDITION: $reason[] = 'pas de contrat'; break;
        case VACANCY: $reason[] = 'vacances'; $vacancy += $entry['days']; break;
        case HEALTH: $reason[] = 'maladie'; break;
        case ARMY: $reason[] = 'armée'; break;
        case ACCIDENT: $reason[] = 'accident'; break;
        case LEARNING: $reason[] = 'formation'; break;
      }
    }
  }

  $reason = implode(', ', $reason);
    
  list ($y, $m, $d) = explode('-', $k);
  /* total du temps effectué est temps fait en temps normal + temps fait en période de surtravail multiplié par le ratio */
  $total = $entry['done'] + ($entry['overtime'] * $entry['overtime_ratio']);
  $diff = $total - $entry['todo'];
  $ratio = $entry['ratio'] * 100;
  $todo += $entry['todo'];
  $totalDone +=  $total;
  $yearDiff = $totalDone - $todo;

  if ($month !== intval($m)) {
    $month = intval($m);
    echo "\n=== ";
    switch(intval($m)) {
      case 1: echo 'Janvier'; break;
      case 2: echo 'Février'; break;
      case 3: echo 'Mars'; break;
      case 4: echo 'Avril'; break;
      case 5: echo 'Mai'; break;
      case 6: echo 'Juin'; break;
      case 7: echo 'Juillet'; break;
      case 8: echo 'Août'; break;
      case 9: echo 'Septembre'; break;
      case 10: echo 'Octobre'; break;
      case 11: echo 'Novembre'; break;
      case 12: echo 'Décembre'; break;        
    }
    echo " $y ===\n";
    echo $header . "\n";
  }
  $e = array_slice($entry['entries'], 0, 2);
  if (count($e) === 1) {
    $e[] = '           ';
  }
  if (count($e) === 0) {
    $e[] = '           ';
    $e[] = '           ';
  }
  echo "$d.$m.$y\t" . implode("\t", $e);
  if (count($entry['entries']) > 2) {
    echo "*\t";
  } else {
    echo " \t";
  }
  echo  toHM($entry['todo']) .
       "\t" . toHM($entry['done']) .
       "\t" . toHM($entry['overtime']) .
       "\t" . toHM($total) .
       "\t" . toHM($diff) .
       "\t" . toHM($yearDiff) . "\t$ratio %\t$entry[days]\t$reason\n";
}
echo "\n";
$diff = $totalDone - $todo;
echo "=== Total ===\n";
echo "À effectuer : \t\t" . toHM($todo).  "\n";
echo "Effectué : \t\t" . toHM($totalDone) . "\n";
echo "Solde : \t\t" . toHM($diff) . "\n";
echo "Jour de vacances pris :\t $vacancy\n";
?>
