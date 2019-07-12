<?PHP

define('NORMAL', 0x1);
define('WEEKEND', 0x2);
define('HOLIDAY', 0x4);
define('NO_CONDITION', 0x8);
define('VACANCY', 0x10);
define('HEALTH', 0x20);
define('ARMY', 0x40);
define('ACCIDENT', 0x80);
define('LEARNING', 0x100);

const DAY_LENGTH_IN_S = 86400; /* 60*60*24 */
const DAY_LENGTH_IN_MS = 86400000; /* DAY_LENGTH_IN_S * 1000 */

/* En cas d'erreur on affiche pour traiter manuellement */
function error ($begin, $end) {
  echo 'Erreur de date -> début le ' . $begin->format('d.m.Y H:i') .
       ', fin le ' . $end->format('d.m.Y H:i') . PHP_EOL;
}

function toHM($h) {
  $m = round((floatval($h) - floor(floatval($h))) * 60);
  $h = floor(floatval($h));
  return $m < 10 ? "$h:0$m" : "$h:$m";
}

function cmp_date ($a, $b) {
  /* Raccourci salvateur pour comparer deux entier entre ==, < ou > :
     a - b == 0 si égal
     a - b < 0 si b est plus grand
     a - b > 0 si a est plus grand
   */
  return round($a->getTimestamp() / DAY_LENGTH_IN_S) -
         round($b->getTimestamp() / DAY_LENGTH_IN_S);
}

function load_conditions ($file, $year) {
  if (!is_readable($file)) {
    die('pas de fichiers de configuration' . PHP_EOL);
  }

  /* t'as pas cette fonction magique en C :D :D :D */
  $conf = parse_ini_file($file, true);
  if (!isset($conf["$year"])) {
    die('pas de configuration pour l\'année' . PHP_EOL);
  }

  $conditions = array();
  /* et encore moins foreach (clé => valeur) d'un tableau nommé */
  foreach($conf["$year"] as $key => $value) {
    list ($group, $property) = explode('_', $key, 2); /* découpe start_XXXX */
    if (!isset($conditions[$group])) {
      $conditions[$group] = []; /* je sais pas s'il est pas possible de ne pas
                                   faire cette initialisation .... mais dans le doute */
    }
    switch ($property) {
      case 'begin':
        /* dans ce genre de cas, indiquer "fall through" est une bonne pratique
           pour que l'on sache que c'est volontaire de pas avoir de break,
           d'ailleurs mon analyseur statique pour le javascript gueule si je le
           fait pas */
      case 'end':
        list ($d, $m) = explode('.', $value, 2);
        $value = new DateTime();
        $value->setDate($year, $m, $d);
        $value->setTime(12, 0, 0); /* 12h permet de pas trop se prendre les
                                      pieds dans les zones horaires */
        break;
      default:
        /* uniquement pour indiquer que j'ai pas oublier de condition */
        break;
    }
    $conditions[$group][$property] = $value;
  }

  return $conditions;
}

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
  $HoursInYear[$date->format("Y-m-d")] = ['todo' => 0,'ratio' => 0, 'reason' => NORMAL, 'done' => 0, 'overtime' => 0, 'days' => 0, 'driving' => 0, 'overtime_ratio' => 1.5];
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
    'overtime_ratio' => 1.5
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

  if ($full_overtime) {
    $diff = $begin->diff($end);
    $overtime = (float)$diff->h + ((float)$diff->i / 60);
    $time = 0;
  } else {
    /* Si l'heure de début est avant 5:00, cette différence est en temps de
       surtravaille et on déplace le début à 5:00 */
    $early = new DateTime($row['atTemp_begin']);
    $early->setTime($early_hour, $early_minute, 0);
    if ($early->getTimestamp() > $begin->getTimestamp()) {
      $diff = $early->diff($begin);
      $overtime = (float)$diff->h + ((float)$diff->i / 60);
      $begin = $early;
    }

    /* Si l'heure de début est après 22:00, plusieurs choix : */
    $late = new DateTime($row['atTemp_begin']);
    $late->setTime($late_hour, $late_minute, 0);
    if ($late->getTimestamp() < $begin->getTimestamp()) {

      $early = new DateTime($row['atTemp_end']);
      $early->setTime($early_hour, $early_minute, 0);
      /* certainement impossible */
      if ($early->getTimestamp() < $begin->getTimestamp()) {
        error($begin, $end);
        continue;
      }
      /* Si la fin est avant 5:00, nous sommes totalement dans du travaille
         supplémentaire, donc calcul différence début -> fin entièrement dans
         surtravail */
      if ($end->getTimestamp() < $early->getTimestamp()) {
        $notime = true;
        $diff = $end->diff($begin, true);
        $overtime = (float)$diff->h + ((float)$diff->i / 60);
      } else {
        /* sinon nous calculon la différence début -> 5:00 et déplaçons le début
           à 5:00 pour calculer le reste en travail normal (ce qui pourrait être
           cause pour une lutte syndicale, à ajouter dans mon carnet de
           révolutionnaire) */
        $diff = $begin->diff($early, true);
        $begin = $early;
        $overtime = (float)$diff->h + ((float)$diff->i / 60);
      }
    }

    /* normalement il reste que le cas où la fin est plus tard que 22:00 à
       traiter, donc on calcul la différence en temps supp et on déplace la fin à
       22:00 */
    $late = new DateTime($row['atTemp_end']);
    $late->setTime($late_hour, $late_minute, 0);
    if ($late->getTimestamp() < $end->getTimestamp()) {
      $diff = $end->diff($late, true);
      $overtime = (float)$diff->h + ((float)$diff->i / 60);                                                                                                                                                        
      $end = $late;
    }
    
    if (!$notime) {
      $interval = $begin->diff($end, true);
      $time = (float)$interval->h + ((float)$interval->i / 60);
    }
  }
notTimeType:

  $currentDay = $begin->format('Y-m-d');
  /* Composition du tableau final, en prenant en compte les cas où le type et
     "jour entier" ou "demi-jour" */
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

/*
    'todo' => $day_hours,                                                                                                                                                                                        
    'ratio' => $work_ratio,
    'reason' => $workday, 
    'done' => 0, 
    'overtime' => 0 */
/* le gros flemmard utilise un fonction de déboggage pour sortir le résultat */
$header = "Date     \tÀ faire\tFait\tSupp\tTotal\tDiff\tAn tot\tAn fait\tAn diff\tTaux\tJours\tCond\tRaison\n";
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
  echo "$d.$m.$y\t" . toHM($entry['todo']) .
       "\t" . toHM($entry['done']) .
       "\t" . toHM($entry['overtime']) .
       "\t" . toHM($total) .
       "\t" . toHM($diff) .
       "\t" . toHM($todo) .
       "\t" . toHM($totalDone) .
       "\t" . toHM($yearDiff) . "\t$ratio %\t$entry[days]\t$entry[driving]\t$reason\n";
}
echo "\n";
$diff = $totalDone - $todo;
echo "=== Total ===\n";
echo "À effectuer : \t\t" . toHM($todo).  "\n";
echo "Effectué : \t\t" . toHM($totalDone) . "\n";
echo "Solde : \t\t" . toHM($diff) . "\n";
echo "Jour de vacances pris : $vacancy\n";
?>
