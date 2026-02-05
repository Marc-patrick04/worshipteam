<?php
require_once 'includes/config.php';

logAction('logout', 'User logged out');

session_destroy();

header('Location: index.php');
exit;
?>
