<?php

/**
 * Antaris AI Player System - Fleet Strategy
 * 
 * Handles ship construction for AI players.
 * Ship types depend on personality and current fleet composition.
 *
 * @package Antaris
 * @subpackage AI
 */

class AIFleetStrategy
{
	private $aiPlayer;
	
	/**
	 * Ship build priorities per personality
	 * IDs reference ship element IDs (202-215)
	 */
	private static $shipPriority = array(
		'balanced' => array(
			204 => 5,   // Light Fighter
			205 => 3,   // Heavy Fighter
			206 => 2,   // Cruiser
			207 => 1,   // Battleship
			202 => 3,   // Small Cargo
			203 => 2,   // Large Cargo
			210 => 1,   // Espionage Probe
			209 => 1,   // Recycler
		),
		'aggressive' => array(
			204 => 8,   // Light Fighter
			205 => 5,   // Heavy Fighter
			206 => 4,   // Cruiser
			207 => 3,   // Battleship
			211 => 1,   // Bomber
			215 => 1,   // Battlecruiser
			210 => 2,   // Espionage Probe
			202 => 2,   // Small Cargo
		),
		'defensive' => array(
			204 => 3,   // Light Fighter
			202 => 3,   // Small Cargo
			203 => 2,   // Large Cargo
			210 => 3,   // Espionage Probe
			209 => 2,   // Recycler
			205 => 1,   // Heavy Fighter
		),
		'trader' => array(
			202 => 5,   // Small Cargo
			203 => 5,   // Large Cargo
			204 => 2,   // Light Fighter
			210 => 2,   // Espionage Probe
			209 => 3,   // Recycler
		),
		'researcher' => array(
			210 => 5,   // Espionage Probe
			202 => 3,   // Small Cargo
			204 => 2,   // Light Fighter
			203 => 2,   // Large Cargo
			209 => 1,   // Recycler
		),
	);
	
	function __construct(AIPlayer $aiPlayer)
	{
		$this->aiPlayer = $aiPlayer;
	}
	
	/**
	 * Execute fleet building strategy
	 * Returns false if no action taken, or description of action
	 */
	public function execute()
	{
		global $resource, $reslist, $pricelist;
		
		$USER   = $this->aiPlayer->USER;
		$PLANET = $this->aiPlayer->activePlanet;
		$planetID = $PLANET['id'];
		
		// Skip if hangar is currently building
		if (!empty($PLANET['b_hangar_id'])) {
			return false;
		}
		
		// Need a shipyard
		$shipyardLevel = isset($PLANET[$resource[21]]) ? $PLANET[$resource[21]] : 0;
		if ($shipyardLevel < 1) {
			return false;
		}
		
		$personality = $this->aiPlayer->getPersonality();
		$weights = isset(self::$shipPriority[$personality]) 
			? self::$shipPriority[$personality] 
			: self::$shipPriority['balanced'];
		
		// Select a random ship type based on weights
		$shipID = $this->selectWeightedRandom($weights);
		if ($shipID === false) {
			return false;
		}
		
		if (!isset($resource[$shipID])) {
			return false;
		}
		
		// Validate this ship type exists in fleet list
		if (!in_array($shipID, $reslist['fleet'])) {
			return false;
		}
		
		// Check requirements
		if (!BuildFunctions::isTechnologieAccessible($USER, $PLANET, $shipID)) {
			return false;
		}
		
		// Calculate how many we can build
		$maxBuildable = BuildFunctions::getMaxConstructibleElements($USER, $PLANET, $shipID);
		if ($maxBuildable < 1) {
			return false;
		}
		
		// Build a reasonable quantity based on difficulty
		$quantity = $this->calculateBuildQuantity($shipID, $maxBuildable);
		if ($quantity < 1) {
			return false;
		}
		
		return $this->startShipProduction($shipID, $quantity, $planetID);
	}
	
	/**
	 * Start ship production
	 */
	private function startShipProduction($shipID, $quantity, $planetID)
	{
		global $resource, $pricelist;
		
		$USER   = &$this->aiPlayer->USER;
		$PLANET = &$this->aiPlayer->PLANETS[$planetID];
		
		// Calculate total cost
		$metalCost     = isset($pricelist[$shipID][901]) ? $pricelist[$shipID][901] * $quantity : 0;
		$crystalCost   = isset($pricelist[$shipID][902]) ? $pricelist[$shipID][902] * $quantity : 0;
		$deuteriumCost = isset($pricelist[$shipID][903]) ? $pricelist[$shipID][903] * $quantity : 0;
		$elyriumCost   = isset($pricelist[$shipID][904]) ? $pricelist[$shipID][904] * $quantity : 0;
		
		// Deduct resources
		$PLANET[$resource[901]] -= $metalCost;
		$PLANET[$resource[902]] -= $crystalCost;
		$PLANET[$resource[903]] -= $deuteriumCost;
		$PLANET[$resource[904]] -= $elyriumCost;
		
		// Build time per unit
		$buildTime = BuildFunctions::getBuildingTime($USER, $PLANET, $shipID);
		
		// Create hangar queue entry: elementID,quantity;
		$hangarQueue = $shipID.",".$quantity;
		
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
		
		$this->aiPlayer->logAction('fleet', 'Building '.$quantity.'x '.$resource[$shipID].' on planet '.$planetID, 'started');
		
		return 'Building '.$quantity.'x '.$resource[$shipID];
	}
	
	/**
	 * Calculate how many ships to build
	 */
	private function calculateBuildQuantity($shipID, $maxBuildable)
	{
		$difficulty = $this->aiPlayer->getDifficulty();
		
		// Build fraction based on difficulty
		switch ($difficulty) {
			case 3:
				$fraction = 0.5; // Build up to 50% of max
				break;
			case 2:
				$fraction = 0.3;
				break;
			default:
				$fraction = 0.15;
				break;
		}
		
		$quantity = max(1, floor($maxBuildable * $fraction));
		
		// Cap at reasonable amounts
		$maxCap = ($difficulty >= 3) ? 50 : (($difficulty >= 2) ? 20 : 5);
		return min($quantity, $maxCap);
	}
	
	/**
	 * Select a random ship type based on weighted priorities
	 */
	private function selectWeightedRandom($weights)
	{
		$totalWeight = array_sum($weights);
		if ($totalWeight <= 0) {
			return false;
		}
		
		$rand = mt_rand(1, $totalWeight);
		$cumulative = 0;
		
		foreach ($weights as $shipID => $weight) {
			$cumulative += $weight;
			if ($rand <= $cumulative) {
				return $shipID;
			}
		}
		
		return false;
	}
}
