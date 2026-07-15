<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('customer.view');
redirect('crm.php');
