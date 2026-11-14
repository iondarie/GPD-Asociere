<?php
require __DIR__.'/../app/src/Core.php';
foreach([
 ['PERSOANĂ FIZICĂ - ȘIR_TEST ȚÎNTĂ','Tinta Sir Test'],
 ['PERSOANA FIZICA ŞIR_TEST ŢÎNTĂ','sir test tinta'],
 ['ȘABLON_DATE EXEMPLU','exemplu sablon date'],
 ['TEXT-SINTETIC CÂMP','Camp sintetic text'],
 ['ĂÂÎȘŞȚŢ ăâîșşțţ','aaisstt aaisstt'],
] as [$a,$b])if(Plati\nameKey($a)!==Plati\nameKey($b))throw new RuntimeException('Diacritic normalization failed');
echo "PASS: Romanian diacritics, legacy cedilla forms, case, prefix and word order.\n";
