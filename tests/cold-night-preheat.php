<?php
declare(strict_types=1);
require_once __DIR__.'/../Center/rules.php';
function ok(bool $result,string $label): void {if(!$result)throw new RuntimeException($label);echo "PASS ".$label.PHP_EOL;}
$week=array_fill(0,7,[[0,17.0],[420,21.0],[1320,17.0]]);
$now=(new DateTimeImmutable('2026-11-20 08:00:00',new DateTimeZone('Europe/Berlin')))->getTimestamp();
$base=(new DateTimeImmutable('2026-11-20 00:00:00',new DateTimeZone('Europe/Berlin')))->getTimestamp();
$samples=[[$base+300,-4.0],[$base+2100,-3.0],[$base+3900,-3.0],[$base+5700,-2.0],[$base+7500,-3.0]];
$run=static fn($name,$present=true,$season='autumn',$temp=17.5,$readings=null)=>scColdNightPreheatPlan($week,$readings??$samples,4,$now,$name,$temp,$present,$season);
$living=$run('Wohnzimmer');
ok($living['minutes']===90 && $living['plan'][4][1][0]===330,'Wohnzimmer: 90 Min. earlier');
ok($run('Bad')['minutes']===60,'Bad: 60 Min. earlier');
ok($run('Flur')['minutes']===45,'Flur: 45 Min. earlier');
ok($run('Wohnzimmer',false)['minutes']===0,'No preheat while away');
ok($run('Wohnzimmer',true,'summer')['minutes']===0,'No summer preheat');
ok($run('Wohnzimmer',true,'winter',21.0)['minutes']===0,'No preheat when already warm');
ok($run('Schlafzimmer')['minutes']===0,'Other rooms unchanged');
ok($run('Wohnzimmer',true,'autumn',17.5,[[$base+300,-4.0]])['minutes']===0,'Insufficient weather samples fail closed');
ok($run('Wohnzimmer',true,'autumn',17.5,[[$base+300,5],[$base+2100,5],[$base+3900,5],[$base+5700,5]])['minutes']===0,'Warm night no preheat');
ok($week[4][1][0]===420,'Stored input plan unchanged');
echo "All cold-night preheat checks passed.".PHP_EOL;
