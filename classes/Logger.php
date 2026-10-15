<?php
class Logger {
    private $logFile;
    
    public function __construct($logFile = 'logs.txt') {
        $this->logFile = realpath(__DIR__ . '/' . $logFile);
        if (!file_exists($this->logFile)) {
            $logDir = dirname($this->logFile);
            
            // Create the directory if it doesn't exist
            if (!is_dir($logDir)) {
                if (!mkdir($logDir, 0777, true)) {
                    throw new Exception("Could not create log directory: " . $logDir);
                }
            }
            
            // Create the log file
            if (!touch($this->logFile)) {
                throw new Exception("Could not create log file: " . $this->logFile);
            }
        }
        
        // Check if the log file is writable
        if (!is_writable($this->logFile)) {
            throw new Exception("Log file is not writable: " . $this->logFile);
        }
    }
    private function log($level, $message, $extra = null) {
        $timestamp = date('Y-m-d H:i:s.u');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";
        if ($extra !== null) {
            $logEntry .= var_export($extra, true) . "\n";
        }
        // Open the log file and append the new log entry
        file_put_contents($this->logFile, $logEntry, FILE_APPEND);
    }
    public function debug($message, $extra = null) {
        $this->log('debug', $message, $extra);
    }
    public function info($message, $extra = null) {
        $this->log('info', $message, $extra);
    }
    public function notice($message, $extra = null) {
        $this->log('notice', $message, $extra);
    }
    public function warning($message, $extra = null) {
        $this->log('warning', $message, $extra);
    }
    public function error($message, $extra = null) {
        $this->log('error', $message, $extra);
    }
    public function critical($message, $extra = null) {
        $this->log('critical', $message, $extra);
    }
    public function alert($message, $extra = null) {
        $this->log('alert', $message, $extra);
    }
    public function emergency($message, $extra = null) {
        $this->log('emergency', $message, $extra);
    }
}
