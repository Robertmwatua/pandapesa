<?php
require_once __DIR__ . '/../api/session.php';
session_unset();
session_destroy();
header('Location: /admin/login.php');
exit;