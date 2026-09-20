<?php
// PalPluss gateway was never implemented — safety-net no-op so the
// unconditional ping from trade.php doesn't 404.
header('Content-Type: application/json');
echo json_encode(['status' => 'not_configured']);
