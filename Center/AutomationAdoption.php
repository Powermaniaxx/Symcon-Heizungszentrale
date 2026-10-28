<?php
// Explicit user decisions only; reading a changed device target never edits a plan.
trait AutomationAdoption
{
    private function AutomationProfiles(int $room): array
    {
        $profiles=[];$templates=$this->Templates();
        foreach (self::SEASONS as $season=>$label) {
            $plan=$templates[$room][$season]['plan']??null;
            $profiles[$season]=['plan'=>$plan,'revision'=>$plan?$this->Hash($plan):''];
        }
        return $profiles;
    }

    private function AutomationTargetCandidate(int $room,?DateTimeImmutable $now=null): array
    {
        $now=($now??new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Berlin'));
        $season=$this->Decision()['season'];
        if (!in_array($season,['spring','autumn','winter'],true)) return ['available'=>false,
            'reason'=>$season==='summer'?'Die automatische Sommer-Heizpause bleibt bei 14 °C.':'Bei Abwesenheit wird die Saisonvorlage abgesenkt. Bitte den gewünschten Saisonplan im Editor bearbeiten.'];
        $plan=$this->Templates()[$room][$season]['plan']??null;
        if (!$plan) return ['available'=>false,'reason'=>'Zuerst die Automatikvorlage für diese Saison einlesen.'];
        try {$plan=scValidatePlan($plan);} catch (Throwable $e) {return ['available'=>false,'reason'=>'Die Automatikvorlage muss zuerst geprüft werden.'];}
        $targets=[];
        foreach ($room===3?[3,4]:[$room] as $part) {
            $id=$this->Zones()[$part][3];
            if (!IPS_VariableExists($id)) return ['available'=>false,'reason'=>'Geräte-Sollwert fehlt.'];
            $value=GetValue($id);$updated=(int)(IPS_GetVariable($id)['VariableUpdated']??0);
            if ((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<4.5||$value>30.5
                ||abs($value*2-round($value*2))>0.0001||$updated>$now->getTimestamp()||$now->getTimestamp()-$updated>1200) {
                return ['available'=>false,'reason'=>'Nur ein gültiger, höchstens 20 Minuten alter Geräte-Sollwert kann übernommen werden.'];
            }
            $targets[]=(float)$value;
        }
        if (count(array_unique($targets))!==1) return ['available'=>false,'reason'=>'Die beiden Geräte haben unterschiedliche Sollwerte. Bitte die gemeinsame Temperatur im Planeditor festlegen.'];
        $day=(int)$now->format('N')-1;$minute=(int)$now->format('G')*60+(int)$now->format('i');$segment=0;
        foreach ($plan[$day] as $index=>$point) if ($point[0]<=$minute) $segment=$index;
        $candidate=['season'=>$season,'day'=>$day,'date'=>$now->format('Y-m-d'),'segment'=>$segment,
            'from'=>$plan[$day][$segment][0],'until'=>$plan[$day][$segment+1][0]??1440,
            'target'=>$targets[0],'previous'=>(float)$plan[$day][$segment][1],
            'revision'=>$this->Hash($this->Templates()[$room][$season]['plan']),'mode'=>$this->ReadAttributeString('Mode')];
        if ($candidate['target']===$candidate['previous']) return ['available'=>false,'reason'=>'Der Geräte-Sollwert entspricht bereits diesem Abschnitt der Automatikvorlage.'];
        return ['available'=>true,'token'=>hash('sha256',json_encode([$room,$candidate],JSON_THROW_ON_ERROR))]+$candidate;
    }

    private function AdoptAutomation(int $room,string $season,array $payload): void
    {
        if (!in_array($season,['spring','summer','autumn','winter'],true)) throw new Exception('Bitte eine Jahreszeit auswählen; Abwesenheit wird aus deren Automatikplan abgeleitet.');
        if (($payload['mode']??null)!==$this->ReadAttributeString('Mode')) throw new Exception('Die Betriebsart wurde inzwischen geändert. Bitte neu laden.');
        $source=$this->WorkingTemplates()[$room][$season]['plan']??null;
        if (($payload['revision']??null)!==($source?$this->Hash($source):'')) throw new Exception('Der bearbeitete Plan wurde inzwischen geändert. Bitte neu laden.');
        $automatic=$this->Templates()[$room][$season]['plan']??null;
        if (($payload['automationRevision']??null)!==($automatic?$this->Hash($automatic):'')) throw new Exception('Die Automatikvorlage wurde inzwischen geändert. Bitte neu laden.');
        $plan=scValidatePlan($payload['plan']??null);
        $this->StoreAutomationPlan($room,$season,$plan,$this->ReadAttributeString('Mode')!=='auto');
    }

    private function AdoptAutomationTarget(int $room,array $payload): void
    {
        $candidate=$this->AutomationTargetCandidate($room);
        if (!$candidate['available']||!is_string($payload['token']??null)||!hash_equals($candidate['token'],$payload['token'])) {
            throw new Exception('Sollwert, Zeitabschnitt oder Saison haben sich inzwischen geändert. Bitte aktualisieren und erneut prüfen.');
        }
        $plan=scValidatePlan($this->Templates()[$room][$candidate['season']]['plan']);
        $plan[$candidate['day']][$candidate['segment']][1]=$candidate['target'];
        $this->StoreAutomationPlan($room,$candidate['season'],scValidatePlan($plan),false);
    }

    private function StoreAutomationPlan(int $room,string $season,array $plan,bool $saveManual): void
    {
        $templates=$this->Templates();
        $manual=json_decode($this->ReadAttributeString('ManualTemplates'),true,64,JSON_THROW_ON_ERROR);
        $this->Backup(['room'=>$room,'season'=>$season,'previous'=>$templates[$room][$season]??null,
            'previousManual'=>$saveManual?($manual[$room][$season]??null):null,'manualSaved'=>$saveManual],'Automatik-Uebernahme');
        $entry=['plan'=>$plan,'origin'=>'Aus manueller Änderung übernommen','updated'=>time()];
        $templates[$room][$season]=$entry;
        $this->WriteAttributeString('Templates',json_encode($templates,JSON_THROW_ON_ERROR));
        if ($saveManual) {
            $manual[$room][$season]=$entry;
            $this->WriteAttributeString('ManualTemplates',json_encode($manual,JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('ManualPresetName','');
        }
        // A previous learned reduction must not immediately undo an explicit choice.
        $state=$this->State();unset($state['learning'][$room][$season]);
        foreach (['live','test'] as $scope) unset($state['runtimeCorrection'][$scope][$room]);
        $this->StoreState($state);
        $this->WriteAttributeString('Pending','');
        $effect=$this->ReadAttributeBoolean('TestMode')?'Testmodus: keine Gerätebefehle.':
            ($this->ReadAttributeBoolean('Active')&&$this->ReadAttributeString('Mode')==='auto'?
                'Die aktive Automatik verwendet die Vorlage beim nächsten Prüflauf, sobald diese Saison gilt.':'Die Vorlage gilt, sobald die Automatik mit dieser Saison aktiv ist.');
        $this->WriteAttributeString('Status',$this->Zones()[$room][0].' · '.self::SEASONS[$season].': in die Automatik übernommen. '.$effect.
            ($season==='summer'?' Die automatische Sommer-Heizpause mit 14 °C hat Vorrang.':' Wetter- und Abwesenheitsregeln bleiben wirksam.'));
    }
}
