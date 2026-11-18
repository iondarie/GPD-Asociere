<?php
declare(strict_types=1);

define('PLATI_ENTRY',true);
require __DIR__.'/../app/src/Core.php';
require __DIR__.'/../app/src/Matching.php';

use Plati\Matching;
use function Plati\nameKey;

if(nameKey('PERSOANA FIZICA - IONUT NOVAC')!==nameKey('Ionut Novac')){
    throw new RuntimeException('Prefixul persoană fizică trebuie ignorat la asociere.');
}
if(!Matching::minorNameDifference('cozac ioan claudio','cozac ioan claudiu')){
    throw new RuntimeException('Diferența de o literă trebuie semnalată ca potrivire apropiată.');
}
if(Matching::minorNameDifference('mihai','mihai vil')){
    throw new RuntimeException('Numele incomplet nu poate deveni asociere automată.');
}
if(Matching::minorNameDifference('ion popescu','maria ionescu')){
    throw new RuntimeException('Nume diferite nu pot fi considerate potrivire apropiată.');
}

echo "PASS: normalizare nume și praguri de asociere protejate.\n";
