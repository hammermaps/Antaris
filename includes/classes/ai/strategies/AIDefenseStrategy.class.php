<?php

/**
 * Antaris AI Player System - Defense Strategy
 * 
 * Handles defense structure construction for AI players.
 *
 * @package Antaris
 * @subpackage AI
 */

class AIDefenseStrategy
{
	private $aiPlayer;
	
	/**
	 * Defense build priorities per personality
	 * IDs reference defense element IDs (401-410+)
	 */
	private static $defensePriority = array(
		'balanced' => array(
			401 => 10,  // Rocket Launcher
			402 => 5,   // Light Laser
			403 => 3,   // Heavy Laser
			404 => 1,   // Gauss Cannon
			405 => 1,   // Ion Cannon
		),
		'aggressive' => array(
			401 => 5,
			402 => 3,
			403 => 2,
		),
		'defensive' => array(
			401 => 15,  // Rocket Launcher (mass)
			402 => 10,  // Light Laser
			403 => 6,   // Heavy Laser
			404 => 3,   // Gauss Cannon
			405 => 3,   // Ion Cannon
			406 => 1,   // Plasma Turret
			407 => 1,   // Small Shield Dome
			408 => 1,   // Large Shield Dome
		),
		'trader' => array(
			401 => 8,
			402 => 4,
			407 => 1,   // Small Shield Dome
		),
		'researcher' => array(
			401 => 6,
			402 => 3,
			403 => 2,
		),
	);
	
	function __construct(AIPlayer $aiPlayer)
	{
		$this->aiPlayer = $aiPlayer;
	}
	
	/**
	 * Execute defense building strategy
	 */
	public function execute()
	{
		global $resource, $reslist, $pricelist;
		
		$USER   = $this->aiPlayer->USER;
		$PLANET = $this->aiPlayer->activePlanet;
		$planetID = $PLANET['id'];
		
		// Skip if defense is currently building
		if (!empty($PLANET['b_hangar_id'])) {
			return false;
		}
		
		// Need a shipyard for defense
		$shipyardLevel = isset($PLANET[$resource[21]]) ? $PLANET[$resource[21]] : 0;
		if ($shipyardLevel < 1) {
			return false;
		}
		
		$personality = $this->aiPlayer->getPersonality();
		$weights = isset(self::$defensePriority[$personality]) 
			? self::$defensePriority[$personality] 
			: self::$defensePriority['balanced'];
		
		// Select a defense type based on weights
		$defenseID = $this->selectWeightedRandom($weights);
		if ($defenseID === false) {
			return false;
		}
		
		if (!isset($resource[$defenseID])) {
			return false;
		}
		
		// Check if this is a valid defense
		if (!in_array($defenseID, $reslist['defense'])) {
			return false;
		}
		
		// Shield domes: max 1 each
		if (in_array($defenseID, array(407, 408))) {
			$currentCount = isset($PLANET[$resource[$defenseID]]) ? $PLANET[$resource[$defenseID]] : 0;
			if ($currentCount >= 1) {
				return false;
			}
		}
		
		// Check requirements
		if (!BuildFunctions::isTechnologieAccessible($USER, $PLANET, $defenseID)) {
			return false;
		}
		
		// Calculate how many we can build
		$maxBuildable = BuildFunctions::getMaxConstructibleElements($USER, $PLANET, $defenseID);
		if ($maxBuildable < 1) {
			return false;
		}
		
		$quantity = $this->calculateDefenseQuantity($defenseID, $maxBuildable);
		if ($quantity < 1) {
			return false;
		}
		
		return $this->startDefenseProduction($defenseID, $quantity, $planetID);
	}
	
	/**
	 * Start defense production
	 */
	private function startDefenseProduction($defenseID, $quantity, $planetID)
	{
		global $resource, $pricelist;
		
		$USER   = &$this->aiPlayer->USER;
		$PLANET = &$this->aiPlayer->PLANETS[$planetID];
		
		// Calculate total cost
		$metalCost     = isset($pricelist[$defenseID][901]) ? $pricelist[$defenseID][901] * $quantity : 0;
		$crystalCost   = isset($pricelist[$defenseID][902]) ? $pricelist[$defenseID][902] * $quantity : 0;
		$deuteriumCost = isset($pricelist[$defenseID][903]) ? $pricelist[$defenseID][903] * $quantity : 0;
		$elyriumCost   = isset($pricelist[$defenseID][904]) ? $pricelist[$defenseID][904] * $quantity : 0;
		
		// Deduct resources
		$PLANET[$resource[901]] -= $metalCost;
		$PLANET[$resource[902]] -= $crystalCost;
		$PLANET[$resource[903]] -= $deuteriumCost;
		$PLANET[$resource[904]] -= $elyriumCost;
		
		$buildTime = BuildFunctions::getBuildingTime($USER, $PLANET, $defenseID);
		
		// Create hangar queue entry
		$hangarQueue = $defenseID.",".$quantity;
		
		$SQL = "UPDATE ".PLANETS." SET 
			b_hangar_id = '".$GLOBALS['DATABASE']->sql_escape($hangarQueue)."',
			b_hangar = ".(TIMESTAMP + ($buildTime * $quantity)).",
			".$resource[901]." = ".$PLANET[$resource[901]].",
			".$resource[902]." = ".$PLANET[$resource[902]].",
			".$resource[903]." = ".$PLANET[$resource[903]].",
			".$resource[904]." = ".$PLANET[$resource[904]]."
			WHERE id = ".$planetID.";";
		
		$GLOBALS['DATABASE']->query($SQL);
		
		$PLANET['b_hangar_id'] = $hangarQueue;
		$PLANET['b_hangar'] = TIMESTAMP + ($buildTime * $quantity);
		
		$this->aiPlayer->logAction('defense', 'Building '.$quantity.'x '.$resource[$defenseID].' on planet '.$planetID, 'started');
		
		return 'Building '.$quantity.'x '.$resource[$defenseID];
	}
	
	/**
	 * Calculate defense quantity to build
	 */
	private function calculateDefenseQuantity($defenseID, $maxBuildable)
	{
		$difficulty = $this->aiPlayer->getDifficulty();
		
		// Shield domes are always 1
		if (in_array($defenseID, array(407, 408))) {
			return 1;
		}
		
		switch ($difficulty) {
			case 3:
				$fraction = 0.4;
				$maxCap = 30;
				break;
			case 2:
				$fraction = 0.25;
				$maxCap = 15;
				break;
			default:
				$fraction = 0.1;
				$maxCap = 5;
				break;
		}
		
		$quantity = max(1, floor($maxBuildable * $fraction));
		return min($quantity, $maxCap);
	}
	
	/**
	 * Select a random defense type based on weighted priorities
	 */
	private function selectWeightedRandom($weights)
	{
		$totalWeight = array_sum($weights);
		if ($totalWeight <= 0) {
			return false;
		}
		
		$rand = mt_rand(1, $totalWeight);
		$cumulative = 0;
		
		foreach ($weights as $defID => $weight) {
			$cumulative += $weight;
			if ($rand <= $cumulative) {
				return $defID;
			}
		}
		
		return false;
	}
}
