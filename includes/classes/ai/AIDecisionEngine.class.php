<?php

/**
 * Antaris AI Player System
 * 
 * Decision engine that prioritizes and executes AI actions.
 * Coordinates between different strategy modules.
 *
 * @package Antaris
 * @subpackage AI
 */

require_once('includes/classes/ai/AIPlayer.class.php');
require_once('includes/classes/ai/strategies/AIBuildStrategy.class.php');
require_once('includes/classes/ai/strategies/AIResearchStrategy.class.php');
require_once('includes/classes/ai/strategies/AIFleetStrategy.class.php');
require_once('includes/classes/ai/strategies/AIDefenseStrategy.class.php');
require_once('includes/classes/ai/strategies/AIMissionStrategy.class.php');
require_once('includes/classes/ai/strategies/AIColonizeStrategy.class.php');

class AIDecisionEngine
{
	private $aiPlayer;
	private $strategies;
	private $maxActionsPerTick;
	
	function __construct(AIPlayer $aiPlayer)
	{
		$this->aiPlayer = $aiPlayer;
		$this->maxActionsPerTick = (int) AIConfigHelper::get('ai_max_actions_per_tick');
		if ($this->maxActionsPerTick < 1) {
			$this->maxActionsPerTick = 3;
		}
		
		$this->strategies = array(
			'build'     => new AIBuildStrategy($aiPlayer),
			'research'  => new AIResearchStrategy($aiPlayer),
			'fleet'     => new AIFleetStrategy($aiPlayer),
			'defense'   => new AIDefenseStrategy($aiPlayer),
			'mission'   => new AIMissionStrategy($aiPlayer),
			'colonize'  => new AIColonizeStrategy($aiPlayer),
		);
	}
	
	/**
	 * Execute one tick of AI decision-making
	 * Returns array of performed actions
	 */
	public function executeTick()
	{
		$actions = array();
		$actionsPerformed = 0;
		
		// Get prioritized action list
		$priorities = $this->getPriorities();
		
		foreach ($priorities as $strategyName => $priority) {
			if ($actionsPerformed >= $this->maxActionsPerTick) {
				break;
			}
			
			if ($priority <= 0) {
				continue;
			}
			
			$strategy = $this->strategies[$strategyName];
			
			// Iterate over each planet for planet-specific strategies
			if (in_array($strategyName, array('build', 'fleet', 'defense'))) {
				foreach ($this->aiPlayer->PLANETS as $planetID => $planet) {
					if ($actionsPerformed >= $this->maxActionsPerTick) {
						break;
					}
					
					$this->aiPlayer->setActivePlanet($planetID);
					$result = $strategy->execute();
					
					if ($result !== false) {
						$actions[] = array(
							'strategy' => $strategyName,
							'planet'   => $planetID,
							'result'   => $result,
						);
						$actionsPerformed++;
					}
				}
			} else {
				// Global strategies (research, mission, colonize)
				$result = $strategy->execute();
				
				if ($result !== false) {
					$actions[] = array(
						'strategy' => $strategyName,
						'result'   => $result,
					);
					$actionsPerformed++;
				}
			}
		}
		
		return $actions;
	}
	
	/**
	 * Get strategy priorities based on personality and current state
	 * Returns array of strategy => priority (higher = more important)
	 */
	private function getPriorities()
	{
		$personality = $this->aiPlayer->getPersonality();
		$planet = $this->aiPlayer->activePlanet;
		
		// Base priorities
		$priorities = array(
			'build'    => 80,
			'research' => 60,
			'fleet'    => 40,
			'defense'  => 30,
			'mission'  => 20,
			'colonize' => 10,
		);
		
		// Adjust for personality
		switch ($personality) {
			case 'aggressive':
				$priorities['fleet']   += 30;
				$priorities['mission'] += 25;
				$priorities['defense'] -= 10;
				break;
			case 'defensive':
				$priorities['defense'] += 30;
				$priorities['build']   += 10;
				$priorities['mission'] -= 10;
				break;
			case 'trader':
				$priorities['build']   += 20;
				$priorities['fleet']   += 10; // transport ships
				$priorities['mission'] += 5;
				break;
			case 'researcher':
				$priorities['research'] += 40;
				$priorities['build']    += 10;
				break;
			case 'balanced':
			default:
				// Keep defaults
				break;
		}
		
		// Adjust for threat level (incoming fleets)
		if ($this->hasIncomingAttacks()) {
			$priorities['defense'] += 50;
			$priorities['fleet']   += 20;
		}
		
		// If no building is in queue, boost build priority
		if ($planet['b_building'] == 0) {
			$priorities['build'] += 20;
		}
		
		// If no research in progress, boost research
		if ($this->aiPlayer->USER['b_tech'] == 0) {
			$priorities['research'] += 20;
		}
		
		// Difficulty modifier: easy AI skips some actions randomly
		if ($this->aiPlayer->getDifficulty() == 1) {
			foreach ($priorities as $key => $val) {
				if (mt_rand(1, 100) > 60) {
					$priorities[$key] = 0; // 40% chance to skip
				}
			}
		}
		
		// Sort by priority descending
		arsort($priorities);
		
		return $priorities;
	}
	
	/**
	 * Check if there are incoming attacks against this AI player
	 */
	private function hasIncomingAttacks()
	{
		$count = $GLOBALS['DATABASE']->getFirstCell(
			"SELECT COUNT(*) FROM ".FLEETS." 
			WHERE fleet_target_owner = ".$this->aiPlayer->getUserID()." 
			AND fleet_mission IN (1, 2, 9) 
			AND fleet_mess = 0;"
		);
		
		return $count > 0;
	}
}
