<?php
// Membership declaration first, then an inner namespace: members are prefixed
// with the module boundary, yielding "Vendor\User::Auth\PasswordChecker".
module Vendor\User;
namespace Auth;

class PasswordChecker {
    public function tag(): string { return "checker"; }
}
