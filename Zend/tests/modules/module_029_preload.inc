<?php
module Vendor\App {
    public class Service { public function ok(): string { return "svc"; } }
    internal class Secret { public function ping(): string { return "secret"; } }
    public const OPEN = 1;
    internal const HIDDEN = 2;
}
