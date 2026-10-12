<?php
/** Isolated runner ONLY: ZIP shim for the dedicated test server without PHP zip. */
if (getenv('ACHBOT_PRIVATE_TEST') !== '1') exit(3);
class ZipArchive {
    const CREATE = 1;
    const OVERWRITE = 8;
    private $output;
    private $input;
    public function open($file, $flags) {
        if (getenv('ACHBOT_TEST_ZIP_ERROR') === '1') return false;
        $this->output = $file;
        return true;
    }
    public function addFile($source, $name) {
        if ($name !== 'bot_backup.sql') return false;
        $this->input = $source;
        return is_file($source);
    }
    public function close() {
        $command = '/usr/bin/zip -q -j ' . escapeshellarg($this->output) . ' ' . escapeshellarg($this->input);
        $output = []; $status = 0;
        exec($command, $output, $status);
        return $status === 0;
    }
}
