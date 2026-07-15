<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('promotion.view');
redirect('crm.php#promotion');
