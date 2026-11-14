<?php
declare(strict_types=1);
namespace Plati;

if(!defined('PLATI_ENTRY')&&PHP_SAPI!=='cli'){http_response_code(404);exit;}

final class FanTransfers {
 const TYPE='fan_financial_transfer';
 const HEADERS=['AWB','Suma incasata','Data virament'];

 static function parse(array $sheet):array {
  $records=[];
  $errors=[];
  foreach($sheet['rows'] as $raw){
   $values=$raw['values'];
   try{
    $numeric=$raw['numeric'];
    $awb=Sources::id($values['AWB'],in_array('AWB',$numeric,true),true);
    $amount=Sources::fixed($values['Suma incasata'],2,in_array('Suma incasata',$numeric,true));
    $date=Sources::date($values['Data virament'],in_array('Data virament',$numeric,true),$sheet['epoch']);
    if(!$awb||$amount<=0||!$date)throw new Problem('Sunt necesare AWB-ul, suma pozitivă și data viramentului.');
    $records[]=['type'=>self::TYPE,'row'=>$raw['row'],'sheet'=>$sheet['name'],'awb'=>$awb,
     'amount_cents'=>$amount,'transfer_date'=>$date];
   }catch(Problem $e){$errors[]=['row'=>$raw['row'],'error'=>$e->getMessage()];}
  }
  return [$records,$errors];
 }

 static function record(\PDO $db,int $import,array $record):void {
  $row=Store::importRow($db,$import,$record['sheet'],$record['row'],$record['awb'],hashSource($record),'separate');
  $proof=['amount_cents'=>$record['amount_cents'],'transfer_date'=>$record['transfer_date']];
  query($db,'INSERT INTO provider_adjustments(import_row_id,adjustment_type,operation_number,source_details_json,review_status) VALUES(?,?,?,?,?)',
   [$row,self::TYPE,$record['awb'],json($proof),'waiting_awb']);
 }

 private static function evidenceByAwb(\PDO $db,?string $awb=null):array {
  $rows=query($db,'SELECT a.id,a.operation_number,a.review_status,a.source_details_json,f.filename,r.source_row_number
   FROM provider_adjustments a JOIN import_rows r ON r.id=a.import_row_id JOIN import_files f ON f.id=r.import_id
   WHERE a.adjustment_type=?'.($awb!==null?' AND a.operation_number=?':'').' ORDER BY a.id',$awb!==null?[self::TYPE,$awb]:[self::TYPE]);
  $grouped=[];
  foreach($rows as $row){
   $row+=json_decode($row['source_details_json'],true,32,JSON_THROW_ON_ERROR);
   $key=$row['operation_number'];
   unset($row['source_details_json'],$row['operation_number']);
   $grouped[$key][]=$row;
  }
  return $grouped;
 }

 static function reconcileImported(\PDO $db,array $touched, array $actor):void {
  foreach(self::evidenceByAwb($db) as $awb=>$rows){
   if(isset($touched[$awb]))self::reconcile($db,(string)$awb,$rows,$actor);
  }
 }

 private static function status(\PDO $db,array $row,string $status):void {
  if($row['review_status']!==$status)query($db,'UPDATE provider_adjustments SET review_status=? WHERE id=?',[$status,$row['id']]);
 }

 private static function remember(\PDO $db,array $shipment, array $proof,array $actor,string $reason):void {
  $previous=one($db,"SELECT id FROM decisions WHERE shipment_id=? AND action='fan_transfer_date' AND active=1 FOR UPDATE",[$shipment['id']]);
  $id=decision($db,'fan_transfer_date',['shipment'=>$shipment['id']],$proof,$reason,$actor,'fan-transfer:'.bin2hex(random_bytes(16)));
  if($previous){
   query($db,'UPDATE decisions SET active=0 WHERE id=?',[$previous['id']]);
   query($db,'UPDATE decisions SET supersedes_decision_id=? WHERE id=?',[$previous['id'],$id]);
  }
  audit($db,'fan_transfer_date',['awb'=>$shipment['awb'],'proof'=>$proof],$actor,$id);
 }

 private static function reconcile(\PDO $db,string $awb,array $rows,array $actor,?string $chosenDate=null,?int $evidenceId=null):void {
  $shipment=one($db,'SELECT * FROM fan_shipments WHERE awb=? FOR UPDATE',[$awb]);
  if(!$rows){
   if($chosenDate!==null)throw new Problem('Raportul financiar nu mai este disponibil.',409);
   return;
  }
  $wait=!$shipment?'waiting_awb':($shipment['delivery_status']==='returned'?'returned':($shipment['cash_due_cents']===null?'waiting_amount':null));
  if($wait){
   if($chosenDate!==null)throw new Problem('AWB-ul nu permite confirmarea datei.');
   foreach($rows as $row)self::status($db,$row,$wait);
   return;
  }

  $amount=(int)$shipment['cash_due_cents'];
  $valid=array_values(array_filter($rows,fn($row)=>(int)$row['amount_cents']===$amount));
  $lastValid=$valid?(int)end($valid)['id']:0;
  $dates=array_values(array_unique(array_column($valid,'transfer_date')));
  sort($dates);
  $previous=one($db,"SELECT source_hashes_json FROM decisions WHERE shipment_id=? AND action='fan_transfer_date' AND active=1 ORDER BY id DESC LIMIT 1",[$shipment['id']]);
  $proof=$previous?json_decode($previous['source_hashes_json'],true):null;
  $confirmed=($proof['amount_cents']??null)===$amount&&in_array($proof['date']??null,$dates,true)?$proof['date']:null;
  $conflict=count($dates)>1&&(!$confirmed||array_diff($dates,$proof['reviewed_dates']??[])!==[]);
  $hasMismatch=(int)end($rows)['amount_cents']!==$amount;
  if($chosenDate!==null){
   if(!$conflict||$hasMismatch||!array_filter($valid,fn($row)=>(int)$row['id']===$evidenceId&&$row['transfer_date']===$chosenDate)){
    throw new Problem('Datele de verificat s-au schimbat. Reîncarcă pagina.',409);
   }
   $confirmed=$chosenDate;
   $conflict=false;
   self::remember($db,$shipment,['amount_cents'=>$amount,'date'=>$confirmed,'reviewed_dates'=>$dates,'evidence_id'=>$evidenceId,'evidence_ids'=>array_column($valid,'id')],$actor,'Data viramentului FAN a fost aleasă de operator dintre rapoartele financiare.');
  }elseif(!$confirmed&&!$hasMismatch&&count($dates)===1){
   $confirmed=$dates[0];
   self::remember($db,$shipment,['amount_cents'=>$amount,'date'=>$confirmed,'reviewed_dates'=>$dates,'evidence_ids'=>array_column($valid,'id')],$actor,'Data viramentului provine din raportul financiar FAN cu sumă egală rambursului.');
  }
  foreach($rows as $row){
   if((int)$row['amount_cents']!==$amount)$state=(int)$row['id']<$lastValid?'superseded':'amount_mismatch';
   elseif($conflict)$state='date_conflict';
   elseif($confirmed===$row['transfer_date'])$state='confirmed';
   else $state='superseded';
   self::status($db,$row,$state);
  }

  // The operational import remains in source_versions. Project the effective transfer date onto the shipment used by reports.
  $date=$confirmed;
  if($date===null&&!$hasMismatch){
   $raw=one($db,"SELECT source_json FROM source_versions WHERE source_type='fan' AND source_key=? AND revision=?",[$awb,$shipment['revision']]);
   if($raw)$date=json_decode($raw['source_json'],true)['transfer_date']??null;
   elseif(!$previous)$date=$shipment['transfer_date'];
  }
  if($shipment['transfer_date']!==$date)query($db,'UPDATE fan_shipments SET transfer_date=? WHERE id=?',[$date,$shipment['id']]);
 }

 static function cases(\PDO $db):array {
  $rows=query($db,'SELECT a.operation_number AS awb, a.review_status, s.cash_due_cents, s.transfer_date, s.delivery_status
   FROM provider_adjustments a LEFT JOIN fan_shipments s ON s.awb=a.operation_number
   WHERE a.adjustment_type=? AND a.review_status IN (\'waiting_awb\',\'waiting_amount\',\'amount_mismatch\',\'returned\',\'date_conflict\')
   GROUP BY a.operation_number,a.review_status,s.cash_due_cents,s.transfer_date,s.delivery_status
   ORDER BY a.operation_number,a.review_status',[self::TYPE])->fetchAll();
  $cases=[];
  $evidence=$rows?self::evidenceByAwb($db):[];
  foreach($rows as $row){
   $awb=$row['awb'];
   if(!isset($cases[$awb]))$cases[$awb]=array_diff_key($row,['review_status'=>true])+['reasons'=>[],'evidence'=>$evidence[$awb]??[]];
   $cases[$awb]['reasons'][]=$row['review_status'];
  }
  return array_values($cases);
 }

 static function chooseDate(\PDO $db,array $input,array $actor):array {
  permit($actor,'operate');
  $awb=Sources::id((string)($input['awb']??''),false,true);
  $date=$input['date']??null;
  $evidence=filter_var($input['evidence']??null,FILTER_VALIDATE_INT);
  if(!$awb||!is_string($date)||!validDate($date)||!$evidence)throw new Problem('Selectează o dată dintr-un raport financiar FAN.');
  $lock='rebuild_import_'.config()['database'];
  if((int)query($db,'SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1)throw new Problem('Un import este deja în curs.',409);
  try{
   return transaction($db,function()use($db,$awb,$date,$evidence,$actor){
    self::reconcile($db,$awb,self::evidenceByAwb($db,$awb)[$awb]??[],$actor,$date,$evidence);
    return ['saved'=>true,'automatic'=>Matching::apply($db,$actor)];
   });
  }finally{query($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
