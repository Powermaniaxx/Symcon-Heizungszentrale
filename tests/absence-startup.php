<?php
require __DIR__.'/module-safety.php';
$plan=array_fill(0,7,[[0,12.0],[360,14.0],[480,16.0],[600,21.0]]);
$copy=$plan;
$ordinary=scAbsencePlan($plan,'autumn','Flur');
check($ordinary[0]=== [[0,12.0],[360,14.0],[480,14.5],[600,15.0]],'Autumn reduction, cap and existing frost values');
check(scAbsencePlan($plan,'autumn','Schlafzimmer')[0] === [[0,14.0]],'Bedroom constant 14');
check(scAbsencePlan($plan,'autumn','Wohnzimmer')[0] === [[0,18.0],[600,19.5]],'Living floor 18, warmer reduced schedule retained');
check(scAbsencePlan($plan,'winter','Wohnzimmer')===scReducedPlan($plan,1.5),'Winter unchanged');
check($copy===$plan,'Runtime copies must preserve source');
$zones[1][0]='Wohnzimmer';$zones[2][0]='Schlafzimmer';$m->p['ZoneConfiguration']=json_encode($zones);
$m->a['State']=json_encode(['seasonGate'=>['season'=>'autumn']]);
check(min(array_column(invoke($m,'RuntimePlan',1,'away')['plan'][0],1))>=18,'Living runtime floor');
check(invoke($m,'RuntimePlan',2,'away')['plan'][0]===[[0,14.0]],'Bedroom runtime fixed');
$m->a['Mode']='winter';$m->a['Active']=true;
$m->MessageSink(time(),1,IPS_KERNELSTARTED,[]);check($m->a['Mode']==='winter','Ignore other senders');
$m->MessageSink(time(),0,IPS_KERNELSTARTED,[]);
check($m->a['Mode']==='auto'&&!$m->a['Active']&&$m->a['StartupPending'],'Startup selects auto and defers activation');
check($m->a['LastManualMode']==='winter','Remember manual choice');
$m->a['TestMode']=true;invoke($m,'ResumeAfterStartup');
check(!$m->a['Active']&&!$m->a['StartupPending'],'Test restart sends nothing');
$m->a['TestMode']=false;$m->MessageSink(time(),0,IPS_KERNELSTARTED,[]);invoke($m,'ResumeAfterStartup');
check(!$m->a['Active']&&!$m->a['StartupPending'],'Never auto-migrate a new installation');
// Exercise deferred reactivation with a fake device boundary; no network.
class StartupHarness extends IPSModuleStrict {
    use AbsenceHistory;
    public int $activations=0;
    private function State(): array {return ['migrationBackup'=>'prior-activation.json'];}
    private function Activate(): void {$this->activations++;throw new Exception('device unavailable');}
    public function resume(): void {$this->ResumeAfterStartup();}
}
$h=new StartupHarness();$h->MessageSink(time(),0,IPS_KERNELSTARTED,[]);
try {$h->resume();} catch(Exception $e) {check($e->getMessage()==='device unavailable','Expected simulated error');}
$h->resume();check($h->activations===1&&!$h->a['StartupPending'],'Failed startup is not retried repeatedly');
$m->a['State']='{}';$GLOBALS['values'][12]=21.0;
$runtime=['target'=>18.0,'reason'=>'Herbst-Abwesenheit'];
invoke($m,'RecordControlHistory',1,'away',$runtime,true);
$state=json_decode($m->a['State'],true);$event=$state['reductionHistory'][0];
check($event['kind']==='planConfirmed'&&$event['from']==21&&$event['to']==18&&$event['room']==='Wohnzimmer','Confirmed plan is labelled honestly');
invoke($m,'RecordControlHistory',1,'away',$runtime,false);
check(count(json_decode($m->a['State'],true)['reductionHistory'])===1,'No repeated plan events');
$state=json_decode($m->a['State'],true);$state['observedTargets'][1]=['at'=>time()-60,'value'=>21];$m->a['State']=json_encode($state);$GLOBALS['values'][12]=18.0;
invoke($m,'RecordControlHistory',1,'away',$runtime,false);
check(json_decode($m->a['State'],true)['reductionHistory'][1]['kind']==='targetObserved','Fresh feedback reduction separately confirmed');
$m->a['TestMode']=true;$before=$m->a['State'];invoke($m,'RecordControlHistory',1,'away',$runtime,true);
check($m->a['State']===$before,'Simulation never creates confirmations');
$m->a['TestMode']=false;
for($i=0;$i<205;$i++) invoke($m,'RecordControlHistory',1,'away',$runtime,true);
check(count(json_decode($m->a['State'],true)['reductionHistory'])===200,'Bounded persistent history');
echo "OK: autumn exceptions, startup selection/deferred activation and honest bounded history.\n";
