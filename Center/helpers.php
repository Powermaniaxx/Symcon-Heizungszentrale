<?php
require_once __DIR__.'/rules.php';

function hvToDevice($plan,$profile,$description) {
    $days = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
    if (!in_array($profile,[1,2,3],true) || count($plan)!==7) throw new Exception('Ungültiger Wochenplan oder Profilplatz.');
    $patch = [];
    foreach ($days as $day=>$label) {
        $points = $plan[$day]??[];
        if (!$points || $points[0][0]!==0 || count($points)>13) {
            throw new Exception($label . ': Tagesplan benötigt 00:00 als Beginn und höchstens 13 Abschnitte.');
        }
        $previous=-1;
        foreach ($points as $point) {
            if (!is_int($point[0]) || $point[0]<0 || $point[0]>=1440 || $point[0]<=$previous
                || !is_numeric($point[1]) || !is_finite((float)$point[1])) {
                throw new Exception('Ungültiger Schaltpunkt am ' . $label);
            }
            if (abs($point[1]*2-round($point[1]*2))>0.0001) {
                throw new Exception('Temperaturen müssen für dieses Thermostat in 0,5-Grad-Schritten liegen: ' . $label);
            }
            $previous=$point[0];
        }
        for ($slot=1;$slot<=13;$slot++) {
            $index=min($slot-1,count($points)-1);
            $end=isset($points[$slot]) ? $points[$slot][0] : 1440;
            foreach (['ENDTIME'=>$end,'TEMPERATURE'=>$points[$index][1]] as $kind=>$value) {
                $key="P{$profile}_{$kind}_{$label}_{$slot}";
                if (!isset($description[$key])) throw new Exception('Ziel unterstützt diesen Profilparameter nicht: ' . $key);
                $meta=$description[$key];
                if (isset($meta['OPERATIONS']) && (((int)$meta['OPERATIONS']&2)===0)) {
                    throw new Exception('Parameter nicht schreibbar: ' . $key);
                }
                if ((isset($meta['MIN']) && $value<$meta['MIN']) || (isset($meta['MAX']) && $value>$meta['MAX'])) {
                    throw new Exception('Wert außerhalb der Gerätegrenzen: ' . $key);
                }
                $type=strtoupper($meta['TYPE']??'');
                if (!in_array($type,['INTEGER','FLOAT'],true)) throw new Exception('Unerwarteter Parametertyp: ' . $key);
                if ($type==='INTEGER' && floor($value)!=$value) throw new Exception('Ganzzahl erforderlich: ' . $key);
                $patch[$key]=$type==='FLOAT'?(float)$value:(int)$value;
            }
        }
    }
    return $patch;
}

function hvFromDevice($params, $profile) {
    $days = ['MONDAY','TUESDAY','WEDNESDAY','THURSDAY','FRIDAY','SATURDAY','SUNDAY'];
    $plan = [];
    foreach ($days as $day) {
        $points = [];
        $start = 0;
        for ($slot = 1; $slot <= 13; $slot++) {
            $endKey = "P{$profile}_ENDTIME_{$day}_{$slot}";
            $tempKey = "P{$profile}_TEMPERATURE_{$day}_{$slot}";
            if (!isset($params[$endKey], $params[$tempKey])) throw new Exception('Profilparameter fehlt: ' . $endKey);
            $end = (int)$params[$endKey];
            $temperature = (float)$params[$tempKey];
            if ($end <= $start || $end > 1440) throw new Exception('Ungültige Abschnittsfolge: ' . $endKey);
            if (!$points || $points[count($points)-1][1] !== $temperature) $points[] = [$start, $temperature];
            $start = $end;
            if ($end === 1440) break;
        }
        if ($start !== 1440) throw new Exception('Tagesplan endet nicht um 24:00.');
        $plan[] = $points;
    }
    return $plan;
}

function hvFromEvent($event) {
    $data = IPS_GetEvent($event);
    $temperatures = [];
    foreach ($data['ScheduleActions'] as $action) {
        if (!preg_match('/^(-?\d+(?:[.,]\d+)?)\s*°C$/u', $action['Name'], $m)) {
            throw new Exception('Temperaturaktion kann nicht gelesen werden: ' . $action['Name']);
        }
        $temperatures[$action['ID']] = (float)str_replace(',', '.', $m[1]);
    }
    $plan = [];
    foreach ($data['ScheduleGroups'] as $group) {
        $points = [];
        foreach ($group['Points'] as $point) {
            if (!isset($temperatures[$point['ActionID']])) throw new Exception('Ungültige Temperaturaktion.');
            if (($point['Start']['Second']??0)!==0) throw new Exception('Heizpläne unterstützen nur volle Minuten.');
            $points[] = [$point['Start']['Hour']*60+$point['Start']['Minute'], $temperatures[$point['ActionID']]];
        }
        usort($points, function($a,$b) {return $a[0]<=>$b[0];});
        if (!$points || $points[0][0] !== 0) throw new Exception('Jeder Tag benötigt einen Schaltpunkt um 00:00.');
        foreach (range(0,6) as $day) if ($group['Days'] & (1 << $day)) {
            if (isset($plan[$day])) throw new Exception('Ein Wochentag ist mehrfach enthalten.');
            $plan[$day] = $points;
        }
    }
    if (count($plan) !== 7) throw new Exception('Der Plan muss alle sieben Tage enthalten.');
    ksort($plan);
    return $plan;
}

function hpEncode($value) {
    if (is_array($value)) {
        $xml = '<struct>';
        foreach ($value as $key => $item) {
            $xml .= '<member><name>' . htmlspecialchars((string)$key, ENT_XML1, 'UTF-8')
                . '</name>' . hpEncode($item) . '</member>';
        }
        return '<value>' . $xml . '</struct></value>';
    }
    if (is_int($value)) return '<value><int>' . $value . '</int></value>';
    if (is_float($value)) return '<value><double>' . json_encode($value, JSON_PRESERVE_ZERO_FRACTION) . '</double></value>';
    return '<value><string>' . htmlspecialchars((string)$value, ENT_XML1, 'UTF-8') . '</string></value>';
}

function hpDecode($value) {
    $children = $value->children();
    if (count($children) === 0) return (string)$value;
    $node = $children[0];
    switch ($node->getName()) {
        case 'struct':
            $result = [];
            foreach ($node->member as $member) $result[(string)$member->name] = hpDecode($member->value);
            return $result;
        case 'array':
            $result = [];
            foreach ($node->data->value as $item) $result[] = hpDecode($item);
            return $result;
        case 'int': case 'i4': case 'i8': return (int)(string)$node;
        case 'double': return (float)(string)$node;
        case 'boolean': return (string)$node === '1';
        case 'nil': return null;
        case 'string': return (string)$node;
        default: throw new Exception('Unerwarteter XML-RPC-Datentyp: ' . $node->getName());
    }
}

function hpRpc($method, $arguments) {
    global $ccuUrl, $ccuUser, $ccuPassword, $hpTimeout;
    $xml = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' . $method . '</methodName><params>';
    foreach ($arguments as $argument) $xml .= '<param>' . hpEncode($argument) . '</param>';
    $xml .= '</params></methodCall>';
    $curl = curl_init($ccuUrl);
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$xml,
        CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=utf-8'],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>($hpTimeout ?? 30)]);
    if ($ccuUser !== '') curl_setopt($curl, CURLOPT_USERPWD, $ccuUser . ':' . $ccuPassword);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($response === false || $status !== 200) {
        throw new Exception($method . ': HTTP ' . $status . ' ' . $error
            . '. Angefragte Adresse: ' . $ccuUrl
            . ($status === 404 ? '. Der Server antwortet, aber dieser Pfad wurde nicht gefunden.'
                : '. CCU-XML-RPC-Zugriff für den Symcon-Rechner an dieser Adresse prüfen.'));
    }
    $previous = libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($parsed === false || $parsed->getName() !== 'methodResponse') throw new Exception('Ungültige XML-RPC-Antwort.');
    if (isset($parsed->fault)) {
        $fault = hpDecode($parsed->fault->value);
        throw new Exception($method . ': CCU-Fehler ' . ($fault['faultCode'] ?? '') . ' ' . ($fault['faultString'] ?? ''));
    }
    if (!isset($parsed->params->param->value)) throw new Exception('XML-RPC-Antwort ohne Rückgabewert.');
    return hpDecode($parsed->params->param->value);
}
