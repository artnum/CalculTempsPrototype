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
define('COMPENSATION', 0x200);
define('ERROR_INPUT', 0x00100000);

const DAY_LENGTH_IN_S = 86400; /* 60*60*24 */
const DAY_LENGTH_IN_MS = 86400000; /* DAY_LENGTH_IN_S * 1000 */

class ClockHour {
  const INT_MAX = 1440;
  public $h;
  public $m;
  public $is24th = false;
  
  function __construct($h = 0.0, $m = 0.0) {
    if (is_string($h)) {
      $this->fromStr($h);
    } else if (is_integer($h)) {
      $this->fromInt($h);
    } else {
      $this->h = floatval($h);
      $this->m = floatval($m);
    }
    $this->is24th = false;
  }
  
  function isThe24th() {
    $this->is24th = true;
    if ($this->h === 0.0 && $this->m === 0.0) {
      $this->h = 24.0;
    }
  }

  /* pour le calcul entier, valeur opaque pour le calcul, doit être utilisé en corrélation avec INT_MAX */
  function toInt() {
    return intval(round($this->h * 60 + $this->m));
  }

  function fromInt($int) {
    $this->h = floor($int / 60);
    $this->m = (($int / 60) - $this->h) * 60;
  }
  
  function toMin() {
    return floatval($this->h * 60 + $this->m);
  }

  function fromMin($min) {
    $this->h = floor($int / 60);
    $this->m = (($int / 60) - $this->h) * 60;
  }

  function toStr() {
    return sprintf('% 2u:%02u', intval($this->h), intval($this->m));
  }

  function fromStr($str) {
    $x = explode(':', $str, 2);
    $strH = ''; $strM = '';
    
    if (isset($x[0])) { $strH = $x[0]; }
    if (isset($x[1])) { $strM = $x[1]; }
    if (is_numeric($strH) && is_numeric($strM)) {
      $this->h = floatval($strH);
      $this->m = floatval($strM);
    }   
  }
}

class ClockInterval {
  public $begin;
  public $end;
  
  function __construct ($begin, $end) {
    if (is_string($begin)) {
      $this->begin = new ClockHour($begin);
    } else if ($begin instanceof ClockHour) {
      $this->begin = clone $begin;
    } else if (is_integer($begin)) {
      $this->begin = new ClockHour();
      $this->begin->fromInt($begin);
    } else if (is_float($begin)) {
      $this->begin = new ClockHour();
      $this->begin->fromMin($begin);
    }
    if (is_string($end)) {
      $this->end = new ClockHour($end);
    } else if($end instanceof ClockHour) {
      $this->end = clone $end;
    } else if (is_integer($end)) {
      $this->end = new ClockHour();
      $this->end->fromInt($end);
    } else if ($is_float($end)) {
      $this->end = new ClockHour();
      $this->end->fromMin($end);
    }
   
    /* la fin est la 24ème heure si miniuit */
    $this->end->isThe24th();
  }

  /* retourne une intervalle normalisée avec le départ à 0 */
  function normalized () {
    $b = $this->begin->toInt();
    $e = $this->end->toInt();
   
    $e = ($e - $b) < 0 ? $e - $b + ClockHour::INT_MAX : $e - $b;
    return new ClockInterval(0, $e);
  }

  function norm_to_this ($interval) {
    $n = $this->normalized();
    $n1 = $interval->normalized();
    $deltaB = $this->begin->toInt() - $interval->begin->toInt();
    $deltaE = $this->end->toInt() - $interval->end->toInt();
    return [$n, new ClockInterval($n1->begin->toInt() + $deltaB, $n1->end->toInt() + $deltaE)];
  }

  function _overlap_($ib, $ie, $ref_b, $ref_e) {
    if ($ib <= $ref_b) {
      if ($ie <= $ref_b) { return 0; }
      if ($ie <= $ref_e) { return $ie - $ref_b; }
      if ($ie > $ref_e) { return $ref_e - $ref_b; }
    } else {
      if ($ib >= $ref_e) {  return 0; }
      if ($ie <= $ref_e) { return $ie - $ib; }
      if ($ie > $ref_e) { return $ref_e - $ib; }
    }
    return 0;
  }
  
  /* longueur où les intersections se croisent */
  function overlap_length($interval) {
    $ib = $interval->begin->toInt();
    $ie = $interval->end->toInt();
    $ref_b = $this->begin->toInt();
    $ref_e = $this->end->toInt();

    /* la fin est le jour suivant */
    $r = 0;
    if ($ref_e < $ref_b) {
      $r = $this->_overlap_($ib, $ie, $ref_b, ClockHour::INT_MAX) + $this->_overlap_($ib, $ie, 0, $ref_e);
    } else {
      $r = $this->_overlap_($ib, $ie, $ref_b, $ref_e);
    }
    
    return new ClockHour($r);
  }

  function _in_($ib, $ie, $ref_b, $ref_e) {
    if ($ib >= $ref_b && $ie <= $ref_e && $ie >= $ref_b && $ib <= $ref_e) { return true; }
    return false;
  }
  
  /* l'heure est dans l'interval */
  function isIn($p) {
    $ref_b = $this->begin->toInt();
    $ref_e = $this->end->toInt();
    if ($p instanceof ClockInterval) {
      if ($ref_b > $ref_e) {
        return $this->_in_($p->begin->toInt(), $p->begin->toInt(), $ref_b, ClockHour::INT_MAX) ||
               $this->_in_($p->begin->toInt(), $p->begin->toInt(), 0, $ref_e);
      } else {
        return $this->_in_($p->begin->toInt(), $p->begin->toInt(), $ref_b, $ref_e);
      }
      
    } else {
      if (is_string($p)) {
        $p = new ClockHour($p);
      }
      $h = $p->toInt();
      if ($ref_b > $ref_e) {
        if (($h >= $ref_b && $h <= ClockHour::INT_MAX) || ($h >= 0 && $h <= $ref_e)) { return true; }
      } else {
        if ($h >= $ref_b && $h <= $ref_e) { return true; }
      }
      return false;
    }
  }

  function length() {
    $result = new ClockHour();
    $b = $this->begin->toInt();
    $e = $this->end->toInt();
    return new ClockHour(intval($e - $b < 0 ? $e - $b + ClockHour::INT_MAX : $e - $b));
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
  $date = new ClockHour(intval($h), intval($m));
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
  return (new ClockInterval($i1, $i2))->length()->toMin();
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
  $i1 = new ClockInterval($i1, $i2);
  $i2 = new ClockInterval($j1, $j2);

  return $i2->isIn($i1);
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
