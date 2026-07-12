<?php
// Module definition: claims the split-file members public so they are reachable
// from outside the module. (Unclaimed split-file symbols are internal by default.)
module Vendor\User {
    public Auth\PasswordChecker;
    public GuestUser;
}
