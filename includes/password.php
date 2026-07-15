<?php
function password_is_strong($password)
{
    return is_string($password)
        && strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password)
        && preg_match('/[0-9]/', $password);
}

function password_strength_message()
{
    return '密码至少 8 位，并包含字母和数字。';
}
