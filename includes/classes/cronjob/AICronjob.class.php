<?php

/**
 * Antaris AI Player System - Cronjob Fallback
 * 
 * This cronjob runs AI logic when the daemon is not active.
 * Register in uni1_cronjobs table to use.
 *
 * @package Antaris
 * @subpackage AI
 */

class AICronjob
{
	function run()
	{
		require_once('includes/classes/class.BuildFunctions.php');
		require_once('includes/classes/class.PlanetRessUpdate.php');
		require_once('includes/classes/class.FleetFunctions.php');
		require_once('includes/classes/ai/AIPlayer.class.php');
		require_once('includes/classes/ai/AIDecisionEngine.class.php');
		
		// Check if AI is enabled
		if (AIConfigHelper::get('ai_enabled') != '1') {
			return;
		}
		
		// Check if daemon is already running (no need for cronjob)
		$daemonPID = AIConfigHelper::get('ai_daemon_pid');
		if (!empty($daemonPID)) {
			// Check if process is actually alive
			if (file_exists('/proc/'.$daemonPID)) {
				return; // Daemon is running, skip cronjob
			}
			// Daemon PID is stale, clear it
			AIConfigHelper::set('ai_daemon_pid', '');
		}
		
		// Process all active AI players
		$aiPlayers = $GLOBALS['DATABASE']->query(
			"SELECT id FROM ".USERS." 
			WHERE is_ai = 1 
			AND urlaubs_modus = 0 
			AND user_deleted = 0 
			ORDER BY id ASC;"
		);
		
		while ($row = $GLOBALS['DATABASE']->fetch_array($aiPlayers)) {
			try {
				$ai = new AIPlayer($row['id']);
				
				if (!$ai->isReadyForAction()) {
					continue;
				}
				
				$ai->updateResources();
				
				$engine = new AIDecisionEngine($ai);
				$actions = $engine->executeTick();
				
				foreach ($actions as $action) {
					$ai->logAction(
						$action['strategy'],
						is_array($action['result']) ? serialize($action['result']) : $action['result'],
						'ok'
					);
				}
				
				$ai->saveState(serialize(array('tick' => 'cronjob', 'actions' => count($actions))));
				
			} catch (Exception $e) {
				// Log error but continue with other AI players
				error_log("AI Cronjob error for player #".$row['id'].": ".$e->getMessage());
			}
		}
		
		$GLOBALS['DATABASE']->free_result($aiPlayers);
		
		AIConfigHelper::set('ai_daemon_last_tick', TIMESTAMP);
	}
}
