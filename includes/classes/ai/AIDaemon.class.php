<?php

/**
 * Antaris AI Player System
 * 
 * The AI Daemon runs as a persistent PHP CLI process.
 * It periodically processes all active AI players.
 *
 * @package Antaris
 * @subpackage AI
 */

require_once('includes/classes/ai/AIPlayer.class.php');
require_once('includes/classes/ai/AIDecisionEngine.class.php');

class AIDaemon
{
	private $running = false;
	private $tickInterval;
	private $pidFile;
	private $logFile;
	
	function __construct()
	{
		$this->pidFile = '/tmp/antaris_ai_daemon.pid';
		$this->logFile = ROOT_PATH.'includes/ai_daemon.log';
		$this->tickInterval = (int) AIConfigHelper::get('ai_tick_interval');
		if ($this->tickInterval < 10) {
			$this->tickInterval = 60;
		}
	}
	
	/**
	 * Start the daemon
	 */
	public function start()
	{
		if ($this->isRunning()) {
			$this->log("Daemon is already running (PID: ".$this->getRunningPID().")");
			return false;
		}
		
		$this->log("Starting AI Daemon...");
		$this->running = true;
		
		// Write PID file
		$pid = getmypid();
		file_put_contents($this->pidFile, $pid);
		AIConfigHelper::set('ai_daemon_pid', $pid);
		
		// Install signal handlers if available
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGTERM, array($this, 'handleSignal'));
			pcntl_signal(SIGINT,  array($this, 'handleSignal'));
			pcntl_signal(SIGHUP,  array($this, 'handleSignal'));
		}
		
		$this->log("AI Daemon started (PID: ".$pid.", interval: ".$this->tickInterval."s)");
		
		// Main loop
		$this->mainLoop();
		
		return true;
	}
	
	/**
	 * Main processing loop
	 */
	private function mainLoop()
	{
		$tickCount = 0;
		
		while ($this->running) {
			$tickStart = time();
			$tickCount++;
			
			try {
				// Dispatch signals if available
				if (function_exists('pcntl_signal_dispatch')) {
					pcntl_signal_dispatch();
				}
				
				// Check if AI is still enabled
				AIConfigHelper::clearCache();
				if (AIConfigHelper::get('ai_enabled') != '1') {
					$this->log("AI system disabled, sleeping...");
					sleep($this->tickInterval);
					continue;
				}
				
				// Reload tick interval in case it changed
				$newInterval = (int) AIConfigHelper::get('ai_tick_interval');
				if ($newInterval >= 10) {
					$this->tickInterval = $newInterval;
				}
				
				// Process all AI players
				$this->processTick($tickCount);
				
				// Update daemon status
				AIConfigHelper::set('ai_daemon_last_tick', TIMESTAMP);
				
			} catch (Exception $e) {
				$this->log("ERROR in tick #".$tickCount.": ".$e->getMessage());
			}
			
			// Memory cleanup every 100 ticks
			if ($tickCount % 100 == 0) {
				$this->log("Memory cleanup (tick #".$tickCount.", memory: ".round(memory_get_usage()/1024/1024, 2)."MB)");
				gc_collect_cycles();
			}
			
			// Sleep until next tick
			$elapsed = time() - $tickStart;
			$sleepTime = max(1, $this->tickInterval - $elapsed);
			sleep($sleepTime);
		}
		
		$this->cleanup();
	}
	
	/**
	 * Process one tick: iterate over all active AI players
	 */
	private function processTick($tickCount)
	{
		
		$aiPlayers = $GLOBALS['DATABASE']->query(
			"SELECT id FROM ".USERS." 
			WHERE is_ai = 1 
			AND urlaubs_modus = 0 
			AND user_deleted = 0 
			ORDER BY id ASC;"
		);
		
		$playerCount = 0;
		$actionCount = 0;
		
		while ($row = $GLOBALS['DATABASE']->fetch_array($aiPlayers)) {
			try {
				$ai = new AIPlayer($row['id']);
				
				if (!$ai->isReadyForAction()) {
					continue;
				}
				
				// Update resources first
				$ai->updateResources();
				
				// Run decision engine
				$engine = new AIDecisionEngine($ai);
				$actions = $engine->executeTick();
				
				// Log and save state
				foreach ($actions as $action) {
					$ai->logAction(
						$action['strategy'],
						is_array($action['result']) ? serialize($action['result']) : $action['result'],
						'ok'
					);
					$actionCount++;
				}
				
				$ai->saveState(serialize(array('tick' => $tickCount, 'actions' => count($actions))));
				$playerCount++;
				
			} catch (Exception $e) {
				$this->log("ERROR processing AI player #".$row['id'].": ".$e->getMessage());
			}
		}
		
		$GLOBALS['DATABASE']->free_result($aiPlayers);
		
		if ($playerCount > 0 || $tickCount % 60 == 0) {
			$this->log("Tick #".$tickCount.": processed ".$playerCount." AI players, ".$actionCount." actions");
		}
	}
	
	/**
	 * Stop the daemon
	 */
	public function stop()
	{
		$pid = $this->getRunningPID();
		if ($pid && $pid != getmypid()) {
			$this->log("Sending SIGTERM to PID ".$pid);
			if (function_exists('posix_kill')) {
				posix_kill($pid, SIGTERM);
			}
		}
		
		$this->running = false;
		$this->cleanup();
		$this->log("AI Daemon stopped.");
		return true;
	}
	
	/**
	 * Get daemon status
	 */
	public function status()
	{
		$running = $this->isRunning();
		$pid = $this->getRunningPID();
		$lastTick = AIConfigHelper::get('ai_daemon_last_tick');
		
		return array(
			'running'   => $running,
			'pid'       => $pid,
			'last_tick' => $lastTick,
			'interval'  => $this->tickInterval,
		);
	}
	
	/**
	 * Signal handler for graceful shutdown
	 */
	public function handleSignal($signal)
	{
		switch ($signal) {
			case SIGTERM:
			case SIGINT:
				$this->log("Received signal ".$signal.", shutting down...");
				$this->running = false;
				break;
			case SIGHUP:
				$this->log("Received SIGHUP, reloading config...");
				AIConfigHelper::clearCache();
				$newInterval = (int) AIConfigHelper::get('ai_tick_interval');
				if ($newInterval >= 10) {
					$this->tickInterval = $newInterval;
				}
				break;
		}
	}
	
	/**
	 * Check if the daemon is currently running
	 */
	public function isRunning()
	{
		$pid = $this->getRunningPID();
		if (empty($pid)) {
			return false;
		}
		
		// Check if process is actually alive
		if (function_exists('posix_kill')) {
			return posix_kill($pid, 0);
		}
		
		// Fallback: check /proc filesystem
		return file_exists('/proc/'.$pid);
	}
	
	/**
	 * Get the PID from the PID file
	 */
	private function getRunningPID()
	{
		if (!file_exists($this->pidFile)) {
			return false;
		}
		
		$pid = (int) trim(file_get_contents($this->pidFile));
		return $pid > 0 ? $pid : false;
	}
	
	/**
	 * Cleanup on shutdown
	 */
	private function cleanup()
	{
		if (file_exists($this->pidFile)) {
			unlink($this->pidFile);
		}
		AIConfigHelper::set('ai_daemon_pid', '');
	}
	
	/**
	 * Write to log file
	 */
	private function log($message)
	{
		$line = '['.date('Y-m-d H:i:s').'] '.$message."\n";
		file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
		
		// Also echo if running in foreground
		if (php_sapi_name() === 'cli') {
			echo $line;
		}
	}
}
