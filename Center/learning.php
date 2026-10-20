<?php
// Local observations only. No device I/O; persisted data is bounded and versioned.
function scLearningTick(array $state, string $signature, ?float $actual, float $target, int $measured, int $now, bool $eligible, bool $manual=false): array {
    if (($state['signature']??'')!==$signature) $state=['signature'=>$signature];
    $offset=($state['offset']??0)===-0.5?-0.5:0.0;
    $state['offset']=$offset;
    $state['days']=array_filter($state['days']??[],static fn($day)=>is_int($day)&&$day>=intdiv($now,86400)-28&&$day<=intdiv($now,86400));
    $state['votes']=array_intersect_key($state['votes']??[],$state['days']);
    $day=intdiv($now,86400);
    if ($manual) {$state['blockedDay']=$day;unset($state['days'][$day],$state['votes'][$day]);}
    if (!$eligible||$actual===null||!is_finite($actual)||$actual<5||$actual>35||$measured>$now||$now-$measured>1200) {
        unset($state['since'],$state['last'],$state['target'],$state['kind']);
        return $state;
    }
    $kind=$actual>=$target+0.7?'warm':($actual<$target-0.5?'cold':'neutral');
    if (!isset($state['last'])||$measured<$state['last']||$measured-$state['last']>1200||($state['target']??null)!==$target||($state['kind']??'')!==$kind) $state['since']=$measured;
    $state['last']=$measured;$state['target']=$target;$state['kind']=$kind;
    // One vote per day, after two hours of uninterrupted matching telemetry.
    if ($kind==='neutral'||$measured-$state['since']<7200||($state['blockedDay']??-1)===$day||isset($state['days'][$day])) return $state;
    $state['days'][$day]=$day;$state['votes'][$day]=$kind;
    $state['votes']=array_intersect_key($state['votes']??[],$state['days']);
    $votes=array_slice($state['votes'],-7,null,true);
    if (count($votes)===7&&count(array_unique($votes))===1&&$now-($state['changed']??0)>=7*86400) {
        $state['offset']=$kind==='warm'?-0.5:0.0;$state['changed']=$now;
        $state['days']=[];$state['votes']=[];
    }
    return $state;
}

function scReducedPlan(array $plan,float $reduction,bool $heatingOnly=false): array {
    $plan=scValidatePlan($plan);
    foreach($plan as &$points) {
        $low=min(array_column($points,1));$high=max(array_column($points,1));
        foreach($points as &$point) if (!$heatingOnly||($high>$low&&$point[1]>$low&&$point[1]>=$high-0.5)) $point[1]=min($point[1],max($heatingOnly?$low:14.0,$point[1]-$reduction));
        unset($point);
    }
    unset($point,$points);return scValidatePlan($plan);
}

function scWinterAllowed(DateTimeImmutable $date): bool {
    $date=$date->setTimezone(new DateTimeZone('Europe/Berlin'));
    $month=(int)$date->format('n');$day=(int)$date->format('j');
    return !($month===9||$month===10||($month===11&&$day<15));
}

function scSeasonGate(array $state,string $candidate,int $now,bool $winterAllowed=true): array {
    if (!$winterAllowed&&$candidate==='winter') $candidate='autumn';
    // A saved winter state must not prolong a season explicitly excluded by date.
    if (!$winterAllowed&&($state['season']??null)==='winter') $state=[];
    $valid=['spring','summer','autumn','winter'];
    if (!in_array($state['season']??null,$valid,true)) return ['season'=>$candidate,'changed'=>$now];
    if ($now-($state['last']??$now)>7200) unset($state['candidate'],$state['since']);
    $state['last']=$now;
    if ($candidate===$state['season']) {unset($state['candidate'],$state['since']);return $state;}
    if (($state['candidate']??null)!==$candidate||$now<($state['since']??0)) {$state['candidate']=$candidate;$state['since']=$now;}
    if ($now-$state['since']>=2*86400&&$now-($state['changed']??$now)>=7*86400) return ['season'=>$candidate,'changed'=>$now];
    return $state;
}
