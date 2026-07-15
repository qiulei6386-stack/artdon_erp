<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('mail.view');
redirect('crm.php#mail');
