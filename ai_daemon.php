<?php

/**
 * Antaris AI Player System - CLI Daemon Entry Point
 * 
 * Usage: php ai_daemon.php [start|stop|status|restart|tick]
 * 
 * start   - Start the AI daemon as a persistent process
 * stop    - Stop the running daemon
 * status  - Show daemon status
 * restart - Restart the daemon
 * tick    - Run a single tick (useful for cron fallback)
 *
 * @package Antaris
 * @subpackage AI
 */

if (php_sapi_name() !== 'cli') {
	die('Error: This script can only be run from the command line interface (CLI).');
}

define('MODE', 'AI_DAEMON');
define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)).'/');
set_include_path(ROOT_PATH);

// Load the game engine
require('includes/common.php');
require_once('includes/classes/ai/AIDaemon.class.php');

// Parse command
$command = isset($argv[1]) ? strtolower($argv[1]) : 'start';

$daemon = new AIDaemon();

switch ($command) {
	case 'start':
		echo "Starting AI Daemon...\n";
		$daemon->start();
		break;
		
	case 'stop':
		echo "Stopping AI Daemon...\n";
		$daemon->stop();
		echo "Daemon stopped.\n";
		break;
		
	case 'status':
		$status = $daemon->status();
		echo "AI Daemon Status:\n";
		echo "  Running:    ".($status['running'] ? 'YES' : 'NO')."\n";
		echo "  PID:        ".($status['pid'] ? $status['pid'] : 'N/A')."\n";
		echo "  Last Tick:  ".($status['last_tick'] ? date('Y-m-d H:i:s', $status['last_tick']) : 'Never')."\n";
		echo "  Interval:   ".$status['interval']."s\n";
		echo "  AI Enabled: ".(AIConfigHelper::get('ai_enabled') == '1' ? 'YES' : 'NO')."\n";
		
		// Count AI players
		$aiCount = $GLOBALS['DATABASE']->getFirstCell("SELECT COUNT(*) FROM ".USERS." WHERE is_ai = 1;");
		echo "  AI Players: ".$aiCount."\n";
		break;
		
	case 'restart':
		echo "Restarting AI Daemon...\n";
		$daemon->stop();
		sleep(2);
		$daemon->start();
		break;
		
	case 'tick':
		// Single tick mode (for cron fallback)
		echo "Running single AI tick...\n";
		require_once('includes/classes/ai/AIPlayer.class.php');
		require_once('includes/classes/ai/AIDecisionEngine.class.php');
		
		if (AIConfigHelper::get('ai_enabled') != '1') {
			echo "AI system is disabled.\n";
			exit(0);
		}
		
		$aiPlayers = $GLOBALS['DATABASE']->query(
			"SELECT id FROM ".USERS." WHERE is_ai = 1 AND urlaubs_modus = 0 AND user_deleted = 0;"
		);
		
		$count = 0;
		while ($row = $GLOBALS['DATABASE']->fetch_array($aiPlayers)) {
			try {
				$ai = new AIPlayer($row['id']);
				if ($ai->isReadyForAction()) {
					$ai->updateResources();
					$engine = new AIDecisionEngine($ai);
					$actions = $engine->executeTick();
					$ai->saveState(serialize(array('tick' => 'manual', 'actions' => count($actions))));
					$count++;
					echo "  Player #".$row['id'].": ".count($actions)." actions\n";
				}
			} catch (Exception $e) {
				echo "  ERROR Player #".$row['id'].": ".$e->getMessage()."\n";
			}
		}
		
		$GLOBALS['DATABASE']->free_result($aiPlayers);
		echo "Processed ".$count." AI players.\n";
		break;
		
	default:
		echo "Usage: php ai_daemon.php [start|stop|status|restart|tick]\n";
		echo "\n";
		echo "  start   - Start the AI daemon\n";
		echo "  stop    - Stop the running daemon\n";
		echo "  status  - Show daemon status\n";
		echo "  restart - Restart the daemon\n";
		echo "  tick    - Run a single AI tick\n";
		break;
}
