<?php
/** Read-only synthetic preflight fixture; zero real database/network/payment. */
if (PHP_SAPI !== 'cli') exit(3);
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$from_id = 1001;
$admin = 9001;
$alerts = [];
function alert($message, $urgent = false) {
    $GLOBALS['alerts'][] = (string)$message;
}
function sendMessage(...$args) {
    throw new RuntimeException('Unexpected notification in preflight');
}
final class FixtureRows {
    public $num_rows;
    private $row;
    public function __construct($row) {
        $this->row = $row;
        $this->num_rows = $row ? 1 : 0;
    }
    public function fetch_assoc() { return $this->row; }
}
final class FixtureStatement {
    private $connection;
    private $sql;
    public function __construct($connection, $sql) {
        $this->connection = $connection;
        $this->sql = $sql;
    }
    public function bind_param(...$args) {}
    public function execute() {}
    public function get_result() {
        if (strpos($this->sql, 'FROM `pays`') !== false) {
            return new FixtureRows([
                'hash_id' => 'synthetic-only',
                'state' => 'pending',
                'type' => $this->connection->case['paytype'],
                'plan_id' => 7,
                'volume' => $this->connection->case['renew_inbound'],
                'user_id' => 1001,
                'price' => 50
            ]);
        }
        if (strpos($this->sql, 'FROM `server_plans`') !== false) {
            return new FixtureRows($this->connection->case['row']);
        }
        throw new RuntimeException('Unexpected read');
    }
    public function close() {}
}
final class FixtureDatabase {
    public $case;
    public $rollbacks = 0;
    public $commits = 0;
    public $reads = 0;
    public function __construct($case) { $this->case = $case; }
    public function begin_transaction() {}
    public function prepare($sql) {
        if (!preg_match('/^SELECT\\s/i', ltrim($sql))) {
            throw new RuntimeException('Financial mutation attempted during rejected preflight');
        }
        $this->reads++;
        return new FixtureStatement($this, $sql);
    }
    public function rollback() { $this->rollbacks++; }
    public function commit() { $this->commits++; }
}
require dirname(__DIR__) . '/src/Finance/WalletSettlement.php';
$cases = [
    ['paytype'=>'BUY_SUB','renew_inbound'=>0,'row'=>['type'=>'sanaei','inbound_id'=>0]],
    ['paytype'=>'BUY_SUB','renew_inbound'=>0,'row'=>['type'=>'alireza','inbound_id'=>0]],
    ['paytype'=>'BUY_SUB','renew_inbound'=>0,'row'=>['type'=>'normal','inbound_id'=>0]],
    ['paytype'=>'BUY_SUB','renew_inbound'=>0,'row'=>['type'=>'future','inbound_id'=>0]],
    ['paytype'=>'BUY_SUB','renew_inbound'=>0,'row'=>['type'=>'marzban','inbound_id'=>7]],
    ['paytype'=>'RENEW_SCONFIG','renew_inbound'=>7,'row'=>['type'=>'marzban','inbound_id'=>0]],
    ['paytype'=>'BUY_SUB','renew_inbound'=>0,'row'=>null],
];
foreach ($cases as $case) {
    $db = new FixtureDatabase($case);
    $invoked = false;
    $result = processWalletPayment($db, 'synthetic-only',
        function () use (&$invoked) { $invoked = true; return true; });
    if ($result['status'] !== 'unsupported_panel' ||
        $invoked || $db->rollbacks !== 1 || $db->commits !== 0 ||
        $db->reads !== 2) {
        throw new RuntimeException('Unrecognized panel failed pre-commit guard');
    }
}
if (count($alerts) !== count($cases)) {
    throw new RuntimeException('No safe user-visible unsupported panel feedback');
}
echo json_encode(['complete'=>true,'cases'=>count($cases),
                  'http_calls'=>0,'committed_sql_mutations'=>0]);
