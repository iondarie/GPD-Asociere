<?php
declare(strict_types=1);
if(!defined('PLATI_ENTRY')&&PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/Core.php';
foreach(['Allocations','ReturnReview','Topups','Appearance','Sources','FanTransfers','SiteFeed','Store','Migration','Matching','Review','Reports','Backups','Operations','Deployment'] as $module)require_once __DIR__.'/'.$module.'.php';
\Plati\bootstrap();
