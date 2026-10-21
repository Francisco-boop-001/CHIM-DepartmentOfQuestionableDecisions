<?php
declare(strict_types=1);

interface CorrespondenceStore
{
    public function transaction(callable $work): mixed;
    public function readTransaction(callable $work): mixed;
    public function scope(): array;
    public function actors(): array;
    public function player(): string;
    public function lockActorRows(array $npcIds, bool $includePlayer = false): array;
    public function findRequest(string $scope, string $request): ?array;
    public function loadDocument(string $id): ?array;
    public function saveDocument(array $document): void;
    public function documents(string $scope, ?int $recipientId = null): array;
    public function loadOperation(string $id): ?array;
    public function saveOperation(array $operation): void;
    public function appendEvent(string $type, string $data, string $people, string $sess): array;
    public function eventMatches(array $event): bool;
    public function managedExecutor(): ?string;
    public function prepareNote(string $title, string $body): void;
    public function queueCommand(string $actor, string $action): void;
    public function physicalActor(array $actor): array;
    public function physicalItem(array $item): array;
}

function correspondenceUuid(): string
{
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}
function correspondenceKeys(array $value, array $keys): void
{
    $actual = array_keys($value); sort($actual); sort($keys);
    if ($actual !== $keys) throw new InvalidArgumentException('invalid_fields');
}
function correspondenceUuidCheck(mixed $value): string
{
    if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $value)!==1) throw new InvalidArgumentException('invalid_uuid');
    return $value;
}
function correspondenceJson(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function correspondencePromptJson(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); }
function correspondenceRetryableReason(string $reason):bool
{
    return in_array($reason,['invalid_actor','actor_unavailable','note_dependency_unavailable','renderer_not_ready','papyrus_extender_unavailable','invalid_item','protected_item','insufficient_inventory','inventory_overflow'],true);
}function correspondenceCanonicalJson(array $value):string
{
    foreach($value as &$entry)if(is_array($entry))$entry=json_decode(correspondenceCanonicalJson($entry),true,512,JSON_THROW_ON_ERROR);unset($entry);
    if(!array_is_list($value))ksort($value,SORT_STRING);return correspondenceJson($value);
}function correspondenceRequestClock(array $request):array
{
    $clock=[];foreach(['ts'=>1,'gamets'=>2]as$key=>$index){$raw=$request[$index]??null;
        if(is_int($raw))$raw=(string)$raw;
        if(!is_string($raw)||preg_match('/\A[1-9][0-9]{0,18}\z/D',$raw)!==1||filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new InvalidArgumentException('invalid_request_clock');
        $clock[$key]=$raw;
    }return $clock;
}function correspondenceBody(mixed $body): string
{
    if (!is_string($body) || trim($body)==='' || strlen($body)>8000 || preg_match('//u',$body)!==1 || preg_match_all('/./us',$body)>2000 || str_contains($body,"\0")) throw new InvalidArgumentException('invalid_body');
    return $body;
}
function correspondenceActorName(mixed $name):bool
{
    return is_string($name)&&$name!==''&&strlen($name)<=200&&preg_match('//u',$name)===1&&preg_match_all('/./us',$name)<=128&&!str_contains($name,'|')&&preg_match('/\p{Cc}/u',$name)!==1&&preg_match('/\A[\p{Z}\p{Cc}]|[\p{Z}\p{Cc}]\z/u',$name)!==1;
}function correspondenceAttachments(mixed $items): array
{
    if (!is_array($items) || !array_is_list($items) || count($items)>8) throw new InvalidArgumentException('invalid_attachments');
    $seen=[];
    foreach ($items as $item) {
        if (!is_array($item)) throw new InvalidArgumentException('invalid_attachment');
        correspondenceKeys($item,['kind','form_id','quantity']);
        if (!in_array($item['kind'],['gold','item'],true) || !is_int($item['quantity']) || $item['quantity']<1 || $item['quantity']>2147483647 || !is_string($item['form_id']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9 _().-]{0,249}\.(?i:esm|esp|esl)\|[0-9A-F]{8}\z/D',$item['form_id'])!==1) throw new InvalidArgumentException('invalid_attachment');
        $key=strtolower($item['form_id']);
        if (($item['kind']==='gold'&&$item['form_id']!=='Skyrim.esm|0000000F') || isset($seen[$key]) || ($item['kind']==='gold') !== ($key==='skyrim.esm|0000000f')) throw new InvalidArgumentException('invalid_attachment_identity');
        $seen[$key]=true;
    }
    return $items;
}

final class CorrespondenceService
{
    public array $sourceReferences=[];
    public array $knownSourceReferences=[];
    public function __construct(private CorrespondenceStore $store) {}
    private function scope(): string
    {
        $scope=$this->store->scope()['playthrough_id']??null;
        if (!is_string($scope) || ($scope!=='unprofiled' && preg_match('/\A[1-9][0-9]*\z/D',$scope)!==1)) throw new DomainException('scope_unavailable');
        return $scope;
    }
    public function actors(): array
    {
        $player=$this->store->player();if(!correspondenceActorName($player))throw new DomainException('player_unavailable');
        $result=[['key'=>'player','kind'=>'player','id'=>null,'name'=>$player]];
        foreach ($this->store->actors() as $row) {
            if (!is_int($row['id']??null) && !ctype_digit((string)($row['id']??''))) continue;
            $id=(int)$row['id']; $name=$row['npc_name']??null;
            if ($id>0 && correspondenceActorName($name)) $result[]=['key'=>'npc:'.$id,'kind'=>'npc','id'=>$id,'name'=>$name];
        }
        return $result;
    }
    private function actor(mixed $key): array
    {
        if (!is_string($key)) throw new InvalidArgumentException('invalid_actor');
        foreach ($this->actors() as $actor) if ($actor['key']===$key && $actor['name']!=='') { unset($actor['key']); return $actor; }
        throw new InvalidArgumentException('actor_unavailable');
    }
    private function sameActor(array $actor): bool
    {
        try { return $this->actor($actor['kind']==='player'?'player':'npc:'.$actor['id'])==$actor; } catch (Throwable) { return false; }
    }
    private function actorSnapshotValid(mixed $actor): bool
    {
        if (!is_array($actor)) return false;
        try { correspondenceKeys($actor, ['kind','id','name']); } catch (InvalidArgumentException) { return false; }
        if (!correspondenceActorName($actor['name'])) return false;
        if ($actor['kind'] === 'player') return $actor['id'] === null;
        return $actor['kind'] === 'npc' && is_int($actor['id']) && $actor['id'] > 0;
    }
    private function identitiesMatch(array $doc, bool $lockForEffect = false): bool
    {
        $npcIds=[];
        $includePlayer=false;
        foreach (['author','payer','recipient','claimed_sender'] as $field) {
            if (!$this->sameActor($doc[$field])) return false;
            if ($doc[$field]['kind']==='player') $includePlayer=true;
            else $npcIds[]=$doc[$field]['id'];
        }
        if (!$lockForEffect) return true;
        $locked=$this->store->lockActorRows(array_values(array_unique($npcIds)),$includePlayer);
        foreach (['author','payer','recipient','claimed_sender'] as $field) {
            $actor=$doc[$field];
            if ($actor['kind']==='player') {
                if (($locked['player_name']??null)!==$actor['name']) return false;
                continue;
            }
            $row=$locked['actors'][$actor['id']]??null;
            if (!is_array($row) || (int)($row['id']??0)!==$actor['id'] || ($row['npc_name']??null)!==$actor['name']) return false;
        }
        return true;
    }
    private function storedDocument(string $id): array
    {
        correspondenceUuidCheck($id);
        $doc = $this->store->loadDocument($id);
        if (!is_array($doc)) throw new DomainException('document_unavailable');
        foreach (['document_id','playthrough_id','request_id','request_hash','title','author','payer','recipient','claimed_sender','body','attachments','operation_id','anchor'] as $field) {
            if (!array_key_exists($field, $doc)) throw new DomainException('document_unavailable');
        }
        if ($doc['document_id'] !== $id || !is_string($doc['playthrough_id']) || $doc['playthrough_id'] !== $this->scope()) {
            throw new DomainException('document_unavailable');
        }
        try {
            correspondenceUuidCheck($doc['request_id']);
            if (!is_string($doc['request_hash']) || preg_match('/\A[a-f0-9]{64}\z/D', $doc['request_hash']) !== 1) throw new DomainException('document_unavailable');
            if (!is_string($doc['title']) || $doc['title'] !== 'letter '.$id) throw new DomainException('document_unavailable');
            correspondenceBody($doc['body']);
            correspondenceAttachments($doc['attachments']);
            if ($doc['operation_id'] !== null) correspondenceUuidCheck($doc['operation_id']);
            foreach (['author','payer','recipient','claimed_sender'] as $field) {
                if (!$this->actorSnapshotValid($doc[$field])) throw new DomainException('document_unavailable');
            }
            $actorKey = static fn(array $actor): string => $actor['kind'] === 'player' ? 'player' : 'npc:'.$actor['id'];
            $requestInput = [
                'request_id'=>$doc['request_id'],
                'author'=>$actorKey($doc['author']),
                'payer'=>$actorKey($doc['payer']),
                'recipient'=>$actorKey($doc['recipient']),
                'claimed_sender'=>$actorKey($doc['claimed_sender']),
                'body'=>$doc['body'],
                'attachments'=>$doc['attachments'],
            ];
            if (!hash_equals($doc['request_hash'], hash('sha256', correspondenceCanonicalJson($requestInput)))) {
                throw new DomainException('document_unavailable');
            }
            $anchor = $doc['anchor'];
            if (!is_array($anchor)) throw new DomainException('document_unavailable');
            correspondenceKeys($anchor, ['rowid','ts','gamets','type','data','sess','localts','people']);
            $rowid = $anchor['rowid'];
            if ((!is_int($rowid) && (!is_string($rowid) || !ctype_digit($rowid))) || (int)$rowid < 1
                || !is_numeric($anchor['ts']) || !is_numeric($anchor['gamets']) || !is_numeric($anchor['localts'])
                || !in_array($anchor['type'], ['info','correspondence_anchor'], true)
                || $anchor['sess'] !== 'correspondence_v1' || !is_string($anchor['data']) || !is_string($anchor['people'])) {
                throw new DomainException('document_unavailable');
            }
            $anchorData = json_decode($anchor['data'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($anchorData)) throw new DomainException('document_unavailable');
            correspondenceKeys($anchorData, ['document_id','scope_token']);
            if ($anchorData['document_id'] !== $id) throw new DomainException('document_unavailable');
            correspondenceUuidCheck($anchorData['scope_token']);
        } catch (InvalidArgumentException|JsonException) {
            throw new DomainException('document_unavailable');
        }
        if (!$this->store->eventMatches($anchor)) throw new DomainException('document_unavailable');
        return $doc;
    }
    private function document(string $id, bool $effect = false): array
    {
        $doc = $this->storedDocument($id);
        if (!$this->identitiesMatch($doc,$effect)) throw new DomainException('actor_identity_changed');
        return $doc;
    }
    private function state(array $doc,?array $operation=null): string
    {
        if ($doc['operation_id']===null) return 'queued';
        $op=$operation??$this->store->loadOperation($doc['operation_id']);
        if (!$op || !$this->store->eventMatches($op['dispatch_anchor'])) return 'unknown';
        if ($op['receipt']!==null && (!$op['receipt_anchor'] || !$this->store->eventMatches($op['receipt_anchor']))) return 'unknown';
        if ($op['receipt']===null) return 'dispatched';
        if($op['receipt']['status']==='failed'&&!correspondenceRetryableReason($op['receipt']['reason']))return 'unknown';
        if ($op['receipt']['status']!=='delivered') return $op['receipt']['status'];
        if ($op['source']!==null && $this->store->eventMatches($op['source'])) return 'exposed';
        return 'delivered';
    }
    public function get(string $id): array { return $this->store->readTransaction(function()use($id){$doc=$this->document($id); return ['document_id'=>$id,'state'=>$this->state($doc),'operation_id'=>$doc['operation_id']];}); }
    public function readExposedSource(int $eventId, int $recipientId): array
    {
        if ($eventId < 1 || $recipientId < 1) throw new InvalidArgumentException('invalid_source_identity');
        return $this->store->readTransaction(function() use ($eventId, $recipientId) {
            $scope = $this->scope();
            $event = null;
            if (method_exists($this->store, 'query')) {
                $rows = $this->store->query('SELECT rowid,ts,gamets,type,data,sess,localts,people FROM public.eventlog WHERE rowid=$1', [$eventId]);
                if (count($rows) === 1) $event = $rows[0];
            } else {
                foreach ($this->store->documents($scope) as $row) {
                    try {
                        $candidate = $this->document($row['document_id']);
                        if ($candidate['operation_id'] === null) continue;
                        $candidateOp = $this->store->loadOperation($candidate['operation_id']);
                        if (is_array($candidateOp['source'] ?? null) && (int)($candidateOp['source']['rowid'] ?? 0) === $eventId) {
                            $event = $candidateOp['source'];
                            break;
                        }
                    } catch (DomainException|InvalidArgumentException) {
                        continue;
                    }
                }
            }
            if (!is_array($event) || (int)($event['rowid'] ?? 0) !== $eventId || ($event['type'] ?? null) !== 'info' || ($event['sess'] ?? null) !== 'written_evidence_v1' || !is_string($event['data'] ?? null)) throw new DomainException('source_unavailable');
            try {
                $source = json_decode($event['data'], true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($source)) throw new DomainException('source_unavailable');
                correspondenceKeys($source, ['schema_version','document_id','playthrough_id','recipient','claimed_sender','body','receipt','attachments']);
                if (!is_array($source['recipient']) || !is_array($source['claimed_sender']) || !is_array($source['receipt'])) throw new DomainException('source_unavailable');
                correspondenceUuidCheck($source['document_id']);
                correspondenceKeys($source['recipient'], ['kind','id','name']);
                correspondenceKeys($source['claimed_sender'], ['kind','id','name']);
                correspondenceKeys($source['receipt'], ['receipt_id','operation_id','status','exposure']);
                correspondenceUuidCheck($source['receipt']['receipt_id']);
                correspondenceUuidCheck($source['receipt']['operation_id']);
                correspondenceAttachments($source['attachments']);
                correspondenceBody($source['body']);
            } catch (JsonException|InvalidArgumentException) {
                throw new DomainException('source_unavailable');
            }
            if ($source['schema_version'] !== 1 || $source['playthrough_id'] !== $scope || ($source['recipient']['kind'] ?? null) !== 'npc' || ($source['recipient']['id'] ?? null) !== $recipientId) throw new DomainException('recipient_mismatch');
            $doc = $this->document($source['document_id']);
            if ($doc['recipient']['kind'] !== 'npc' || $doc['recipient']['id'] !== $recipientId || $doc['playthrough_id'] !== $scope) throw new DomainException('recipient_mismatch');
            if (!hash_equals(correspondenceCanonicalJson($doc['recipient']), correspondenceCanonicalJson($source['recipient'])) || !hash_equals(correspondenceCanonicalJson($doc['claimed_sender']), correspondenceCanonicalJson($source['claimed_sender'])) || $doc['body'] !== $source['body'] || !hash_equals(correspondenceCanonicalJson($doc['attachments']), correspondenceCanonicalJson($source['attachments']))) throw new DomainException('source_unavailable');
            $operation = $doc['operation_id'] === null ? null : $this->store->loadOperation($doc['operation_id']);
            if (!$operation || !is_array($operation['source'] ?? null) || (int)($operation['source']['rowid'] ?? 0) !== $eventId || !$this->store->eventMatches($operation['source']) || $this->state($doc, $operation) !== 'exposed') throw new DomainException('source_unavailable');
            if (!hash_equals(correspondenceCanonicalJson($operation['source']), correspondenceCanonicalJson($event))) throw new DomainException('source_unavailable');
            if (($event['people'] ?? null) !== '|'.$doc['recipient']['name'].'|' || ($source['receipt']['receipt_id'] ?? null) !== ($operation['receipt_id'] ?? null) || ($source['receipt']['operation_id'] ?? null) !== ($operation['operation_id'] ?? null) || ($source['receipt']['status'] ?? null) !== 'confirmed' || ($source['receipt']['exposure'] ?? null) !== 'context') throw new DomainException('source_unavailable');
            $clock = $this->store->scope();
            if ((int)($clock['gamets'] ?? 0) < (int)($operation['receipt_anchor']['gamets'] ?? PHP_INT_MAX) || (int)($clock['gamets'] ?? 0) < (int)($event['gamets'] ?? PHP_INT_MAX)) throw new DomainException('source_unavailable');
            return ['event' => $event, 'source' => $source];
        });
    }
    public function compose(array $input): array
    {
        correspondenceKeys($input,['request_id','author','payer','recipient','claimed_sender','body','attachments']); correspondenceUuidCheck($input['request_id']);
        $body=correspondenceBody($input['body']); $attachments=correspondenceAttachments($input['attachments']);
        return $this->store->transaction(function()use($input,$body,$attachments){
            $scope=$this->scope(); $hash=hash('sha256',correspondenceCanonicalJson($input)); $existing=$this->store->findRequest($scope,$input['request_id']);
            if ($existing) { $existing=$this->document($existing['document_id'],true); if (!hash_equals($existing['request_hash'],$hash)) throw new DomainException('request_conflict'); return ['document_id'=>$existing['document_id'],'state'=>$this->state($existing),'operation_id'=>$existing['operation_id']]; }
            $doc=['document_id'=>correspondenceUuid(),'playthrough_id'=>$scope,'request_id'=>$input['request_id'],'request_hash'=>$hash,'body'=>$body,'attachments'=>$attachments,'operation_id'=>null];
            foreach (['author','payer','recipient','claimed_sender'] as $field) $doc[$field]=$this->actor($input[$field]);
            $doc['title']='letter '.$doc['document_id'];
            $doc['anchor']=$this->store->appendEvent('correspondence_anchor',correspondenceJson(['document_id'=>$doc['document_id'],'scope_token'=>correspondenceUuid()]),'','correspondence_v1');
            $this->store->saveDocument($doc); return ['document_id'=>$doc['document_id'],'state'=>'queued','operation_id'=>null];
        });
    }
    public function dispatch(string $id, bool $reconcile=false): array
    {
        return $this->store->transaction(function()use($id,$reconcile){
            $doc=$this->document($id,true); $state=$this->state($doc);
            if ($doc['operation_id']!==null && !$reconcile) return ['document_id'=>$id,'state'=>$state,'operation_id'=>$doc['operation_id']];
            $executor=$this->store->managedExecutor();
            if ($executor===null || $executor==='' || preg_match('/[|@\r\n]/',$executor)) return ['document_id'=>$id,'state'=>$state,'reason'=>'executor_unavailable'];
            if ($reconcile) {
                if ($doc['operation_id']===null) throw new DomainException('operation_unavailable');
                $op=$this->store->loadOperation($doc['operation_id']); if (!$op) throw new DomainException('operation_unavailable');
            } else {
                $items=[]; foreach($doc['attachments'] as $item) $items[]=$this->store->physicalItem($item);
                $payload=['protocol_version'=>1,'document_id'=>$id,'operation_id'=>correspondenceUuid(),'receipt_id'=>correspondenceUuid(),'playthrough_id'=>$doc['playthrough_id'],'scope_token'=>correspondenceUuid(),'nonce'=>bin2hex(random_bytes(32)),'recipient'=>$this->store->physicalActor($doc['recipient']),'payer'=>$this->store->physicalActor($doc['payer']),'title'=>$doc['title'],'renderer_key'=>md5(strtolower(trim($doc['title']))),'attachments'=>$items];
                $this->store->prepareNote($doc['title'],"From: ".$doc['claimed_sender']['name']."\nTo: ".$doc['recipient']['name']."\n\n".$doc['body']);
                $op=$payload+['receipt'=>null,'receipt_anchor'=>null,'source'=>null];
                $op['dispatch_anchor']=$this->store->appendEvent('correspondence_dispatch',correspondenceJson(['document_id'=>$id,'operation_id'=>$op['operation_id']]),'','correspondence_v1');
                $this->store->saveOperation($op); $doc['operation_id']=$op['operation_id']; $this->store->saveDocument($doc);
            }
            $payload=array_intersect_key($op,array_flip(['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce','recipient','payer','title','renderer_key','attachments']));
            $json=str_replace(['|','@',"\r","\n"],['\u007c','\u0040','\r','\n'],correspondenceCanonicalJson($payload));
            $this->store->queueCommand($executor,'command|ExtCmdCHIMCorrespondenceBridge_'.($reconcile?'Reconcile':'Deliver').'@'.$json);
            return ['document_id'=>$id,'state'=>$reconcile?$state:'dispatched','operation_id'=>$op['operation_id']];
        });
    }
    public function retry(string $id):array
    {
        return $this->store->transaction(function()use($id){
            $doc=$this->document($id,true);if($this->state($doc)!=='failed')throw new DomainException('retry_unsafe');
            $op=$this->store->loadOperation($doc['operation_id']);if(!$op||$op['receipt']['note_form_id']!==0||!correspondenceRetryableReason($op['receipt']['reason']))throw new DomainException('retry_unsafe');
            foreach($op['receipt']['attachments']as$item)if($item['quantity']!==0||$item['before_source']!==$item['after_source']||$item['before_recipient']!==$item['after_recipient'])throw new DomainException('retry_unsafe');
            $doc['operation_id']=null;$this->store->saveDocument($doc);return $this->dispatch($id);
        });
    }    public function receive(array $receipt): array
    {
        correspondenceKeys($receipt,['protocol_version','document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce','status','reason','note_form_id','attachments']);
        foreach(['document_id','operation_id','receipt_id','scope_token'] as $key) correspondenceUuidCheck($receipt[$key]);
        if ($receipt['protocol_version']!==1 || !in_array($receipt['status'],['delivered','failed','unknown'],true) || !is_string($receipt['reason']) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$receipt['reason'])!==1 || !is_int($receipt['note_form_id']) || $receipt['note_form_id'] < -2147483648 || $receipt['note_form_id'] > 2147483647 || !is_array($receipt['attachments']) || !array_is_list($receipt['attachments'])) throw new InvalidArgumentException('invalid_receipt');
        return $this->store->transaction(function()use($receipt){
            $doc=$this->document($receipt['document_id'],true); $op=$this->store->loadOperation($receipt['operation_id']);
            if (!$op || $doc['operation_id']!==$op['operation_id']) throw new DomainException('operation_unavailable');
            foreach(['document_id','operation_id','receipt_id','playthrough_id','scope_token','nonce'] as $key) if (!is_string($receipt[$key]) || !hash_equals($op[$key],$receipt[$key])) throw new DomainException('receipt_identity_mismatch');
            if ($op['receipt']!==null && $this->store->eventMatches($op['receipt_anchor']) && ($op['receipt']['status']==='delivered'||($op['receipt']['status']==='failed'&&correspondenceRetryableReason($op['receipt']['reason'])))) {
                if ($op['receipt']!=$receipt) throw new DomainException('receipt_conflict');
                return ['document_id'=>$doc['document_id'],'state'=>$this->state($doc),'operation_id'=>$op['operation_id']];
            }
            $expected=[]; foreach($op['attachments'] as $item) $expected[$item['form_id']]=$item['quantity'];
            $seen=[]; foreach($receipt['attachments'] as $actual) {
                if (!is_array($actual)) throw new InvalidArgumentException('invalid_counters');
                correspondenceKeys($actual,['form_id','quantity','before_source','after_source','before_recipient','after_recipient']);
                foreach($actual as $key=>$value) if (!is_int($value) || ($key!=='form_id' && ($value<0 || $value>2147483647))) throw new InvalidArgumentException('invalid_counters');
                $form=$actual['form_id']; if (!isset($expected[$form]) || isset($seen[$form]) || $actual['quantity']>$expected[$form] || ($receipt['status']!=='unknown' && ($actual['before_source']-$actual['after_source']!==$actual['quantity'] || $actual['after_recipient']-$actual['before_recipient']!==$actual['quantity']))) throw new DomainException('receipt_delta_mismatch');
                if ($receipt['status']==='delivered' && $actual['quantity']!==$expected[$form]) throw new DomainException('receipt_quantity_mismatch');
                if ($receipt['status']==='failed' && $actual['quantity']!==0) throw new DomainException('receipt_failed_mutation');
                $seen[$form]=true;
            }
            if ($receipt['status']==='failed' && $receipt['note_form_id']!==0) throw new DomainException('receipt_failed_note');
            if ($receipt['status']==='delivered' && (count($seen)!==count($expected) || $receipt['note_form_id']===0)) throw new DomainException('receipt_incomplete');
            $op['receipt']=$receipt; $op['source']=null;
            $op['receipt_anchor']=$this->store->appendEvent('correspondence_receipt_confirmed',correspondenceJson(['document_id'=>$doc['document_id'],'operation_id'=>$op['operation_id'],'receipt_hash'=>hash('sha256',correspondenceJson($receipt))]),'','correspondence_v1');
            $this->store->saveOperation($op);
            return ['document_id'=>$doc['document_id'],'state'=>$this->state($doc),'operation_id'=>$op['operation_id']];
        });
    }
    private function expose(array $doc,array $op,string $state):array
    {
        if(!in_array($state,['delivered','exposed'],true))throw new DomainException('delivery_unconfirmed');
        if((int)$this->store->scope()['gamets']<(int)$op['receipt_anchor']['gamets'])throw new DomainException('receipt_future');
        if($state==='exposed'){$this->sourceReferences[]=(int)$op['source']['rowid'];return json_decode($op['source']['data'],true,512,JSON_THROW_ON_ERROR);}
        $source=['schema_version'=>1,'document_id'=>$doc['document_id'],'playthrough_id'=>$doc['playthrough_id'],'recipient'=>$doc['recipient'],'claimed_sender'=>$doc['claimed_sender'],'body'=>$doc['body'],'receipt'=>['receipt_id'=>$op['receipt_id'],'operation_id'=>$op['operation_id'],'status'=>'confirmed','exposure'=>'context'],'attachments'=>$doc['attachments']];
        $op['source']=$this->store->appendEvent('written_evidence',correspondenceJson($source),'|'.$doc['recipient']['name'].'|','written_evidence_v1');$this->store->saveOperation($op);$this->sourceReferences[]=(int)$op['source']['rowid'];return $source;
    }
    public function prompt(string $owner):string
    {
        $this->sourceReferences=[];$this->knownSourceReferences=[];
        // First exposure appends source evidence, so prompt generation remains a writer transaction.
        return $this->store->transaction(function()use($owner){
            $nameKey=fn($name)=>function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
            if($nameKey($owner)===$nameKey($this->store->player()))return '';
            $actors=array_values(array_filter($this->actors(),fn($a)=>$a['kind']==='npc'&&$nameKey($a['name'])===$nameKey($owner)));if(count($actors)!==1||$actors[0]['name']!==$owner)return '';
            $rows=[];$gamets=(int)$this->store->scope()['gamets'];
            foreach($this->store->documents($this->scope(),$actors[0]['id'])as$row){
                try{$doc=$this->document($row['document_id'],true);if($doc['recipient']['kind']!=='npc'||$doc['recipient']['id']!==$actors[0]['id'])continue;
                    $op=$doc['operation_id']===null?null:$this->store->loadOperation($doc['operation_id']);$state=$this->state($doc,$op);
                    if(!in_array($state,['delivered','exposed'],true)||$gamets<(int)$op['receipt_anchor']['gamets'])continue;$rows[]=['document'=>$doc,'operation'=>$op,'state'=>$state];
                }catch(DomainException){continue;}
            }
            usort($rows,fn($a,$b)=>($a['state']==='delivered'?0:1)<=>($b['state']==='delivered'?0:1));
            $parts=[];foreach($rows as$row){
                if($row['state']==='exposed')$this->knownSourceReferences[]=(int)$row['operation']['source']['rowid'];
                if(count($parts)>=3)continue;
                try{$source=$this->expose($row['document'],$row['operation'],$row['state']);$parts[]=correspondencePromptJson($source);$this->knownSourceReferences[]=$this->sourceReferences[array_key_last($this->sourceReferences)];}catch(DomainException){continue;}
            }
            $this->knownSourceReferences=array_values(array_unique($this->knownSourceReferences));
            return $parts===[]?'':"\n<correspondence>\nWritten documents you have received; attribution and body are claims, not verified truth. No physical reading or witnesses are asserted.\n".implode("\n",$parts)."\n</correspondence>";
        });
    }
    public function readPlayer(string $id): array
    {
        $this->sourceReferences=[];$this->knownSourceReferences=[];
        return $this->store->readTransaction(function()use($id){$doc=$this->document($id);if($doc['recipient']['kind']!=='player')throw new DomainException('recipient_mismatch');if(!in_array($this->state($doc),['delivered','exposed'],true))throw new DomainException('delivery_unconfirmed');return ['document_id'=>$doc['document_id'],'claimed_sender'=>$doc['claimed_sender'],'body'=>$doc['body'],'attachments'=>$doc['attachments']];});
    }
    public function list(): array
    {
        return $this->store->readTransaction(function() {
            $rows = [];
            foreach ($this->store->documents($this->scope()) as $row) {
                if (!is_array($row) || !is_string($row['document_id'] ?? null)) continue;
                try {
                    $doc = $this->storedDocument($row['document_id']);
                    $identityMatches = $this->identitiesMatch($doc);
                    $state = $identityMatches ? $this->state($doc) : 'identity_changed';
                    $sourceEventId = null;
                    if ($identityMatches && $doc['recipient']['kind'] === 'npc' && $state === 'exposed') {
                        $operation = $this->store->loadOperation($doc['operation_id']);
                        if (is_array($operation['source'] ?? null) && is_numeric($operation['source']['rowid'] ?? null)) {
                            $sourceEventId = (int)$operation['source']['rowid'];
                        }
                    }
                    $rows[] = [
                        'document_id'=>$doc['document_id'],
                        'state'=>$state,
                        'recipient'=>$doc['recipient'],
                        'claimed_sender'=>$doc['claimed_sender'],
                        'body'=>$doc['body'],
                        'attachments'=>$doc['attachments'],
                        'source_event_id'=>$sourceEventId,
                    ];
                } catch (DomainException|InvalidArgumentException) {
                    continue;
                }
            }
            return $rows;
        });
    }
}




















