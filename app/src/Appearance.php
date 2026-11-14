<?php
declare(strict_types=1);
namespace Plati;
if(!defined('PLATI_ENTRY')&&PHP_SAPI!=='cli'){http_response_code(404);exit;}
final class Appearance {
 const THEMES=['mineral-blue','warm-gold','sage-green'];
 static function get(\PDO $db,array $actor):string{$row=one($db,"SELECT details_json FROM audit_events WHERE actor_id=? AND event_type='appearance_selected' ORDER BY id DESC LIMIT 1",[$actor['id']]);$theme=$row?(json_decode($row['details_json'],true)['theme']??''):'';return in_array($theme,self::THEMES,true)?$theme:'warm-gold';}
 static function save(\PDO $db,array $actor,array $input):array{$theme=$input['theme']??'';if(!is_string($theme)||!in_array($theme,self::THEMES,true))throw new Problem('Paletă invalidă.');audit($db,'appearance_selected',['theme'=>$theme],$actor);return ['theme'=>$theme];}
}
