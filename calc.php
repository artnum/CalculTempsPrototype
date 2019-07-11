<?PHP
const DAY_LENGTH_IN_S = 86400; /* 60*60*24 */
const DAY_LENGTH_IN_MS = 86400000; /* DAY_LENGTH_IN_S * 1000 */

/* En cas d'erreur on affiche pour traiter manuellement */
function error ($begin, $end) {
  echo 'Erreur de date -> début le ' . $begin->format('d.m.Y H:i') .
       ', fin le ' . $end->format('d.m.Y H:i') . PHP_EOL;
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
$res = $DB->query('SELECT * FROM atTemp WHERE atTemp_target = "' .
                  $argv[1] . '"');
$results = [];

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
while (cmp_date($date, $to) <= 0) {
  if ($date->format('N') === '6' || $date->format('N') === '7') {
    $date->add($int);
    continue;
  }
  if (in_array($date->format("Y-m-d\n"), $holidays)) {
    $date->add($int);
    continue;
  }

  $group = null;
  foreach($conditions as $k => $v) {
    if (!isset($v['begin']) || !isset($v['end'])) {
      error($begin, $end);
      break;
    }
    if (cmp_date($v['begin'], $date) < 0 && cmp_date($v['end'], $date) >= 0) {
      $group = $k;
      break;
    }
  }
  if (is_null($group)) {
    $date->add($int);
    continue;
  }
  $work_ratio = isset($conditions[$group]['workratio']) ? floatval($conditions[$group]['workratio']) / 100 : 1;
  $day_hours = (isset($conditions[$group]['workweek']) ? floatval($conditions[$group]['workweek']) / 5 : 9.6) * $work_ratio; 

  $HoursInYear[$date->format("Y-m-d\n")] = [
    'todo' => $day_hours,
    'done' => 0
    ];
  $HoursToDo += $day_hours;

  $date->add($int);
}

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
  
notTimeType:
  if (!isset($results[$group])) {
    $results[$group] = [
      'condition' => $conditions[$group],
      'v' => []
    ];
  }
  if (!isset($results[$group]['v'][$row['atTemp_reason']])) {
    $results[$group]['v'][$row['atTemp_reason']] = [
      'time' => 0,
      'overtime' => [0, 0],
      'day' => 0
    ];
  }

  if (!isset($results[$group]['v']['work'])) {
    $results[$group]['v']['work'] = [
      'time' => 0,
      'overtime' => [0, 0],
      'day' => 0
      ];
  }
  /* Composition du tableau final, en prenant en compte les cas où le type et
     "jour entier" ou "demi-jour" */
  switch ($row['atTemp_reason']) {
    default:
    case 'work': 
      if ($row['atTemp_type'] === 'halfday') {
        $results[$group]['v'][$row['atTemp_reason']]['time'] += $day_hours / 2;
      } else if ($row['atTemp_type'] === 'wholeday') {
        $results[$group]['v'][$row['atTemp_reason']]['time'] += $day_hours;
      } else {
        $results[$group]['v'][$row['atTemp_reason']]['time'] += $time;
        $results[$group]['v'][$row['atTemp_reason']]['overtime'][0] += $overtime;
        $results[$group]['v'][$row['atTemp_reason']]['overtime'][1] = $results[$group]['v'][$row['atTemp_reason']]['overtime'][0] * $overtime_ratio;
      }
      break;
    case 'driving':
      $results[$group]['v'][$row['atTemp_reason']]['time'] += $time + $overtime;
      break;
    case 'holiday':
    case 'learning':
    case 'accident':
    case 'army':
    case 'health':
      if ($row['atTemp_type'] === 'halfday') {
        $results[$group]['v'][$row['atTemp_reason']]['day'] += 0.5;
      } else if ($row['atTemp_type'] === 'wholeday') {
        $results[$group]['v'][$row['atTemp_reason']]['day'] += 1;
      } else {
        $results[$group]['v'][$row['atTemp_reason']]['time'] += $time + $overtime;
      }
      
      if ($row['atTemp_type'] === 'halfday') {
        $results[$group]['v']['work']['time'] += $day_hours / 2;
      } else if ($row['atTemp_type'] === 'wholeday') {
        $results[$group]['v']['work']['time'] += $day_hours;
      } else {
        $results[$group]['v']['work']['time'] += $time;
        $results[$group]['v']['work']['overtime'][0] += $overtime;
        $results[$group]['v']['work']['overtime'][1] = $results[$group]['v']['work']['overtime'][0] * $overtime_ratio;
      }
      break;
  }
}

/* le gros flemmard utilise un fonction de déboggage pour sortir le résultat */
print_r($results);


$wTime = 0;
$hDay = 0;
foreach ($results as $groups) {
  print_r($groups, $wTime);
  $wTime += $groups['v']['work']['time'] + $groups['v']['work']['overtime'][1];
  $hDay += $groups['v']['holiday']['day'];
}

echo 'Jour de congé pris : ' . $hDay . "\n";
echo 'Temps travaillé : ' . $wTime . "\n";
echo 'Temps à effectuer : ' . $HoursToDo . "\n";
echo 'SOLDE : ' . ($wTime - $HoursToDo) . "\n";
?>
