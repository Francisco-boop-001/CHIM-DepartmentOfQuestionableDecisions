<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/protocol.php';
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

echo "PASS {$checks} authoring PHP protocol checks\n";
