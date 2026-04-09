<?php

/**
 * Antaris AI Player System
 * 
 * Base class for AI player logic. Loads player data, planets, and 
 * coordinates with the decision engine for action selection.
 *
 * @package Antaris
 * @subpackage AI
 */

class AIPlayer
{
	public $USER;
	public $PLANETS;
	public $activePlanet;
	public $state;
	
	private $userID;
	private $difficulty;
	private $personality;
	
	function __construct($userID)
	{
		$this->userID = $userID;
		$this->loadPlayerData();
		$this->loadPlanets();
		$this->loadState();
	}
	
	/**
	 * Load the AI player's user record from the database
	 */
	private function loadPlayerData()
	{
		$this->USER = $GLOBALS['DATABASE']->getFirstRow(
			"SELECT * FROM ".USERS." WHERE id = ".$this->userID." AND is_ai = 1;"
		);
		
		if (empty($this->USER)) {
			throw new Exception("AI Player not found: ".$this->userID);
		}
		
		$this->difficulty  = $this->USER['ai_difficulty'];
		$this->personality = $this->USER['ai_personality'];
		
		// Load user factor bonuses
		$this->USER['factor'] = getFactors($this->USER);
	}
	
	/**
	 * Determine and set the default active planet (home planet or first available)
	 */
	private function setDefaultActivePlanet()
	{
		$this->activePlanet = isset($this->PLANETS[$this->USER['id_planet']]) 
			? $this->PLANETS[$this->USER['id_planet']] 
			: reset($this->PLANETS);
	}
	
	/**
	 * Load all planets belonging to this AI player
	 */
	private function loadPlanets()
	{
		$this->PLANETS = array();
		$planetsResult = $GLOBALS['DATABASE']->query(
			"SELECT * FROM ".PLANETS." WHERE id_owner = ".$this->userID." AND destruyed = 0 ORDER BY id ASC;"
		);
		
		while ($planet = $GLOBALS['DATABASE']->fetch_array($planetsResult)) {
			$this->PLANETS[$planet['id']] = $planet;
		}
		
		$GLOBALS['DATABASE']->free_result($planetsResult);
		
		if (empty($this->PLANETS)) {
			throw new Exception("AI Player has no planets: ".$this->userID);
		}
		
		$this->setDefaultActivePlanet();
	}
	
	/**
	 * Load the AI state from the database
	 */
	private function loadState()
	{
		$this->state = $GLOBALS['DATABASE']->getFirstRow(
			"SELECT * FROM ".AI_STATE." WHERE ai_user_id = ".$this->userID.";"
		);
		
		if (empty($this->state)) {
			$GLOBALS['DATABASE']->query(
				"INSERT INTO ".AI_STATE." SET 
				ai_user_id = ".$this->userID.",
				current_strategy = '".$GLOBALS['DATABASE']->sql_escape($this->personality)."',
				state_data = '',
				last_tick = ".TIMESTAMP.",
				next_action_time = ".TIMESTAMP.";"
			);
			$this->state = array(
				'ai_user_id'      => $this->userID,
				'current_strategy' => $this->personality,
				'state_data'      => '',
				'last_tick'       => TIMESTAMP,
				'next_action_time' => TIMESTAMP,
			);
		}
	}
	
	/**
	 * Update resources for all planets (process pending builds, research, etc.)
	 */
	public function updateResources()
	{
		require_once('includes/classes/class.PlanetRessUpdate.php');
		
		foreach ($this->PLANETS as $planetID => $planetData) {
			$resUpdate = new ResourceUpdate(true, true);
			list($updatedUser, $updatedPlanet) = $resUpdate->CalcResource(
				$this->USER, $planetData, true, TIMESTAMP, true
			);
			
			$this->USER = $updatedUser;
			$this->PLANETS[$planetID] = $updatedPlanet;
		}
		
		// Re-set active planet
		$this->setDefaultActivePlanet();
	}
	
	/**
	 * Set the currently active planet for building/research operations
	 */
	public function setActivePlanet($planetID)
	{
		if (isset($this->PLANETS[$planetID])) {
			$this->activePlanet = $this->PLANETS[$planetID];
			return true;
		}
		return false;
	}
	
	/**
	 * Save the AI state back to database
	 */
	public function saveState($stateData = '')
	{
		$GLOBALS['DATABASE']->query(
			"UPDATE ".AI_STATE." SET 
			current_strategy = '".$GLOBALS['DATABASE']->sql_escape($this->personality)."',
			state_data = '".$GLOBALS['DATABASE']->sql_escape($stateData)."',
			last_tick = ".TIMESTAMP.",
			next_action_time = ".(TIMESTAMP + $this->getTickDelay())."
			WHERE ai_user_id = ".$this->userID.";"
		);
	}
	
	/**
	 * Log an AI action for debugging and balancing
	 */
	public function logAction($actionType, $actionData = '', $result = '')
	{
		$aiConfig = AIConfigHelper::get('ai_log_actions');
		if ($aiConfig != '1') {
			return;
		}
		
		$GLOBALS['DATABASE']->query(
			"INSERT INTO ".AI_ACTION_LOG." SET 
			ai_user_id = ".$this->userID.",
			action_type = '".$GLOBALS['DATABASE']->sql_escape($actionType)."',
			action_data = '".$GLOBALS['DATABASE']->sql_escape($actionData)."',
			executed_at = ".TIMESTAMP.",
			result = '".$GLOBALS['DATABASE']->sql_escape($result)."';"
		);
	}
	
	/**
	 * Get tick delay based on difficulty (harder AI acts faster)
	 */
	private function getTickDelay()
	{
		switch ($this->difficulty) {
			case 3: return 30;   // Hard: 30 seconds
			case 2: return 60;   // Medium: 60 seconds
			case 1: 
			default: return 120; // Easy: 120 seconds
		}
	}
	
	/**
	 * Check if this AI player is ready for the next action
	 */
	public function isReadyForAction()
	{
		return TIMESTAMP >= $this->state['next_action_time'];
	}
	
	public function getUserID()     { return $this->userID; }
	public function getDifficulty() { return $this->difficulty; }
	public function getPersonality(){ return $this->personality; }
}

/**
 * Helper class for AI configuration
 */
class AIConfigHelper
{
	private static $cache = array();
	
	/**
	 * Get an AI config value
	 */
	static function get($key)
	{
		if (isset(self::$cache[$key])) {
			return self::$cache[$key];
		}
		
		$value = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT config_value FROM ".AI_CONFIG." WHERE config_key = '".$GLOBALS['DATABASE']->sql_escape($key)."';"
		);
		
		self::$cache[$key] = $value;
		return $value;
	}
	
	/**
	 * Set an AI config value
	 */
	static function set($key, $value)
	{
		$GLOBALS['DATABASE']->query(
			"INSERT INTO ".AI_CONFIG." (config_key, config_value) 
			VALUES ('".$GLOBALS['DATABASE']->sql_escape($key)."', '".$GLOBALS['DATABASE']->sql_escape($value)."')
			ON DUPLICATE KEY UPDATE config_value = '".$GLOBALS['DATABASE']->sql_escape($value)."';"
		);
		self::$cache[$key] = $value;
	}
	
	/**
	 * Get all AI config values
	 */
	static function getAll()
	{
		$result = $GLOBALS['DATABASE']->query("SELECT config_key, config_value FROM ".AI_CONFIG.";");
		$config = array();
		while ($row = $GLOBALS['DATABASE']->fetch_array($result)) {
			$config[$row['config_key']] = $row['config_value'];
			self::$cache[$row['config_key']] = $row['config_value'];
		}
		$GLOBALS['DATABASE']->free_result($result);
		return $config;
	}
	
	/**
	 * Clear config cache
	 */
	static function clearCache()
	{
		self::$cache = array();
	}
}
