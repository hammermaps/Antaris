<?php

/**
 * Antaris AI Player System - Mission Strategy
 * 
 * Handles fleet missions (spy, attack, transport, expedition) for AI players.
 *
 * @package Antaris
 * @subpackage AI
 */

require_once('includes/classes/class.FleetFunctions.php');

class AIMissionStrategy
{
	private $aiPlayer;
	
	/**
	 * Mission type names for logging
	 */
	private static $missionNames = array(
		1  => 'Attack',
		2  => 'ACS',
		3  => 'Transport',
		4  => 'Station',
		5  => 'Hold',
		6  => 'Spy',
		7  => 'Colonize',
		8  => 'Recycle',
		9  => 'Destroy',
		15 => 'Expedition',
	);
	
	function __construct(AIPlayer $aiPlayer)
	{
		$this->aiPlayer = $aiPlayer;
	}
	
	/**
	 * Execute mission strategy
	 */
	public function execute()
	{
		$USER = $this->aiPlayer->USER;
		$personality = $this->aiPlayer->getPersonality();
		
		// Check if we have any fleet slots available
		$maxSlots = FleetFunctions::GetMaxFleetSlots($USER);
		$usedSlots = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT COUNT(*) FROM ".FLEETS." WHERE fleet_owner = ".$USER['id'].";"
		);
		
		if ($usedSlots >= $maxSlots) {
			return false;
		}
		
		// Choose mission type based on personality
		switch ($personality) {
			case 'aggressive':
				$missions = array('spy' => 40, 'attack' => 40, 'expedition' => 20);
				break;
			case 'defensive':
				$missions = array('spy' => 50, 'expedition' => 40, 'transport' => 10);
				break;
			case 'trader':
				$missions = array('transport' => 40, 'expedition' => 30, 'spy' => 30);
				break;
			case 'researcher':
				$missions = array('expedition' => 50, 'spy' => 40, 'transport' => 10);
				break;
			case 'balanced':
			default:
				$missions = array('spy' => 35, 'expedition' => 30, 'attack' => 20, 'transport' => 15);
				break;
		}
		
		// Weighted random selection
		$missionType = $this->selectWeightedRandom($missions);
		
		switch ($missionType) {
			case 'spy':
				return $this->executeSpy();
			case 'attack':
				return $this->executeAttack();
			case 'expedition':
				return $this->executeExpedition();
			case 'transport':
				return $this->executeTransport();
		}
		
		return false;
	}
	
	/**
	 * Execute spy mission on a nearby player
	 */
	private function executeSpy()
	{
		global $resource;
		
		$USER = $this->aiPlayer->USER;
		
		// Find best planet with spy probes
		$sourcePlanet = $this->findPlanetWithShip(210); // Espionage Probe
		if ($sourcePlanet === false) {
			return false;
		}
		
		$PLANET = $this->aiPlayer->PLANETS[$sourcePlanet];
		
		// Find a nearby target (non-AI, non-vacation, non-banned)
		$target = $this->findNearbyTarget($PLANET['galaxy'], $PLANET['system']);
		if ($target === false) {
			return false;
		}
		
		// Send spy probes
		$probeCount = min(isset($PLANET[$resource[210]]) ? $PLANET[$resource[210]] : 0, 5);
		if ($probeCount < 1) {
			return false;
		}
		
		$fleetArray = array(210 => $probeCount);
		
		return $this->sendFleet(
			$sourcePlanet,
			$target['galaxy'], $target['system'], $target['planet'], 1,
			$fleetArray,
			6, // Spy mission
			0, 0, 0, 0
		);
	}
	
	/**
	 * Execute attack mission
	 */
	private function executeAttack()
	{
		global $resource;
		
		$USER = $this->aiPlayer->USER;
		$allowAttacks = AIConfigHelper::get('ai_allow_attacks');
		if ($allowAttacks != '1') {
			return false;
		}
		
		// Find planet with combat ships
		$sourcePlanet = $this->findPlanetWithCombatShips();
		if ($sourcePlanet === false) {
			return false;
		}
		
		$PLANET = $this->aiPlayer->PLANETS[$sourcePlanet];
		
		// Find a weaker target
		$target = $this->findWeakerTarget($PLANET['galaxy'], $PLANET['system']);
		if ($target === false) {
			return false;
		}
		
		// Select ships to send
		$fleetArray = $this->selectCombatFleet($PLANET);
		if (empty($fleetArray)) {
			return false;
		}
		
		return $this->sendFleet(
			$sourcePlanet,
			$target['galaxy'], $target['system'], $target['planet'], 1,
			$fleetArray,
			1, // Attack mission
			0, 0, 0, 0
		);
	}
	
	/**
	 * Execute expedition to empty space
	 */
	private function executeExpedition()
	{
		global $resource;
		
		$USER = $this->aiPlayer->USER;
		
		// Check expedition limit
		$expLimit = FleetFunctions::getExpeditionLimit($USER);
		$currentExp = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT COUNT(*) FROM ".FLEETS." WHERE fleet_owner = ".$USER['id']." AND fleet_mission = 15;"
		);
		
		if ($currentExp >= $expLimit || $expLimit < 1) {
			return false;
		}
		
		// Find planet with available ships
		$sourcePlanet = $this->findPlanetWithShip(202); // Small Cargo
		if ($sourcePlanet === false) {
			$sourcePlanet = $this->findPlanetWithShip(204); // Light Fighter
			if ($sourcePlanet === false) {
				return false;
			}
		}
		
		$PLANET = $this->aiPlayer->PLANETS[$sourcePlanet];
		
		// Send a small fleet on expedition
		$fleetArray = array();
		$shipTypes = array(202, 204, 210);
		foreach ($shipTypes as $shipID) {
			$available = isset($PLANET[$resource[$shipID]]) ? $PLANET[$resource[$shipID]] : 0;
			if ($available > 0) {
				$fleetArray[$shipID] = min($available, 3);
			}
		}
		
		if (empty($fleetArray)) {
			return false;
		}
		
		// Expedition target: same galaxy/system, position 16
		return $this->sendFleet(
			$sourcePlanet,
			$PLANET['galaxy'], $PLANET['system'], 16, 1,
			$fleetArray,
			15, // Expedition
			0, 0, 0, 0
		);
	}
	
	/**
	 * Execute transport between own planets
	 */
	private function executeTransport()
	{
		global $resource;
		
		$planets = $this->aiPlayer->PLANETS;
		
		if (count($planets) < 2) {
			return false;
		}
		
		// Find planet with most resources and one with least
		$richest = null;
		$poorest = null;
		$maxRes = 0;
		$minRes = PHP_INT_MAX;
		
		foreach ($planets as $pid => $p) {
			$total = $p[$resource[901]] + $p[$resource[902]] + $p[$resource[903]];
			if ($total > $maxRes) {
				$maxRes = $total;
				$richest = $pid;
			}
			if ($total < $minRes) {
				$minRes = $total;
				$poorest = $pid;
			}
		}
		
		if ($richest === null || $poorest === null || $richest === $poorest) {
			return false;
		}
		
		$sourcePlanet = $planets[$richest];
		$targetPlanet = $planets[$poorest];
		
		// Need transport ships
		$cargoShips = isset($sourcePlanet[$resource[202]]) ? $sourcePlanet[$resource[202]] : 0;
		$largeCargoShips = isset($sourcePlanet[$resource[203]]) ? $sourcePlanet[$resource[203]] : 0;
		
		if ($cargoShips == 0 && $largeCargoShips == 0) {
			return false;
		}
		
		$fleetArray = array();
		if ($largeCargoShips > 0) {
			$fleetArray[203] = min($largeCargoShips, 5);
		} elseif ($cargoShips > 0) {
			$fleetArray[202] = min($cargoShips, 10);
		}
		
		// Transport a portion of resources
		$metalSend = floor($sourcePlanet[$resource[901]] * 0.3);
		$crystalSend = floor($sourcePlanet[$resource[902]] * 0.3);
		$deutSend = floor($sourcePlanet[$resource[903]] * 0.1);
		
		return $this->sendFleet(
			$richest,
			$targetPlanet['galaxy'], $targetPlanet['system'], $targetPlanet['planet'], 1,
			$fleetArray,
			3, // Transport
			$metalSend, $crystalSend, $deutSend, 0
		);
	}
	
	/**
	 * Send a fleet mission
	 */
	private function sendFleet($sourcePlanetID, $targetGalaxy, $targetSystem, $targetPlanet, $targetType, $fleetArray, $mission, $metal, $crystal, $deuterium, $elyrium)
	{
		global $resource, $pricelist;
		
		$USER   = &$this->aiPlayer->USER;
		$PLANET = &$this->aiPlayer->PLANETS[$sourcePlanetID];
		
		if (empty($fleetArray)) {
			return false;
		}
		
		// Calculate fleet speed
		$speedAllMin = FleetFunctions::GetFleetMaxSpeed($fleetArray, $USER);
		
		// Calculate distance
		$distance = FleetFunctions::GetTargetDistance(
			array($PLANET['galaxy'], $PLANET['system'], $PLANET['planet']),
			array($targetGalaxy, $targetSystem, $targetPlanet)
		);
		
		// Calculate duration
		$SpeedFactor = FleetFunctions::GetGameSpeedFactor();
		$duration = FleetFunctions::GetMissionDuration(10, $speedAllMin, $distance, $SpeedFactor, $USER);
		
		// Calculate consumption
		$consumption = FleetFunctions::GetFleetConsumption($fleetArray, $duration, $distance, $speedAllMin, $USER, $SpeedFactor);
		
		// Check enough deuterium for fuel
		if ($PLANET[$resource[903]] < $consumption + $deuterium) {
			return false;
		}
		
		// Build fleet array string
		$fleetArrayStr = '';
		foreach ($fleetArray as $shipID => $count) {
			$fleetArrayStr .= $shipID.','.$count.';';
		}
		$fleetArrayStr = rtrim($fleetArrayStr, ';');
		
		$startTime = TIMESTAMP + $duration;
		$stayTime  = ($mission == 15) ? $startTime + 3600 : 0; // 1h stay for expeditions
		$endTime   = ($mission == 15) ? $stayTime + $duration : $startTime + $duration;
		
		if ($mission == 4 || $mission == 5) {
			$stayTime = $startTime + 3600;
			$endTime  = $stayTime + $duration;
		}
		
		// Deduct ships from planet
		foreach ($fleetArray as $shipID => $count) {
			$PLANET[$resource[$shipID]] -= $count;
			$GLOBALS['DATABASE']->query(
				"UPDATE ".PLANETS." SET ".$resource[$shipID]." = ".$PLANET[$resource[$shipID]]." WHERE id = ".$sourcePlanetID.";"
			);
		}
		
		// Deduct resources (fuel + cargo)
		$PLANET[$resource[903]] -= $consumption;
		if ($metal > 0)      $PLANET[$resource[901]] -= $metal;
		if ($crystal > 0)    $PLANET[$resource[902]] -= $crystal;
		if ($deuterium > 0)  $PLANET[$resource[903]] -= $deuterium;
		
		$GLOBALS['DATABASE']->query(
			"UPDATE ".PLANETS." SET 
			".$resource[901]." = ".$PLANET[$resource[901]].",
			".$resource[902]." = ".$PLANET[$resource[902]].",
			".$resource[903]." = ".$PLANET[$resource[903]]."
			WHERE id = ".$sourcePlanetID.";"
		);
		
		// Find target planet owner
		$targetOwner = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT id_owner FROM ".PLANETS." 
			WHERE galaxy = ".$targetGalaxy." AND system = ".$targetSystem." AND planet = ".$targetPlanet." AND planet_type = ".$targetType."
			LIMIT 1;"
		);
		if (empty($targetOwner)) {
			$targetOwner = 0;
		}
		
		// Insert fleet record
		$SQL = "INSERT INTO ".FLEETS." SET 
			fleet_owner = ".$USER['id'].",
			fleet_mission = ".$mission.",
			fleet_amount = ".array_sum($fleetArray).",
			fleet_array = '".$GLOBALS['DATABASE']->sql_escape($fleetArrayStr)."',
			fleet_start_time = ".$startTime.",
			fleet_start_id = ".$sourcePlanetID.",
			fleet_start_galaxy = ".$PLANET['galaxy'].",
			fleet_start_system = ".$PLANET['system'].",
			fleet_start_planet = ".$PLANET['planet'].",
			fleet_start_type = 1,
			fleet_end_time = ".$endTime.",
			fleet_end_stay = ".$stayTime.",
			fleet_end_id = 0,
			fleet_end_galaxy = ".$targetGalaxy.",
			fleet_end_system = ".$targetSystem.",
			fleet_end_planet = ".$targetPlanet.",
			fleet_end_type = ".$targetType.",
			fleet_target_owner = ".$targetOwner.",
			fleet_resource_metal = ".$metal.",
			fleet_resource_crystal = ".$crystal.",
			fleet_resource_deuterium = ".$deuterium.",
			fleet_resource_elyrium = ".$elyrium.",
			fleet_fuel = ".$consumption.",
			fleet_universe = ".$USER['universe'].",
			start_time = ".TIMESTAMP.",
			fleet_mess = 0;";
		
		$GLOBALS['DATABASE']->query($SQL);
		$fleetID = $GLOBALS['DATABASE']->GetInsertID();
		
		// Insert fleet event
		$GLOBALS['DATABASE']->query(
			"INSERT INTO ".FLEETS_EVENT." SET 
			fleetID = ".$fleetID.",
			`time` = ".$startTime.",
			`lock` = NULL;"
		);
		
		// Update local planet data
		$this->aiPlayer->PLANETS[$sourcePlanetID] = $PLANET;
		
		$missionName = isset(self::$missionNames[$mission]) ? self::$missionNames[$mission] : 'Unknown';
		
		$this->aiPlayer->logAction('mission', $missionName.' fleet to '.$targetGalaxy.':'.$targetSystem.':'.$targetPlanet, 'sent');
		
		return $missionName.' fleet sent to '.$targetGalaxy.':'.$targetSystem.':'.$targetPlanet;
	}
	
	/**
	 * Find a planet that has a specific ship type
	 */
	private function findPlanetWithShip($shipID)
	{
		global $resource;
		
		foreach ($this->aiPlayer->PLANETS as $planetID => $planet) {
			$count = isset($planet[$resource[$shipID]]) ? $planet[$resource[$shipID]] : 0;
			if ($count > 0) {
				return $planetID;
			}
		}
		
		return false;
	}
	
	/**
	 * Find a planet with combat ships
	 */
	private function findPlanetWithCombatShips()
	{
		global $resource;
		
		$combatShips = array(204, 205, 206, 207, 211, 215);
		
		foreach ($this->aiPlayer->PLANETS as $planetID => $planet) {
			foreach ($combatShips as $shipID) {
				$count = isset($planet[$resource[$shipID]]) ? $planet[$resource[$shipID]] : 0;
				if ($count > 0) {
					return $planetID;
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Select combat ships to send from a planet
	 */
	private function selectCombatFleet($PLANET)
	{
		global $resource;
		
		$fleetArray = array();
		$combatShips = array(204, 205, 206, 207, 211, 215);
		$difficulty = $this->aiPlayer->getDifficulty();
		
		// Send a fraction of available ships based on difficulty
		$fraction = ($difficulty >= 3) ? 0.5 : (($difficulty >= 2) ? 0.3 : 0.2);
		
		foreach ($combatShips as $shipID) {
			$available = isset($PLANET[$resource[$shipID]]) ? $PLANET[$resource[$shipID]] : 0;
			if ($available > 0) {
				$send = max(1, floor($available * $fraction));
				$fleetArray[$shipID] = $send;
			}
		}
		
		return $fleetArray;
	}
	
	/**
	 * Find a nearby non-AI target for spying/attacking
	 */
	private function findNearbyTarget($galaxy, $system)
	{
		$aiAttackAI = AIConfigHelper::get('ai_attack_ai');
		$aiCondition = ($aiAttackAI != '1') ? " AND u.is_ai = 0" : "";
		
		$target = $GLOBALS['DATABASE']->getFirstRow(
			"SELECT p.galaxy, p.system, p.planet, p.id_owner 
			FROM ".PLANETS." p 
			JOIN ".USERS." u ON p.id_owner = u.id
			WHERE p.galaxy = ".$galaxy." 
			AND p.system BETWEEN ".max(1, $system - 10)." AND ".($system + 10)."
			AND p.planet_type = 1
			AND p.id_owner != ".$this->aiPlayer->getUserID()."
			AND u.urlaubs_modus = 0
			AND u.bana = 0
			AND u.user_deleted = 0
			".$aiCondition."
			ORDER BY RAND() LIMIT 1;"
		);
		
		return !empty($target) ? $target : false;
	}
	
	/**
	 * Find a weaker target for attacks
	 */
	private function findWeakerTarget($galaxy, $system)
	{
		$aiAttackAI = AIConfigHelper::get('ai_attack_ai');
		$aiCondition = ($aiAttackAI != '1') ? " AND u.is_ai = 0" : "";
		
		$myPoints = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT total_points FROM ".STATPOINTS." WHERE id_owner = ".$this->aiPlayer->getUserID()." AND stat_type = 1;"
		);
		
		if (empty($myPoints)) {
			$myPoints = 0;
		}
		
		$target = $GLOBALS['DATABASE']->getFirstRow(
			"SELECT p.galaxy, p.system, p.planet, p.id_owner, s.total_points, u.onlinetime, u.banaday 
			FROM ".PLANETS." p 
			JOIN ".USERS." u ON p.id_owner = u.id
			LEFT JOIN ".STATPOINTS." s ON s.id_owner = u.id AND s.stat_type = 1
			WHERE p.galaxy = ".$galaxy." 
			AND p.system BETWEEN ".max(1, $system - 20)." AND ".($system + 20)."
			AND p.planet_type = 1
			AND p.id_owner != ".$this->aiPlayer->getUserID()."
			AND u.urlaubs_modus = 0
			AND u.bana = 0
			AND u.user_deleted = 0
			AND (s.total_points IS NULL OR s.total_points < ".$myPoints.")
			".$aiCondition."
			ORDER BY RAND() LIMIT 1;"
		);
		
		if (empty($target)) {
			return false;
		}
		
		// Check newbie protection
		$ownerPlayer = array('total_points' => $myPoints);
		$targetPlayer = array('total_points' => isset($target['total_points']) ? $target['total_points'] : 0);
		$playerInfo = array(
			'banaday' => isset($target['banaday']) ? $target['banaday'] : 0,
			'onlinetime' => isset($target['onlinetime']) ? $target['onlinetime'] : 0,
		);
		
		$noobCheck = CheckNoobProtec($ownerPlayer, $targetPlayer, $playerInfo);
		if ($noobCheck['NoobPlayer'] || $noobCheck['StrongPlayer']) {
			return false;
		}
		
		return $target;
	}
	
	/**
	 * Select a random item based on weighted priorities
	 */
	private function selectWeightedRandom($weights)
	{
		$totalWeight = array_sum($weights);
		if ($totalWeight <= 0) {
			return false;
		}
		
		$rand = mt_rand(1, $totalWeight);
		$cumulative = 0;
		
		foreach ($weights as $item => $weight) {
			$cumulative += $weight;
			if ($rand <= $cumulative) {
				return $item;
			}
		}
		
		return false;
	}
}
