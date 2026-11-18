<?php
declare(strict_types=1);
define('PLATI_ENTRY',true);require __DIR__.'/../src/load.php';
use Plati\Problem;use Plati\Sources;use Plati\Store;use Plati\Review;use Plati\Reports;use Plati\Backups;
use function Plati\{db,query,one,json,user,permit,csrf,transaction,audit,decision,validDate};
header('Content-Type: application/json; charset=utf-8');
try{
 $action=(string)($_GET['action']??'state');$db=db();$post=$_SERVER['REQUEST_METHOD']==='POST';$input=[];if($post){csrf();$type=$_SERVER['CONTENT_TYPE']??'';if(str_contains($type,'application/json')){if((int)($_SERVER['CONTENT_LENGTH']??0)>2*1024*1024)throw new Problem('Cerere prea mare.',413);$input=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);if(!is_array($input))throw new Problem('Cerere JSON invalidă.');}else $input=$_POST;}
 if($action==='login'){if(!$post)throw new Problem('Folosește POST.',405);$limitPath=Plati\storage().'/login-attempts.json';$handle=fopen($limitPath,'c+');flock($handle,LOCK_EX);$attempts=json_decode(stream_get_contents($handle)?:'{}',true)?:[];$ip=$_SERVER['REMOTE_ADDR']??'local';$list=array_values(array_filter($attempts[$ip]??[],fn($t)=>$t>time()-300));if(count($list)>=10){flock($handle,LOCK_UN);fclose($handle);throw new Problem('Prea multe încercări. Reîncearcă peste câteva minute.',429);}$u=one($db,'SELECT * FROM users WHERE username=?',[(string)($input['username']??'')]);$ok=$u&&$u['enabled']&&password_verify((string)($input['password']??''),$u['password_hash']);if(!$ok)$list[]=time();$attempts[$ip]=$ok?[]:$list;ftruncate($handle,0);rewind($handle);fwrite($handle,json($attempts));flock($handle,LOCK_UN);fclose($handle);if(!$ok)throw new Problem('Utilizator sau parolă incorectă.',401);session_regenerate_id(true);$_SESSION['user']=(int)$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(24));echo json(['ok'=>true,'data'=>['signed_in'=>true]]);exit;}
 $actor=user($db);if(!$post){$result=match($action){
  'state'=>['account'=>$actor,'appearance'=>Plati\Appearance::get($db,$actor),'preview'=>Plati\preview(),'stats'=>['invoices'=>(int)query($db,'SELECT COUNT(*) FROM invoices')->fetchColumn(),'payments'=>(int)query($db,'SELECT COUNT(*) FROM netopia_payments')->fetchColumn(),'shipments'=>(int)query($db,'SELECT COUNT(*) FROM fan_shipments')->fetchColumn(),'orders'=>(int)query($db,'SELECT COUNT(*) FROM site_orders')->fetchColumn()],'attention'=>Reports::attention($db),'batch'=>Review::batch($db,$actor)],
  'report'=>Reports::report($db,$_GET),'netopia'=>Reports::netopia($db,$_GET),'orders'=>Reports::orders($db,$_GET),'returns'=>Reports::returns($db,$_GET),'drivers'=>query($db,'SELECT * FROM gpd_drivers ORDER BY name')->fetchAll(),'candidates'=>Review::candidates($db,(string)($_GET['kind']??'netopia'),(int)($_GET['resource']??0),(string)($_GET['q']??''),(string)($_GET['days']??'all')),'batch'=>Review::batch($db,$actor),
  'history'=>Plati\Operations::history($db,(string)($_GET['kind']??''),(int)($_GET['id']??0)),'legacy_batches'=>Plati\Operations::legacyBatches($db,$actor),'cash_cases'=>Plati\Operations::cashCases($db),
  'fan_transfer_cases'=>Plati\FanTransfers::cases($db),
  'imports'=>query($db,'SELECT id,source,filename,status,summary_json,created_at FROM import_files ORDER BY id DESC LIMIT 50')->fetchAll(),
  'resources'=>(function()use($db){$kind=$_GET['kind']??'netopia';if(!in_array($kind,['netopia','fan'],true))throw new Problem('Sursă invalidă.');$table=$kind==='fan'?'fan_shipments':'netopia_payments';$link=$kind==='fan'?'shipment_assignments':Plati\Topups::links();$column=$kind==='fan'?'shipment_id':'payment_id';$name=$kind==='fan'?'recipient':'client';$key=$kind==='fan'?'awb':'canonical_external_id';$money=$kind==='fan'?'cash_due_cents':'gross_amount_cents';$where='a.'.$column.' IS NULL';$args=[];if(($_GET['q']??'')!==''){$where.=' AND (LOWER(r.'.$name.') LIKE LOWER(?) OR LOWER(r.'.$key.') LIKE LOWER(?))';$args=array_fill(0,2,'%'.$_GET['q'].'%');}if(($_GET['amount']??'')!==''){$where.=' AND ABS(r.'.$money.'-?)<=1';$args[]=(int)$_GET['amount'];}return ['rows'=>query($db,'SELECT r.* FROM '.$table.' r LEFT JOIN '.$link.' a ON a.'.$column.'=r.id WHERE '.$where.' ORDER BY r.id DESC LIMIT 100',$args)->fetchAll()];})(),
  'invoice'=>(function()use($db){$i=one($db,"SELECT id,invoice_number,invoice_date,client,amount_cents,balance_cents,comisionar,workplace,observation,scope,scope_reason,source_hash FROM invoices WHERE id=? AND scope<>'internal'",[(int)($_GET['id']??0)]);if(!$i)throw new Problem('Factura nu există în lista accesibilă.',404);return ['invoice'=>$i,'matching_issues'=>Plati\Matching::plan($db)['issues'][$i['id']]??[],'alerts'=>Plati\Operations::alerts($db,(int)$i['id']),'versions'=>query($db,"SELECT source_type,revision,content_hash,recorded_at FROM source_versions WHERE source_key=? AND source_type IN('omc','legacy:invoice') ORDER BY recorded_at DESC LIMIT 50",[$i['invoice_number']])->fetchAll(),'payment_allocations'=>Plati\Allocations::rows($db,(int)$i['id']),'supplemental_payments'=>Plati\Topups::rows($db,(int)$i['id']),'decisions'=>query($db,'SELECT d.id,d.action,d.reason,d.accepted_difference_cents,d.created_at,COALESCE(u.display_name,d.legacy_actor) AS actor FROM decisions d LEFT JOIN users u ON u.id=d.actor_id WHERE d.invoice_id=? ORDER BY d.id DESC LIMIT 50',[$i['id']])->fetchAll()];})(),
  'settings'=>(function()use($db,$actor){if($actor['role']!=='admin')return ['appearance'=>Plati\Appearance::get($db,$actor)];return ['appearance'=>Plati\Appearance::get($db,$actor),'health'=>Plati\Deployment::health($db,$actor),'users'=>query($db,'SELECT id,username,display_name,role,enabled FROM users ORDER BY username')->fetchAll(),'backups'=>Backups::listing(),'audit'=>query($db,'SELECT event_type,created_at FROM audit_events ORDER BY id DESC LIMIT 30')->fetchAll()];})(),
  'export'=>(function()use($db){$text=($_GET['format']??'excel')==='text';$rows=Reports::exportRows($db,$_GET,!$text);header('Content-Type: '.($text?'text/plain; charset=utf-8':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));header('Content-Disposition: attachment; filename="'.Reports::exportFilename($_GET,$text).'"');echo $text?Reports::textExport($rows):Reports::xlsx($rows);exit;})(),
  default=>throw new Problem('Acțiune necunoscută.',404)};
 }else{
  permit($actor,in_array($action,['logout','appearance_save'],true)?'read':(in_array($action,['user_save','driver_save','backup_create'],true)?'admin':'operate'));
  $result=match($action){
   'logout'=>(function(){unset($_SESSION['user']);session_regenerate_id(true);return ['signed_out'=>true];})(),
   'appearance_save'=>Plati\Appearance::save($db,$actor,$input),
   'fetch_site'=>Plati\SiteFeed::import($db,$actor),
   'fan_transfer_date'=>Plati\FanTransfers::chooseDate($db,$input,$actor),
   'import_files'=>(function()use($db,$actor){
    $uploads=$_FILES['files']??[];
    $kinds=$_POST['kinds']??null;
    $count=filter_var($_POST['file_count']??null,FILTER_VALIDATE_INT);
    if(!is_array($kinds)||!array_is_list($kinds)||!is_array($uploads['name']??null)||!array_is_list($uploads['name'])||
       $count<1||$count>20||count($uploads['name'])!==$count||count($kinds)!==$count){
     throw new Problem('Lot incomplet sau invalid. Poți importa maximum 20 de fișiere odată.');
    }
    $files=[];
    $validation=[];
    $hasErrors=false;
    foreach($uploads['name'] as $index=>$original){
     $filename=basename($original);
     $kind=$kinds[$index];
     $tmp=$uploads['tmp_name'][$index]??'';
     if(!is_string($kind)||!isset(Sources::HEADERS[$kind]))throw new Problem('Sursă necunoscută.');
     if(($uploads['error'][$index]??null)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)){
      throw new Problem('Încărcare incompletă: '.$filename);
     }
     $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
     if(!in_array($ext,['csv','xlsx'],true))throw new Problem('Format neacceptat: '.$filename);
     $prior=one($db,"SELECT summary_json FROM import_files WHERE source=? AND file_sha256=? AND status='completed'",[$kind,hash_file('sha256',$tmp)]);
     $trusted=$kind==='netopia'&&($_POST['combined']??'')==='1';
     if($prior)$trusted=$trusted||(bool)(json_decode($prior['summary_json'],true)['combined_confirmed']??false);
     try{
      $parsed=Sources::read($tmp,$filename,$kind,$trusted);
      $errors=$parsed['errors'];
      $files[]=['tmp'=>$tmp,'ext'=>$ext,'parsed'=>$parsed];
     }catch(Problem $e){
      $errors=[['error'=>$e->getMessage()]];
     }
     $validation[]=['filename'=>$filename,'errors'=>$errors];
     $hasErrors=$hasErrors||$errors!==[];
    }
    if($hasErrors)return ['invalid'=>true,'files'=>$validation];
    $dir=Plati\storage().'/uploads';
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new Problem('Arhiva de import nu este disponibilă.');
    $archives=[];
    $batch=[];
    try{
     foreach($files as $file){
      $archive=$dir.'/'.bin2hex(random_bytes(16)).'.'.$file['ext'];
      if(!move_uploaded_file($file['tmp'],$archive))throw new Problem('Fișierul nu a putut fi salvat: '.$file['parsed']['filename']);
      $archives[]=$archive;
      $batch[]=['archive'=>$archive,'parsed'=>$file['parsed']];
     }
     $result=Store::applyFiles($db,$batch,$actor);
    }catch(\Throwable $e){
     foreach($archives as $archive)unlink($archive);
     throw $e;
    }
    foreach($result['files'] as $index=>$file){
     if(!empty($file['repeated_file']))unlink($archives[$index]);
    }
    return $result;
   })(),

   'preview_import'=>(function()use($db,$actor){$kind=(string)($_POST['kind']??'');$combined=($_POST['combined']??'')==='1';if(!isset($_FILES['files']))throw new Problem('Fișiere lipsă. Verifică și limita PHP post_max_size.');$files=$_FILES['files'];if(!is_array($files['name']))throw new Problem('Format de încărcare invalid.');if(count($files['name'])>20)throw new Problem('Maximum 20 fișiere per lot.');$out=[];$dir=Plati\storage().'/uploads';if(!is_dir($dir))mkdir($dir,0700,true);foreach($files['name'] as $k=>$name){if($files['error'][$k]!==UPLOAD_ERR_OK||!is_uploaded_file($files['tmp_name'][$k]))throw new Problem('Încărcare incompletă: '.basename($name));$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,['csv','xlsx'],true))throw new Problem('Format neacceptat.');$token=bin2hex(random_bytes(16));$archive=$dir.'/'.$token.'.'.$ext;if(!move_uploaded_file($files['tmp_name'][$k],$archive))throw new Problem('Fișierul nu a putut fi salvat.');$prior=one($db,'SELECT summary_json FROM import_files WHERE source=? AND file_sha256=? AND status=\'completed\'',[$kind,hash_file('sha256',$archive)]);$trusted=$combined||($prior&&json_decode($prior['summary_json'],true)['combined_confirmed']);$p=Sources::read($archive,$name,$kind,(bool)$trusted);$meta=['owner'=>(int)$actor['id'],'created'=>time(),'path'=>$archive,'kind'=>$kind,'filename'=>basename($name),'combined'=>(bool)$trusted,'sha256'=>$p['sha256']];file_put_contents($dir.'/'.$token.'.json',json($meta),LOCK_EX);$out[]=['token'=>$token,'filename'=>basename($name),'summary'=>$p['summary'],'errors'=>$p['errors']];}return ['files'=>$out];})(),
   'commit_import'=>(function()use($db,$actor,$input){$tokens=$input['tokens']??[];if(!is_array($tokens)||!count($tokens)||count($tokens)>20)throw new Problem('Lot invalid.');$files=[];foreach($tokens as $token){if(!is_string($token)||!preg_match('/^[a-f0-9]{32}$/D',$token))throw new Problem('Token invalid.');$path=Plati\storage().'/uploads/'.$token.'.json';if(!is_file($path))throw new Problem('Previzualizarea nu mai există.');$m=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if($m['owner']!==(int)$actor['id']||$m['created']<time()-86400||!hash_equals($m['sha256'],hash_file('sha256',$m['path'])))throw new Problem('Fișierul sau sesiunea de import s-a schimbat.');$files[]=['archive'=>$m['path'],'parsed'=>Sources::read($m['path'],$m['filename'],$m['kind'],$m['combined'])];}return Store::applyFiles($db,$files,$actor);})(),
   'acknowledge_change'=>Plati\Operations::acknowledge($db,$input,$actor),'correct_decision'=>Plati\Operations::correct($db,$input,$actor),'legacy_resolve'=>Plati\Operations::legacyResolve($db,$input,$actor),'cash_confirm'=>Plati\Operations::cashApply($db,$actor,$input),
   'save'=>Review::save($db,$input,$actor),'stage'=>Review::stage($db,$input,$actor),'remove_item'=>Review::removeItem($db,$input,$actor),'commit_batch'=>Review::commitBatch($db,$input,$actor),'approve_alias'=>(function()use($db,$input,$actor){$reason=Plati\Operations::reason($input);$result=Review::approveAlias($db,$input,$actor);audit($db,'alias_review_reason',['alias'=>$input['external_id'],'reason'=>$reason],$actor);return $result;})(),
   'promote_invoice'=>transaction($db,function()use($db,$input,$actor){$i=one($db,"SELECT * FROM invoices WHERE id=? AND scope<>'internal' FOR UPDATE",[(int)$input['id']]);if(!$i||!hash_equals($i['source_hash'],(string)$input['source_hash']))throw new Problem('Factura s-a schimbat.',409);$reason=trim((string)$input['reason']);if(mb_strlen($reason)<10)throw new Problem('Motiv obligatoriu, minimum 10 caractere.');$did=decision($db,'classify_site',['invoice'=>$i['id']],['source_hash'=>$i['source_hash']],$reason,$actor,'classify:'.bin2hex(random_bytes(16)));query($db,"UPDATE invoices SET scope='accepted',scope_reason='manual' WHERE id=?",[$i['id']]);audit($db,'invoice_accepted_manually',['invoice'=>$i['invoice_number']],$actor,$did);return ['saved'=>true];}),
   'driver_save'=>transaction($db,function()use($db,$input,$actor){$name=trim((string)($input['name']??''));if(mb_strlen($name)<2||mb_strlen($name)>190)throw new Problem('Nume șofer invalid.');$id=(int)($input['id']??0);if($id)query($db,'UPDATE gpd_drivers SET name=?,enabled=? WHERE id=?',[$name,($input['enabled']??true)?1:0,$id]);else{$existing=one($db,'SELECT id FROM gpd_drivers WHERE name=? FOR UPDATE',[$name]);if($existing)$id=(int)$existing['id'];else{query($db,'INSERT INTO gpd_drivers(name,enabled) VALUES(?,1)',[$name]);$id=(int)$db->lastInsertId();}}audit($db,'driver_saved',['id'=>$id,'name'=>$name],$actor);return ['saved'=>true];}),
   'delivery_save'=>transaction($db,function()use($db,$input,$actor){$i=one($db,"SELECT * FROM invoices WHERE id=? AND scope<>'internal' FOR UPDATE",[(int)$input['invoice']]);if(!$i||one($db,'SELECT shipment_id FROM shipment_assignments WHERE invoice_id=?',[$i['id']]))throw new Problem('Factura lipsește sau are tracking FAN.');$o=one($db,'SELECT o.* FROM invoice_orders l JOIN site_orders o ON o.id=l.order_id WHERE l.invoice_id=?',[$i['id']]);if($o&&$o['awb'])throw new Problem('Comanda are AWB.');$driver=one($db,'SELECT * FROM gpd_drivers WHERE id=? AND enabled=1',[(int)$input['driver']]);if(!$driver||!validDate($input['date'])||$input['date']>date('Y-m-d'))throw new Problem('Șofer sau dată de livrare invalidă.');$did=decision($db,'gpd_delivery',['invoice'=>$i['id'],'order'=>$o['id']??null],['driver'=>$driver['id'],'date'=>$input['date']],'Livrare GPD confirmată manual.',$actor,'delivery:'.bin2hex(random_bytes(16)));$old=one($db,'SELECT id FROM gpd_deliveries WHERE invoice_id=? OR (invoice_id IS NULL AND order_id=?)',[$i['id'],$o['id']??null]);if($old)query($db,'UPDATE gpd_deliveries SET invoice_id=?,driver_id=?,delivery_date=?,decision_id=? WHERE id=?',[$i['id'],$driver['id'],$input['date'],$did,$old['id']]);else query($db,'INSERT INTO gpd_deliveries(invoice_id,order_id,driver_id,delivery_date,decision_id) VALUES(?,?,?,?,?)',[$i['id'],$o['id']??null,$driver['id'],$input['date'],$did]);return ['saved'=>true];}),
   'refund_save'=>Plati\Operations::refund($db,$input,$actor),
   'backup_create'=>Backups::create($db,$actor),
   'user_save'=>transaction($db,function()use($db,$input,$actor){$id=(int)($input['id']??0);query($db,"SELECT id FROM users WHERE role='admin' AND enabled=1 ORDER BY id FOR UPDATE");$name=trim((string)$input['username']);$display=trim((string)$input['display_name']);$role=$input['role']??'';$enabled=($input['enabled']??true)?1:0;$pass=(string)($input['password']??'');if(!preg_match('/^[\pL0-9_.-]{3,100}$/uD',$name)||mb_strlen($display)<2||!in_array($role,['admin','operator','viewer'],true))throw new Problem('Date cont invalide.');$old=$id?one($db,'SELECT * FROM users WHERE id=? FOR UPDATE',[$id]):null;if($id&&!$old)throw new Problem('Contul nu există.');if($old&&$old['role']==='admin'&&($role!=='admin'||!$enabled)&&(int)query($db,"SELECT COUNT(*) FROM users WHERE role='admin' AND enabled=1")->fetchColumn()<=1)throw new Problem('Trebuie păstrat cel puțin un administrator activ.');if((!$id||$pass!=='')&&strlen($pass)<12)throw new Problem('Parola trebuie să aibă minimum 12 caractere.');if($id){query($db,'UPDATE users SET username=?,display_name=?,role=?,enabled=? WHERE id=?',[$name,$display,$role,$enabled,$id]);if($pass!=='')query($db,'UPDATE users SET password_hash=? WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$id]);}else query($db,'INSERT INTO users(username,password_hash,display_name,role,enabled) VALUES(?,?,?,?,?)',[$name,password_hash($pass,PASSWORD_DEFAULT),$display,$role,$enabled]);audit($db,'user_saved',['id'=>$id,'username'=>$name,'role'=>$role],$actor);return ['saved'=>true];}),
   default=>throw new Problem('Acțiune necunoscută.',404)};
 }
 echo json(['ok'=>true,'data'=>$result]);
}catch(\Throwable $e){$status=$e instanceof Problem?$e->status:500;http_response_code($status);if(!$e instanceof Problem)error_log($e->getMessage());echo json(['ok'=>false,'error'=>$e instanceof Problem?$e->getMessage():'Operația nu a putut fi finalizată. Datele nu au fost aplicate parțial.','status'=>$status]);}
