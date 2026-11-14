<?php
declare(strict_types=1);
namespace Plati;
if(!defined('PLATI_ENTRY')&&PHP_SAPI!=='cli'){http_response_code(404);exit;}

final class Store {
 const TABLE=['omc'=>'invoices','site'=>'site_orders','fan'=>'fan_shipments','netopia'=>'netopia_payments'];
 const KEY=['omc'=>'invoice_number','site'=>'order_number','fan'=>'awb','netopia'=>'canonical_external_id'];
 static function importRow(\PDO $db,int $import,string $sheet,int $row,string $key,string $hash,string $result='stored',array $issues=[]):int{query($db,'INSERT INTO import_rows(import_id,sheet_name,source_row_number,source_key,content_hash,result,issue_json) VALUES(?,?,?,?,?,?,?)',[$import,mb_substr($sheet,0,100),$row,$key,$hash,$result,json($issues)]);return (int)$db->lastInsertId();}
 static function upsert(\PDO $db,string $kind,array $record,int $rowId,?array $actor=null,?string $forcedScope=null):array {
  $key=(string)$record['id'];$source=array_filter($record,fn($k)=>!str_starts_with((string)$k,'_'),ARRAY_FILTER_USE_KEY);unset($source['id']);$table=self::TABLE[$kind];$keyField=self::KEY[$kind];$old=one($db,'SELECT * FROM '.$table.' WHERE '.$keyField.'=?',[$key]);
  if($kind==='site'&&SiteFeed::status($db,$record,$old,$actor))return ['id'=>(int)($old['id']??0),'result'=>'ignored'];
  if($kind==='netopia'){$identifier=one($db,'SELECT * FROM payment_identifiers WHERE provider=\'NETOPIA\' AND external_id=?',[$key]);if($identifier){$old=one($db,'SELECT * FROM netopia_payments WHERE id=?',[$identifier['payment_id']]);if($identifier['status']!=='approved')return ['id'=>(int)$old['id'],'result'=>'pending_alias'];$key=$old['canonical_external_id'];}
   elseif(!$old&&preg_match('~^([0-9]+)(?:/[0-9]+|(?:_[0-9]+)+)$~D',$key,$m)){$base=one($db,'SELECT p.* FROM payment_identifiers a JOIN netopia_payments p ON p.id=a.payment_id WHERE a.provider=\'NETOPIA\' AND a.external_id=?',[$m[1]]);if($base){$same=(int)$base['gross_amount_cents']===$source['gross_amount_cents']&&$base['operation_date']===$source['operation_date']&&$base['transfer_date']===$source['transfer_date']&&$base['operation_number']===$source['operation_number']&&$base['normalized_name']===nameKey($source['client']);query($db,'INSERT INTO payment_identifiers(provider,external_id,payment_id,status,proof_json) VALUES(?,?,?,?,?)',['NETOPIA',$key,$base['id'],'pending',json(['candidate'=>$record,'same_financial_identity'=>$same,'import_row'=>$rowId])]);audit($db,'payment_identifier_pending',['external_id'=>$key,'existing'=>$base['canonical_external_id'],'same_financial_identity'=>$same],$actor);return ['id'=>(int)$base['id'],'result'=>'pending_alias'];}}
  }
  $hash=hashSource([$keyField=>$key]+$source);if($old&&hash_equals($old['source_hash'],$hash))return ['id'=>(int)$old['id'],'result'=>'unchanged'];
  $fields=[$keyField=>$key]+$source;
  if($kind==='omc'){$fields['normalized_name']=nameKey($source['client']);$fields['name_hash']=hash('sha256',$fields['normalized_name']);$internal=$fields['normalized_name']===nameKey('SUSEANU MARIAN')||$fields['amount_cents']<=0;$marker=label($fields['comisionar'])==='SITE GOLDENDRINKS'||label($fields['workplace'])==='SITE';$ref=reference($fields['observation']);$known=$ref['kind']==='order'&&(one($db,'SELECT id FROM site_orders WHERE order_number=?',[$ref['id']])||one($db,'SELECT id FROM fan_shipments WHERE order_reference=? LIMIT 1',[$ref['id']]));$fields['scope']=$internal?'internal':($forcedScope??($marker||$known||($old['scope']??'')==='accepted'?'accepted':'outside'));$fields['scope_reason']=$internal?'internal':($forcedScope?'manual':($marker?'marker':($known?'reference':(($old['scope_reason']??'')==='manual'?'manual':($fields['scope']==='accepted'?'retained':'unclassified')))));}
  if($kind==='fan'){$fields['normalized_name']=nameKey($source['recipient']);$fields['name_hash']=hash('sha256',$fields['normalized_name']);$fields['order_reference']=preg_match('/^COMANDA\s*:?\s*([0-9]{1,30})\s*$/iu',$source['content'],$m)?$m[1]:null;$fields['delivery_status']=match(label($source['delivery_status_raw'])){'LIVRAT'=>'delivered','RETUR'=>'returned',default=>'pending'};$fields['service_type']=['TRANSPORT MARFA'=>'STANDARD','TRANSPORT MARFA-CONT COLECTOR'=>'CONT COLECTOR'][label($source['service_raw'])]??label($source['service_raw']);}
  if($kind==='site'&&SiteFeed::status($db,$record,$old,$actor))return ['id'=>(int)($old['id']??0),'result'=>'ignored'];
  if($kind==='netopia'){$fields['normalized_name']=nameKey($source['client']);$fields['name_hash']=hash('sha256',$fields['normalized_name']);}
  $fields['source_hash']=$hash;$fields['revision']=$old?(int)$old['revision']+1:1;$fields['import_row_id']=$rowId;
  if($old){$q='UPDATE '.$table.' SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($fields))).' WHERE id=?';query($db,$q,[...array_values($fields),$old['id']]);$id=(int)$old['id'];}else{query($db,'INSERT INTO '.$table.'('.implode(',',array_keys($fields)).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')',array_values($fields));$id=(int)$db->lastInsertId();}
  query($db,'INSERT INTO source_versions(source_type,source_key,revision,content_hash,source_json,import_row_id,recorded_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())',[$kind,$key,$fields['revision'],$hash,json([$keyField=>$key]+$source),$rowId]);
  if($kind==='netopia'&&!one($db,'SELECT payment_id FROM payment_identifiers WHERE provider=\'NETOPIA\' AND external_id=?',[$key]))query($db,'INSERT INTO payment_identifiers(provider,external_id,payment_id,status,proof_json) VALUES(?,?,?,\'approved\',?)',['NETOPIA',$key,$id,json(['native'=>true])]);
  if($old)Operations::changed($db,$kind,$old,$fields,$id,$actor);
  audit($db,'source_'.($old?'updated':'inserted'),['kind'=>$kind,'key'=>$key,'before_hash'=>$old['source_hash']??null,'after_hash'=>$hash],$actor);return ['id'=>$id,'result'=>$old?'updated':'inserted'];
 }
 static function applyFiles(\PDO $db,array $files,array $actor,bool $auto=true):array {
  foreach($files as $file)if($file['parsed']['errors'])throw new Problem('Fișierul '.$file['parsed']['filename'].' conține rânduri invalide. Nicio modificare aplicată.');
  $lock='rebuild_import_'.config()['database'];if((int)query($db,'SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1)throw new Problem('Un alt import este în curs.',409);
  try{return transaction($db,function()use($db,$files,$actor,$auto){$results=[];$fanAwbs=[];foreach($files as $file){$p=$file['parsed'];$old=one($db,'SELECT * FROM import_files WHERE source=? AND file_sha256=?',[$p['kind'],$p['sha256']]);if($old&&$old['status']==='completed'){$results[]=['filename'=>$p['filename'],'repeated_file'=>true,'summary'=>json_decode($old['summary_json'],true)];continue;}$summary=$p['summary']+['inserted'=>0,'updated'=>0,'unchanged'=>0,'pending_alias'=>0,'ignored'=>0];query($db,'INSERT INTO import_files(source,filename,file_sha256,private_archive_reference,contract_version,status,summary_json,actor_id,created_at) VALUES(?,?,?,?,?,\'applying\',?,?,UTC_TIMESTAMP())',[$p['kind'],$p['filename'],$p['sha256'],$file['archive'],'rebuild-1',json($summary),$actor['id']]);$import=(int)$db->lastInsertId();foreach($p['records'] as $r){$rid=self::importRow($db,$import,$r['_sheet'],$r['_row'],$r['id'],hashSource($r));$saved=self::upsert($db,$p['kind'],$r,$rid,$actor);$summary[$saved['result']]++;
    if($p['kind']==='fan')$fanAwbs[$r['id']]=true;
   }
   foreach($p['adjustments'] as $a){
    if($a['type']===FanTransfers::TYPE){
     FanTransfers::record($db,$import,$a);
     $fanAwbs[$a['awb']]=true;
     continue;
    }
    $rid=self::importRow($db,$import,'CSV',$a['row'],'provider-adjustment',hashSource($a),'separate');query($db,'INSERT INTO provider_adjustments(import_row_id,adjustment_type,operation_number,source_details_json,review_status) VALUES(?,?,?,?,?)',[$rid,$a['type'],$a['values']['#']??null,json($a['values']),$a['type']==='bank_transfer_fee'?'recorded':'review']);}query($db,'UPDATE import_files SET status=\'completed\',summary_json=? WHERE id=?',[json($summary),$import]);audit($db,'import_completed',['import'=>$import,'summary'=>$summary],$actor);$results[]=['id'=>$import,'filename'=>$p['filename'],'summary'=>$summary];}
   if($fanAwbs)FanTransfers::reconcileImported($db,$fanAwbs,$actor);
   // A later SITE/FAN member may provide the explicit reference missing in OMC.
   foreach(query($db,"SELECT id,observation FROM invoices WHERE scope='outside'")->fetchAll() as $i){$ref=reference($i['observation']);if($ref['kind']==='order'&&(one($db,'SELECT id FROM site_orders WHERE order_number=?',[$ref['id']])||one($db,'SELECT id FROM fan_shipments WHERE order_reference=? LIMIT 1',[$ref['id']])))query($db,"UPDATE invoices SET scope='accepted',scope_reason='reference' WHERE id=?",[$i['id']]);}
   $automatic=$auto?Matching::apply($db,$actor):[];return ['files'=>$results,'automatic'=>$automatic];});}finally{query($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
 }
}
