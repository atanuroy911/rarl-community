<?php require_once __DIR__.'/functions.php'; if(session_status()===PHP_SESSION_NONE){session_name(MEMBER_SESSION_NAME);session_start();} session_unset(); session_destroy(); redirect('login.php');
