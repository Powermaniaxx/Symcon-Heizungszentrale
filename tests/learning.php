<?php
require_once dirname(__DIR__).'/Center/rules.php';
require_once dirname(__DIR__).'/Center/learning.php';
function check($condition,$message) {if (!$condition) throw new Exception($message);}
$start=1800057600; // UTC day boundary
$start=intdiv($start,86400)*86400;
function observeDay(array $s,int $day,float $actual=22.0,bool $manual=false): array {
    global $start;
    for($i=0;$i<=12;$i++) {
        $at=$start+$day*86400+36000+$i*600;
        $s=scLearningTick($s,'plan',$actual,21.0,$at,$at,!$manual,$manual);
    }
    return $s;
}
$s=[];
for($d=0;$d<6;$d++) $s=observeDay($s,$d);
check($s['offset']===0.0,'Six days must not change temperatures');
$s=observeDay($s,6);
check($s['offset']===-0.5,'Seven independent warm days permit half a degree');
$s=json_decode(json_encode($s),true); // persisted model survives restart
check(scLearningTick($s,'plan',22.0,21.0,$start+7*86400,$start+7*86400,false)['offset']===-0.5,'Restart retains bounded model');
check(scLearningTick($s,'edited',22.0,21.0,$start+7*86400,$start+7*86400,true)['offset']===0.0,'Editing template resets model');
$manual=[];
for($d=0;$d<10;$d++) $manual=observeDay($manual,$d,24.0,true);
check($manual['offset']===0.0&&empty($manual['days']),'Manual temperatures never become votes');
$one=observeDay([],0);$one=scLearningTick($one,'plan',24.0,21.0,$start+45000,$start+45000,false,true);
check(empty($one['days']),'Later manual interference removes same-day vote');
$repeat=[];for($i=0;$i<1000;$i++) $repeat=scLearningTick($repeat,'plan',22.0,21.0,$start,$start+600,true);
check(empty($repeat['days']),'Repeated reading is not new evidence');
$gap=scLearningTick([],'plan',22.0,21.0,$start,$start,true);
$gap=scLearningTick($gap,'plan',22.0,21.0,$start+7200,$start+7200,true);
check(empty($gap['days']),'A gap is not two continuous hours');
foreach([null,NAN,INF,40.0] as $bad) check(empty(scLearningTick([],'plan',$bad,21.0,$start,$start,true)['days']),'Invalid actual blocked');
for($d=7;$d<14;$d++) $s=observeDay($s,$d,20.0);
check($s['offset']===0.0,'Seven cold days restore baseline without positive correction');
$p=array_fill(0,7,[[0,17.0],[360,21.0],[1200,18.0]]);$copy=$p;
$reduced=scReducedPlan($p,0.5,true);
check($reduced[0]===[[0,17.0],[360,20.5],[1200,18.0]],'Learning preserves night values and times');
check(scReducedPlan($p,1.5)[0][1][1]===19.5,'Away is relative to seasonal baseline');
check(scReducedPlan(array_fill(0,7,[[0,4.5],[360,14.0]]),1.5)[0][0][1]===4.5,'Floor never raises an existing frost target');
check($p===$copy,'Templates remain unchanged');
$gate=scSeasonGate([],'autumn',$start);
for($t=$start+3600;$t<$start+7*86400;$t+=3600) {
    $gate=scSeasonGate($gate,'winter',$t);check($gate['season']==='autumn','Minimum season dwell');
}
$gate=scSeasonGate($gate,'winter',$start+7*86400);
check($gate['season']==='winter','Persistent candidate eventually switches');
$gate=scSeasonGate($gate,'summer',$start+20*86400);
check($gate['season']==='winter','Long downtime cannot confirm a candidate');
$gate=scSeasonGate($gate,'autumn',$start+20*86400+3600);
check($gate['candidate']==='autumn','Changed candidate restarts confirmation');
echo "OK: learning evidence, manual exclusion, restart, stale data, bounds, away and season gate.\n";
