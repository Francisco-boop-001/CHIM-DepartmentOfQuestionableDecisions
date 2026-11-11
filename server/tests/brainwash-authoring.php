<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/protocol.php';
require_once __DIR__ . '/../lib/dispatcher.php';
$checks=0;
function check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$fields = array_fill_keys(QD_BRAINWASH_FIELDS, 'Unsaved author text.');
$payload = (object)['actorKey'=>'npc:42', 'expectedRevision'=>str_repeat('a',64), 'operationId'=>'123e4567-e89b-12d3-a456-426614174000', 'mode'=>'ai', 'changes'=>(object)['personality'=>'Unsaved personality.'], 'instruction'=>'Keep her dry humour.', 'authoring'=>(object)['workingCard'=>(object)$fields, 'conversation'=>[(object)['role'=>'user','content'=>'Keep her dry humour.']], 'historyLimit'=>200]];
check(qd_validate_payload('brainwash.catalog.read',(object)['nearby'=>true])==='ok','Nearby catalog must pass PHP boundary.');
check(qd_validate_payload('brainwash.draft',$payload)==='ok','Authoring must pass PHP boundary.');
foreach (['system', '', 12] as $role) { $bad=unserialize(serialize($payload)); $bad->authoring->conversation[0]->role=$role; check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Invalid authoring role must fail.'); }
foreach ([201,-1,true,'200'] as $limit) { $bad=unserialize(serialize($payload));$bad->authoring->historyLimit=$limit;check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Invalid history bound must fail.'); }
$bad=unserialize(serialize($payload));unset($bad->authoring->workingCard->core);check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Incomplete working card must fail.');
$bad=unserialize(serialize($payload));$bad->mode='manual';check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Authoring must be AI-only.');
$bad=unserialize(serialize($payload));$bad->authoring->scopeKey='client override';check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Unknown authoring override must fail.');
$bad=unserialize(serialize($payload));$bad->authoring->conversation[0]->content=str_repeat('x',2001);check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Oversized message must fail.');

foreach (['creative','development'] as $intent) { $new=unserialize(serialize($payload));$new->authoring->intent=$intent;check(qd_validate_payload('brainwash.draft',$new)==='ok','Explicit intent must pass.'); }
$bad=unserialize(serialize($payload));$bad->authoring->intent='development';$bad->authoring->historyLimit=0;check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Development needs recorded history.');
foreach (['',null,'unknown',1] as $intent) { $bad=unserialize(serialize($payload));$bad->authoring->intent=$intent;check(qd_validate_payload('brainwash.draft',$bad)==='invalid_request','Invalid explicit intent must fail.'); }
$draft=(object)['origin'=>'ai','before'=>(object)['personality'=>'Quiet.'],'changes'=>(object)['personality'=>'Brusque.'],'authoring'=>(object)['reply'=>'Proposed.','evidence'=>[(object)['eventId'=>'12','reason'=>'Witnessed.','excerpt'=>'She agreed.']],'historyCount'=>1,'historyTruncated'=>false,'intent'=>'development','assessments'=>[(object)['field'=>'personality','basis'=>'evidence','reason'=>'A durable witnessed consequence.','eventIds'=>['12']]]]];
check(qd_brainwash_valid_draft_authoring($draft),'Bounded field evidence must pass PHP response boundary.');
$mutations=[
 fn($d)=>unset_intent($d),
 function($d){unset($d->authoring->assessments);},
 function($d){$d->authoring->assessments=[];},
 function($d){$d->authoring->assessments[]=$d->authoring->assessments[0];},
 function($d){$d->authoring->assessments[0]->field='core';},
 function($d){$d->authoring->assessments[0]->basis='forged';},
 function($d){$d->authoring->assessments[0]->reason="\u{0085}";},
 function($d){$d->authoring->assessments[0]->reason="\u{180e}";},
 function($d){$d->authoring->assessments[0]->reason='';},
 function($d){$d->authoring->assessments[0]->reason=" \t\u{00a0}";},
 function($d){$d->authoring->assessments[0]->reason=str_repeat('é',257);},
 function($d){$d->authoring->assessments[0]->eventIds=[];},
 function($d){$d->authoring->assessments[0]->eventIds=['13'];},
 function($d){$d->authoring->assessments[0]->eventIds=['12','12'];},
 function($d){$d->authoring->assessments[0]->eventIds=(object)[];},
 function($d){$d->authoring->assessments[0]->extra=true;},
 function($d){$d->authoring->assessments[0]->basis='unchanged';$d->authoring->assessments[0]->eventIds=[];},
 function($d){$d->authoring->assessments[0]->basis='author';$d->authoring->assessments[0]->eventIds=[];},
];
function unset_intent($d): void {unset($d->authoring->intent);}
foreach($mutations as $change){$bad=unserialize(serialize($draft));$change($bad);check(!qd_brainwash_valid_draft_authoring($bad),'Malformed or unsupported field assessment must fail.');}
$unchanged=unserialize(serialize($draft));$unchanged->changes->personality='Quiet.';$unchanged->authoring->assessments[0]->basis='unchanged';$unchanged->authoring->assessments[0]->eventIds=[];check(qd_brainwash_valid_draft_authoring($unchanged),'Saved-baseline unchanged proposal passes.');
$creative=unserialize(serialize($draft));$creative->authoring->intent='creative';$creative->authoring->assessments[0]->basis='author';$creative->authoring->assessments[0]->eventIds=[];check(qd_brainwash_valid_draft_authoring($creative),'Creative authorship is explicit.');
$belief=unserialize(serialize($draft));$belief->before=(object)[];$belief->changes=(object)[];$belief->beliefsBefore='Old belief';$belief->beliefs='New belief';$belief->authoring->assessments[0]->field='beliefs';check(qd_brainwash_valid_draft_authoring($belief),'Beliefs must be assessed too.');
$legacy=unserialize(serialize($draft));unset($legacy->authoring->intent,$legacy->authoring->assessments);check(qd_brainwash_valid_draft_authoring($legacy),'Legacy authoring output remains supported.');

$longField=unserialize(serialize($payload));$longField->changes=(object)['personality'=>str_repeat('é',32768)];check(qd_validate_payload('brainwash.draft',$longField)==='ok','A 65536-byte UTF-8 profile field passes.');
$longCard=unserialize(serialize($payload));$longCard->authoring->workingCard=(object)array_fill_keys(QD_BRAINWASH_FIELDS,'');$longCard->authoring->workingCard->core=str_repeat('é',32768);$longCard->authoring->workingCard->personality=str_repeat('é',32768);check(qd_validate_payload('brainwash.draft',$longCard)==='ok','A 131072-byte eight-field authored card passes.');
$largeField=unserialize(serialize($payload));$largeField->changes=(object)['personality'=>str_repeat('é',32768).'x'];check(qd_validate_payload('brainwash.draft',$largeField)==='invalid_request','A field one byte above the limit is refused.');
$largeCard=unserialize(serialize($longCard));$largeCard->authoring->workingCard->goals='x';check(qd_validate_payload('brainwash.draft',$largeCard)==='invalid_request','A card above the aggregate byte limit is refused.');
$makeRequest=static fn(string $operation, object $requestPayload):object=>(object)['version'=>1,'requestId'=>'123e4567-e89b-12d3-a456-426614174010','operation'=>$operation,'context'=>(object)['viewToken'=>'123e4567-e89b-12d3-a456-426614174011','nativeGeneration'=>'1','serverContext'=>str_repeat('b',64)],'payload'=>$requestPayload];
$largeRequest=$makeRequest('brainwash.draft',(object)['actorKey'=>'npc:42','expectedRevision'=>str_repeat('a',64),'operationId'=>'123e4567-e89b-12d3-a456-426614174012','mode'=>'manual','changes'=>(object)['personality'=>str_repeat('x',65536)],'instruction'=>'']);
$largeRequestJson=json_encode($largeRequest,JSON_THROW_ON_ERROR);check(strlen($largeRequestJson)>65536&&qd_parse_json_request($largeRequestJson)['error']===null,'A full-profile draft request exceeds the normal body cap safely.');
$largeAuthoredRequest=$makeRequest('brainwash.draft',$longCard);$largeAuthoredRequestJson=json_encode($largeAuthoredRequest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
check(strlen($largeAuthoredRequestJson)>65536&&qd_parse_json_request($largeAuthoredRequestJson)['error']===null,'A full authored-profile draft request parses through the production PHP boundary.');
$largeNormalRequest=$makeRequest('correspondence.catalog.read',(object)['cursor'=>str_repeat('x',65536)]);check(qd_parse_json_request(json_encode($largeNormalRequest,JSON_THROW_ON_ERROR))['error']==='payload_too_large','Non-profile requests retain the 65536-byte body cap.');
$oversizedBody=str_repeat(' ',2097153);check(qd_parse_json_request($oversizedBody)['error']==='payload_too_large','The global profile parsing read cap remains hard.');
$readRequest=(object)['version'=>1,'requestId'=>'123e4567-e89b-12d3-a456-426614174013','operation'=>'brainwash.card.read','context'=>(object)['viewToken'=>'123e4567-e89b-12d3-a456-426614174011','nativeGeneration'=>'1','serverContext'=>str_repeat('b',64)],'payload'=>(object)['actorKey'=>'npc:42']];
$largeFields=(object)array_fill_keys(QD_BRAINWASH_FIELDS,'');$largeFields->core=str_repeat('x',65536);$largeFields->personality=str_repeat('y',65536);
$largeCard=(object)['actorKey'=>'npc:42','label'=>'Lydia','scope'=>'synthetic-scope','revision'=>str_repeat('a',64),'fields'=>$largeFields,'beliefs'=>'','draftId'=>'123e4567-e89b-12d3-a456-426614174014','lastOperation'=>null];
$largeCardResponse=null;try{$largeCardResponse=qd_response_with_bounded_data($readRequest,$largeCard,false);}catch(Throwable){};$largeCardJson=is_array($largeCardResponse)?json_encode($largeCardResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):'';check(strlen($largeCardJson)>65536&&($largeCardResponse['data']->fields->core??null)===str_repeat('x',65536),'The full supported card response survives the ordinary response cap.');
$largeFields->goals='x';$largeProfileCode=null;try{qd_response_with_bounded_data($readRequest,$largeCard,false);}catch(QDServerFault $error){$largeProfileCode=$error->wireCode;}check($largeProfileCode==='profile_too_large'&&qd_http_status_for_error($largeProfileCode)===422,'A valid stored card above the supported aggregate returns profile_too_large.');
$largeFields->goals='';$largeCard->label=str_repeat('m',8193);$metadataCode=null;try{qd_response_with_bounded_data($readRequest,$largeCard,false);}catch(QDServerFault $error){$metadataCode=$error->wireCode;}check($metadataCode==='unavailable','Large metadata remains invalid even on a full-profile response.');
$largeCard->label='Lydia';$largeFields->core="\xff";$malformedCode=null;try{qd_response_with_bounded_data($readRequest,$largeCard,false);}catch(QDServerFault $error){$malformedCode=$error->wireCode;}check($malformedCode==='unavailable','Malformed stored text remains unavailable, not profile_too_large.');

$emitOptions=getopt('',['emit-dir:']);$emitDirectory=is_string($emitOptions['emit-dir']??null)?realpath($emitOptions['emit-dir']):false;
if(is_string($emitDirectory)&&$emitDirectory!==''&&is_dir($emitDirectory)){
    $ownerRoot=realpath(dirname($emitDirectory).DIRECTORY_SEPARATOR.'owners'.DIRECTORY_SEPARATOR.'mmp7'.DIRECTORY_SEPARATOR.'ext'.DIRECTORY_SEPARATOR.'brainwash');
    if(!is_string($ownerRoot)||!is_file($ownerRoot.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'service.php'))throw new RuntimeException('The staged frozen MMP dev7 source is required for wire export.');
    $ownerManifest=json_decode((string)file_get_contents($ownerRoot.DIRECTORY_SEPARATOR.'manifest.json'),true,8,JSON_THROW_ON_ERROR);
    if(($ownerManifest['version']??null)!=='0.1.0-dev.7')throw new RuntimeException('Wire export requires the exact frozen MMP dev7 owner.');
    $ownerPins=[
        'lib/service.php'=>['fda5dbc9b7695a195c63816d9c9535e88480f41920841979ab41172a4db9818a','2dbfbbf3ef11744afacef68894f5a239bd94fa940fa408de44bcd2a11357d123'],
        'lib/authoring.php'=>['a01a1954053e20503b553c284e0654a2f110329dfaa4d188474e8eb1eebb5bb5','c5281752fe4d0e7b125f468b82da4252ae569647247e6f8047b0fad8932c8ae6'],
    ];
    foreach($ownerPins as $relative=>$accepted){
        $source=(string)file_get_contents($ownerRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
        $lf=str_replace("\r\n","\n",$source);$crlf=str_replace("\n","\r\n",$lf);
        if(!in_array(hash('sha256',$lf),$accepted,true)&&!in_array(hash('sha256',$crlf),$accepted,true))throw new RuntimeException('Frozen MMP dev7 source pin mismatch: '.$relative);
    }
    require_once $ownerRoot.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'service.php';
    $ownerSerializer=(new ReflectionClass(BrainwashService::class))->newInstanceWithoutConstructor();
    $publicDraft=(new ReflectionMethod(BrainwashService::class,'publicDraft'));
    $publicDraft->setAccessible(true);
    $largeDraftPayload=unserialize(serialize($longCard));
    $largeDraftPayload->changes=(object)['personality'=>$largeDraftPayload->authoring->workingCard->personality];
    $largeDraftRequest=$makeRequest('brainwash.draft',$largeDraftPayload);
    $largeDraftRequestJson=json_encode($largeDraftRequest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    check(qd_parse_json_request($largeDraftRequestJson)['error']===null,'The owner-shaped large draft request parses through the production PHP boundary.');
    $ownerDraft=$publicDraft->invoke($ownerSerializer,[
        'scope'=>'synthetic-scope','revision'=>str_repeat('a',64),'draftId'=>'123e4567-e89b-12d3-a456-426614174014','origin'=>'ai',
        'before'=>['personality'=>str_repeat('b',65536)],'changes'=>['personality'=>str_repeat('c',65536)],
        'authoring'=>['reply'=>'Synthetic authoring result.','evidence'=>[],'historyCount'=>0,'historyTruncated'=>false,'intent'=>'creative','assessments'=>[['field'=>'personality','basis'=>'author','reason'=>'Synthetic authored change.','eventIds'=>[]]]],
    ],'npc:42');
    $largeDraftResponse=qd_response_with_bounded_data($largeDraftRequest,$ownerDraft,false);
    $largeDraftJson=json_encode($largeDraftResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $cardReadJson=json_encode($largeCardResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    check(strlen($largeDraftJson)>65536&&isset($largeDraftResponse['data']->before->personality,$largeDraftResponse['data']->changes->personality),'The frozen owner publicDraft survives the PHP response boundary in full.');
    $assertCorrelated=static function(object $request,array $response,string $label):void{
        check(($response['operation']??null)===$request->operation,$label.' request and response operations match.');
        check(($response['requestId']??null)===$request->requestId,$label.' request and response IDs match.');
        check(isset($response['context'])&&json_encode($response['context'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)===json_encode($request->context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$label.' request and response contexts match.');
    };
    $assertCorrelated($largeDraftRequest,$largeDraftResponse,'Draft wire');
    $assertCorrelated($readRequest,$largeCardResponse,'Card-read wire');
    $encodePair=static fn(object $request,mixed $response):string=>json_encode(['request'=>$request,'response'=>$response],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    $writeArtifact=static function(string $name,string $bytes)use($emitDirectory):void{if(file_put_contents($emitDirectory.DIRECTORY_SEPARATOR.$name,$bytes,LOCK_EX)===false)throw new RuntimeException('Could not emit synthetic MMP wire artifact: '.$name);};
    $legacyWireBytes=$encodePair(json_decode($largeAuthoredRequestJson,false,8,JSON_THROW_ON_ERROR),json_decode($largeCardJson,false,8,JSON_THROW_ON_ERROR));
    $writeArtifact('mmp-full-profile-draft-wire-mismatched-legacy.json',$legacyWireBytes);
    $draftWireBytes=$encodePair($largeDraftRequest,$largeDraftResponse);
    $writeArtifact('mmp-full-profile-draft-wire.json',$draftWireBytes);
    $draftMeta=['operation'=>'brainwash.draft','requestBytes'=>strlen($largeDraftRequestJson),'responseBytes'=>strlen($largeDraftJson),'pairBytes'=>strlen($draftWireBytes),'containsOnlySyntheticText'=>true,'responseProducedByFrozenOwner'=>'0.1.0-dev.7 BrainwashService::publicDraft'];
    $writeArtifact('mmp-full-profile-draft-wire-meta.json',json_encode($draftMeta,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    $cardWireBytes=$encodePair($readRequest,$largeCardResponse);
    $writeArtifact('mmp-full-profile-card-read-wire.json',$cardWireBytes);
    $cardMeta=['operation'=>'brainwash.card.read','requestBytes'=>strlen(json_encode($readRequest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'responseBytes'=>strlen($cardReadJson),'pairBytes'=>strlen($cardWireBytes),'containsOnlySyntheticText'=>true];
    $writeArtifact('mmp-full-profile-card-read-wire-meta.json',json_encode($cardMeta,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    $writeArtifact('mmp-full-profile-draft-wire-mismatched-legacy-meta.json',json_encode(['operation'=>'brainwash.draft','responseOperation'=>'brainwash.card.read','requestBytes'=>strlen($largeAuthoredRequestJson),'responseBytes'=>strlen($largeCardJson),'pairBytes'=>strlen($legacyWireBytes),'preservedAsKnownMismatchedFixture'=>true,'containsOnlySyntheticText'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
}

echo "PASS {$checks} authoring PHP protocol checks\n";
