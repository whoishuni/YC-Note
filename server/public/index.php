<?php
declare(strict_types=1);
// The management UI uses the rental application's native Session and ACL.
$tab=is_string($_GET['page']??null)?$_GET['page']:'notes';
if (!in_array($tab,['notes','devices','users','security'],true)) $tab='notes';
header('Cache-Control: no-store');
header('Location: /rent/admin/notes.php?tab='.rawurlencode($tab),true,302);
exit;
