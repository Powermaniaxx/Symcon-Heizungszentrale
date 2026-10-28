<?php
// Work on a runtime copy: stored seasonal plans remain editable and unchanged.
function scAbsencePlan(array $plan,string $season,string $name): array
{
    $reduced=scReducedPlan($plan,1.5);
    if ($season!=='autumn') return $reduced;
    foreach($reduced as &$day) foreach($day as &$point) {
        if (preg_match('/wohnzimmer|living/iu',$name)) $point[1]=max(18.0,$point[1]);
        elseif (preg_match('/schlafzimmer|bedroom/iu',$name)) $point[1]=14.0;
        else $point[1]=min(15.0,$point[1]);
    }
    unset($day,$point);
    return scValidatePlan($reduced);
}

trait AbsenceHistory
{
    public function MessageSink(int $TimeStamp,int $SenderID,int $Message,array $Data): void
    {
        if ($SenderID!==0||$Message!==IPS_KERNELSTARTED) return;
        if ($this->ReadAttributeString('Mode')!=='auto') $this->WriteAttributeString('LastManualMode',$this->ReadAttributeString('Mode'));
        $this->WriteAttributeString('Mode','auto');
        $this->WriteAttributeBoolean('Active',false);
        $this->WriteAttributeBoolean('StartupPending',true);
        $this->WriteAttributeString('Status','Neustart: Automatik gewählt. Geräteprüfung beim nächsten Steuerungslauf.');
    }

    private function ResumeAfterStartup(): void
    {
        if (!$this->ReadAttributeBoolean('StartupPending')) return;
        // Consume before any RPC: a failed check must not loop automatically.
        $this->WriteAttributeBoolean('StartupPending',false);
        if ($this->ReadAttributeBoolean('TestMode')) {
            $this->WriteAttributeString('Status','Neustart: Automatik im Testmodus. Keine Gerätebefehle.');
            return;
        }
        if (empty($this->State()['migrationBackup'])) {
            $this->WriteAttributeString('Status','Neustart: Automatik gewählt. Ersteinrichtung muss einmal aktiviert werden.');
            return;
        }
        $this->Activate();
    }

    private function RecordControlHistory(int $room,string $season,array $runtime,bool $changed): void
    {
        if ($this->ReadAttributeBoolean('TestMode')) return;
        $zone=$this->Zones()[$room];$state=$this->State();$now=time();
        $id=$zone[3];$value=$this->Num($id);
        $updated=IPS_VariableExists($id)?(int)(IPS_GetVariable($id)['VariableUpdated']??0):0;
        $fresh=$value!==null&&$updated<=$now&&$now-$updated<=1200;
        $previous=$state['observedTargets'][$room]??null;
        $events=$state['reductionHistory']??[];
        $base=['at'=>$now,'room'=>$zone[0],'roomId'=>$room,'season'=>self::SEASONS[$season],
            'reason'=>$runtime['reason'],'to'=>$runtime['target']];
        if ($changed&&($season==='away'||($fresh&&$value>$runtime['target']))) {
            $events[]=$base+['kind'=>'planConfirmed','from'=>$fresh?$value:null];
        }
        // Fresh Symcon feedback confirms a setpoint, never the measured room temperature.
        if ($fresh&&$previous&&$updated>$previous['at']&&$now-$previous['at']<=1200
            &&$value<$previous['value']&&abs($value-$runtime['target'])<0.01) {
            $events[]=array_replace($base,['at'=>$updated,'kind'=>'targetObserved','from'=>$previous['value'],'to'=>$value]);
        }
        if ($fresh) $state['observedTargets'][$room]=['value'=>$value,'at'=>$updated];
        $state['reductionHistory']=array_slice($events,-200);
        $this->StoreState($state);
    }
}
