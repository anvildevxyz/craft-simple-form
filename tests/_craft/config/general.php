<?php

return [
    'devMode' => true,
    // Production always has a securityKey; set one here so tests exercise the
    // real keyed-hash / encryption paths (MCP tokens F9/F12, integration
    // secrets F4) instead of degraded fallbacks.
    'securityKey' => 'simple-form-test-security-key-0123456789',
];
