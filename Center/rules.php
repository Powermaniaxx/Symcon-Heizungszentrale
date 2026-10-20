<?php
// Nur beobachtete, frische Messwerte zählen; Vorhersagen starten keine Absenkung.
function scWarmWeatherTick(array $previous,?float $temperature,int $measured,int $now): array {
    if ($temperature===null||!is_finite($temperature)||$temperature< -40||$temperature>55||$measured>$now||$now-$measured>1200) return ['active'=>false];
    $active=(bool)($previous['active']??false);
    $continuous=isset($previous['last'])&&$measured>=$previous['last']&&$measured-$previous['last']<=1200;
    if (!$continuous) {$previous=[];$active=false;}
    $kind=$temperature>=19?'warm':($temperature<=18.5?'cool':'neutral');
    $since=($previous['kind']??'')===$kind?($previous['since']??$measured):$measured;
    if ($kind==='warm'&&$measured-$since>=1800) $active=true;
    if ($kind==='cool'&&$measured-$since>=1800) $active=false;
    return ['active'=>$active,'kind'=>$kind,'since'=>$since,'last'=>$measured,'temperature'=>$temperature];
}

function scRuntimePlan(array $plan,string $mode,string $season,bool $warm,?float $actual,int $day,int $minute,array $previous,int $now): array {
    $plan=scValidatePlan($plan);$baseline=(float)$plan[$day][0][1];
    foreach($plan[$day] as $point) if($point[0]<=$minute)$baseline=(float)$point[1];
    $result=['plan'=>$plan,'target'=>$baseline,'baseline'=>$baseline,'correction'=>0.0,'reason'=>'Gespeicherter Wochenplan.','state'=>['active'=>false,'changed'=>$previous['changed']??0]];
    if ($mode!=='auto') return $result;
    if ($season==='summer') {
        $result['plan']=array_fill(0,7,[[0,14.0]]);$result['target']=14.0;
        $result['reason']='Sommer: durchgehend 14 °C als Rückfalllösung; AUS mit Frostschutz ist für diese Anbindung noch nicht bestätigt.';
        return $result;
    }
    if (!in_array($season,['spring','autumn'],true)) return $result;
    $temperatures=array_column($plan[$day],1);$low=min($temperatures);$high=max($temperatures);
    $heating=$high>$low&&$baseline>$low&&$baseline>=$high-0.5;
    $was=(bool)($previous['active']??false);
    $eligible=$warm&&$heating&&$actual!==null&&$actual>=($was?$baseline:$baseline+0.5);
    $active=$eligible&&($was||$now-($previous['changed']??0)>=1800);
    $changed=$was!==$active?$now:($previous['changed']??0);
    $result['state']=['active'=>$active,'changed'=>$changed];
    if (!$heating) $result['reason']='Nacht-/Absenkphase bleibt unverändert.';
    elseif($actual===null) $result['reason']='Raumtemperatur fehlt oder ist veraltet; keine Zusatzabsenkung.';
    elseif(!$eligible&&$warm) $result['reason']='Raum noch zu kühl für eine Zusatzabsenkung.';
    elseif(!$warm) $result['reason']='Keine bestätigte Wärmephase ab 19 °C für 30 Minuten.';
    else $result['reason']='30-Minuten-Sperre vor erneuter Zusatzabsenkung.';
    if (!$active) return $result;
    foreach($result['plan'] as &$points) {
        $values=array_column($points,1);$minimum=min($values);$maximum=max($values);
        foreach($points as &$point) if($maximum>$minimum&&$point[1]>$minimum&&$point[1]>=$maximum-0.5) $point[1]=max($minimum,$point[1]-0.5);
        unset($point);
    }
    unset($points);
    $result['plan']=scValidatePlan($result['plan']);$result['target']=$baseline-0.5;$result['correction']=-0.5;
    $result['reason']='Wärmephase bestätigt: Heizphasen vorübergehend 0,5 °C kühler; Nachtwerte unverändert.';
    return $result;
}


/**
 * Cold-night morning preheat. Uses measured local-night samples only.
 * Moves exactly one existing morning comfort transition earlier; no added
 * temperature step, no stored-template changes, and no heating while absent.
 */
function scColdNightPreheatPlan(array $plan,array $samples,int $day,int $now,string $room,?float $actual,bool $present,string $season): array {
    $result=['plan'=>$plan,'minutes'=>0,'reason'=>'Keine Kalt-Nacht-Vorheizung.'];
    if (!$present||!in_array($season,['spring','autumn','winter'],true)
        ||$actual===null||!is_finite($actual)||$actual<5||$actual>35) return $result;
    $room=mb_strtolower($room,'UTF-8');
    $limit=preg_match('/wohnzimmer/u',$room)?90:(preg_match('/(?:bad|badezimmer)/u',$room)?60:(preg_match('/flur/u',$room)?45:0));
    if ($limit===0) return $result;
    $clock=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Europe/Berlin'));
    $date=$clock->format('Y-m-d');
    $begin=(new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Europe/Berlin')))->getTimestamp();
    $cutoff=$begin+3*3600;
    // A fixed completed window prevents plan-hash churn from later samples.
    if ($now<$cutoff) return $result;
    $values=[];$timestamps=[];
    foreach($samples as $sample) {
        if (!is_array($sample)||count($sample)!==2||!is_numeric($sample[0])||!is_numeric($sample[1])) continue;
        [$at,$temp]=$sample;$at=(int)$at;$temp=(float)$temp;
        if ($at<$begin||$at>$cutoff||!is_finite($temp)||$temp< -40||$temp>55) continue;
        $values[]=$temp;$timestamps[]=$at;
    }
    if (count($values)<4||max($timestamps)-min($timestamps)<5400) return $result;
    sort($timestamps);
    for($i=1;$i<count($timestamps);$i++) if($timestamps[$i]-$timestamps[$i-1]>5400) return $result;
    $mean=array_sum($values)/count($values);
    if ($mean>3.0) return $result;
    $plan=scValidatePlan($plan);
    $points=$plan[$day]??null;
    if (!$points||count($points)<2) return $result;
    $low=min(array_column($points,1));$high=max(array_column($points,1));
    if ($high-$low<1.0||$actual>=$high-0.5) return $result;
    $lead=$mean<=-2.0?$limit:(int)(ceil($limit/2/5)*5);
    foreach($points as $i=>$point) {
        if ($i===0||$point[0]<240||$point[0]>660||$point[1]<$high-0.5) continue;
        $before=$points[$i-1];
        if ($point[1]-$before[1]<1.0) continue;
        // Keep at least 15 minutes of the preceding section and never cross midnight.
        $new=max($before[0]+15,$point[0]-$lead);
        if ($new>=$point[0]) return $result;
        $points[$i][0]=$new;$plan[$day]=$points;
        $result['plan']=scValidatePlan($plan);
        $result['minutes']=$point[0]-$new;
        $result['reason']='Gemessene kalte Nacht (00–03 Uhr, Ø '.round($mean,1).' °C): Komfortphase '.($point[0]-$new).' Minuten früher.';
        return $result;
    }
    return $result;
}

function scSeasonName(mixed $value): ?string {
    if (!is_string($value)) return null;
    $text=html_entity_decode(strip_tags($value),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text=strtolower(strtr($text,['Ä'=>'ä','Ö'=>'ö','Ü'=>'ü']));
    $map=['frühjahr'=>'spring','frühling'=>'spring','fruehjahr'=>'spring','fruehling'=>'spring','sommer'=>'summer','herbst'=>'autumn','winter'=>'winter'];
    if (!preg_match_all('/(?<!\p{L})(frühjahr|frühling|fruehjahr|fruehling|sommer|herbst|winter)(?!\p{L})/u',$text,$matches)) return null;
    $found=[];foreach($matches[1] as $name) $found[$map[$name]]=true;
    return count($found)===1?array_key_first($found):null;
}

function scValidatePlan($plan): array {
    if (!is_array($plan)||count($plan)!==7||array_keys($plan)!==range(0,6)) throw new Exception('Der Wochenplan muss sieben Tage enthalten.');
    $result=[];
    foreach($plan as $day=>$points) {
        if (!is_array($points)||!$points||count($points)>13||($points[0][0]??-1)!==0) throw new Exception('Jeder Tag beginnt um 00:00 und hat maximal 13 Abschnitte.');
        $last=-1; $normalized=[];
        foreach($points as $point) {
            if (!is_array($point)||count($point)!==2||!is_int($point[0])||$point[0]<0||$point[0]>=1440||$point[0]<=$last) throw new Exception('Ungültige oder überlappende Zeiten.');
            $temp=$point[1];
            if ((!is_int($temp)&&!is_float($temp))||!is_finite((float)$temp)||$temp<4.5||$temp>30.5||abs($temp*2-round($temp*2))>0.0001) throw new Exception('Temperatur muss zwischen 4,5 und 30,5 °C in 0,5-Grad-Schritten liegen.');
            $last=$point[0];
            if (!$normalized||$normalized[count($normalized)-1][1]!=(float)$temp) $normalized[]=[$point[0],(float)$temp];
        }
        $result[]=$normalized;
    }
    return $result;
}
function scAutumnFromWinter(array $winter): array {
    $winter=scValidatePlan($winter); $original=[]; $warm=[]; $autumn=[];
    foreach($winter as $day=>$points) {
        $temperatures=array_column($points,1);$low=min($temperatures);$high=max($temperatures);
        $index=0;
        for($minute=0;$minute<1440;$minute++) {
            while(isset($points[$index+1])&&$points[$index+1][0]<=$minute) $index++;
            $temp=(float)$points[$index][1];
            // Die wärmsten Tagesabschnitte, einschließlich halber Gradstufen.
            // Gleichmäßige Tage werden abgesenkt, ohne neue Zeiten einzufügen.
            $isWarm=$high===$low||($temp>$low&&$temp>=$high-0.5);
            $original[]=$temp;$warm[]=$isWarm&&$high!==$low;
            $autumn[]=$isWarm?max($high===$low?4.5:$low,$temp-1.0):$temp;
        }
    }
    $length=count($original);$anchor=array_search(false,$warm,true);
    if($anchor!==false) {
        $runStart=null;$runLength=0;
        for($offset=1;$offset<=$length;$offset++) {
            $at=($anchor+$offset)%$length;
            if($warm[$at]) {if($runStart===null)$runStart=$at;$runLength++;}
            elseif($runStart!==null) {
                // Mindestens 30 Minuten einer kurzen Heizphase beibehalten.
                $trim=min(30,intdiv(max(0,$runLength-30),10)*5);
                $beforeAt=($runStart-1+$length)%$length;$lastAt=($runStart+$runLength-1)%$length;
                $before=$autumn[$beforeAt];$after=$autumn[$at];
                $startTrim=$original[$beforeAt]>=$original[$runStart]?0:$trim;
                $endTrim=$original[$at]>=$original[$lastAt]?0:$trim;
                for($i=0;$i<$trim;$i++) {
                    $first=($runStart+$i)%$length;$last=($runStart+$runLength-1-$i)%$length;
                    // Das Kürzen darf eine Temperatur niemals erhöhen.
                    if($i<$startTrim)$autumn[$first]=min($autumn[$first],$before);
                    if($i<$endTrim)$autumn[$last]=min($autumn[$last],$after);
                }
                $runStart=null;$runLength=0;
            }
        }
    }
    $result=[];
    for($day=0;$day<7;$day++) {
        $points=[];
        for($minute=0;$minute<1440;$minute++) {
            $temp=$autumn[$day*1440+$minute];
            if(!$points||$points[count($points)-1][1]!==$temp)$points[]=[$minute,$temp];
        }
        if(count($points)>13) throw new Exception('Der abgeleitete Herbstplan benötigt mehr als 13 Abschnitte an Tag '.($day+1).'. Bitte diesen Winterplan zuerst vereinfachen.');
        $result[]=$points;
    }
    return scValidatePlan($result);
}

function scWeatherMean(array $samples,int $hours,int $minimum,int $spanHours,int $now): ?float {
    $values=array_values(array_filter($samples,static fn($s)=>is_array($s)&&count($s)===2&&$s[0]>=$now-$hours*3600&&$s[0]<=$now));
    usort($values,static fn($a,$b)=>$a[0]<=>$b[0]);
    if (count($values)<$minimum||$now-$values[count($values)-1][0]>10800||$values[count($values)-1][0]-$values[0][0]<$spanHours*3600) return null;
    for($i=1;$i<count($values);$i++) if($values[$i][0]-$values[$i-1][0]>21600) return null;
    return array_sum(array_column($values,1))/count($values);
}
