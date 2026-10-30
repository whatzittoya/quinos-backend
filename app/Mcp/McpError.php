<?php

namespace App\Mcp;

use RuntimeException;

/** A JSON-RPC protocol error; the code is the JSON-RPC error code. */
class McpError extends RuntimeException {}
