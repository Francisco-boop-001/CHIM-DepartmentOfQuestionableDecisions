<?php
declare(strict_types=1);
require_once __DIR__.'/runtime.php';

final class CorrespondencePgStore implements CorrespondenceStore
{
    private bool $active=false;
    private bool $writerActive=false;
    private ?array $actorCatalog=null;
    private ?array $offscreenActorRows=null;
    private ?string $playerName=null;
    private ?array $requestClock=null;
    public function __construct(private mixed $connection, private Closure $executorResolver, private Closure $notePreparer) {}
    private function clearTransactionCache():void{$this->actorCatalog=null;$this->offscreenActorRows=null;$this->playerName=null;}
    public function bindRequestClock(array $request):void
    {
        if($this->active)throw new LogicException('clock_rebind_during_transaction');
        $this->requestClock=correspondenceRequestClock($request);
    }    public function ensureSchema():void
    {
        $ready=$this->query("SELECT to_regclass('plugins.correspondence_documents') IS NOT NULL AS documents,to_regclass('plugins.correspondence_operations') IS NOT NULL AS operations,to_regclass('plugins.correspondence_presence') IS NOT NULL AS presence")[0]??[];
        if(($ready['documents']??null)!=='t'||($ready['operations']??null)!=='t'||($ready['presence']??null)!=='t'){
        $migration=@file_get_contents(dirname(__DIR__).'/migrations/001_initial.sql');
        if(!is_string($migration)||$migration==='')throw new DomainException('schema_migration_unavailable');
        $this->transaction(function()use($migration){
            foreach(explode(';',$migration)as$statement)if(trim($statement)!=='')$this->query($statement);
        });
        }
        $columns=$this->query("SELECT table_name,column_name FROM information_schema.columns WHERE table_schema='plugins' AND table_name IN ('correspondence_documents','correspondence_operations','correspondence_presence')");
        $expected=['correspondence_documents'=>['document_id','playthrough_id','request_id','payload','created_at'],'correspondence_operations'=>['operation_id','document_id','payload','created_at'],'correspondence_presence'=>['playthrough_id','payload']];
        foreach($expected as$table=>$names)foreach($names as$name)if(!array_filter($columns,fn($row)=>$row['table_name']===$table&&$row['column_name']===$name))throw new DomainException('schema_migration_incomplete');
    }    public function query(string $sql,array $parameters=[]): array
    {
        $result=@pg_query_params($this->connection,$sql,$parameters);
        if($result===false)throw new RuntimeException('storage_failed');
        try{return pg_num_fields($result)>0?(pg_fetch_all($result)?:[]):[];}finally{pg_free_result($result);}
    }
    public function transaction(callable $work): mixed
    {
        if($this->active)return $work();
        $this->clearTransactionCache();
        $this->query('BEGIN'); $this->active=true; $this->writerActive=true;
        try{
            $this->query("SET LOCAL lock_timeout='3s'");
            $this->query('SELECT pg_advisory_xact_lock(18459814, 1)');
            // Core restore and source publication must not interleave with receipt validation.
            $this->query('LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE');
            if($this->requestClock!==null){$latest=$this->query('SELECT MAX(ts) AS ts FROM public.eventlog')[0]['ts']??null;if($latest!==null&&(int)$latest>(int)$this->requestClock['ts'])throw new DomainException('stale_request_clock');}
            $result=$work();$this->query('COMMIT');return $result;
        }catch(Throwable $e){try{$this->query('ROLLBACK');}catch(Throwable){}throw $e;}finally{$this->active=false;$this->writerActive=false;$this->clearTransactionCache();}
    }
    public function readTransaction(callable $work):mixed
    {
        if($this->active)return $work();
        $this->clearTransactionCache();
        $this->query('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $this->active=true; $this->writerActive=false;
        try{
            $this->query("SET LOCAL lock_timeout='3s'");
            if($this->requestClock!==null){$latest=$this->query('SELECT MAX(ts) AS ts FROM public.eventlog')[0]['ts']??null;if($latest!==null&&(int)$latest>(int)$this->requestClock['ts'])throw new DomainException('stale_request_clock');}
            $result=$work();$this->query('COMMIT');return $result;
        }
        catch(Throwable $e){try{$this->query('ROLLBACK');}catch(Throwable){}throw $e;}
        finally{$this->active=false;$this->writerActive=false;$this->clearTransactionCache();}
    }
    public function scope(): array
    {
        $exists=$this->query("SELECT to_regclass('chim_meta.playthrough_profiles') AS profile_table")[0]??null;
        if(!$exists || !array_key_exists('profile_table',$exists))throw new DomainException('scope_unavailable');
        $profiles=$exists['profile_table']===null?[]:$this->query('SELECT id FROM chim_meta.playthrough_profiles WHERE is_active IS TRUE LIMIT 2');
        if(count($profiles)>1)throw new DomainException('scope_ambiguous');
        $id=$profiles===[]?'unprofiled':(string)$profiles[0]['id'];
        $clock=$this->requestClock??($this->query('SELECT ts,gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1')[0]??null);
        if(!$clock || !is_numeric($clock['ts']) || !is_numeric($clock['gamets']))throw new DomainException('game_clock_unavailable');
        return ['playthrough_id'=>$id,'ts'=>$clock['ts'],'gamets'=>$clock['gamets']];
    }
    public function actors():array
    {
        if($this->active&&$this->actorCatalog!==null)return $this->actorCatalog;
        $rows=$this->query('SELECT id,npc_name,refid,profile_id FROM public.core_npc_master WHERE id>0 ORDER BY npc_name,id LIMIT 10000');
        if($this->active)$this->actorCatalog=$rows;
        return $rows;
    }
    public function offscreenActors():array
    {
        if($this->active&&$this->offscreenActorRows!==null)return $this->offscreenActorRows;
        $rows=$this->query('SELECT id,npc_name,personality,extended_data::text AS extended_data,metadata::text AS metadata FROM public.core_npc_master WHERE id>0 ORDER BY id LIMIT 10000');
        if($this->active)$this->offscreenActorRows=$rows;
        return $rows;
    }
    public function player():string
    {
        if($this->active&&$this->playerName!==null)$name=$this->playerName;
        else{
            $rows=$this->query('SELECT value FROM public.core_player WHERE id=$1 LIMIT 2',['player_name']);
            $name=count($rows)===1?($rows[0]['value']??''):'';
            if($this->active)$this->playerName=$name;
        }
        if(!is_string($name)||trim($name)===''||strlen($name)>200||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f|]/',$name))throw new DomainException('player_unavailable');return $name;
    }
    public function lockActorRows(array $npcIds,bool $includePlayer=false):array
    {
        if(!$this->active||!$this->writerActive)throw new LogicException('effect_identity_lock_requires_writer');
        $ids=[];
        foreach($npcIds as$id){if(!is_int($id)||$id<1)throw new InvalidArgumentException('invalid_actor_ids');$ids[$id]=$id;}
        if(count($ids)>4)throw new InvalidArgumentException('invalid_actor_ids');
        sort($ids,SORT_NUMERIC);
        $wanted=array_fill_keys($ids,true);
        $actors=[];
        if($ids!==[]){
            $array='{'.implode(',',$ids).'}';
            foreach($this->query('SELECT * FROM public.core_npc_master WHERE id=ANY($1::bigint[]) AND id>0 ORDER BY id FOR SHARE',[$array])as$row){
                if(!ctype_digit((string)($row['id']??'')))continue;
                $id=(int)$row['id'];if(isset($wanted[$id]))$actors[$id]=$row;
            }
        }
        $playerName=null;
        if($includePlayer){$rows=$this->query('SELECT value FROM public.core_player WHERE id=$1 FOR SHARE',['player_name']);if(count($rows)===1)$playerName=$rows[0]['value']??null;}
        return ['actors'=>$actors,'player_name'=>$playerName];
    }
    private function payload(array $rows):?array{return isset($rows[0]['payload'])?json_decode($rows[0]['payload'],true,512,JSON_THROW_ON_ERROR):null;}
    public function findRequest(string $scope,string $request):?array{return $this->payload($this->query('SELECT payload FROM plugins.correspondence_documents WHERE playthrough_id=$1 AND request_id=$2',[$scope,$request]));}
    public function loadDocument(string $id):?array{return $this->payload($this->query('SELECT payload FROM plugins.correspondence_documents WHERE document_id=$1',[$id]));}
    public function saveDocument(array $document):void
    {
        $rows=$this->query("INSERT INTO plugins.correspondence_documents(document_id,playthrough_id,request_id,payload) VALUES($1,$2,$3,$4::jsonb) ON CONFLICT(document_id) DO UPDATE SET payload=EXCLUDED.payload WHERE plugins.correspondence_documents.payload-'operation_id'=EXCLUDED.payload-'operation_id' RETURNING document_id",[$document['document_id'],$document['playthrough_id'],$document['request_id'],correspondenceJson($document)]);
        if(count($rows)!==1)throw new DomainException('immutable_document_conflict');
    }
    public function documents(string $scope, ?int $recipientId=null):array
    {
        $sql = 'SELECT payload FROM plugins.correspondence_documents WHERE playthrough_id=$1';
        $parameters = [$scope];
        if ($recipientId !== null) {
            if ($recipientId < 1) throw new InvalidArgumentException('invalid_recipient_id');
            $sql .= " AND payload->'recipient'->>'kind'='npc' AND payload->'recipient'->>'id'=\$2";
            $parameters[] = (string)$recipientId;
        }
        $sql .= ' ORDER BY created_at DESC,document_id';
        return array_map(fn($r)=>json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR),$this->query($sql,$parameters));
    }
    public function loadOperation(string $id):?array{return $this->payload($this->query('SELECT payload FROM plugins.correspondence_operations WHERE operation_id=$1',[$id]));}
    public function saveOperation(array $operation):void
    {
        $rows=$this->query("INSERT INTO plugins.correspondence_operations(operation_id,document_id,payload) VALUES($1,$2,$3::jsonb) ON CONFLICT(operation_id) DO UPDATE SET payload=EXCLUDED.payload WHERE plugins.correspondence_operations.payload-ARRAY['receipt','receipt_anchor','source']=EXCLUDED.payload-ARRAY['receipt','receipt_anchor','source'] RETURNING operation_id",[$operation['operation_id'],$operation['document_id'],correspondenceJson($operation)]);
        if(count($rows)!==1)throw new DomainException('immutable_operation_conflict');
    }
    public function appendEvent(string $type,string $data,string $people,string $sess):array
    {
        if(!$this->active)throw new LogicException('transaction_required');
        if(!in_array($type,['written_evidence','correspondence_anchor','correspondence_dispatch','correspondence_receipt_confirmed','correspondence_presence'],true)||$sess!==($type==='written_evidence'?'written_evidence_v1':'correspondence_v1'))throw new InvalidArgumentException('invalid_event_carrier');
        if(in_array($type,['written_evidence','correspondence_receipt_confirmed','correspondence_presence'],true)&&$this->requestClock===null)throw new DomainException('request_clock_unavailable');
        if($type==='written_evidence'){
            $source=json_decode($data,true,512,JSON_THROW_ON_ERROR);
            $existing=$this->query("WITH marked AS MATERIALIZED (SELECT rowid,data FROM public.eventlog WHERE type='info' AND sess='written_evidence_v1') SELECT rowid FROM marked WHERE data::jsonb->>'document_id'=$1 LIMIT 1",[$source['document_id']]);
            if($existing!==[])throw new DomainException('duplicate_written_source');
        }
        $clock=$this->scope();
        $row=$this->query('INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1,$2,$3,$4,$5,$6,$7,$8,$9) RETURNING rowid,ts,gamets,type,data,sess,localts,people',[$clock['ts'],$clock['gamets'],'info',$data,$sess,time(),$people,'',''])[0]??null;
        if(!$row)throw new RuntimeException('event_write_failed');return $row;
    }
    public function eventMatches(array $event):bool
    {
        $rows=$this->query('SELECT rowid,ts,gamets,type,data,sess,localts,people FROM public.eventlog WHERE rowid=$1',[$event['rowid']??0]);
        return count($rows)===1 && $rows[0]==$event;
    }
    public function managedExecutor():?string
    {
        $name=($this->executorResolver)($this);
        if(!is_string($name)||$name===''||preg_match('/[|@\r\n]/',$name))return null;
        $matches=array_filter($this->actors(),fn($a)=>$a['npc_name']===$name);return count($matches)===1?$name:null;
    }
    public function prepareNote(string $title,string $body):void
    {
        if(($this->notePreparer)($title,$body)!==true)throw new DomainException('renderer_unavailable');
        $this->query("INSERT INTO public.books(ts,gamets,content,sess,localts,title) VALUES(0,0,$1,'generated',$2,$3)",[$body,time(),$title]);
        $this->queueCommand('rolemaster','rolecommand|generateLetter@'.$title);
    }
    public function queueCommand(string $actor,string $action):void
    {
        if(!$this->active)throw new LogicException('transaction_required');
        $this->query("INSERT INTO public.responselog(localts,sent,actor,text,action,tag) VALUES($1,0,$2,'',$3,'')",[time(),$actor,$action]);
    }
    private function pluginRows():array{return $this->query('SELECT plugin_name,formid_prefix FROM public.game_plugins');}
    private function signed(int $id):int{return $id>2147483647?$id-4294967296:$id;}
    private function strictPlugin(string $name):void{if(preg_match('/\A[^|@\x00-\x1f\/\\\\]{1,200}\.(?i:esm|esp|esl)\z/D',$name)!==1)throw new DomainException('invalid_plugin');}
    public function physicalActor(array $actor):array
    {
        if($actor['kind']==='player')$runtime='00000014';
        else{$rows=$this->query('SELECT refid FROM public.core_npc_master WHERE id=$1',[$actor['id']]);$runtime=$rows[0]['refid']??'';}
        if(!is_string($runtime)||preg_match('/\A(?:0x)?([0-9a-fA-F]{8})\z/D',$runtime,$m)!==1)throw new DomainException('actor_form_unavailable');
        $runtime=strtoupper($m[1]);if(str_starts_with($runtime,'FF'))throw new DomainException('dynamic_form_unsupported');
        $prefix=str_starts_with($runtime,'FE')?substr($runtime,0,5):substr($runtime,0,2);
        $rows=array_values(array_filter($this->pluginRows(),fn($p)=>strtoupper($p['formid_prefix'])===$prefix));
        if(count($rows)!==1)throw new DomainException('plugin_mapping_unavailable');$plugin=$rows[0]['plugin_name'];$this->strictPlugin($plugin);
        $id=(int)hexdec($runtime);$local=$id & (strlen($prefix)===5?4095:16777215);if($local===0)throw new DomainException('invalid_local_form');
        return ['refid'=>$this->signed($id),'plugin'=>$plugin,'local_id'=>$local];
    }
    public function physicalItem(array $item):array
    {
        correspondenceAttachments([$item]);[$plugin,$hex]=explode('|',$item['form_id']);$local=(int)hexdec($hex);
        $rows=array_values(array_filter($this->pluginRows(),fn($p)=>strcasecmp($p['plugin_name'],$plugin)===0));
        if(count($rows)!==1)throw new DomainException('plugin_mapping_unavailable');$row=$rows[0];$prefix=strtoupper($row['formid_prefix']);
        if($local<1 || (strlen($prefix)===5 ? $local>4095 : $local>16777215) || preg_match('/\A(?:[0-9A-E][0-9A-F]|F[0-9A-D]|FE[0-9A-F]{3})\z/D',$prefix)!==1)throw new DomainException('invalid_local_form');
        $runtime=strlen($prefix)===5?(hexdec($prefix)<<12)|$local:(hexdec($prefix)<<24)|$local;
        return ['kind'=>$item['kind'],'form_id'=>$this->signed((int)$runtime),'plugin'=>$row['plugin_name'],'local_id'=>$local,'quantity'=>$item['quantity']];
    }
    public function itemChoices():array
    {
        $choices=[['form_id'=>'Skyrim.esm|0000000F','kind'=>'gold','name'=>'Gold (septims)']];
        $seen=['skyrim.esm|0000000f'=>true];$plugins=$this->pluginRows();
        $cache=$this->query("SELECT to_regclass('public.market_cache') AS cache_table")[0]??[];
        if(!array_key_exists('cache_table',$cache))throw new DomainException('item_cache_unavailable');
        if($cache['cache_table']!==null){
            foreach($this->query('SELECT baseid,plugin,name FROM public.market_cache ORDER BY name,baseid,plugin')as$item){
                if(!is_string($item['baseid']??null)||preg_match('/\A(?:0[xX])?([0-9a-fA-F]{8})\z/D',$item['baseid'],$hex)!==1||!is_string($item['plugin']??null)||!is_string($item['name']??null)||trim($item['name'])===''||strlen($item['name'])>512||preg_match('//u',$item['name'])!==1||preg_match('/\p{Cc}/u',$item['name'])===1)continue;
                try{
                    $identity=$this->physicalActorRef($hex[1],$plugins);
                    // Cache runtime prefixes may belong to another plugin after load-order changes.
                    if(strcasecmp($identity['plugin'],$item['plugin'])!==0||$identity['local_id']===0)continue;
                    $key=$identity['plugin'].'|'.sprintf('%08X',$identity['local_id']);if(isset($seen[strtolower($key)]))continue;
                    correspondenceAttachments([['kind'=>'item','form_id'=>$key,'quantity'=>1]]);
                    $seen[strtolower($key)]=true;$choices[]=['form_id'=>$key,'kind'=>'item','name'=>$item['name']];
                }catch(DomainException|InvalidArgumentException){continue;}
            }
        }
        $row=$this->query("SELECT data FROM public.eventlog WHERE type='infoitems' ORDER BY rowid DESC LIMIT 1")[0]??[];
        if(!preg_match('/\(items in range:(.+)\)/us',$row['data']??'',$m))return $choices;
        foreach(array_slice(explode(',',$m[1]),0,300) as $entry){$parts=explode(':',trim($entry),3);if(count($parts)!==3||preg_match('/\A0x([0-9a-fA-F]{8})\z/D',$parts[1],$hex)!==1)continue;
            try{$a=$this->physicalActorRef($hex[1],$plugins);if($a['local_id']===0)continue;$key=$a['plugin'].'|'.sprintf('%08X',$a['local_id']);if(isset($seen[strtolower($key)]))continue;correspondenceAttachments([['kind'=>'item','form_id'=>$key,'quantity'=>1]]);$seen[strtolower($key)]=true;$choices[]=['form_id'=>$key,'kind'=>'item','name'=>$parts[2]];}catch(DomainException|InvalidArgumentException){continue;}
        }return $choices;
    }
    private function physicalActorRef(string $runtime,array $plugins):array
    {
        if(str_starts_with(strtoupper($runtime),'FF'))throw new DomainException('dynamic_form_unsupported');
        $prefix=str_starts_with(strtoupper($runtime),'FE')?strtoupper(substr($runtime,0,5)):strtoupper(substr($runtime,0,2));
        $rows=array_values(array_filter($plugins,fn($p)=>strtoupper($p['formid_prefix'])===$prefix));if(count($rows)!==1)throw new DomainException('plugin_mapping_unavailable');
        $this->strictPlugin($rows[0]['plugin_name']);return ['plugin'=>$rows[0]['plugin_name'],'local_id'=>(int)hexdec($runtime)&(strlen($prefix)===5?4095:16777215)];
    }
    public function observeManaged(string $raw):void
    {
        $this->transaction(function()use($raw){
            $scope=$this->scope()['playthrough_id'];
            $anchor=$this->appendEvent('correspondence_presence',correspondenceJson(['token'=>correspondenceUuid()]),'','correspondence_v1');
            $payload=['raw'=>$raw,'observed_at'=>time(),'anchor'=>$anchor];
            $this->query('INSERT INTO plugins.correspondence_presence(playthrough_id,payload) VALUES($1,$2::jsonb) ON CONFLICT(playthrough_id) DO UPDATE SET payload=EXCLUDED.payload',[$scope,correspondenceJson($payload)]);
        });
    }
    public function observedExecutor():?string
    {
        $payload=$this->payload($this->query('SELECT payload FROM plugins.correspondence_presence WHERE playthrough_id=$1',[$this->scope()['playthrough_id']]));
        if(!$payload||!is_int($payload['observed_at']??null)||$payload['observed_at']>time()||time()-$payload['observed_at']>45||!$this->eventMatches($payload['anchor']))return null;
        return correspondenceExecutorFromSnapshot($payload['raw'],$this->actors());
    }}










