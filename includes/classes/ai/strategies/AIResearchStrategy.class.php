<?php

/**
 * Antaris AI Player System - Research Strategy
 * 
 * Handles technology research for AI players.
 * Research paths depend on personality.
 *
 * @package Antaris
 * @subpackage AI
 */

class AIResearchStrategy
{
	private $aiPlayer;
	
	/**
	 * Research priority lists per personality
	 * IDs reference tech element IDs
	 */
	private static $researchPriority = array(
		'balanced' => array(
			113, // Energy Technology
			120, // Laser Technology
			111, // Spy Technology
			115, // Combustion Drive
			117, // Impulse Drive
			118, // Hyperspace Drive
			114, // Hyperspace Technology
			109, // Weapons Technology
			110, // Shielding Technology
			108, // Computer Technology
			121, // Ion Technology
			124, // Astrophysics
			106, // Armour Technology
			199, // Graviton Technology
		),
		'aggressive' => array(
			113, 120,
			109, // Weapons Technology (high priority)
			110, // Shielding
			106, // Armour
			115, 117, 118,
			108, // Computer (fleet slots)
			111, // Spy
			114, 121, 124,
		),
		'defensive' => array(
			113, 120,
			110, // Shielding (high priority)
			106, // Armour
			109, // Weapons
			121, // Ion Technology
			115, 117, 118,
			111, 108, 114, 124,
		),
		'trader' => array(
			113, 115, 117, 118, // Drives first
			108, // Computer (fleet slots)
			114, // Hyperspace Tech
			111, 120, 109, 110, 106, 121, 124,
		),
		'researcher' => array(
			113, // Energy
			120, // Laser
			111, // Spy
			108, // Computer
			114, // Hyperspace Tech
			121, // Ion
			124, // Astrophysics
			115, 117, 118,
			109, 110, 106,
			199, // Graviton
		),
	);
	
	function __construct(AIPlayer $aiPlayer)
	{
		$this->aiPlayer = $aiPlayer;
	}
	
	/**
	 * Execute research strategy
	 * Returns false if no action taken, or description of action
	 */
	public function execute()
	{
		global $resource, $reslist, $pricelist;
		
		$USER = $this->aiPlayer->USER;
		
		// Skip if research already in progress
		if ($USER['b_tech'] != 0) {
			return false;
		}
		
		$personality = $this->aiPlayer->getPersonality();
		$priorities = isset(self::$researchPriority[$personality]) 
			? self::$researchPriority[$personality] 
			: self::$researchPriority['balanced'];
		
		// Find the best planet to research on (one with a research lab)
		$researchPlanet = $this->findResearchPlanet();
		if ($researchPlanet === false) {
			return false;
		}
		
		$this->aiPlayer->setActivePlanet($researchPlanet);
		$PLANET = $this->aiPlayer->activePlanet;
		
		foreach ($priorities as $techID) {
			if (!isset($resource[$techID])) {
				continue;
			}
			
			// Check if this is a valid tech
			if (!in_array($techID, $reslist['tech'])) {
				continue;
			}
			
			$currentLevel = isset($USER[$resource[$techID]]) ? $USER[$resource[$techID]] : 0;
			
			// Apply level limits based on difficulty
			$maxLevel = $this->getMaxResearchLevel($techID);
			if ($currentLevel >= $maxLevel) {
				continue;
			}
			
			// Check requirements
			if (!BuildFunctions::isTechnologieAccessible($USER, $PLANET, $techID)) {
				continue;
			}
			
			// Check if resources are available
			$costRessources = BuildFunctions::getElementPrice($USER, $PLANET, $techID);
			if (!BuildFunctions::isElementBuyable($USER, $PLANET, $techID, $costRessources)) {
				continue;
			}
			
			// Start this research
			return $this->startResearch($techID, $costRessources, $researchPlanet);
		}
		
		return false;
	}
	
	/**
	 * Find the best planet to do research (one with highest research lab level)
	 */
	private function findResearchPlanet()
	{
		global $resource;
		
		$bestPlanet = false;
		$bestLevel = 0;
		
		foreach ($this->aiPlayer->PLANETS as $planetID => $planet) {
			// Research lab is building ID 31
			$labLevel = isset($planet[$resource[31]]) ? $planet[$resource[31]] : 0;
			if ($labLevel > $bestLevel) {
				$bestLevel = $labLevel;
				$bestPlanet = $planetID;
			}
		}
		
		return ($bestLevel > 0) ? $bestPlanet : false;
	}
	
	/**
	 * Start research
	 */
	private function startResearch($techID, $costRessources, $planetID)
	{
		global $resource;
		
		$USER   = &$this->aiPlayer->USER;
		$PLANET = &$this->aiPlayer->PLANETS[$planetID];
		
		$currentLevel = isset($USER[$resource[$techID]]) ? $USER[$resource[$techID]] : 0;
		$researchTime = BuildFunctions::getBuildingTime($USER, $PLANET, $techID);
		$researchEndTime = TIMESTAMP + $researchTime;
		
		// Deduct resources from the research planet
		if (isset($costRessources[901])) { $PLANET[$resource[901]] -= $costRessources[901]; }
		if (isset($costRessources[902])) { $PLANET[$resource[902]] -= $costRessources[902]; }
		if (isset($costRessources[903])) { $PLANET[$resource[903]] -= $costRessources[903]; }
		if (isset($costRessources[904])) { $PLANET[$resource[904]] -= $costRessources[904]; }
		if (isset($costRessources[921])) { $USER[$resource[921]]   -= $costRessources[921]; }
		
		// Create research queue entry
		$queueEntry = array(array($techID, $currentLevel + 1, $researchTime, $researchEndTime));
		
		// Update planet resources
		$SQL = "UPDATE ".PLANETS." SET 
			".$resource[901]." = ".$PLANET[$resource[901]].",
			".$resource[902]." = ".$PLANET[$resource[902]].",
			".$resource[903]." = ".$PLANET[$resource[903]].",
			".$resource[904]." = ".$PLANET[$resource[904]]."
			WHERE id = ".$planetID.";";
		$GLOBALS['DATABASE']->query($SQL);
		
		// Update user research queue
		$SQL = "UPDATE ".USERS." SET 
			b_tech = ".$researchEndTime.",
			b_tech_id = ".$techID.",
			b_tech_planet = ".$planetID.",
			b_tech_queue = '".$GLOBALS['DATABASE']->sql_escape(serialize($queueEntry))."'";
		
		if (isset($costRessources[921])) {
			$SQL .= ", ".$resource[921]." = ".$USER[$resource[921]];
		}
		
		$SQL .= " WHERE id = ".$USER['id'].";";
		$GLOBALS['DATABASE']->query($SQL);
		
		$USER['b_tech'] = $researchEndTime;
		$USER['b_tech_id'] = $techID;
		$USER['b_tech_planet'] = $planetID;
		$USER['b_tech_queue'] = serialize($queueEntry);
		
		$this->aiPlayer->logAction('research', 'Researching '.$resource[$techID].' level '.($currentLevel+1), 'started');
		
		return 'Researching '.$resource[$techID].' level '.($currentLevel+1);
	}
	
	/**
	 * Get max research level based on difficulty
	 */
	private function getMaxResearchLevel($techID)
	{
		$difficulty = $this->aiPlayer->getDifficulty();
		
		// Graviton is special (very high requirement)
		if ($techID == 199) {
			return ($difficulty >= 3) ? 1 : 0;
		}
		
		switch ($difficulty) {
			case 3: return 18; // Hard
			case 2: return 12; // Medium
			default: return 6; // Easy
		}
	}
}
