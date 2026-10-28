<?php
// Exercise the real module with an in-memory Symcon API; no CCU is connected.
define('IPS_KERNELSTARTED',10001);
class IPSModuleStrict {
    public array $a=['State'=>'{}','Templates'=>'{}','ManualTemplates'=>'{}','Mode'=>'auto','TestMode'=>false,'Active'=>true];
    public array $p=['RootID'=>0,'CCUHost'=>'ccu.local','LivingGroup'=>'group','ZoneConfiguration'=>'{}'];
    public function ApplyChanges(): void {}
    public function RegisterMessage($sender,$message) {}
    public function ReadAttributeString($n) {return $this->a[$n]??'';}
    public function ReadAttributeBoolean($n) {return $this->a[$n]??false;}
    public function WriteAttributeString($n,$v) {$this->a[$n]=$v;}
    public function WriteAttributeBoolean($n,$v) {$this->a[$n]=$v;}
    public function ReadPropertyString($n) {return $this->p[$n]??'';}
    public function ReadPropertyInteger($n) {return $this->p[$n]??0;}
    public function SetVisualizationType($v) {}
    public function SetTimerInterval($n,$v) {if ($v!==60000) throw new Exception('Refresh timer lost');}
    public function SetStatus($v) {}
}
function IPS_VariableExists($id) {return isset($GLOBALS['values'][$id]);}
function GetValue($id) {return $GLOBALS['values'][$id];}
function IPS_GetVariable($id) {return ['VariableUpdated'=>time()];}
function IPS_LogMessage($source,$message) {}
function IPS_GetKernelVersion() {return $GLOBALS['kernelVersion']??'9.0';}
require_once dirname(__DIR__).'/Center/module.php';
function check($v,$message) {if (!$v) throw new Exception($message);}
function invoke($object,$name,...$args) {return (new ReflectionMethod($object,$name))->invoke($object,...$args);}
$m=new SVHeatingCenter();$zones=[];$templates=[];
foreach(['8.2'=>false,'9.0'=>true,'9.0.1'=>true,'9.0 (Beta)'=>true,'9.1'=>false,'9.01'=>false,'10.0'=>false] as $version=>$hidden) {
    $GLOBALS['kernelVersion']=(string)$version;
    $template=invoke($m,'TileTemplate');
    check(str_contains($template,'<h1 hidden>Heizungszentrale</h1>')===$hidden,'Title visibility must follow Symcon 9.0 only: '.$version);
}
foreach(range(1,8) as $room) {
    $zones[$room]=['Room '.$room,$room*10,$room*10+1,$room*10+2,$room*10+3];
    foreach(['spring'=>20.0,'summer'=>14.0,'autumn'=>21.0,'winter'=>22.0,'away'=>16.0] as $season=>$temp) $templates[$room][$season]=['plan'=>array_fill(0,7,[[0,17.0],[360,$temp]])];
    $GLOBALS['values'][$room*10+1]=23.0;
}
$m->p['ZoneConfiguration']=json_encode($zones);$m->a['Templates']=json_encode($templates);
$m->ApplyChanges();check($m->a['Active']===true,'Healthy restart must retain activation');
$m->a['Active']=false;$m->ApplyChanges();check($m->a['Active']===false,'Restart must preserve explicit pause');
$m->a['Active']=true;$m->a['TestMode']=true;$m->ApplyChanges();check($m->a['Active']===false,'Test mode must remain inactive');
try {invoke($m,'Transfer',1,'winter');throw new Exception('Write escaped test mode');}
catch(Exception $e) {check(str_contains($e->getMessage(),'Testmodus'),'Test guard must precede all RPC');}
$m->a['TestMode']=false;
$m->a['State']=json_encode(['seasonGate'=>['season'=>'autumn'],'lastSeason'=>'autumn']);
$away=invoke($m,'RuntimePlan',1,'away');check($away['plan'][0][0][1]===15.0,'Away uses autumn minus 1.5, not stored away profile');
$m->a['State']=json_encode(['seasonGate'=>['season'=>'winter'],'lastSeason'=>'winter']);
check(invoke($m,'RuntimePlan',1,'away')['plan'][0][0][1]===(scWinterAllowed(new DateTimeImmutable())?15.5:15.0),'Away follows permitted season, including saved winter');
$m->p['SeasonVariable']=999;$GLOBALS['values'][999]='Winter';
if (!scWinterAllowed(new DateTimeImmutable())) {
    check(invoke($m,'SeasonDecision')['season']==='autumn','External winter and stale weather cannot bypass date lock');
    check(invoke($m,'Decision')['season']==='autumn','Saved gate cannot delay autumn');
}
$m->a['Mode']='winter';check(invoke($m,'SeasonDecision')['season']==='winter','Manual winter remains available');$m->a['Mode']='auto';
$signature=invoke($m,'Hash',json_decode($m->a['Templates'],true)[1]['autumn']['plan']);
$m->a['State']=json_encode(['learning'=>[1=>['autumn'=>['signature'=>$signature,'offset'=>-0.5]]]]);
$learned=invoke($m,'RuntimePlan',1,'autumn');
check($learned['plan'][0][0][1]===17.0&&$learned['plan'][0][1][1]===20.5,'Real runtime applies bounded learning');
$m->a['Mode']='autumn';check(invoke($m,'RuntimePlan',1,'autumn')['plan'][0][1][1]===21.0,'Manual season never applies learning');
$m->a['Active']=true;invoke($m,'Fail',new Exception('readback failed'));
check($m->a['Active']===false,'An uncertain operation must stop automatic retries');
echo "OK: module restart, pause, test write guard, seasonal away and learning integration.\n";
