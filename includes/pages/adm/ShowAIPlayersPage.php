<?php

/**
 * Antaris AI Player System - Admin Management Page
 * 
 * Admin interface for managing AI players, configuration, and daemon status.
 *
 * @package Antaris
 * @subpackage AI
 */

if (!allowedTo(str_replace(array(dirname(__FILE__), '\\', '/', '.php'), '', __FILE__))) throw new Exception("Permission error!");

function ShowAIPlayersPage()
{
	global $LNG, $USER;
	
	require_once('includes/classes/ai/AIPlayer.class.php');
	
	$template = new template();
	$action = HTTP::_GP('action', '');
	
	switch ($action) {
		case 'create':
			ShowAIPlayersCreate($template);
			break;
		case 'delete':
			ShowAIPlayersDelete($template);
			break;
		case 'config':
			ShowAIPlayersConfig($template);
			break;
		case 'logs':
			ShowAIPlayersLogs($template);
			break;
		default:
			ShowAIPlayersOverview($template);
			break;
	}
}

/**
 * Show AI Players overview
 */
function ShowAIPlayersOverview($template)
{
	global $LNG;
	
	// Get AI configuration
	$aiConfig = AIConfigHelper::getAll();
	
	// Get all AI players
	$aiPlayers = array();
	$result = $GLOBALS['DATABASE']->query(
		"SELECT u.id, u.username, u.ai_difficulty, u.ai_personality, u.onlinetime, u.urlaubs_modus,
		s.total_points, s.total_rank,
		ast.last_tick, ast.current_strategy
		FROM ".USERS." u
		LEFT JOIN ".STATPOINTS." s ON s.id_owner = u.id AND s.stat_type = 1
		LEFT JOIN ".AI_STATE." ast ON ast.ai_user_id = u.id
		WHERE u.is_ai = 1 AND u.universe = ".$_SESSION['adminuni']."
		ORDER BY u.id ASC;"
	);
	
	while ($row = $GLOBALS['DATABASE']->fetch_array($result)) {
		$aiPlayers[] = $row;
	}
	$GLOBALS['DATABASE']->free_result($result);
	
	// Get daemon status
	$daemonPID = isset($aiConfig['ai_daemon_pid']) ? $aiConfig['ai_daemon_pid'] : '';
	$daemonRunning = false;
	if (!empty($daemonPID)) {
		$daemonRunning = file_exists('/proc/'.$daemonPID);
	}
	$lastTick = isset($aiConfig['ai_daemon_last_tick']) ? $aiConfig['ai_daemon_last_tick'] : 0;
	
	// Get recent action count
	$recentActions = $GLOBALS['DATABASE']->getFirstCell(
		"SELECT COUNT(*) FROM ".AI_ACTION_LOG." WHERE executed_at > ".(TIMESTAMP - 3600).";"
	);
	
	$template->assign_vars(array(
		'aiPlayers'      => $aiPlayers,
		'aiConfig'       => $aiConfig,
		'daemonRunning'  => $daemonRunning,
		'daemonPID'      => $daemonPID,
		'lastTick'       => $lastTick > 0 ? date('Y-m-d H:i:s', $lastTick) : 'Never',
		'recentActions'  => $recentActions,
		'aiEnabled'      => isset($aiConfig['ai_enabled']) ? $aiConfig['ai_enabled'] : '0',
	));
	
	$template->show('AIPlayersOverview.tpl');
}

/**
 * Create a new AI player
 */
function ShowAIPlayersCreate($template)
{
	global $LNG;
	
	if ($_POST) {
		$name       = HTTP::_GP('ai_name', '', UTF8_SUPPORT);
		$difficulty = HTTP::_GP('ai_difficulty', 1);
		$personality = HTTP::_GP('ai_personality', 'balanced');
		$galaxy     = HTTP::_GP('galaxy', 0);
		$system     = HTTP::_GP('system', 0);
		$planet     = HTTP::_GP('planet', 0);
		
		// Validate inputs
		$errors = '';
		
		if (empty($name)) {
			$errors .= 'AI player name is required.<br>';
		}
		
		if (!in_array($difficulty, array(1, 2, 3))) {
			$difficulty = 1;
		}
		
		$validPersonalities = array('balanced', 'aggressive', 'defensive', 'trader', 'researcher');
		if (!in_array($personality, $validPersonalities)) {
			$personality = 'balanced';
		}
		
		// Check if name exists
		$nameExists = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT COUNT(*) FROM ".USERS." WHERE username = '".$GLOBALS['DATABASE']->sql_escape($name)."' AND universe = ".$_SESSION['adminuni'].";"
		);
		if ($nameExists > 0) {
			$errors .= 'Username already exists.<br>';
		}
		
		if (!empty($errors)) {
			$template->message($errors, '?page=aiplayers&action=create', 5, true);
			exit;
		}
		
		// Generate a random password (AI doesn't need to log in)
		$password = cryptPassword(md5(uniqid(mt_rand(), true)));
		$email = 'ai_'.strtolower(str_replace(' ', '_', $name)).'@antaris.local';
		
		// Create the player using the existing pattern from ShowCreatorPage
		$CONF = Config::getAll(NULL, $_SESSION['adminuni']);
		
		// Auto-assign position if not specified
		if ($galaxy == 0 || $system == 0 || $planet == 0) {
			$galaxy = mt_rand(1, $CONF['max_galaxy']);
			$system = mt_rand(1, $CONF['max_system']);
			for ($i = 0; $i < 100; $i++) {
				$planet = mt_rand(
					(int)round($CONF['max_planets'] * 0.2), 
					(int)round($CONF['max_planets'] * 0.8)
				);
				if (PlayerUtil::isPositionFree($_SESSION['adminuni'], $galaxy, $system, $planet)) {
					break;
				}
				$system = mt_rand(1, $CONF['max_system']);
			}
		}
		
		if (!PlayerUtil::isPositionFree($_SESSION['adminuni'], $galaxy, $system, $planet)) {
			$template->message('Could not find a free position. Please specify coordinates manually.', '?page=aiplayers&action=create', 5, true);
			exit;
		}
		
		// Insert user
		$SQL = "INSERT INTO ".USERS." SET
			username = '".$GLOBALS['DATABASE']->sql_escape($name)."',
			password = '".$GLOBALS['DATABASE']->sql_escape($password)."',
			email = '".$GLOBALS['DATABASE']->sql_escape($email)."',
			email_2 = '".$GLOBALS['DATABASE']->sql_escape($email)."',
			lang = 'de',
			authlevel = 0,
			is_ai = 1,
			ai_difficulty = ".(int)$difficulty.",
			ai_personality = '".$GLOBALS['DATABASE']->sql_escape($personality)."',
			ip_at_reg = '127.0.0.1',
			id_planet = 0,
			universe = ".$_SESSION['adminuni'].",
			onlinetime = ".TIMESTAMP.",
			register_time = ".TIMESTAMP.",
			dpath = '".DEFAULT_THEME."',
			timezone = '".$CONF['timezone']."',
			uctime = 0;";
		
		$GLOBALS['DATABASE']->query($SQL);
		$userID = $GLOBALS['DATABASE']->GetInsertID();
		
		// Create home planet
		require_once('includes/functions/CreateOnePlanetRecord.php');
		$planetID = CreateOnePlanetRecord($galaxy, $system, $planet, $_SESSION['adminuni'], $userID, $name, true, 0);
		
		// Update user with planet info + stat entry
		$SQL = "UPDATE ".USERS." SET 
			id_planet = ".$planetID.",
			galaxy = ".$galaxy.",
			system = ".$system.",
			planet = ".$planet."
			WHERE id = ".$userID.";
			INSERT INTO ".STATPOINTS." SET 
			id_owner = ".$userID.",
			universe = ".$_SESSION['adminuni'].",
			stat_type = 1,
			tech_rank = ".(Config::get('users_amount') + 1).",
			build_rank = ".(Config::get('users_amount') + 1).",
			defs_rank = ".(Config::get('users_amount') + 1).",
			fleet_rank = ".(Config::get('users_amount') + 1).",
			total_rank = ".(Config::get('users_amount') + 1).";";
		
		$GLOBALS['DATABASE']->multi_query($SQL);
		Config::update(array('users_amount' => Config::get('users_amount') + 1));
		
		// Create AI state entry
		$GLOBALS['DATABASE']->query(
			"INSERT INTO ".AI_STATE." SET 
			ai_user_id = ".$userID.",
			current_strategy = '".$GLOBALS['DATABASE']->sql_escape($personality)."',
			state_data = '',
			last_tick = ".TIMESTAMP.",
			next_action_time = ".TIMESTAMP.";"
		);
		
		$template->message('AI Player "'.$name.'" created successfully at '.$galaxy.':'.$system.':'.$planet, '?page=aiplayers', 3, true);
		exit;
	}
	
	// Show creation form
	$CONF = Config::getAll(NULL, $_SESSION['adminuni']);
	
	$template->assign_vars(array(
		'maxGalaxy'  => $CONF['max_galaxy'],
		'maxSystem'  => $CONF['max_system'],
		'maxPlanets' => $CONF['max_planets'],
	));
	
	$template->show('AIPlayersCreate.tpl');
}

/**
 * Delete an AI player
 */
function ShowAIPlayersDelete($template)
{
	global $LNG;
	
	$userID = HTTP::_GP('id', 0);
	
	if ($userID > 0) {
		// Verify this is actually an AI player
		$isAI = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT is_ai FROM ".USERS." WHERE id = ".$userID.";"
		);
		
		if ($isAI == 1) {
			// Delete AI state and logs
			$GLOBALS['DATABASE']->query("DELETE FROM ".AI_STATE." WHERE ai_user_id = ".$userID.";");
			$GLOBALS['DATABASE']->query("DELETE FROM ".AI_ACTION_LOG." WHERE ai_user_id = ".$userID.";");
			
			// Use existing player deletion
			PlayerUtil::deletePlayer($userID);
			
			$template->message('AI Player deleted.', '?page=aiplayers', 3, true);
		} else {
			$template->message('Player is not an AI player.', '?page=aiplayers', 3, true);
		}
		exit;
	}
	
	$template->message('Invalid player ID.', '?page=aiplayers', 3, true);
	exit;
}

/**
 * Update AI configuration
 */
function ShowAIPlayersConfig($template)
{
	global $LNG;
	
	if ($_POST) {
		$enabled        = HTTP::_GP('ai_enabled', 0);
		$maxPlayers     = HTTP::_GP('ai_max_players', 10);
		$tickInterval   = HTTP::_GP('ai_tick_interval', 60);
		$maxActions     = HTTP::_GP('ai_max_actions_per_tick', 3);
		$allowAttacks   = HTTP::_GP('ai_allow_attacks', 0);
		$attackAI       = HTTP::_GP('ai_attack_ai', 0);
		$logActions     = HTTP::_GP('ai_log_actions', 1);
		
		AIConfigHelper::set('ai_enabled', $enabled ? '1' : '0');
		AIConfigHelper::set('ai_max_players', max(1, (int)$maxPlayers));
		AIConfigHelper::set('ai_tick_interval', max(10, (int)$tickInterval));
		AIConfigHelper::set('ai_max_actions_per_tick', max(1, (int)$maxActions));
		AIConfigHelper::set('ai_allow_attacks', $allowAttacks ? '1' : '0');
		AIConfigHelper::set('ai_attack_ai', $attackAI ? '1' : '0');
		AIConfigHelper::set('ai_log_actions', $logActions ? '1' : '0');
		
		$template->message('AI Configuration saved.', '?page=aiplayers', 3, true);
		exit;
	}
	
	// Show config page
	$aiConfig = AIConfigHelper::getAll();
	
	$template->assign_vars(array(
		'aiConfig' => $aiConfig,
	));
	
	$template->show('AIPlayersConfig.tpl');
}

/**
 * Show AI action logs
 */
function ShowAIPlayersLogs($template)
{
	global $LNG;
	
	$page = max(1, HTTP::_GP('p', 1));
	$perPage = 50;
	$offset = ($page - 1) * $perPage;
	
	$totalLogs = $GLOBALS['DATABASE']->getFirstCell("SELECT COUNT(*) FROM ".AI_ACTION_LOG.";");
	
	$logs = array();
	$result = $GLOBALS['DATABASE']->query(
		"SELECT l.*, u.username 
		FROM ".AI_ACTION_LOG." l
		LEFT JOIN ".USERS." u ON u.id = l.ai_user_id
		ORDER BY l.executed_at DESC 
		LIMIT ".$offset.", ".$perPage.";"
	);
	
	while ($row = $GLOBALS['DATABASE']->fetch_array($result)) {
		$row['executed_at_formatted'] = date('Y-m-d H:i:s', $row['executed_at']);
		$logs[] = $row;
	}
	$GLOBALS['DATABASE']->free_result($result);
	
	$template->assign_vars(array(
		'logs'       => $logs,
		'totalLogs'  => $totalLogs,
		'currentPage' => $page,
		'totalPages' => ceil($totalLogs / $perPage),
	));
	
	$template->show('AIPlayersLogs.tpl');
}
