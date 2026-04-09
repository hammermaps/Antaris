{include file="overall_header.tpl"}
<h2>AI Players Management</h2>

<div style="margin-bottom: 15px;">
	<a href="?page=aiplayers&action=create" class="btn">Create AI Player</a>
	<a href="?page=aiplayers&action=config" class="btn">Configuration</a>
	<a href="?page=aiplayers&action=logs" class="btn">Action Logs</a>
</div>

<table width="100%">
<tr>
	<th colspan="2">Daemon Status</th>
</tr>
<tr>
	<td width="200">Status:</td>
	<td>{if $daemonRunning}<span style="color:green;font-weight:bold;">RUNNING (PID: {$daemonPID})</span>{else}<span style="color:red;font-weight:bold;">STOPPED</span>{/if}</td>
</tr>
<tr>
	<td>AI System:</td>
	<td>{if $aiEnabled == '1'}<span style="color:green;">Enabled</span>{else}<span style="color:red;">Disabled</span>{/if}</td>
</tr>
<tr>
	<td>Last Tick:</td>
	<td>{$lastTick}</td>
</tr>
<tr>
	<td>Actions (last hour):</td>
	<td>{$recentActions}</td>
</tr>
<tr>
	<td colspan="2" style="font-size:11px; color:#888;">
		Start daemon: <code>php ai_daemon.php start</code> | 
		Stop: <code>php ai_daemon.php stop</code> | 
		Status: <code>php ai_daemon.php status</code>
	</td>
</tr>
</table>

<br>

<table width="100%">
<tr>
	<th>ID</th>
	<th>Name</th>
	<th>Difficulty</th>
	<th>Personality</th>
	<th>Points</th>
	<th>Rank</th>
	<th>Last Tick</th>
	<th>Strategy</th>
	<th>Actions</th>
</tr>
{if count($aiPlayers) > 0}
{foreach name=AIPlayer item=player from=$aiPlayers}
<tr>
	<td>{$player.id}</td>
	<td>{$player.username}</td>
	<td>
		{if $player.ai_difficulty == 1}Easy
		{elseif $player.ai_difficulty == 2}Medium
		{elseif $player.ai_difficulty == 3}Hard
		{/if}
	</td>
	<td>{$player.ai_personality}</td>
	<td>{if isset($player.total_points)}{$player.total_points}{else}0{/if}</td>
	<td>{if isset($player.total_rank)}{$player.total_rank}{else}-{/if}</td>
	<td>{if $player.last_tick > 0}{$player.last_tick|date_format:"%Y-%m-%d %H:%M:%S"}{else}Never{/if}</td>
	<td>{if isset($player.current_strategy)}{$player.current_strategy}{else}-{/if}</td>
	<td>
		<a href="?page=aiplayers&action=delete&id={$player.id}" onclick="return confirm('Delete AI player {$player.username}?');" style="color:red;">Delete</a>
	</td>
</tr>
{/foreach}
{else}
<tr>
	<td colspan="9" style="text-align:center;">No AI players configured. <a href="?page=aiplayers&action=create">Create one</a>.</td>
</tr>
{/if}
</table>
{include file="overall_footer.tpl"}
