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
define('ERROR_INPUT', 0x00100000);

const DAY_LENGTH_IN_S = 86400; /* 60*60*24 */
const DAY_LENGTH_IN_MS = 86400000; /* DAY_LENGTH_IN_S * 1000 */

class ClockHour {
  public $h;
  public $m;
  public $is24th = false;
  
  function __construct($h = 0.0, $m = 0.0) {
    $this->h = floatval($h);
    $this->m = floatval($m);
    $this->is24th = false;
  }
  
  function isThe24th() {
    $this->is24th = true;
    if ($this->h === 0.0 && $this->m === 0.0) {
      $this->h = 24.0;
    }
  }
  
  function toMin() {
    return floatval($this->h * 60 + $this->m);
  }
}

/* En cas d'erreur on affiche pour traiter manuellement */
function error ($begin, $end) {
  echo 'Erreur de date -> début le ' . $begin->format('d.m.Y H:i') .
       ', fin le ' . $end->format('d.m.Y H:i') . PHP_EOL;
}

function iToH($i) {
  $time = (float)abs($i->h) + ((float)abs($i->i) / 60);
  if ($i->invert === 1) { return -$time; }
  return $time;
}

function toHM($h) {
  $neg = false;
  if ($h < 0) { $neg = true; }
  $h = abs($h);
  $m = round((floatval($h) - floor(floatval($h))) * 60);
  $h = floor(floatval($h));

  $sign = ' ';
  if ($neg) { $sign = '-'; }
  return sprintf("% 3u:%02u$sign", $h, $m);
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

function cmp_hour ($a, $b) {
  $ha = intval($a->format('G'));
  $hb = intval($b->format('G'));
  if ($ha === $hb) {
    $ma = intval($a->format('i'));
    $mb = intval($b->format('i'));
    return $ma - $mb;
  }
  return $ha - $hb;
}

function strToDT ($str) {
  list ($h, $m) = explode(':', $str, 2);
  $date = new ClockHour($h, $m);
  return $date;
}

function inInterval ($a, $i1, $i2) {
  /* mettre 24h sur un cercle de 360° (1440 minutes = 360°) */
  $ra = $a->toMin();
  $ri1 = $i1->toMin();
  $ri2 = $i2->toMin();

  /* basé sur https://math.stackexchange.com/questions/1044905/simple-angle-between-two-angles-of-circle */
  $ri2 = ($ri2 - $ri1) < 0 ? $ri2 - $ri1 + 1440.0 : $ri2 - $ri1;
  $ra = ($ra - $ri1) < 0 ? $ra - $ri1 + 1440.0 : $ra - $ri1;

  return ($ra < $ri2);
}

/* longueur de l'interval entre deux points sur un cercle de 1 de diametre */
function intervalLength($i1, $i2) {
  $ri1 = $i1->toMin();
  $ri2 = $i2->toMin();
  $ri2 = ($ri2 - $ri1) < 0 ? $ri2 - $ri1 + 1440.0 : $ri2 - $ri1;
  return $ri2;
}

function crossIntervalLength ($i1, $i2, $j1, $j2) {
  $il = intervalLength($i1, $i2);
  $ij1 = 0;
  if ($i1->toMin() - $j1->toMin() < 0) {
    $ij1 = intervalLength($i1, $j1);
  }
  $ij2 = 0;
  if ($i2->toMin() - $j2->toMin() > 0) {
    $ij2 = intervalLength($j2, $i2);
  }
  return [$il - ($ij1 + $ij2), $ij1 + $ij2];
}

function intervalInInterval ($i1, $i2, $j1, $j2) {
  if (inInterval($i1, $j1, $j2) && inInterval($i2, $j1, $j2)) {
    /* normalise avec le point de départ à 0 */
    $ix = $i2->toMin() - $i1->toMin() < 0 ? $i2->toMin() - $i1->toMin() + 1440.0 : $i2->toMin() - $i1->toMin();
    $jx = $j2->toMin() - $j1->toMin() < 0 ? $j2->toMin() - $j1->toMin() + 1440.0 : $j2->toMin() - $j1->toMin();

    /* is l'intervalle i normalisée est plus grande que l'intervalle j normalisée alors i n'est pas dans j */
    if ($ix > $jx) { return false; }
    else { return true; }
  }
  return false;
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

?>
