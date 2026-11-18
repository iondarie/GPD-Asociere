<?php
declare(strict_types=1);
namespace Plati;
if(!defined('PLATI_ENTRY')&&PHP_SAPI!=='cli'){http_response_code(404);exit;}

final class Matching {
 static function ranked(array $edges,array $occupiedI=[],array $occupiedR=[],array $blocked=[]):array{$result=[];$usedI=[];$usedR=[];for($rank=0;$rank<=4;$rank++){$left=[];$right=[];$bestI=[];$bestR=[];$tier=[];foreach($edges as $e){if(isset($usedI[$e['invoice']],$usedR[$e['resource']])||isset($usedI[$e['invoice']])||isset($usedR[$e['resource']]))continue;$i=$e['invoice'];$r=$e['resource'];$bestI[$i]=min($bestI[$i]??99,$e['rank']);$bestR[$r]=min($bestR[$r]??99,$e['rank']);if($e['rank']===$rank){$left[$i]=($left[$i]??0)+1;$right[$r]=($right[$r]??0)+1;$tier[]=$e;}}foreach($tier as $e){$i=$e['invoice'];$r=$e['resource'];if($bestI[$i]!==$rank||$bestR[$r]!==$rank||$left[$i]!==1||$right[$r]!==1||isset($occupiedI[$i])||isset($occupiedR[$r])||isset($blocked[$i])||!empty($e['blocked']))continue;$result[]=$e;$usedI[$i]=true;$usedR[$r]=true;}}return $result;}
 static function similar(string $a,string $b):bool{$aa=explode(' ',$a);$bb=explode(' ',$b);if(count($aa)!==count($bb)||count($aa)<2)return false;$edits=0;foreach($aa as $k=>$v){$edits+=levenshtein($v,$bb[$k]);if($edits>2)return false;}return true;}
 static function edge(array $i,array $r,string $kind,bool $anchor=false,bool $identityBridge=false):?array {
  $date=$r[$kind==='fan'?'awb_date':'operation_date'];if((int)$i['amount_cents']<=0||$i['scope']==='internal'||!validDate($i['invoice_date'])||!validDate($date))return null;
  $same=$i['normalized_name']!==''&&$i['normalized_name']===$r['normalized_name'];$typo=$kind==='fan'&&$anchor&&self::similar($i['normalized_name'],$r['normalized_name']);
  // Ignore a numeric company suffix only with an exact order anchor and identical remaining legal name.
  $company=$kind==='fan'&&$anchor&&str_contains($i['normalized_name'],'srl')&&preg_replace('/\b[0-9]{6,}\b\s*/','',$r['normalized_name'])===$i['normalized_name'];
  if(!$same&&!$typo&&!$company&&!$identityBridge)return null;if(!$anchor&&gap($i['invoice_date'],$date)>3)return null;$amount=$r[$kind==='fan'?'cash_due_cents':'gross_amount_cents'];$zero=$kind==='fan'&&method($r)==='NETOPIA'&&($amount===null||(int)$amount===0);if($amount===null&&!$zero)return null;$diff=$zero?null:(int)$amount-(int)$i['amount_cents'];if(!$zero&&!in_array($diff,$kind==='fan'?[-1,0,1]:[0,1],true))return null;
  $rank=($anchor?0:($diff===0||$zero?0:2))+($i['invoice_date']===$date?0:1);return ['invoice'=>(int)$i['id'],'resource'=>(int)$r['id'],'kind'=>$kind,'rank'=>$rank,'zero'=>$zero,'difference'=>$diff,'anchor'=>$anchor,'reason'=>$anchor?'ID exact verificat; sumă și identitate concordante.':'Nume identic, sumă în toleranță și date în ±3 zile.'];
 }
 static function plan(\PDO $db):array {
  $invoices=query($db,"SELECT * FROM invoices WHERE amount_cents>0 AND scope<>'internal'")->fetchAll();$ships=query($db,'SELECT * FROM fan_shipments')->fetchAll();$nets=query($db,'SELECT p.* FROM netopia_payments p WHERE '.Topups::available())->fetchAll();$orders=query($db,'SELECT o.* FROM site_orders o WHERE '.SiteFeed::eligibleSQL())->fetchAll();$ordersByNumber=array_column($orders,null,'order_number');$orderLinks=[];foreach(query($db,'SELECT l.invoice_id,o.* FROM invoice_orders l JOIN site_orders o ON o.id=l.order_id')->fetchAll() as $l)$orderLinks[$l['invoice_id']]=$l;
  $linkedOrderCounts=array_count_values(array_column($orderLinks,'id'));
  $siteAwbCounts=array_count_values(query($db,"SELECT awb FROM site_orders WHERE awb IS NOT NULL AND awb<>''")->fetchAll(\PDO::FETCH_COLUMN));
  $reviewAwbs=array_fill_keys(query($db,"SELECT DISTINCT operation_number FROM provider_adjustments
   WHERE adjustment_type=? AND review_status IN ('waiting_amount','amount_mismatch','returned','date_conflict')",[FanTransfers::TYPE])->fetchAll(\PDO::FETCH_COLUMN),true);
  $shipNames=[];$shipOrders=[];$shipsByAwb=[];$netNames=[];$netOrders=[];foreach($ships as $s){$shipsByAwb[$s['awb']]=$s;$shipNames[$s['normalized_name']][]=$s;if($s['order_reference'])$shipOrders[$s['order_reference']][]=$s;}foreach($nets as $n)$netNames[$n['normalized_name']][]=$n;foreach(query($db,"SELECT a.external_id,p.* FROM payment_identifiers a JOIN netopia_payments p ON p.id=a.payment_id WHERE a.status='approved' AND ".Topups::available())->fetchAll() as $n)$netOrders[$n['external_id']]=$n;
  $orderNames=[];foreach($orders as $o)foreach(array_unique([nameKey($o['client']),nameKey($o['company'])]) as $key)if($key!=='')$orderNames[$key][]=$o;
  $occupiedI=['fan'=>[],'netopia'=>[]];$occupiedR=$occupiedI;foreach(['fan'=>'shipment_assignments','netopia'=>'payment_resolutions'] as $kind=>$table)foreach(query($db,'SELECT * FROM '.$table)->fetchAll() as $c){$occupiedR[$kind][(int)$c[$kind==='fan'?'shipment_id':'payment_id']]=true;if($c['invoice_id']!==null)$occupiedI[$kind][(int)$c['invoice_id']]=true;}
  foreach(query($db,"SELECT payment_id,shipment_id FROM decisions WHERE action='revoked' AND active=1")->fetchAll() as $hold){if($hold['payment_id'])$occupiedR['netopia'][(int)$hold['payment_id']]=true;if($hold['shipment_id'])$occupiedR['fan'][(int)$hold['shipment_id']]=true;}
  foreach(query($db,"SELECT invoice_id FROM decisions WHERE action='netopia_allocation' AND active=1")->fetchAll() as $allocated)$occupiedI['netopia'][(int)$allocated['invoice_id']]=true;
  $rejected=[];foreach(query($db,"SELECT invoice_id,payment_id,shipment_id FROM decisions WHERE action='rejected' AND active=1")->fetchAll() as $d)$rejected[($d['payment_id']!==null?'netopia':'fan').':'.$d['invoice_id'].':'.($d['payment_id']??$d['shipment_id'])]=true;
  $edges=['fan'=>[],'netopia'=>[]];$blocked=[];$issues=[];$evidence=[];$scope=[];
  foreach($invoices as $i){$id=(int)$i['id'];$scope[$id]=$i;$ref=reference($i['observation']);$order=$orderLinks[$id]??($ref['kind']==='order'?($ordersByNumber[$ref['id']]??null):null);$anchor=$order['order_number']??($ref['kind']==='order'?$ref['id']:null);
   $fanChoices=$shipNames[$i['normalized_name']]??[];if($anchor)$fanChoices=array_merge($fanChoices,$shipOrders[$anchor]??[]);if($order&&isset($shipsByAwb[$order['awb']]))$fanChoices[]=$shipsByAwb[$order['awb']];
   $netChoices=$netNames[$i['normalized_name']]??[];foreach($orderNames[$i['normalized_name']]??[] as $o)if(isset($netOrders[$o['order_number']])&&abs((int)$o['amount_cents']-(int)$i['amount_cents'])<=1&&$o['order_date']&&gap($i['invoice_date']??'1900-01-01',$o['order_date'])<=3)$netChoices[]=$netOrders[$o['order_number']];if($anchor&&isset($netOrders[$anchor]))$netChoices[]=$netOrders[$anchor];
   foreach(['fan'=>$fanChoices,'netopia'=>$netChoices] as $kind=>$choices){$seen=[];foreach($choices as $r){if(isset($seen[$r['id']]))continue;$seen[$r['id']]=true;$hasAnchor=$kind==='fan'&&$anchor&&$r['order_reference']===$anchor;$bridge=false;
     $confirmedFanChain=$kind==='fan'&&$hasAnchor&&isset($orderLinks[$id],$ordersByNumber[$anchor])
      && ($linkedOrderCounts[$order['id']]??0)===1&&($siteAwbCounts[$r['awb']]??0)===1
      && $order['awb']===$r['awb']&&$order['payment_method']==='Plata in sistem ramburs'
      && $r['delivery_status']==='delivered'&&method($r)==='FAN'
      && ($ref['kind']==='none'||($ref['kind']==='order'&&$ref['id']===$anchor))
      && !isset($occupiedI['netopia'][$id])&&!isset($reviewAwbs[$r['awb']]);
     if($kind==='fan')$bridge=$confirmedFanChain;
     if($kind==='netopia'){foreach($orderNames[$i['normalized_name']]??[] as $o)if(isset($netOrders[$o['order_number']])&&(int)$netOrders[$o['order_number']]['id']===(int)$r['id']&&nameKey($o['client'])===$r['normalized_name']&&(int)$o['amount_cents']===(int)$r['gross_amount_cents']&&$o['order_date']&&gap($i['invoice_date']??'1900-01-01',$o['order_date'])<=3)$bridge=true;}$e=self::edge($i,$r,$kind,(bool)$hasAnchor,$bridge);if(!$e||isset($rejected[$kind.':'.$id.':'.$r['id']]))continue;
     if($kind==='fan'&&$anchor&&$r['order_reference']&&$anchor!==$r['order_reference']){$issues[$id][]='ID-ul comenzii FAN contrazice factura.';continue;}
     if($order){if(abs((int)$order['amount_cents']-(int)$i['amount_cents'])>1)$issues[$id][]='Valoarea comenzii SITE diferă.';if($kind==='fan'&&$order['awb']&&$order['awb']!==$r['awb'])$issues[$id][]='AWB-ul SITE este diferit.';if(($kind==='netopia'||$e['zero'])&&$order['payment_method']==='Plata in sistem ramburs')$issues[$id][]='Metoda SITE este ramburs, dar sursa indică NETOPIA.';}
     if($kind==='fan'&&!$e['zero']&&isset($occupiedI['netopia'][$id])){
      $issues[$id][]='Factura are deja o plată NETOPIA asociată.';
      $e['blocked']=true;
     }
     if($kind==='fan'&&isset($reviewAwbs[$r['awb']]))$e['blocked']=true;
     if((int)$i['balance_cents']===0&&(!$confirmedFanChain||!validDate($r['transfer_date']??null)))$e['blocked']=true;
     if($confirmedFanChain)$e['reason']='AWB și comandă identice în FAN și SITE, factură unică asociată comenzii, sumă în toleranța de un ban.';
     $edges[$kind][]=$e;$evidence[$id][$kind][]=$e;
   }}
   $fs=$evidence[$id]['fan']??[];$ns=$evidence[$id]['netopia']??[];$positive=array_filter($fs,fn($e)=>!$e['zero']);$zero=array_filter($fs,fn($e)=>$e['zero']);if(($positive&&$ns)||($positive&&$zero))$issues[$id][]='Surse financiare incompatibile; necesită verificare.';if($i['scope_reason']==='retained')$issues[$id][]='Marcajele SITE s-au schimbat.';if($i['scope']!=='accepted'||(int)$i['balance_cents']<0)$blocked[$id]=true;
  }
  foreach(query($db,"SELECT original_invoice_id FROM cash_circuits WHERE status='confirmed'")->fetchAll() as $cash)$blocked[(int)$cash['original_invoice_id']]=true;
  foreach($issues as $id=>$reason)$blocked[$id]=true;$actions=[];foreach($edges as $kind=>$e)$actions=array_merge($actions,self::ranked($e,$occupiedI[$kind],$occupiedR[$kind],$blocked));return ['actions'=>$actions,'issues'=>$issues,'edges'=>$edges];
 }
 static function minorNameDifference(string $a,string $b):bool{if($a===$b||$a===''||$b==='')return false;$aa=explode(' ',$a);$bb=explode(' ',$b);if(count($aa)!==count($bb)||count($aa)<2)return false;foreach($aa as $k=>$token){$found=array_search($token,$bb,true);if($found!==false){unset($aa[$k],$bb[$found]);}}if(count($aa)!==1||count($bb)!==1)return false;$x=reset($aa);$y=reset($bb);if(mb_strlen($x)<3||mb_strlen($y)<3)return false;$x=preg_split('//u',$x,-1,PREG_SPLIT_NO_EMPTY);$y=preg_split('//u',$y,-1,PREG_SPLIT_NO_EMPTY);if(abs(count($x)-count($y))>2)return false;$previous=range(0,count($y));foreach($x as $i=>$char){$current=[$i+1];foreach($y as $j=>$other)$current[$j+1]=min($current[$j]+1,$previous[$j+1]+1,$previous[$j]+($char===$other?0:1));$previous=$current;}$distance=$previous[count($y)];return $distance>=1&&$distance<=2;}
 static function cashOrderCompatible(array $invoice,array $order):bool {
  $ref=reference($invoice['observation']);
  return $ref['kind']==='order'
   && $ref['id']===$order['order_number']
   && $invoice['normalized_name']!==''
   && in_array($invoice['normalized_name'],[nameKey($order['client']),nameKey($order['company'])],true)
   && (int)$invoice['amount_cents']===(int)$order['amount_cents']
   && validDate($invoice['invoice_date']) && validDate($order['order_date'])
   && gap($invoice['invoice_date'],$order['order_date'])<=3
   && !$order['awb'] && $order['payment_method']==='Plata in sistem ramburs';
 }
 static function corroboratedOrder(array $invoice,array $order,array $shipment):bool {
  return $order['payment_method']==='Plata in sistem ramburs'
   && $invoice['invoice_date']>=$order['order_date']
   && gap($invoice['invoice_date'],$order['order_date'])<=7
   && validDate($shipment['awb_date'])
   && $shipment['awb_date']>=$order['order_date']
   && gap($invoice['invoice_date'],$shipment['awb_date'])<=7
   && (!$shipment['order_reference']||$shipment['order_reference']===$order['order_number'])
   && ($shipment['invoice_id']===null||(int)$shipment['invoice_id']===(int)$invoice['id'])
   && $shipment['normalized_name']===$invoice['normalized_name']
   && (int)$shipment['cash_due_cents']===(int)$invoice['amount_cents']
   && (int)$order['amount_cents']===(int)$invoice['amount_cents'];
 }
 static function orderPlan(\PDO $db):array{$invoices=query($db,"SELECT * FROM invoices WHERE amount_cents>0 AND scope<>'internal'")->fetchAll();$orders=query($db,'SELECT o.* FROM site_orders o WHERE '.SiteFeed::eligibleSQL())->fetchAll();$usedI=[];$usedO=[];foreach(query($db,'SELECT invoice_id,order_id FROM invoice_orders')->fetchAll() as $l){$usedI[$l['invoice_id']]=true;$usedO[$l['order_id']]=true;}$deliveredByAwb=[];
  foreach(query($db,"SELECT s.*,a.invoice_id FROM fan_shipments s
   LEFT JOIN shipment_assignments a ON a.shipment_id=s.id
   JOIN (SELECT awb FROM site_orders WHERE awb IS NOT NULL GROUP BY awb HAVING COUNT(*)=1) unique_order ON unique_order.awb=s.awb
   WHERE s.delivery_status='delivered' AND s.cash_due_cents>0")->fetchAll() as $shipment){
   $deliveredByAwb[$shipment['awb']]=$shipment;
  }
  foreach(query($db,"SELECT order_id FROM decisions WHERE action='revoked' AND active=1 AND order_id IS NOT NULL")->fetchAll() as $hold)$usedO[(int)$hold['order_id']]=true;$knownShips=[];foreach(query($db,'SELECT a.invoice_id,s.awb,s.cash_due_cents FROM shipment_assignments a JOIN fan_shipments s ON s.id=a.shipment_id')->fetchAll() as $shipment)$knownShips[$shipment['invoice_id']]=$shipment;$knownPayments=[];foreach(query($db,"SELECT r.invoice_id,a.external_id FROM payment_resolutions r JOIN payment_identifiers a ON a.payment_id=r.payment_id WHERE r.invoice_id IS NOT NULL AND a.status='approved'")->fetchAll() as $payment)$knownPayments[$payment['invoice_id']][$payment['external_id']]=true;$idx=[];foreach($orders as $o)foreach(array_unique([nameKey($o['client']),nameKey($o['company'])]) as $name)$idx[$name][]=$o;$byAmount=[];foreach($orders as $candidate)$byAmount[(int)$candidate['amount_cents']][]=$candidate;$byNr=array_column($orders,null,'order_number');$rejectedOrders=[];foreach(query($db,"SELECT invoice_id,order_id FROM decisions WHERE action='rejected' AND active=1 AND order_id IS NOT NULL")->fetchAll() as $rejected)$rejectedOrders[$rejected['invoice_id'].':'.$rejected['order_id']]=true;$cashBlocked=[];foreach(query($db,"SELECT original_invoice_id FROM cash_circuits WHERE status='confirmed'")->fetchAll() as $cash)$cashBlocked[(int)$cash['original_invoice_id']]=true;$edges=[];$blocked=[];foreach($invoices as $i){$ref=reference($i['observation']);$candidates=array_merge($idx[$i['normalized_name']]??[],$byAmount[(int)$i['amount_cents']]??[]);if($ref['kind']==='order'&&isset($byNr[$ref['id']]))$candidates[]=$byNr[$ref['id']];$seen=[];foreach($candidates as $o){if(isset($seen[$o['id']]))continue;$seen[$o['id']]=true;if(isset($rejectedOrders[$i['id'].':'.$o['id']]))continue;$cashConflict=isset($cashBlocked[(int)$i['id']])&&!self::cashOrderCompatible($i,$o);$exactName=in_array($i['normalized_name'],[nameKey($o['client']),nameKey($o['company'])],true);$minorName=!$exactName&&(self::minorNameDifference($i['normalized_name'],nameKey($o['client']))||self::minorNameDifference($i['normalized_name'],nameKey($o['company'])));if(!$exactName&&!$minorName)continue;if($minorName&&(int)$o['amount_cents']!==(int)$i['amount_cents'])continue;if(!validDate($i['invoice_date'])||!validDate($o['order_date'])||!in_array((int)$o['amount_cents']-(int)$i['amount_cents'],[0,1],true))continue;
    $extended=gap($i['invoice_date'],$o['order_date'])>3;
    if($extended&&(!$exactName||!isset($deliveredByAwb[$o['awb']])||!self::corroboratedOrder($i,$o,$deliveredByAwb[$o['awb']])))continue;
    if($ref['kind']==='order'&&$ref['id']!==$o['order_number'])continue;$ship=$knownShips[$i['id']]??null;$paid=$knownPayments[$i['id']]??null;if($ship&&$o['awb']&&$ship['awb']!==$o['awb'])continue;if($paid&&(!isset($paid[$o['order_number']])||$o['payment_method']==='Plata in sistem ramburs'))continue;if($ship&&((int)$ship['cash_due_cents']>0&&$o['payment_method']==='Plata online - NETOPIA Payments'||(int)$ship['cash_due_cents']===0&&$o['payment_method']==='Plata in sistem ramburs'))continue;$edge=['invoice'=>(int)$i['id'],'resource'=>(int)$o['id'],'rank'=>$extended?4:($exactName?($ref['kind']==='order'?0:($i['invoice_date']===$o['order_date']?0:1)):(2+($i['invoice_date']===$o['order_date']?0:1))),'name_match'=>$exactName?'exact':'minor_typo'];if($cashConflict)$edge['blocked']=true;
    if($extended)$edge['evidence']='delivered_fan';
    $edges[]=$edge;}if($i['scope']!=='accepted'||(int)$i['balance_cents']<0)$blocked[$i['id']]=true;}return self::ranked($edges,$usedI,$usedO,$blocked);}
 static function apply(\PDO $db,array $actor):array {
  $counts=['fan'=>0,'netopia'=>0,'orders'=>0];
  // Resolve safe identity links first, so tracking/payment uses them in this import.
  foreach(self::orderPlan($db) as $a){
   $i=one($db,'SELECT source_hash FROM invoices WHERE id=?',[$a['invoice']]);$r=one($db,'SELECT source_hash FROM site_orders WHERE id=?',[$a['resource']]);
   $reason=match(true){
    ($a['evidence']??null)==='delivered_fan'=>'Identitate automată unică: AWB FAN livrat, nume și ramburs identice, factură emisă în cel mult 7 zile de la comandă.',
    ($a['name_match']??'exact')==='minor_typo'=>'Identitate automată cu potrivire aproximativă: 1–2 litere într-un singur cuvânt, sumă exactă și dată în ±3 zile; unicitate în ambele sensuri.',
    default=>'Identitate automată unică: nume, sumă și dată concordante.',
   };
   Review::save($db,['action'=>'confirmed','kind'=>'site','invoice'=>$a['invoice'],'resource'=>$a['resource'],'invoice_hash'=>$i['source_hash'],'resource_hash'=>$r['source_hash'],'reason'=>$reason,'accept_difference'=>true,'request_key'=>'auto-order:'.hash('sha256',json([$a,$i,$r]))],$actor);$counts['orders']++;
  }
  $plan=self::plan($db);
  foreach($plan['actions'] as $a){
   $i=one($db,'SELECT source_hash FROM invoices WHERE id=?',[$a['invoice']]);$r=one($db,'SELECT source_hash FROM '.($a['kind']==='fan'?'fan_shipments':'netopia_payments').' WHERE id=?',[$a['resource']]);
   Review::save($db,['action'=>'confirmed','kind'=>$a['kind'],'invoice'=>$a['invoice'],'resource'=>$a['resource'],'invoice_hash'=>$i['source_hash'],'resource_hash'=>$r['source_hash'],'reason'=>'Asociere automată: '.$a['reason'],'accept_difference'=>true,'request_key'=>'auto:'.hash('sha256',json([$a,$i,$r]))],$actor);$counts[$a['kind']]++;
  }
  $cash=Operations::cashApply($db,$actor);return $counts+['cash_circuits'=>$cash['saved'],'review_cases'=>count($plan['issues'])];
 }
}
