<?php

/**
 * Antaris AI Player System - Colonize Strategy
 * 
 * Handles planet colonization for AI players.
 * AI will colonize new planets when allowed by tech level and planet count.
 *
 * @package Antaris
 * @subpackage AI
 */

class AIColonizeStrategy
{
	private $aiPlayer;
	
	function __construct(AIPlayer $aiPlayer)
	{
		$this->aiPlayer = $aiPlayer;
	}
	
	/**
	 * Execute colonization strategy
	 */
	public function execute()
	{
		global $resource;
		
		$USER = $this->aiPlayer->USER;
		
		// Check if we can have more planets
		$maxPlanets = PlayerUtil::maxPlanetCount($USER);
		$currentPlanets = count($this->aiPlayer->PLANETS);
		
		if ($currentPlanets >= $maxPlanets) {
			return false;
		}
		
		// Need astrophysics level >= 1 (tech 124) 
		$astroLevel = isset($USER[$resource[124]]) ? $USER[$resource[124]] : 0;
		if ($astroLevel < 1) {
			return false;
		}
		
		// Find a planet with a colony ship (ship 208)
		$sourcePlanet = $this->findPlanetWithColonyShip();
		if ($sourcePlanet === false) {
			return false;
		}
		
		$PLANET = $this->aiPlayer->PLANETS[$sourcePlanet];
		
		// Find a free position
		$freePosition = $this->findFreePosition($PLANET['galaxy']);
		if ($freePosition === false) {
			return false;
		}
		
		// Check fleet slots
		$maxSlots = FleetFunctions::GetMaxFleetSlots($USER);
		$usedSlots = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT COUNT(*) FROM ".FLEETS." WHERE fleet_owner = ".$USER['id'].";"
		);
		
		if ($usedSlots >= $maxSlots) {
			return false;
		}
		
		// Send colony ship
		$fleetArray = array(208 => 1);
		
		return $this->sendColonizeFleet($sourcePlanet, $freePosition, $fleetArray);
	}
	
	/**
	 * Find a planet with a colony ship
	 */
	private function findPlanetWithColonyShip()
	{
		global $resource;
		
		foreach ($this->aiPlayer->PLANETS as $planetID => $planet) {
			$count = isset($planet[$resource[208]]) ? $planet[$resource[208]] : 0;
			if ($count > 0) {
				return $planetID;
			}
		}
		
		return false;
	}
	
	/**
	 * Find a free planet position in the galaxy
	 */
	private function findFreePosition($preferredGalaxy)
	{
		$CONF = Config::getAll(NULL, $this->aiPlayer->USER['universe']);
		
		// Try to find a position in the same galaxy first
		for ($attempts = 0; $attempts < 50; $attempts++) {
			$galaxy = $preferredGalaxy;
			$system = mt_rand(1, $CONF['max_system']);
			$position = mt_rand(
				(int)round($CONF['max_planets'] * 0.2), 
				(int)round($CONF['max_planets'] * 0.8)
			);
			
			if (PlayerUtil::isPositionFree($this->aiPlayer->USER['universe'], $galaxy, $system, $position)) {
				// Check if AI player is allowed at this position
				if (PlayerUtil::allowPlanetPosition($position, $this->aiPlayer->USER)) {
					return array(
						'galaxy'   => $galaxy,
						'system'   => $system,
						'planet'   => $position,
					);
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Send a colonization fleet
	 */
	private function sendColonizeFleet($sourcePlanetID, $target, $fleetArray)
	{
		global $resource, $pricelist;
		
		$USER   = &$this->aiPlayer->USER;
		$PLANET = &$this->aiPlayer->PLANETS[$sourcePlanetID];
		
		// Calculate fleet details
		$speedAllMin = FleetFunctions::GetFleetMaxSpeed($fleetArray, $USER);
		$distance = FleetFunctions::GetTargetDistance(
			$PLANET['galaxy'], $target['galaxy'],
			$PLANET['system'], $target['system'],
			$PLANET['planet'], $target['planet']
		);
		
		$SpeedFactor = FleetFunctions::GetGameSpeedFactor();
		$duration = FleetFunctions::GetMissionDuration(10, $speedAllMin, $distance, $SpeedFactor, $USER);
		$consumption = FleetFunctions::GetFleetConsumption($fleetArray, $duration, $distance, $USER, $SpeedFactor);
		
		if ($PLANET[$resource[903]] < $consumption) {
			return false;
		}
		
		// Build fleet array string
		$fleetArrayStr = '';
		foreach ($fleetArray as $shipID => $count) {
			$fleetArrayStr .= $shipID.','.$count.';';
		}
		$fleetArrayStr = rtrim($fleetArrayStr, ';');
		
		$startTime = TIMESTAMP + $duration;
		$endTime   = $startTime + $duration;
		
		// Deduct ship from planet
		$PLANET[$resource[208]] -= 1;
		$PLANET[$resource[903]] -= $consumption;
		
		$GLOBALS['DATABASE']->query(
			"UPDATE ".PLANETS." SET 
			".$resource[208]." = ".$PLANET[$resource[208]].",
			".$resource[903]." = ".$PLANET[$resource[903]]."
			WHERE id = ".$sourcePlanetID.";"
		);
		
		// Insert fleet record
		$SQL = "INSERT INTO ".FLEETS." SET 
			fleet_owner = ".$USER['id'].",
			fleet_mission = 7,
			fleet_amount = 1,
			fleet_array = '".$GLOBALS['DATABASE']->sql_escape($fleetArrayStr)."',
			fleet_start_time = ".$startTime.",
			fleet_start_id = ".$sourcePlanetID.",
			fleet_start_galaxy = ".$PLANET['galaxy'].",
			fleet_start_system = ".$PLANET['system'].",
			fleet_start_planet = ".$PLANET['planet'].",
			fleet_start_type = 1,
			fleet_end_time = ".$endTime.",
			fleet_end_stay = 0,
			fleet_end_id = 0,
			fleet_end_galaxy = ".$target['galaxy'].",
			fleet_end_system = ".$target['system'].",
			fleet_end_planet = ".$target['planet'].",
			fleet_end_type = 1,
			fleet_target_owner = 0,
			fleet_resource_metal = 0,
			fleet_resource_crystal = 0,
			fleet_resource_deuterium = 0,
			fleet_resource_elyrium = 0,
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
		
		$this->aiPlayer->PLANETS[$sourcePlanetID] = $PLANET;
		
		$this->aiPlayer->logAction('colonize', 'Colonizing '.$target['galaxy'].':'.$target['system'].':'.$target['planet'], 'sent');
		
		return 'Colonize fleet sent to '.$target['galaxy'].':'.$target['system'].':'.$target['planet'];
	}
}
