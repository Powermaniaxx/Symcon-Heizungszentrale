<?php
require_once dirname(__DIR__).'/Center/rules.php';
function verify($condition,$message) {if(!$condition)throw new Exception($message);}
$start=1800000000;$weather=[];
foreach([0,600,1200] as $offset) {
    $weather=scWarmWeatherTick($weather,19.0,$start+$offset,$start+$offset);
    verify(!$weather['active'],'Vor 30 Minuten nicht absenken.');
}
$weather=scWarmWeatherTick($weather,19.0,$start+1800,$start+1800);
verify($weather['active'],'Nach bestätigten 30 Minuten ab 19 Grad freigeben.');
verify(scWarmWeatherTick($weather,19.0,$start+1800,$start+3601)['active']===false,'Veraltete Wetterdaten sperren.');
verify(scWarmWeatherTick([],22.0,$start,$start+1800)['active']===false,'Ein alter warmer Einzelwert beweist keine Dauer.');
$repeated=scWarmWeatherTick([],22.0,$start,$start);
$repeated=scWarmWeatherTick($repeated,22.0,$start,$start+600);
verify(!$repeated['active'],'Wiederholtes Lesen verlängert keinen Messzeitraum.');
$gap=scWarmWeatherTick($weather,22.0,$start+3601,$start+3601);
verify(!$gap['active'],'Messlücke über 20 Minuten startet neu.');
foreach([2400,3000] as $offset){$weather=scWarmWeatherTick($weather,18.5,$start+$offset,$start+$offset);verify($weather['active'],'Kühle Gegenphase erst bestätigen.');}
$weather=scWarmWeatherTick($weather,18.5,$start+4200,$start+4200);
verify(!$weather['active'],'Nach 30 Minuten unterer Schwelle regulären Plan verwenden.');
$plan=array_fill(0,7,[[0,18.0],[300,21.5],[1260,19.0]]);$original=$plan;
$warm=scRuntimePlan($plan,'auto','autumn',true,22.0,0,600,[],$start);
verify($warm['target']===21.0&&$warm['correction']===-0.5,'Warme Herbst-Heizphase um 0,5 Grad absenken.');
verify($warm['plan'][0][0][1]===18.0&&$warm['plan'][0][2][1]===19.0,'Nachtwerte erhalten.');
verify($plan===$original,'Gespeicherter Wochenplan bleibt unverändert.');
foreach(['spring','autumn'] as $season) {
 verify(scRuntimePlan($plan,'auto',$season,true,21.4,0,600,$warm['state'],$start+60)['target']===21.5,'Zu kalter Raum hebt Absenkung sofort auf.');
 verify(scRuntimePlan($plan,'auto',$season,true,null,0,600,[],$start)['plan']===$plan,'Ohne Raumtemperatur nicht zusätzlich absenken.');
 verify(scRuntimePlan($plan,'auto',$season,true,23.0,0,1300,$warm['state'],$start+60)['plan']===$plan,'Nachtabsenkung sofort und unverändert.');
}
$stopped=scRuntimePlan($plan,'auto','autumn',true,21.4,0,600,$warm['state'],$start+60);
verify(!scRuntimePlan($plan,'auto','autumn',true,22.0,0,600,$stopped['state'],$start+120)['state']['active'],'Nach Sicherheitsrückkehr mindestens 30 Minuten vor Neustart warten.');
verify(scRuntimePlan($plan,'auto','autumn',true,22.0,0,600,$stopped['state'],$start+1860)['state']['active'],'Nach Sperrzeit wieder freigeben.');
verify(scRuntimePlan($plan,'auto','winter',true,24.0,0,600,[],$start)['plan']===$plan,'Winter ohne zusätzliche Korrektur.');
foreach(['spring','summer','autumn','winter'] as $season) verify(scRuntimePlan($plan,$season,$season,true,24.0,0,600,[],$start)['plan']===$plan,'Manuell bleibt unverändert: '.$season);
verify(scRuntimePlan($plan,'auto','summer',true,null,0,600,[],$start)['plan']===array_fill(0,7,[[0,14.0]]),'Automatischer Sommer durchgehend 14 Grad.');
verify(scRuntimePlan($plan,'auto','autumn',false,22.0,0,600,[],$start)['plan']===$plan,'Nach Sommer gespeicherten Plan wieder verwenden.');
$constant=array_fill(0,7,[[0,18.0]]);
verify(scRuntimePlan($constant,'auto','autumn',true,23.0,0,600,[],$start)['plan']===$constant,'Gleichmäßige Tage ohne erfundene Heizphase erhalten.');
echo "OK: bestätigte 30 Minuten, Hysterese, Messlücken, Raumschutz, Nachtschutz, Sperrzeit, Sommer, Winter, Manuell und unveränderte Vorlagen.\n";
