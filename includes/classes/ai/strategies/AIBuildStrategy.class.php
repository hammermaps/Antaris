<?php

/**
 * Antaris AI Player System - Build Strategy
 * 
 * Handles building construction for AI players.
 * Priority: Resource buildings → Energy → Storage → Shipyard → Defense facilities
 *
 * @package Antaris
 * @subpackage AI
 */

class AIBuildStrategy
{
	private $aiPlayer;
	
	/**
	 * Building priority lists per personality
	 * IDs reference the element IDs from the game's $resource array
	 */
	private static $buildPriority = array(
		'balanced' => array(
			1,   // Metal Mine
			2,   // Crystal Mine
			3,   // Deuterium Synthesizer
			4,   // Solar Plant
			12,  // Elyrium Synthesizer (if available)
			22,  // Metal Storage
			23,  // Crystal Storage
			24,  // Deuterium Tank
			14,  // Robotics Factory
			21,  // Shipyard
			31,  // Research Lab
			44,  // Missile Silo
		),
		'aggressive' => array(
			1, 2, 3, 4,
			14,  // Robotics Factory
			21,  // Shipyard
			31,  // Research Lab
			22, 23, 24,
			44,  // Missile Silo
		),
		'defensive' => array(
			1, 2, 3, 4, 12,
			22, 23, 24,
			14,  // Robotics Factory
			44,  // Missile Silo
			21, 31,
		),
		'trader' => array(
			1, 2, 3, 4, 12,
			22, 23, 24, 25,
			14, 21, 31,
		),
		'researcher' => array(
			1, 2, 3, 4,
			31,  // Research Lab (high priority)
			14,  // Robotics Factory
			22, 23, 24,
			21, 44,
		),
	);
	
	function __construct(AIPlayer $aiPlayer)
	{
		$this->aiPlayer = $aiPlayer;
	}
	
	/**
	 * Execute building strategy for the current active planet
	 * Returns false if no action taken, or description of action
	 */
	public function execute()
	{
		global $resource, $reslist, $pricelist, $CONF;
		
		$USER   = $this->aiPlayer->USER;
		$PLANET = $this->aiPlayer->activePlanet;
		
		// Skip if building already in progress
		if ($PLANET['b_building'] != 0) {
			return false;
		}
		
		// Check if planet has free fields
		if ($PLANET['field_current'] >= $PLANET['field_max']) {
			return false;
		}
		
		$personality = $this->aiPlayer->getPersonality();
		$priorities = isset(self::$buildPriority[$personality]) 
			? self::$buildPriority[$personality] 
			: self::$buildPriority['balanced'];
		
		// Try each building in priority order
		foreach ($priorities as $elementID) {
			if (!isset($resource[$elementID])) {
				continue;
			}
			
			// Check if this is a valid building for this planet type
			if (!in_array($elementID, $reslist['build'])) {
				continue;
			}
			
			$currentLevel = isset($PLANET[$resource[$elementID]]) ? $PLANET[$resource[$elementID]] : 0;
			
			// Apply level limits based on difficulty
			$maxLevel = $this->getMaxLevel($elementID);
			if ($currentLevel >= $maxLevel) {
				continue;
			}
			
			// Check requirements
			if (!BuildFunctions::isTechnologieAccessible($USER, $PLANET, $elementID)) {
				continue;
			}
			
			// Check if resources are available
			$costRessources = BuildFunctions::getElementPrice($USER, $PLANET, $elementID);
			if (!BuildFunctions::isElementBuyable($USER, $PLANET, $elementID, $costRessources)) {
				continue;
			}
			
			// Build this element
			return $this->startBuilding($elementID, $costRessources);
		}
		
		return false;
	}
	
	/**
	 * Start building construction
	 */
	private function startBuilding($elementID, $costRessources)
	{
		global $resource;
		
		$USER   = &$this->aiPlayer->USER;
		$PLANET = &$this->aiPlayer->activePlanet;
		$planetID = $PLANET['id'];
		
		$currentLevel = isset($PLANET[$resource[$elementID]]) ? $PLANET[$resource[$elementID]] : 0;
		$buildTime = BuildFunctions::getBuildingTime($USER, $PLANET, $elementID);
		$buildEndTime = TIMESTAMP + $buildTime;
		
		// Deduct resources
		if (isset($costRessources[901])) { $PLANET[$resource[901]] -= $costRessources[901]; }
		if (isset($costRessources[902])) { $PLANET[$resource[902]] -= $costRessources[902]; }
		if (isset($costRessources[903])) { $PLANET[$resource[903]] -= $costRessources[903]; }
		if (isset($costRessources[904])) { $PLANET[$resource[904]] -= $costRessources[904]; }
		if (isset($costRessources[921])) { $USER[$resource[921]]   -= $costRessources[921]; }
		
		// Create build queue entry: [elementID, level, buildTime, buildEndTime, mode]
		$queueEntry = array(array($elementID, $currentLevel + 1, $buildTime, $buildEndTime, 'build'));
		
		// Update planet in DB
		$SQL = "UPDATE ".PLANETS." SET 
			b_building = ".$buildEndTime.",
			b_building_id = '".$GLOBALS['DATABASE']->sql_escape(serialize($queueEntry))."',
			".$resource[901]." = ".$PLANET[$resource[901]].",
			".$resource[902]." = ".$PLANET[$resource[902]].",
			".$resource[903]." = ".$PLANET[$resource[903]].",
			".$resource[904]." = ".$PLANET[$resource[904]]."
			WHERE id = ".$planetID.";";
		
		$GLOBALS['DATABASE']->query($SQL);
		
		// Update user darkmatter if needed
		if (isset($costRessources[921])) {
			$GLOBALS['DATABASE']->query(
				"UPDATE ".USERS." SET ".$resource[921]." = ".$USER[$resource[921]]." WHERE id = ".$USER['id'].";"
			);
		}
		
		// Update local planet data
		$PLANET['b_building'] = $buildEndTime;
		$PLANET['b_building_id'] = serialize($queueEntry);
		$this->aiPlayer->PLANETS[$planetID] = $PLANET;
		
		$this->aiPlayer->logAction('build', 'Building '.$resource[$elementID].' level '.($currentLevel+1).' on planet '.$planetID, 'started');
		
		return 'Building '.$resource[$elementID].' level '.($currentLevel+1);
	}
	
	/**
	 * Get max building level based on difficulty
	 */
	private function getMaxLevel($elementID)
	{
		$difficulty = $this->aiPlayer->getDifficulty();
		
		// Resource buildings
		if (in_array($elementID, array(1, 2, 3, 4, 12))) {
			switch ($difficulty) {
				case 3: return 35; // Hard
				case 2: return 25; // Medium
				default: return 15; // Easy
			}
		}
		
		// Storage
		if (in_array($elementID, array(22, 23, 24, 25))) {
			switch ($difficulty) {
				case 3: return 12;
				case 2: return 8;
				default: return 5;
			}
		}
		
		// Infrastructure
		switch ($difficulty) {
			case 3: return 15;
			case 2: return 10;
			default: return 6;
		}
	}
}
