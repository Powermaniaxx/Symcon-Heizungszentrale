<?php
require_once __DIR__ . '/helpers.php';

class SVHeatingCenter extends IPSModuleStrict
{
    private const SEASONS=['spring'=>'Frühjahr','summer'=>'Sommer','autumn'=>'Herbst','winter'=>'Winter','away'=>'Abwesenheit'];


    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger('RootID',0);
        $this->RegisterPropertyInteger('SeasonVariable',0);
        $this->RegisterPropertyString('CCUHost','');
        $this->RegisterPropertyString('CCUUser','');
        $this->RegisterPropertyString('CCUPassword','');
        $this->RegisterPropertyString('LivingGroup','');

        $this->RegisterPropertyString('ZoneConfiguration','{}');
        $this->RegisterPropertyInteger('OutsideVariable',0);
        $this->RegisterPropertyInteger('WeatherTimestampVariable',0);
        $this->RegisterPropertyInteger('PresenceVariable',0);
        $this->RegisterPropertyInteger('ForecastMinVariable',0);
        $this->RegisterPropertyInteger('ForecastMaxVariable',0);
        $this->RegisterPropertyInteger('CentralProfileVariable',0);
        $this->RegisterPropertyInteger('LegacyStateVariable',0);
        $this->RegisterPropertyString('LegacySourceMarker','');
        $this->RegisterPropertyString('LegacyScripts','[]');
        $this->RegisterAttributeString('Templates','{}');
        $this->RegisterAttributeString('ManualTemplates','{}');
        $this->RegisterAttributeString('ManualPresets','{}');
        $this->RegisterAttributeString('LastManualMode','winter');
        $this->RegisterAttributeString('ManualPresetName','');
        $this->RegisterAttributeString('LastActionReply','{}');
        $this->RegisterAttributeString('State','{}');
        $this->RegisterAttributeString('Pending','');
        $this->RegisterAttributeBoolean('Active',false);
        $this->RegisterAttributeBoolean('TestMode',true);
        $this->RegisterAttributeString('Mode','auto');
        $this->RegisterAttributeString('Status','Vorlagen bearbeiten. Neue Automatik ist noch nicht aktiviert.');
        $this->RegisterTimer('Refresh',60000,'SVHZ_Refresh($_IPS["TARGET"]);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if ($this->ReadAttributeString('Mode')!=='auto') $this->WriteAttributeString('LastManualMode',$this->ReadAttributeString('Mode'));
        if ($this->ReadAttributeBoolean('TestMode')) $this->WriteAttributeBoolean('Active',false);
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN')?constant('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN'):1);
        $this->SetTimerInterval('Refresh',60000);
        try {if(!$this->Configured()) {$this->WriteAttributeBoolean('Active',false);$this->WriteAttributeString('Status','Bitte CCU und Raumzuordnungen konfigurieren.');$this->SetStatus(104);return;} $this->SeedExisting(); $this->SetStatus(102);}
        catch (Throwable $e) {$this->WriteAttributeString('Status',get_class($e).': '.$e->getMessage().' ['.basename($e->getFile()).':'.$e->getLine().']'); $this->SetStatus(202);}
    }

    public function GetSetupMessage(): string
    {
        return $this->ReadAttributeString('Status');
    }

    public function GetCommunicationReport(): string
    {
        $decision=$this->Decision();$state=$this->State();
        $active=$this->ReadAttributeBoolean('Active');$testing=$this->ReadAttributeBoolean('TestMode');
        $season=$active&&!$testing?($state['currentSeason']??$decision['season']):$decision['season'];
        return json_encode(['version'=>'1.8.2','status'=>$this->ReadAttributeString('Status'),
            'control'=>['mode'=>$this->ReadAttributeString('Mode'),'active'=>$active,'testMode'=>$testing,
                'season'=>$season,'seasonLabel'=>self::SEASONS[$season],
                'manualSeason'=>$this->ReadAttributeString('LastManualMode'),'reason'=>$decision['reason']],
            'lastActionReply'=>json_decode($this->ReadAttributeString('LastActionReply'),true),
            'preparation'=>$this->View()['seasonPreparation']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }

    private function SendView(array $data): void
    {
        $this->UpdateVisualizationValue(json_encode($data,JSON_THROW_ON_ERROR));
    }

    public function GetConfigurationForm(): string
    {
        return json_encode(['elements'=>[
            ['type'=>'Label','caption'=>'Eine Kachel mit allen Räumen, fünf Saisonvorlagen und Zeitreglern.'],
            ['type'=>'ValidationTextBox','name'=>'ZoneConfiguration','caption'=>'Raumzuordnungen als JSON (siehe README)'],
            ['type'=>'SelectVariable','name'=>'OutsideVariable','caption'=>'Außentemperatur'],
            ['type'=>'SelectVariable','name'=>'WeatherTimestampVariable','caption'=>'Wetter-Messzeitpunkt als Unix-Zeit'],
            ['type'=>'SelectVariable','name'=>'PresenceVariable','caption'=>'Anwesenheit (optional)'],
            ['type'=>'SelectVariable','name'=>'ForecastMinVariable','caption'=>'Vorhersage Minimum (optional)'],
            ['type'=>'SelectVariable','name'=>'ForecastMaxVariable','caption'=>'Vorhersage Maximum (optional)'],
            ['type'=>'SelectVariable','name'=>'CentralProfileVariable','caption'=>'Bisheriges zentrales Profil (optional)'],
            ['type'=>'SelectVariable','name'=>'LegacyStateVariable','caption'=>'Statusvariable der bisherigen Automatik (nur Migration)'],
            ['type'=>'ValidationTextBox','name'=>'LegacyScripts','caption'=>'Weitere bisherige Skript-IDs als JSON (nur Migration)'],
            ['type'=>'ValidationTextBox','name'=>'LegacySourceMarker','caption'=>'Zusätzlicher Prüftext im bisherigen Automatikskript'],
            ['type'=>'SelectCategory','name'=>'RootID','caption'=>'Bestehende Planer-Kategorie'],
            ['type'=>'SelectVariable','name'=>'SeasonVariable','caption'=>'Jahreszeit aus Symcon'],
            ['type'=>'ValidationTextBox','name'=>'CCUHost','caption'=>'CCU-Adresse'],
            ['type'=>'ValidationTextBox','name'=>'LivingGroup','caption'=>'Heizgruppe für Zuordnung 1'],
            ['type'=>'ValidationTextBox','name'=>'CCUUser','caption'=>'CCU-Benutzer (optional)'],
            ['type'=>'PasswordTextBox','name'=>'CCUPassword','caption'=>'CCU-Passwort (optional)']
        ]],JSON_THROW_ON_ERROR);
    }

    public function GetVisualizationTile(): string
    {
        if (!$this->Configured()) return '<div style="padding:64px 24px;font:16px sans-serif">Bitte zuerst CCU-Adresse und Raumzuordnungen in der Instanz konfigurieren.</div>';
        return str_replace('/*__STATE__*/null',json_encode($this->View(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR),file_get_contents(__DIR__.'/tile.html'));
    }

    public function Refresh(): void
    {
        if (!$this->Configured()) return;
        $lock='SVHZ_'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1)) return;
        try {
            $this->SampleWeather();
            if ($this->ReadAttributeBoolean('Active')) $this->Control();
            $this->SendView($this->View());
        } catch (Throwable $e) {$this->Fail($e); $this->SendView($this->View());}
        finally {IPS_SemaphoreLeave($lock);}
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident!=='Do') throw new Exception('Unbekannte Bedienaktion.');
        $incoming=json_decode((string)$Value,true);
        $requestId=is_array($incoming)&&is_string($incoming['requestId']??null)?substr($incoming['requestId'],0,100):null;
        $p=[];
        $lock='SVHZ_'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock,1000)) {
            $this->SendView(['error'=>'Die letzte Aktion läuft noch. Bitte kurz warten.','requestId'=>$requestId]); return;
        }
        try {
            $p=json_decode((string)$Value,true,64,JSON_THROW_ON_ERROR);
            $action=$p['action']??'';
            $room=(int)($p['room']??1);
            if ($room===4) $room=3;
            if (!isset($this->Zones()[$room])) throw new Exception('Unbekannter Raum.');
            $season=(string)($p['season']??'winter');
            if (!isset(self::SEASONS[$season])) throw new Exception('Unbekannte Saisonvorlage.');
            switch ($action) {
                case 'testMode':
                    if (!isset($p['enabled'])||!is_bool($p['enabled'])) throw new Exception('Ungültiger Testmodus.');
                    $this->WriteAttributeBoolean('Active',false);
                    $this->WriteAttributeBoolean('TestMode',$p['enabled']);
                    $this->WriteAttributeString('Pending','');
                    $this->WriteAttributeString('Status',$p['enabled']?'Testmodus aktiv: Diese Zentrale sendet keine Gerätebefehle. Bestehende Steuerungen laufen weiter.':'Echtbetrieb vorbereitet, aber pausiert. Erst eine bestätigte Übertragung oder Aktivierung sendet Gerätebefehle.'); break;
                case 'simulate': $this->Simulate(); break;
                case 'winterAutumn':
                case 'winterAutumnStart': $this->StartWinterAutumn(); break;
                case 'winterAutumnRoom': $this->ReadWinterAutumnRoom($room,(string)($p['jobId']??'')); break;
                case 'winterAutumnCommit': $this->CommitWinterAutumn((string)($p['jobId']??'')); break;
                case 'presetSave': $this->SaveManualPreset($room,$season,$p); break;
                case 'presetLoad': $this->LoadManualPreset((string)($p['presetId']??'')); break;
                case 'refresh': $this->SampleWeather(); break;
                case 'import': $this->ImportRoom($room); break;
                case 'importAll':
                    $readErrors=[];
                    foreach (array_keys($this->Zones()) as $targetRoom) {
                        try {$this->ImportRoom($targetRoom);}
                        catch (Throwable $e) {$readErrors[]=$this->Zones()[$targetRoom][0].': '.$e->getMessage();}
                    }
                    $this->WriteAttributeString('Status',$readErrors?'Einlesen abgeschlossen, aber nicht für alle Räume: '.implode(' | ',$readErrors):'Alle Räume eingelesen. Fünf Vorlagen je Raum sind bereit; vorhandene Bearbeitungen bleiben erhalten.'); break;
                case 'save': $this->SaveTemplate($room,$season,$p); break;
                case 'preview': $this->Preview($room,$season); break;
                case 'publish': $this->Publish($room,$season); break;
                case 'activate': $this->Activate(); break;
                case 'pause':
                    $this->WriteAttributeBoolean('Active',false);
                    $this->WriteAttributeString('Status','Automatik pausiert. Die zuletzt übertragenen Gerätepläne laufen weiter.'); break;
                case 'mode':
                    $mode=(string)($p['mode']??'');
                    if ($mode!=='auto'&&!isset(self::SEASONS[$mode])) throw new Exception('Unbekannter Modus.');
                    $this->WriteAttributeString('Mode',$mode);
                    if ($mode!=='auto') $this->WriteAttributeString('LastManualMode',$mode);
                    $this->WriteAttributeString('Pending','');
                    if ($this->ReadAttributeBoolean('Active')) $this->Control(); break;
                default: throw new Exception('Unbekannte Aktion.');
            }
            if($requestId!==null) $this->WriteAttributeString('LastActionReply',json_encode(['requestId'=>$requestId,'action'=>$p['action']??'','success'=>true,'at'=>time()],JSON_THROW_ON_ERROR));
            $view=$this->View();$view['requestId']=$requestId;$this->SendView($view);
        } catch (Throwable $e) {
            if ($this->ReadAttributeBoolean('Active')) $this->WriteAttributeBoolean('Active',false);
            $this->WriteAttributeString('Status','Fehler: '.$e->getMessage());
            if($requestId!==null) $this->WriteAttributeString('LastActionReply',json_encode(['requestId'=>$requestId,'action'=>$p['action']??'','success'=>false,'error'=>$e->getMessage(),'at'=>time()],JSON_THROW_ON_ERROR));
            $state=$this->State();
            if(!empty($p['jobId'])&&($state['seasonPreparation']['id']??'')===$p['jobId']&&($state['seasonPreparation']['status']??'')==='reading') {
                $state['seasonPreparation']['status']='error';$state['seasonPreparation']['error']=$e->getMessage();$this->StoreState($state);
            }
            $view=$this->View(); $view['error']=$e->getMessage();$view['requestId']=$requestId; $this->SendView($view);
        } finally {IPS_SemaphoreLeave($lock);}
    }


    private function Zones(): array
    {
        $zones=json_decode($this->ReadPropertyString('ZoneConfiguration'),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($zones)||!$zones) return [];
        // Stable logical keys keep existing templates and saved profiles compatible.
        $expected=range(1,8);$keys=array_keys($zones);sort($keys);
        if ($keys!==$expected) throw new Exception('Raumzuordnungen benötigen die Schlüssel 1 bis 8. Siehe README.');
        foreach($zones as $key=>$zone) {
            if (!is_array($zone)||array_keys($zone)!==range(0,4)||!is_string($zone[0])||trim($zone[0])==='') throw new Exception('Ungültige Raumzuordnung '.$key);
            foreach(array_slice($zone,1) as $id) if (!is_int($id)||$id<=0) throw new Exception('Ungültige Objekt-ID in Zuordnung '.$key);
        }
        ksort($zones);return $zones;
    }

    private function Configured(): bool
    {
        $host=$this->ReadPropertyString('CCUHost');
        return preg_match('/^[a-zA-Z0-9.-]+$/D',$host)===1&&$this->ReadPropertyString('LivingGroup')!==''&&count($this->Zones())===8;
    }

    private function Templates(): array {return json_decode($this->ReadAttributeString('Templates'),true,64,JSON_THROW_ON_ERROR);}
    private function Plan(array $templates,int $room,string $season): ?array {
        $room=$room===4?3:$room;
        if ($this->ReadAttributeString('Mode')!=='auto') {
            $manual=json_decode($this->ReadAttributeString('ManualTemplates'),true,64,JSON_THROW_ON_ERROR);
            if (!empty($manual[$room][$season]['plan'])) return $manual[$room][$season]['plan'];
        }
        return $templates[$room][$season]['plan']??null;
    }
    private function WorkingTemplates(): array {
        $templates=$this->Templates();
        if ($this->ReadAttributeString('Mode')!=='auto') {
            $manual=json_decode($this->ReadAttributeString('ManualTemplates'),true,64,JSON_THROW_ON_ERROR);
            foreach($manual as $room=>$seasons) foreach($seasons as $season=>$entry) if(!empty($entry['plan'])) $templates[$room][$season]=$entry;
        }
        return $templates;
    }
    private function State(): array {return json_decode($this->ReadAttributeString('State'),true,64,JSON_THROW_ON_ERROR);}
    private function StoreState(array $state): void {$this->WriteAttributeString('State',json_encode($state,JSON_THROW_ON_ERROR));}
    private function Hash(array $plan): string {return hash('sha256',json_encode($plan,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
    private function Num(int $id): ?float {return IPS_VariableExists($id)?(float)GetValue($id):null;}

    private function SeedExisting(): void
    {
        if ($this->ReadPropertyInteger('RootID')===0) return;
        $templates=$this->Templates();
        $warnings=[];
        $store=@IPS_GetObjectIDByIdent('Templates',$this->ReadPropertyInteger('RootID'));
        if ($store===false) return;
        foreach ($this->Zones() as $room=>$zone) {
            if (isset($templates[$room])) continue;
            $old=@IPS_GetObjectIDByIdent('Room'.$room,$store);
            $source=[];
            if ($old!==false) foreach (IPS_GetChildrenIDs($old) as $id) {
                if (IPS_GetObject($id)['ObjectType']!==4) continue;
                if (!preg_match('/^Geräteprofil ([123]) · (.+)$/u',IPS_GetName($id),$m)) continue;
                $date=DateTimeImmutable::createFromFormat('!d.m. H:i',$m[2]);
                $stamp=$date?$date->getTimestamp():0;
                if (!isset($source[$m[1]])||$source[$m[1]]['time']<$stamp) $source[$m[1]]=['time'=>$stamp,'id'=>$id];
            }
            $plans=[]; $errors=[];
            foreach ($source as $profile=>$entry) {
                try {$plans[$profile]=scValidatePlan(hvFromEvent($entry['id']));}
                catch (Throwable $e) {
                    $errors[$profile]='Alte Vorlage '.$entry['id'].' konnte nicht übernommen werden: '.$e->getMessage();
                    $warnings[]=$zone[0].' · Geräteprofil '.$profile;
                }
            }
            foreach (['spring'=>2,'summer'=>1,'autumn'=>2,'winter'=>2,'away'=>3] as $season=>$profile) {
                $plan=$plans[$profile]??null;
                $templates[$room][$season]=['plan'=>$plan,'origin'=>$errors[$profile]??($plan?'Bestandsimport':'Noch nicht eingelesen'),'updated'=>time()];
            }
        }
        $this->WriteAttributeString('Templates',json_encode($templates,JSON_THROW_ON_ERROR));
        if ($warnings) $this->WriteAttributeString('Status','Kachel bereit. Unvollständige alte Vorlagen: '.implode(', ',$warnings).'. Bitte Gerätepläne einlesen; vorhandene vollständige Vorlagen bleiben erhalten.');
        elseif (preg_match('/^(?:Exception|TypeError|Error|ValueError):/',$this->ReadAttributeString('Status'))) $this->WriteAttributeString('Status','Kachel bereit. Fehlende Vorlagen bitte über Gerätepläne einlesen laden.');
    }

    private function Address(int $room): string
    {
        global $ccuUrl,$ccuUser,$ccuPassword,$hpTimeout;
        $host=$this->ReadPropertyString('CCUHost');
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D',$host)) throw new Exception('Ungültige CCU-Adresse.');
        $ccuUrl='http://'.$host.($room===1?':9292/groups':':2010/');
        $ccuUser=$this->ReadPropertyString('CCUUser'); $ccuPassword=$this->ReadPropertyString('CCUPassword'); $hpTimeout=15;
        return $room===1?$this->ReadPropertyString('LivingGroup'):IPS_GetProperty($this->Zones()[$room][1],'Address');
    }

    private function ImportRoom(int $room): void
    {
        $address=$this->Address($room);
        $values=hpRpc('getParamset',[$address,'MASTER']);
        $this->Backup(['address'=>$address,'MASTER'=>$values],'Import-'.$room);
        $templates=$this->Templates();
        $old=[]; for($profile=1;$profile<=3;$profile++) $old[$profile]=hvFromDevice($values,$profile);
        foreach (['spring'=>2,'summer'=>1,'autumn'=>2,'winter'=>2,'away'=>3] as $season=>$profile) {
            // Erneutes Einlesen überschreibt keine bereits bearbeiteten Vorlagen.
            if (!empty($templates[$room][$season]['plan'])) continue;
            $templates[$room][$season]=['plan'=>$old[$profile],'origin'=>'Geräteprofil '.$profile,'updated'=>time()];
        }
        $this->WriteAttributeString('Templates',json_encode($templates,JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('Status',$this->Zones()[$room][0].': fünf Vorlagen bereit. Frühjahr und Herbst beginnen als Kopie des bisherigen Winterplans.');
    }

    private function StartWinterAutumn(): void
    {
        if (!$this->ReadAttributeBoolean('TestMode')) throw new Exception('Winter und Herbst bitte zuerst im Testmodus vorbereiten.');
        $state=$this->State();
        $state['seasonPreparation']=['id'=>bin2hex(random_bytes(12)),'status'=>'reading','started'=>time(),
            'templatesHash'=>hash('sha256',$this->ReadAttributeString('Templates')),'host'=>$this->ReadPropertyString('CCUHost'),'sources'=>[]];
        $this->StoreState($state);
        $this->WriteAttributeString('Status','Winter/Herbst: Einlesen wird vorbereitet. Bisherige Vorlagen bleiben bis zum Abschluss erhalten.');
    }

    private function PreparationJob(string $id): array
    {
        if (!$this->ReadAttributeBoolean('TestMode')) throw new Exception('Die Vorbereitung erfordert den Testmodus.');
        $job=$this->State()['seasonPreparation']??null;
        if (!$job||$job['id']!==$id) throw new Exception('Dieser Einlesevorgang ist nicht mehr aktuell. Bitte die Ansicht aktualisieren.');
        if (($job['host']??'')!==$this->ReadPropertyString('CCUHost')) throw new Exception('Die CCU-Adresse hat sich geändert. Bitte das Einlesen neu starten.');
        if (time()-$job['started']>900) throw new Exception('Der Einlesevorgang ist abgelaufen. Bitte neu starten.');
        return $job;
    }

    private function ReadWinterAutumnRoom(int $room,string $id): void
    {
        $job=$this->PreparationJob($id);
        if ($job['status']!=='reading') throw new Exception('Der Einlesevorgang ist bereits beendet.');
        if (isset($job['sources'][$room])) return;
        try {
            $state=$this->State();$address=$this->Address($room);
            global $ccuUrl,$hpTimeout;
            if($room===1) {
                $ccuUrl='http://'.$this->ReadPropertyString('CCUHost').':2010/';
                $address=IPS_GetProperty($this->Zones()[1][1],'Address');
            }
            // Pro Aufruf nur ein Gerät, deutlich unter der Skriptlaufzeitgrenze.
            $hpTimeout=10;
            $master=hpRpc('getParamset',[$address,'MASTER']);
            $winter=hvFromDevice($master,2);$origin=$room===1?'Homematic physisches Quellgerät · Geräteprofil 2':'Homematic Geräteprofil 2';
            if(isset($state['sent'][$room][2])) {
                if(isset($state['winterOriginal'][$room]['plan'])) $winter=$state['winterOriginal'][$room]['plan'];
                elseif(!empty($state['migrationBackup'])&&is_readable($state['migrationBackup'])) {
                    $migration=json_decode(file_get_contents($state['migrationBackup']),true,64,JSON_THROW_ON_ERROR);
                    if(empty($migration['devices'][$room]['MASTER'])||($migration['devices'][$room]['address']??'')!==$address) throw new Exception('Ursprünglicher Winterplan dieser Quelle fehlt in der Sicherung.');
                    $winter=hvFromDevice($migration['devices'][$room]['MASTER'],2);
                } else throw new Exception('Ursprünglichen Winterplan aus Sicherung wiederherstellen; der Normal-Speicher wurde bereits von dieser Zentrale beschrieben.');
                $origin='Gesicherter ursprünglicher Homematic-Winterplan';
            }
            $winter=scValidatePlan($winter);
            $backup=$this->Backup(['address'=>$address,'MASTER'=>$master,'winter'=>$winter,'origin'=>$origin],'Winter-Quelle-'.$room);
            $state=$this->State();
            $state['seasonPreparation']['sources'][$room]=['address'=>$address,'winter'=>$winter,'origin'=>$origin,'backup'=>$backup];
            $this->StoreState($state);
            $this->WriteAttributeString('Status','Winter/Herbst: '.count($state['seasonPreparation']['sources']).' von 7 Räumen eingelesen · '.$this->Zones()[$room][0]);
        } catch(Throwable $e) {throw new Exception($this->Zones()[$room][0].': '.$e->getMessage(),0,$e);}
    }

    private function CommitWinterAutumn(string $id): void
    {
        $job=$this->PreparationJob($id);
        if ($job['status']==='done') return;
        if ($job['status']!=='reading') throw new Exception('Einlesen wurde abgebrochen. Bisherige Vorlagen sind unverändert.');
        if(hash('sha256',$this->ReadAttributeString('Templates'))!==$job['templatesHash']) throw new Exception('Eine Automatikvorlage wurde während des Einlesens bearbeitet. Sie wurde nicht überschrieben; bitte neu starten.');
        $templates=$this->Templates();$updates=[];
        foreach($this->Zones() as $room=>$zone) {
            if($room===4) continue;
            if(empty($job['sources'][$room])) throw new Exception('Noch nicht eingelesen: '.$zone[0]);
            $source=$job['sources'][$room];
            try {$winter=scValidatePlan($source['winter']);$autumn=scAutumnFromWinter($winter);}
            catch(Throwable $e) {throw new Exception($zone[0].': '.$e->getMessage(),0,$e);}
            $updates[$room]=['winter'=>['plan'=>$winter,'origin'=>$source['origin'],'updated'=>time()],
                'autumn'=>['plan'=>$autumn,'origin'=>'Aus Winter: wärmste Phasen 1 °C kühler, Beginn/Ende bis zu 30 Minuten verkürzt','updated'=>time()]];
        }
        $backup=$this->Backup(['templates'=>$templates,'sources'=>$job['sources']],'Winter-Herbst');
        $state=$this->State();
        foreach($updates as $room=>$seasons) {
            foreach($seasons as $season=>$entry) $templates[$room][$season]=$entry;
            $state['winterOriginal'][$room]=['plan'=>$job['sources'][$room]['winter'],'origin'=>$job['sources'][$room]['origin']];
        }
        $state['winterAutumnSetup']=['at'=>time(),'backup'=>$backup];
        $state['seasonPreparation']['status']='done';$state['seasonPreparation']['finished']=time();
        $this->WriteAttributeString('Templates',json_encode($templates,JSON_THROW_ON_ERROR));$this->StoreState($state);
        $this->WriteAttributeString('Pending','');
        $this->WriteAttributeString('Status','Fertig: Winter aus Homematic übernommen und Herbst für alle konfigurierten Räume abgeleitet. Keine Gerätebefehle gesendet; manuelle Profile bleiben erhalten.');
    }
    private function SaveTemplate(int $room,string $season,array $payload): void
    {
        $templates=$this->WorkingTemplates();
        $old=$templates[$room][$season]['plan']??null;
        if (($payload['revision']??'')!==($old?$this->Hash($old):'')) throw new Exception('Diese Vorlage wurde inzwischen verändert. Bitte neu laden.');
        $plan=scValidatePlan($payload['plan']??null);
        $this->Backup(['room'=>$room,'season'=>$season,'previous'=>$templates[$room][$season]??null],'Vorlage');
        $templates[$room][$season]=['plan'=>$plan,'origin'=>'Bearbeitet','updated'=>time()];
        $this->WriteAttributeString($this->ReadAttributeString('Mode')==='auto'?'Templates':'ManualTemplates',json_encode($templates,JSON_THROW_ON_ERROR));
        if ($this->ReadAttributeString('Mode')!=='auto') $this->WriteAttributeString('ManualPresetName','');
        $this->WriteAttributeString('Pending','');
        $this->WriteAttributeString('Status',$this->Zones()[$room][0].' · '.self::SEASONS[$season].': Vorlage gespeichert.');
        if ($this->ReadAttributeBoolean('Active')) $this->WriteAttributeString('Status',$this->Zones()[$room][0].': Vorlage gespeichert; die aktive Automatik übernimmt sie beim nächsten Prüflauf.');
    }

    private function SaveManualPreset(int $room,string $season,array $payload): void
    {
        $mode=$this->ReadAttributeString('Mode');
        if ($mode==='auto') throw new Exception('Zusätzliche Profile werden im manuellen Modus gespeichert.');
        $name=trim((string)($payload['name']??''));
        if ($name===''||strlen($name)>120||preg_match('/[\x00-\x1F]/',$name)) throw new Exception('Bitte einen Profilnamen mit höchstens 120 Zeichen eingeben.');
        $templates=$this->WorkingTemplates();
        if (isset($payload['plan'])) {
            $previous=$templates[$room][$season]['plan']??null;
            if (($payload['revision']??'')!==($previous?$this->Hash($previous):'')) throw new Exception('Der Plan wurde inzwischen verändert. Bitte neu laden.');
            $templates[$room][$season]=['plan'=>scValidatePlan($payload['plan']),'origin'=>'Manuell bearbeitet','updated'=>time()];
        }
        $snapshot=[];
        foreach($this->Zones() as $roomID=>$zone) {
            if ($roomID===4) continue;
            foreach(self::SEASONS as $key=>$label) {
                if (empty($templates[$roomID][$key]['plan'])) throw new Exception('Vorlage fehlt: '.$zone[0].' · '.$label.'. Zuerst alle Räume einlesen.');
                scValidatePlan($templates[$roomID][$key]['plan']);
                $snapshot[$roomID][$key]=$templates[$roomID][$key];
            }
        }
        $presets=json_decode($this->ReadAttributeString('ManualPresets'),true,64,JSON_THROW_ON_ERROR);
        $id='';foreach($presets as $key=>$preset) if($preset['name']===$name) $id=(string)$key;
        if ($id==='') $id=bin2hex(random_bytes(8));
        $this->Backup(['previousPreset'=>$presets[$id]??null,'manualTemplates'=>$this->ReadAttributeString('ManualTemplates')],'Manuelles-Profil');
        $presets[$id]=['name'=>$name,'mode'=>$mode,'templates'=>$snapshot,'updated'=>time()];
        $this->WriteAttributeString('ManualPresets',json_encode($presets,JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('ManualTemplates',json_encode($snapshot,JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('ManualPresetName',$name);
        $this->WriteAttributeString('Pending','');
        $this->WriteAttributeString('Status','Manuelles Profil „'.$name.'“ für alle konfigurierten Räume gespeichert. Automatikpläne bleiben erhalten.'.($this->ReadAttributeBoolean('Active')?' Die laufende Steuerung übernimmt Änderungen beim nächsten Prüflauf.':''));
    }

    private function LoadManualPreset(string $id): void
    {
        if ($this->ReadAttributeString('Mode')==='auto') throw new Exception('Zum Laden eines manuellen Profils zuerst Manuell wählen.');
        $presets=json_decode($this->ReadAttributeString('ManualPresets'),true,64,JSON_THROW_ON_ERROR);
        if (!isset($presets[$id])) throw new Exception('Bitte ein gespeichertes manuelles Profil wählen.');
        $preset=$presets[$id];
        if (!isset(self::SEASONS[$preset['mode']])) throw new Exception('Profil enthält eine unbekannte Saison.');
        foreach($this->Zones() as $room=>$zone) if($room!==4) foreach(self::SEASONS as $season=>$label) scValidatePlan($preset['templates'][$room][$season]['plan']??null);
        $this->Backup(['manualTemplates'=>$this->ReadAttributeString('ManualTemplates'),'mode'=>$this->ReadAttributeString('Mode')],'Vor-Profilladen');
        $this->WriteAttributeString('ManualTemplates',json_encode($preset['templates'],JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('Mode',$preset['mode']); $this->WriteAttributeString('LastManualMode',$preset['mode']);
        $this->WriteAttributeString('ManualPresetName',$preset['name']);
        $this->WriteAttributeString('Pending','');
        $this->WriteAttributeString('Status','Manuelles Profil „'.$preset['name'].'“ für alle Räume geladen · '.self::SEASONS[$preset['mode']].'.'.($this->ReadAttributeBoolean('Active')?' Die laufende Steuerung übernimmt es beim nächsten Prüflauf.':''));
    }

    private function Preview(int $room,string $season): void
    {
        $template=$this->Plan($this->Templates(),$room,$season);
        if (!$template) throw new Exception('Zuerst Pläne einlesen oder eine Vorlage speichern.');
        if ($this->ReadAttributeBoolean('TestMode')) {
            scValidatePlan($template);
            $this->WriteAttributeString('Pending',json_encode(['room'=>$room,'season'=>$season,'hash'=>$this->Hash($template),'time'=>time(),'before'=>[]],JSON_THROW_ON_ERROR));
            $this->WriteAttributeString('Status','Testvorschau bereit. Es werden keine Gerätebefehle gesendet.'); return;
        }
        $slot=$season==='away'?3:2; $snapshots=[];
        foreach($room===3?[3,4]:[$room] as $deviceRoom) {
            $address=$this->Address($deviceRoom);
            $description=hpRpc('getParamsetDescription',[$address,'MASTER']);
            $values=hpRpc('getParamset',[$address,'MASTER']);
            $patch=hvToDevice(scValidatePlan($template),$slot,$description);
            $before=[]; foreach($patch as $key=>$value) $before[$key]=$values[$key]??null;
            $snapshots[$deviceRoom]=$before;
        }
        $before=$room===3?['targets'=>$snapshots]:$snapshots[$room];
        $this->WriteAttributeString('Pending',json_encode(['room'=>$room,'season'=>$season,'hash'=>$this->Hash($template),'time'=>time(),'before'=>$before],JSON_THROW_ON_ERROR));
        $this->WriteAttributeString('Status','Geprüft: '.$this->Zones()[$room][0].' · '.self::SEASONS[$season].' → '.($slot===3?'Abwesenheit':'Normal').'.');
    }

    private function Publish(int $room,string $season): void
    {
        if ($this->ReadAttributeBoolean('Active')) throw new Exception('Für eine manuelle Übertragung zuerst die Automatik pausieren.');
        $pending=json_decode($this->ReadAttributeString('Pending'),true);
        $plan=$this->Plan($this->Templates(),$room,$season);
        if (!$plan||!is_array($pending)||$pending['room']!==$room||$pending['season']!==$season
            ||$pending['hash']!==$this->Hash($plan)||time()-$pending['time']>600) throw new Exception('Die Übertragung bitte erneut prüfen.');
        if ($this->ReadAttributeBoolean('TestMode')) {
            $this->Simulate($room,$season); $this->WriteAttributeString('Pending',''); return;
        }
        if ($room===3) {
            if (!isset($pending['before']['targets'][3],$pending['before']['targets'][4])) throw new Exception('Bitte beide gemeinsamer Raum-Thermostate erneut prüfen.');
            // Vor dem ersten Schreiben prüfen, ob beide Zielpläne noch unverändert sind.
            foreach([3,4] as $deviceRoom) {
                $current=hpRpc('getParamset',[$this->Address($deviceRoom),'MASTER']);
                foreach($pending['before']['targets'][$deviceRoom] as $key=>$value) if (!array_key_exists($key,$current)||$current[$key]!=$value) throw new Exception('gemeinsamer Raum-Plan wurde inzwischen verändert. Bitte erneut prüfen.');
            }
            foreach([3,4] as $deviceRoom) $this->Transfer($deviceRoom,$season,$pending['before']['targets'][$deviceRoom]);
        } else $this->Transfer($room,$season,$pending['before']);
        $this->WriteAttributeString('Status',$this->Zones()[$room][0].': '.self::SEASONS[$season].' übertragen und von der CCU zurückgelesen.');
    }

    private function Transfer(int $room,string $season,?array $expected=null,?array $runtimePlan=null): void
    {
        if ($this->ReadAttributeBoolean('TestMode')) throw new Exception('Testmodus: Geräteübertragung gesperrt.');
        $plan=$runtimePlan??$this->Plan($this->Templates(),$room,$season);
        if (!$plan) throw new Exception('Vorlage fehlt: '.$this->Zones()[$room][0].' · '.self::SEASONS[$season]);
        $address=$this->Address($room); $slot=$season==='away'?3:2;
        $description=hpRpc('getParamsetDescription',[$address,'MASTER']);
        $current=hpRpc('getParamset',[$address,'MASTER']);
        $patch=hvToDevice(scValidatePlan($plan),$slot,$description); $before=[];
        foreach($patch as $key=>$value) {
            if (!array_key_exists($key,$current)) throw new Exception('Profilparameter fehlt: '.$key);
            $before[$key]=$current[$key];
        }
        if ($expected!==null&&$expected!=$before) throw new Exception('Geräteplan wurde inzwischen verändert. Bitte erneut prüfen.');
        $backup=$this->Backup(['address'=>$address,'MASTER'=>$current],'Geraeteplan-'.$room);
        $this->WriteAttributeString('Pending','');
        if ($before!=$patch) hpRpc('putParamset',[$address,'MASTER',$patch]);
        $after=hpRpc('getParamset',[$address,'MASTER']);
        foreach($patch as $key=>$value) if (!isset($after[$key])||abs($after[$key]-$value)>0.0001) throw new Exception('Rücklesen weicht ab. Sicherung: '.$backup);
        $state=$this->State(); $state['sent'][$room][$slot]=['season'=>$season,'hash'=>$this->Hash($plan),'at'=>time()]; $this->StoreState($state);
    }

    private function Backup(array $data,string $label): string
    {
        $dir=IPS_GetKernelDir().'scripts'.DIRECTORY_SEPARATOR.'Heizungszentrale-Sicherungen';
        if (!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) throw new Exception('Sicherungsverzeichnis kann nicht erstellt werden.');
        $path=$dir.DIRECTORY_SEPARATOR.date('Ymd-His').'-'.$label.'-'.uniqid().'.json';
        if (file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new Exception('Sicherung fehlgeschlagen.');
        return $path;
    }

    private function SampleWeather(): void
    {
        $state=$this->State();
        $outside=$this->Num($this->ReadPropertyInteger('OutsideVariable'));$measured=IPS_VariableExists($this->ReadPropertyInteger('WeatherTimestampVariable'))?(int)GetValue($this->ReadPropertyInteger('WeatherTimestampVariable')):0;
        $state['warmWeather']=scWarmWeatherTick($state['warmWeather']??[],$outside,$measured,time());
        $this->StoreState($state);
        if (!IPS_VariableExists($this->ReadPropertyInteger('OutsideVariable'))||!IPS_VariableExists($this->ReadPropertyInteger('WeatherTimestampVariable'))) return;
        $timestamp=(int)GetValue($this->ReadPropertyInteger('WeatherTimestampVariable')); $value=(float)GetValue($this->ReadPropertyInteger('OutsideVariable')); $now=time();
        if ($timestamp>$now+300||$now-$timestamp>10800||$value< -40||$value>55||!is_finite($value)) return;
        $state=$this->State(); $samples=$state['weather']??[];
        $last=$samples?end($samples):null;
        if (!$last||($timestamp>$last[0]&&$timestamp-$last[0]>=1800)) $samples[]=[$timestamp,$value];
        $state['weather']=array_values(array_filter($samples,static fn($s)=>$s[0]>=$now-7*86400));
        $this->StoreState($state);
    }

    private function Decision(): array
    {
        $decision=$this->SeasonDecision();
        // Im automatischen Sommer gilt die Heizpause auch bei Abwesenheit.
        if (IPS_VariableExists($this->ReadPropertyInteger('PresenceVariable'))&&!GetValue($this->ReadPropertyInteger('PresenceVariable'))&&!($this->ReadAttributeString('Mode')==='auto'&&$decision['season']==='summer')) return ['season'=>'away','reason'=>'Abwesenheit hat Vorrang.'];
        return $decision;
    }

    private function SeasonDecision(): array
    {
        $mode=$this->ReadAttributeString('Mode');
        if ($mode!=='auto') return ['season'=>$mode,'reason'=>'Manuell gewählt.'];
        $month=(int)(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('n');
        $base=$month>=3&&$month<=5?'spring':($month>=6&&$month<=8?'summer':($month>=9&&$month<=11?'autumn':'winter'));
        $baseReason='Kalendersaison: '.self::SEASONS[$base].'.';
        $seasonID=$this->ReadPropertyInteger('SeasonVariable');
        if (IPS_VariableExists($seasonID)) {
            $value=GetValue($seasonID);
            $selected=scSeasonName($value);
            if ($selected===null&&function_exists('GetValueFormatted')) $selected=scSeasonName(GetValueFormatted($seasonID));
            if ($selected!==null) {$base=$selected; $baseReason='Jahreszeit aus Symcon ('.$seasonID.'): '.self::SEASONS[$base].'.';}
            else $baseReason='Jahreszeit-Variable '.$seasonID.' enthält keine eindeutig erkannte Jahreszeit; '.$baseReason;
        }
        $state=$this->State(); $samples=$state['weather']??[];
        $fresh=IPS_VariableExists($this->ReadPropertyInteger('WeatherTimestampVariable'))&&time()-(int)GetValue($this->ReadPropertyInteger('WeatherTimestampVariable'))<=10800&&(int)GetValue($this->ReadPropertyInteger('WeatherTimestampVariable'))<=time()+300;
        if (!$fresh) return ['season'=>$state['lastSeason']??$base,'reason'=>'Wetterdaten fehlen oder sind veraltet; letzte Saison bleibt erhalten.'];
        if (in_array($base,['spring','autumn'],true)) {
            $cold=scWeatherMean($samples,72,12,66,time());
            $warm=scWeatherMean($samples,120,20,114,time());
            if ($cold!==null&&$cold<12) return ['season'=>'winter','reason'=>'Anhaltend kalt: 72-Stunden-Mittel unter 12 °C.'];
            if ($warm!==null&&$warm>15) return ['season'=>'summer','reason'=>'Anhaltend warm: 120-Stunden-Mittel über 15 °C.'];
            if (($state['autoSeason']??'')==='winter'&&$cold!==null&&$cold<13) return ['season'=>'winter','reason'=>'Kältephase bleibt aktiv, bis das 72-Stunden-Mittel 13 °C erreicht.'];
            if (($state['autoSeason']??'')==='summer'&&$warm!==null&&$warm>14) return ['season'=>'summer','reason'=>'Wärmephase bleibt aktiv, bis das 120-Stunden-Mittel auf 14 °C fällt.'];
        }
        return ['season'=>$base,'reason'=>$baseReason.' Wetterumschaltung in Frühjahr/Herbst erst mit ausreichend frischen Messwerten.'];
    }

    private function Activate(): void
    {
        if (!$this->Configured()) throw new Exception('Bitte zuerst die Raumzuordnungen konfigurieren.');
        if ($this->ReadAttributeBoolean('TestMode')) {
            $this->SampleWeather(); $this->Simulate(); $this->WriteAttributeBoolean('Active',true); return;
        }
        $templates=$this->Templates();
        foreach($this->Zones() as $room=>$zone) foreach(self::SEASONS as $season=>$name) {
            if (!$this->Plan($templates,$room,$season)) throw new Exception('Noch nicht eingelesen: '.$zone[0].'. Zuerst für alle Räume die Pläne laden.');
            scValidatePlan($this->Plan($templates,$room,$season));
        }
        // Alles vorab lesen und prüfen, bevor eine laufende Steuerung angehalten wird.
        $inventory=[];
        foreach($this->Zones() as $room=>$zone) {
            $address=$this->Address($room);
            $description=hpRpc('getParamsetDescription',[$address,'MASTER']);
            foreach(self::SEASONS as $season=>$name) hvToDevice($this->Plan($templates,$room,$season),$season==='away'?3:2,$description);
            $valuesDescription=hpRpc('getParamsetDescription',[$address,'VALUES']);
            if (!isset($valuesDescription['ACTIVE_PROFILE'])) throw new Exception('Ziel unterstützt ACTIVE_PROFILE nicht: '.$zone[0]);
            $inventory[$room]=['address'=>$address,'MASTER'=>hpRpc('getParamset',[$address,'MASTER']),
                'VALUES'=>hpRpc('getParamset',[$address,'VALUES'])];
        }
        $state=$this->State();
        if (!empty($state['migrationBackup'])) {
            foreach($state['legacyTimers']??[] as $script=>$seconds) if (IPS_ScriptExists((int)$script)&&IPS_GetScriptTimer((int)$script)>0) throw new Exception('Eine bisherige Heizungssteuerung wurde wieder gestartet. Zuerst prüfen.');
            foreach($state['legacyEvents']??[] as $event=>$enabled) if (IPS_EventExists((int)$event)&&IPS_GetEvent((int)$event)['EventActive']) throw new Exception('Ein bisheriger Heizungsauslöser wurde wieder aktiviert. Zuerst prüfen.');
            if ($this->ReadPropertyInteger('CentralProfileVariable')>0&&IPS_GetVariable($this->ReadPropertyInteger('CentralProfileVariable'))['VariableCustomAction']!==1) throw new Exception('Die alte Profilbedienung wurde wieder aktiviert. Zuerst prüfen.');
            $state['managedProfile']=$this->ReadPropertyInteger('CentralProfileVariable')>0?(int)GetValue($this->ReadPropertyInteger('CentralProfileVariable')):0; $state['modeInitialized']=[]; $this->StoreState($state);
            $this->WriteAttributeBoolean('Active',true); $this->SampleWeather(); $this->Control(); return;
        }
        if ($this->ReadPropertyInteger('LegacyStateVariable')===0) {
            $backup=$this->Backup(['devices'=>$inventory,'templates'=>$templates],'Aktivierung');
            $state=$this->State();$state['migrationBackup']=$backup;$state['standalone']=true;
            $state['sent']=[];$state['modeInitialized']=[];$this->StoreState($state);
            $this->WriteAttributeBoolean('Active',true);$this->SampleWeather();$this->Control();return;
        }
        $oldScript=IPS_GetParent($this->ReadPropertyInteger('LegacyStateVariable'));
        if (!IPS_ScriptExists($oldScript)) throw new Exception('Bisherige Saisonautomatik wurde nicht gefunden.');
        $source=IPS_GetScriptContent($oldScript);
        if (strpos($source,'HeizungsSaisonState')===false||($this->ReadPropertyString('LegacySourceMarker')!==''&&strpos($source,$this->ReadPropertyString('LegacySourceMarker'))===false)) throw new Exception('Bisherige Saisonautomatik hat sich geändert; Umstellung zunächst prüfen.');
        $scripts=array_values(array_unique(array_merge([$oldScript],json_decode($this->ReadPropertyString('LegacyScripts'),true,64,JSON_THROW_ON_ERROR))));
        $timers=[]; $events=[];
        foreach($scripts as $script) if (IPS_ScriptExists($script)) {
            $timers[$script]=IPS_GetScriptTimer($script);
            foreach(IPS_GetScriptEventList($script) as $event) $events[$event]=IPS_GetEvent($event)['EventActive'];
        }
        foreach(IPS_GetVariableEventList($this->ReadPropertyInteger('CentralProfileVariable')) as $event) {
            $info=IPS_GetEvent($event);
            if (($info['TriggerVariableID']??0)===$this->ReadPropertyInteger('CentralProfileVariable')) {
                $target=IPS_GetParent($event);
                if (!in_array($target,$scripts,true)) throw new Exception('Weitere Steuerung am Heizungsprofil gefunden: '.IPS_GetLocation($event).'. Vor Umstellung prüfen.');
                $events[$event]=$info['EventActive'];
            }
        }
        $legacyAction=IPS_GetVariable($this->ReadPropertyInteger('CentralProfileVariable'))['VariableCustomAction'];
        $backup=$this->Backup(['oldSeasonScript'=>$oldScript,'source'=>$source,'timers'=>$timers,'events'=>$events,'centralCustomAction'=>$legacyAction,'devices'=>$inventory,'templates'=>$templates],'Umstellung');
        // Den gleichen Lock wie die bestehende Saisonautomatik verwenden.
        $lock='HeizungsSaisonautomatik_'.$oldScript;
        if (!IPS_SemaphoreEnter($lock,1000)) throw new Exception('Die bestehende Saisonautomatik läuft noch. Später erneut aktivieren.');
        try {
            // Sicherungsreferenz bereits vor der ersten Änderung dauerhaft merken.
            $state=$this->State(); $state['migrationBackup']=$backup;
            $state['legacyTimers']=$timers; $state['legacyEvents']=$events;
            $state['legacyProfile']=(int)GetValue($this->ReadPropertyInteger('CentralProfileVariable')); $this->StoreState($state);
            foreach($timers as $script=>$seconds) IPS_SetScriptTimer($script,0);
            foreach($events as $event=>$enabled) IPS_SetEventActive($event,false);
            IPS_SetVariableCustomAction($this->ReadPropertyInteger('CentralProfileVariable'),1);
            $state=$this->State(); $state['migrationBackup']=$backup; $state['sent']=[];
            $state['modeInitialized']=[];
            $state['legacyTimers']=$timers; $state['legacyEvents']=$events;
            $state['legacyProfile']=(int)GetValue($this->ReadPropertyInteger('CentralProfileVariable')); $state['managedProfile']=(int)GetValue($this->ReadPropertyInteger('CentralProfileVariable'));
            $this->StoreState($state);
            $this->WriteAttributeBoolean('Active',true);
        } finally {IPS_SemaphoreLeave($lock);}
        $this->SampleWeather();
        $this->Control();
    }

    private function Control(): void
    {
        if ($this->ReadAttributeBoolean('TestMode')) {$this->Simulate(); return;}
        // Bei einem PHP-Abbruch während eines Gerätebefehls nicht automatisch
        // im nächsten Timerlauf weiterschreiben. Erst am Laufende freigeben.
        $this->WriteAttributeBoolean('Active',false);
        $decision=$this->Decision(); $season=$decision['season']; $slot=$season==='away'?3:2;
        // Externe Änderungen des bisherigen Zentralprofils nicht übersteuern.
        $state=$this->State();
        if (isset($state['managedProfile'])&&IPS_VariableExists($this->ReadPropertyInteger('CentralProfileVariable'))&&(int)GetValue($this->ReadPropertyInteger('CentralProfileVariable'))!==$state['managedProfile']) {
            throw new Exception('Das bisherige zentrale Heizungsprofil wurde extern verändert. Automatik pausiert.');
        }
        $templates=$this->Templates();
        foreach($this->Zones() as $room=>$zone) {
            $plan=$this->Plan($templates,$room,$season);
            if (!$plan) throw new Exception('Vorlage fehlt: '.$zone[0].' · '.self::SEASONS[$season]);
            $runtime=$this->RuntimePlan($room,$season,true);$plan=$runtime['plan'];
            $state=$this->State();
            if (($state['sent'][$room][$slot]['hash']??'')!==$this->Hash($plan)) $this->Transfer($room,$season,null,$plan);
            $address=$this->Address($room);
            $state=$this->State();
            if (empty($state['modeInitialized'][$room])) {
                $valuesDescription=hpRpc('getParamsetDescription',[$address,'VALUES']);
                if (isset($valuesDescription['SET_POINT_MODE'])&&(int)hpRpc('getValue',[$address,'SET_POINT_MODE'])!==0) {
                    hpRpc('setValue',[$address,'SET_POINT_MODE',0]);
                }
                $state['modeInitialized'][$room]=true; $this->StoreState($state);
            }
            if ((int)hpRpc('getValue',[$address,'ACTIVE_PROFILE'])!==$slot) hpRpc('setValue',[$address,'ACTIVE_PROFILE',$slot]);
        }
        $state=$this->State();
        if ($season!=='away') $state['lastSeason']=$season;
        if ($this->ReadAttributeString('Mode')==='auto'&&$season!=='away') $state['autoSeason']=$season;
        $state['currentSeason']=$season;
        // Bestehende Anzeige als Status weiterführen, ohne RequestAction/Verteiler.
        $legacy=$season==='away'?3:($season==='summer'?1:2);
        if (IPS_VariableExists($this->ReadPropertyInteger('CentralProfileVariable'))&&(int)GetValue($this->ReadPropertyInteger('CentralProfileVariable'))!==$legacy) SetValue($this->ReadPropertyInteger('CentralProfileVariable'),$legacy);
        $state['managedProfile']=$legacy; $this->StoreState($state);
        $this->WriteAttributeBoolean('Active',true);
        $this->WriteAttributeString('Status',self::SEASONS[$season].' · '.$decision['reason']);
    }

    private function Simulate(?int $onlyRoom=null,?string $chosenSeason=null): void
    {
        $decision=$this->Decision(); $season=$chosenSeason??$decision['season'];
        $report=$this->PlannedRooms($season,$onlyRoom,true);
        $state=$this->State(); $state['simulation']=['at'=>time(),'rooms'=>$report,'reason'=>$chosenSeason?'Gewählte Vorlage':$decision['reason']];
        $this->StoreState($state);
        $this->WriteAttributeString('Status','Simulation: '.self::SEASONS[$season].'. Keine Gerätebefehle, keine Umschaltung der bisherigen Steuerung.');
    }

    private function RuntimePlan(int $room,string $season,bool $persist=false): array
    {
        if ($room===4) $room=3;
        $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));
        $state=$this->State();$scope=$this->ReadAttributeBoolean('TestMode')?'test':'live';
        $actuals=[];
        foreach($room===3?[3,4]:[$room] as $part) {
            $id=$this->Zones()[$part][2];$value=$this->Num($id);
            $updated=IPS_VariableExists($id)?(int)(IPS_GetVariable($id)['VariableUpdated']??0):0;
            if ($value===null||$value<0||$value>50||$updated>time()+300||time()-$updated>10800) {$actuals=[];break;}
            $actuals[]=$value;
        }
        $warm=$state['warmWeather']??[];
        $fresh=!empty($warm['last'])&&time()-$warm['last']<=1200&&$warm['last']<=time();
        $result=scRuntimePlan($this->Plan($this->Templates(),$room,$season),$this->ReadAttributeString('Mode'),$season,
            $fresh&&!empty($warm['active']),$actuals?min($actuals):null,(int)$now->format('N')-1,(int)$now->format('G')*60+(int)$now->format('i'),
            $state['runtimeCorrection'][$scope][$room]??[],time());
        if ($persist) {$state['runtimeCorrection'][$scope][$room]=$result['state'];$this->StoreState($state);}
        return $result;
    }

    private function PlannedRooms(string $season,?int $onlyRoom=null,bool $persist=false): array
    {
        $templates=$this->Templates(); $report=[];
        $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));
        $day=(int)$now->format('N')-1; $minute=(int)$now->format('G')*60+(int)$now->format('i');
        foreach($this->Zones() as $room=>$zone) {
            if ($room===4) continue;
            if ($onlyRoom!==null&&$room!==$onlyRoom) continue;
            $plan=$this->Plan($templates,$room,$season); $target=null; $error=''; $until=null; $nextTarget=null;$runtime=null;
            if ($plan) {
                try {
                    scValidatePlan($plan);
                    $runtime=$this->RuntimePlan($room,$season,$persist);$plan=$runtime['plan'];
                    foreach($plan[$day] as $index=>$point) if($point[0]<=$minute) {
                        $target=$point[1]; $until=$plan[$day][$index+1][0]??1440;
                        $nextTarget=$plan[$day][$index+1][1]??$plan[($day+1)%7][0][1];
                    }
                }
                catch (Throwable $e) {$error=$e->getMessage();}
            } else $error='Vorlage fehlt – zuerst Gerätepläne einlesen.';
            $slot=$season==='away'?3:2; $state=$this->State();
            $last=null;foreach($state['sent'][$room]??[] as $sent) if($last===null||$sent['at']>$last['at']) $last=$sent;
            $report[]=['id'=>$room,'room'=>$zone[0],'season'=>self::SEASONS[$season],
                'slot'=>$slot===3?'Abwesenheit':'Normal','target'=>$target,'until'=>$until,'nextTarget'=>$nextTarget,
                'baseline'=>$runtime['baseline']??null,'correction'=>$runtime['correction']??0,'runtimeReason'=>$runtime['reason']??'',
                'lastTransfer'=>$last['at']??null,'lastTransferSeason'=>$last?self::SEASONS[$last['season']]:null,'error'=>$error];
        }
        return $report;
    }

    private function Fail(Throwable $e): void
    {
        $this->WriteAttributeBoolean('Active',false);
        $this->WriteAttributeString('Status','Automatik pausiert: '.$e->getMessage().'. Letzte Gerätepläne laufen weiter; keine automatische Wiederholung.');
        IPS_LogMessage('Heizungszentrale',$e->getMessage());
    }

    private function View(): array
    {
        $templates=$this->WorkingTemplates(); $state=$this->State(); $rooms=[];
        foreach($this->Zones() as $room=>$zone) {
            if ($room===4) continue;
            $profiles=[];
            foreach(self::SEASONS as $season=>$name) {
                $entry=$templates[$room][$season]??['plan'=>null,'origin'=>'Noch nicht eingelesen'];
                $entry['revision']=$entry['plan']?$this->Hash($entry['plan']):'';
                $profiles[$season]=$entry;
            }
            $actual=$this->Num($zone[2]); $target=$this->Num($zone[3]); $profile=$this->Num($zone[4]); $parts=[];
            if ($room===3) {
                $leftActual=$this->Num($this->Zones()[4][2]);$leftTarget=$this->Num($this->Zones()[4][3]);$leftProfile=$this->Num($this->Zones()[4][4]);
                $parts=[['name'=>'Rechts','actual'=>$actual,'target'=>$target],['name'=>'Links','actual'=>$leftActual,'target'=>$leftTarget]];
                $readings=array_filter([$actual,$leftActual],static fn($v)=>$v!==null);
                $actual=$readings?array_sum($readings)/count($readings):null;
                if ($target!==$leftTarget) $target=null;
                if ($profile!==$leftProfile) $profile=null;
            }
            $rooms[]=['id'=>$room,'name'=>$zone[0],'actual'=>$actual,'target'=>$target,'parts'=>$parts,
                'deviceProfile'=>$profile,'profiles'=>$profiles];
        }
        $pending=json_decode($this->ReadAttributeString('Pending'),true);
        $presets=json_decode($this->ReadAttributeString('ManualPresets'),true,64,JSON_THROW_ON_ERROR);$presetList=[];
        foreach($presets as $id=>$preset) $presetList[]=['id'=>$id,'name'=>$preset['name'],'mode'=>$preset['mode'],'updated'=>$preset['updated']];
        $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));
        $job=$state['seasonPreparation']??null;$preparation=null;
        if($job) $preparation=['id'=>$job['id'],'status'=>$job['status'],'completed'=>array_map('intval',array_keys($job['sources'])),
            'total'=>7,'started'=>$job['started'],'error'=>$job['error']??''];
        return ['rooms'=>$rooms,'seasons'=>self::SEASONS,'active'=>$this->ReadAttributeBoolean('Active'),
            'seasonPreparation'=>$preparation,
            'lastActionReply'=>json_decode($this->ReadAttributeString('LastActionReply'),true,64,JSON_THROW_ON_ERROR),
            'clock'=>['day'=>(int)$now->format('N')-1,'minute'=>(int)$now->format('G')*60+(int)$now->format('i'),'date'=>$now->format('d.m.Y')],
            'manualPresets'=>$presetList,'lastManualMode'=>$this->ReadAttributeString('LastManualMode'),
            'manualPresetName'=>$this->ReadAttributeString('ManualPresetName'),
            'mode'=>$this->ReadAttributeString('Mode'),'status'=>$this->ReadAttributeString('Status'),
            'testMode'=>$this->ReadAttributeBoolean('TestMode'),'simulation'=>$state['simulation']??null,
            'planned'=>$this->PlannedRooms($this->Decision()['season']),
            'outside'=>$this->Num($this->ReadPropertyInteger('OutsideVariable')),'forecastMin'=>$this->Num($this->ReadPropertyInteger('ForecastMinVariable')),'forecastMax'=>$this->Num($this->ReadPropertyInteger('ForecastMaxVariable')),
            'decision'=>$this->Decision(),'pending'=>$pending,'migrationBackup'=>$state['migrationBackup']??''];
    }
}
