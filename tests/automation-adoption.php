<?php
// Run against the real module. In-memory Symcon and local backup files only, no CCU.
class IPSModuleStrict {
    public int $InstanceID=9000;
    public array $a=['State'=>'{}','Templates'=>'{}','ManualTemplates'=>'{}','ManualPresets'=>'{}','LastActionReply'=>'{}','Mode'=>'autumn','TestMode'=>false,'Active'=>true];
    public array $p=['ZoneConfiguration'=>'{}'];
    public array $reply=[];
    public function ReadAttributeString($n) {return $this->a[$n]??'';}
    public function ReadAttributeBoolean($n) {return $this->a[$n]??false;}
    public function WriteAttributeString($n,$v) {$this->a[$n]=$v;}
    public function WriteAttributeBoolean($n,$v) {$this->a[$n]=$v;}
    public function ReadPropertyString($n) {return $this->p[$n]??'';}
    public function ReadPropertyInteger($n) {return $this->p[$n]??0;}
    public function UpdateVisualizationValue($v) {$this->reply=json_decode($v,true,64,JSON_THROW_ON_ERROR);}
}
function IPS_VariableExists($id) {return isset($GLOBALS['values'][$id]);}
function GetValue($id) {return $GLOBALS['values'][$id];}
function IPS_GetVariable($id) {return ['VariableUpdated'=>$GLOBALS['updated'][$id]??time()];}
function IPS_GetKernelDir() {return $GLOBALS['backupRoot'];}
function IPS_SemaphoreEnter($key,$timeout) {return true;}
function IPS_SemaphoreLeave($key) {}
require_once dirname(__DIR__).'/Center/module.php';
function check($v,$message) {if(!$v)throw new Exception($message);}
function invoke($m,$name,...$args) {return (new ReflectionMethod($m,$name))->invoke($m,...$args);}
function rejects($m,$method,...$args) {
    $before=$m->a;
    try {invoke($m,$method,...$args);} catch(Exception $e) {check($m->a===$before,'Rejected edit mutated stored data: '.$e->getMessage());return;}
    throw new Exception('Expected rejection: '.$method);
}
$GLOBALS['backupRoot']=sys_get_temp_dir().DIRECTORY_SEPARATOR.'heating-adoption-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR;
$m=new SVHeatingCenter();$zones=[];$templates=[];
foreach(range(1,8) as $room) {
    $zones[$room]=['Room '.$room,$room*10,$room*10+1,$room*10+2,$room*10+3];
    foreach(['spring','summer','autumn','winter','away'] as $season) $templates[$room][$season]=['plan'=>array_fill(0,7,[[0,14.0],[420,20.0],[1260,15.0]])];
    $GLOBALS['values'][$room*10+1]=18.0;$GLOBALS['values'][$room*10+2]=21.5;$GLOBALS['values'][$room*10+3]=2;
}
$templates=json_decode(json_encode($templates),true);
$m->p['ZoneConfiguration']=json_encode($zones);$m->a['Templates']=json_encode($templates);
$baseline=$m->a;
$draft=$templates[2]['autumn']['plan'];$draft[0]=[[0,14.0],[390,21.5],[1290,15.0]];
$revision=invoke($m,'Hash',$templates[2]['autumn']['plan']);
$payload=['mode'=>'autumn','revision'=>$revision,'automationRevision'=>$revision,'plan'=>$draft];
invoke($m,'AdoptAutomation',2,'autumn',$payload);
$saved=json_decode($m->a['Templates'],true);$manual=json_decode($m->a['ManualTemplates'],true);
check($saved[2]['autumn']['plan']==$draft&&$manual[2]['autumn']['plan']==$draft,'Temperatures and times must be saved to both chosen plans in manual mode');
check($saved[2]['winter']===$templates[2]['winter']&&$saved[1]===$templates[1],'Other seasons and rooms preserved');
check($m->a['Mode']==='autumn'&&$m->a['Active']===true,'Adoption must preserve mode and activation');
check(count(glob($GLOBALS['backupRoot'].'scripts/Heizungszentrale-Sicherungen/*.json'))===1,'Previous plan backed up before mutation');
rejects($m,'AdoptAutomation',2,'autumn',$payload);
$m->a=$baseline;$m->a['Mode']='auto';
$payload['mode']='auto';$payload['plan']=$draft;
$m->a['State']=json_encode(['learning'=>[2=>['autumn'=>['offset'=>-0.5],'winter'=>['offset'=>-0.5]],1=>['autumn'=>['offset'=>-0.5]]]]);
invoke($m,'AdoptAutomation',2,'autumn',$payload);
$learning=json_decode($m->a['State'],true)['learning'];
check(!isset($learning[2]['autumn'])&&isset($learning[2]['winter'],$learning[1]['autumn']),'Only the edited room-season learning is reset');
check($m->a['ManualTemplates']==='{}','Auto edit preserves manual plans');
$m->a=$baseline;
$payload['mode']='winter';rejects($m,'AdoptAutomation',2,'autumn',$payload);
$payload['mode']='autumn';$payload['automationRevision']='stale';rejects($m,'AdoptAutomation',2,'autumn',$payload);
$payload['automationRevision']=$revision;$payload['plan'][0][1][0]=-5;rejects($m,'AdoptAutomation',2,'autumn',$payload);
$payload['plan']=$draft;$payload['plan'][0][1][1]=31;rejects($m,'AdoptAutomation',2,'autumn',$payload);
rejects($m,'AdoptAutomation',2,'away',$payload);

// A device target is adopted only after an explicit action, into today's current slot.
$m->a=$baseline;$before=$m->a;
$candidate=invoke($m,'AutomationTargetCandidate',2);
check($candidate['available']&&$m->a===$before,'Reading a target never changes the automation');
invoke($m,'AdoptAutomationTarget',2,['token'=>$candidate['token']]);
$expected=$templates[2]['autumn']['plan'];$expected[$candidate['day']][$candidate['segment']][1]=21.5;
check(json_decode($m->a['Templates'],true)[2]['autumn']['plan']==scValidatePlan($expected),'Only the current weekday and slot receive the device target');
check($m->a['ManualTemplates']==='{}','Device target adoption leaves manual templates untouched');
rejects($m,'AdoptAutomationTarget',2,['token'=>$candidate['token']]);
$m->a=$baseline;$candidate=invoke($m,'AutomationTargetCandidate',2);
$GLOBALS['values'][22]=22.0;rejects($m,'AdoptAutomationTarget',2,['token'=>$candidate['token']]);
$GLOBALS['values'][22]=21.5;$m->a['Mode']='winter';rejects($m,'AdoptAutomationTarget',2,['token'=>$candidate['token']]);
$m->a=$baseline;
$old=invoke($m,'AutomationTargetCandidate',2,new DateTimeImmutable('yesterday 12:00',new DateTimeZone('Europe/Berlin')));
check(!$old['available'],'Future measurements cannot be accepted in an old clock context');
$GLOBALS['updated'][22]=time()-1201;check(!invoke($m,'AutomationTargetCandidate',2)['available'],'Stale target rejected');unset($GLOBALS['updated'][22]);
$GLOBALS['values'][22]=true;check(!invoke($m,'AutomationTargetCandidate',2)['available'],'Boolean target rejected');$GLOBALS['values'][22]=21.5;
$GLOBALS['values'][42]=20.0;check(!invoke($m,'AutomationTargetCandidate',3)['available'],'Conflicting grouped targets are never averaged');$GLOBALS['values'][42]=21.5;
check(invoke($m,'AutomationTargetCandidate',3)['available'],'Matching grouped targets are accepted');
$m->a['Mode']='summer';check(!invoke($m,'AutomationTargetCandidate',2)['available'],'Summer pause has priority');
$m->a['Mode']='away';check(!invoke($m,'AutomationTargetCandidate',2)['available'],'Away does not overwrite a seasonal plan');
$m->a=$baseline;$m->a['TestMode']=true;
$candidate=invoke($m,'AutomationTargetCandidate',2);invoke($m,'AdoptAutomationTarget',2,['token'=>$candidate['token']]);
check(str_contains($m->a['Status'],'Testmodus: keine Gerätebefehle'),'Test-mode adoption explicitly remains a saved template');

// The actual request handler rejects stale UI state without stopping running heating.
$m->a=$baseline;
$m->RequestAction('Do',json_encode(['action'=>'adoptAutomation','room'=>2,'season'=>'autumn','mode'=>'autumn','revision'=>'stale','requestId'=>'conflict-test']));
check($m->a['Active']===true&&isset($m->reply['error']),'Stale adoption must not pause the active control');
check($m->a['Templates']===$baseline['Templates'],'Rejected request preserves templates');
echo "OK: explicit seasonal adoption, times, device target, conflicts, groups, backups and no device commands.\n";
