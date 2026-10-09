<?php
$accButtons = '<button type="button" class="btn btn-primary" id="addRuleBtn"><i class="ri-add-line me-1"></i>Add a rule</button>';
include __DIR__ . '/toolbar.php';
?>
<div class="alert alert-info d-flex gap-2 align-items-start"><i class="ri-information-line mt-1"></i><div>Every requisition and payment voucher is approved by the rule for its level and amount. A rule for one kind of document beats one for "any money document". If the person asking is the approver, the stage goes to whoever it escalates to. Changes apply to new requests; ones already waiting keep their rule.</div></div>
<div id="ruleGroups"></div>
