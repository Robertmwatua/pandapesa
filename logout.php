<?php
require_once __DIR__ . '/api/session.php';
session_unset();
session_destroy();
header('Location: /login.php');
exit;