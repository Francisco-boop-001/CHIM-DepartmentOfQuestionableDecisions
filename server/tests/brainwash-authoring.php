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
echo "PASS {$checks} authoring PHP protocol checks\n";
