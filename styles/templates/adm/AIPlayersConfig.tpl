{include file="overall_header.tpl"}
<h2>AI Configuration</h2>

<form method="post" action="?page=aiplayers&action=config">
<table width="500">
<tr>
	<th colspan="2">AI System Settings</th>
</tr>
<tr>
	<td width="250">AI System Enabled:</td>
	<td><input type="checkbox" name="ai_enabled" value="1" {if isset($aiConfig.ai_enabled) && $aiConfig.ai_enabled == '1'}checked{/if}></td>
</tr>
<tr>
	<td>Max AI Players:</td>
	<td><input type="number" name="ai_max_players" value="{if isset($aiConfig.ai_max_players)}{$aiConfig.ai_max_players}{else}10{/if}" min="1" max="100"></td>
</tr>
<tr>
	<td>Tick Interval (seconds):</td>
	<td><input type="number" name="ai_tick_interval" value="{if isset($aiConfig.ai_tick_interval)}{$aiConfig.ai_tick_interval}{else}60{/if}" min="10" max="600"></td>
</tr>
<tr>
	<td>Max Actions per Tick:</td>
	<td><input type="number" name="ai_max_actions_per_tick" value="{if isset($aiConfig.ai_max_actions_per_tick)}{$aiConfig.ai_max_actions_per_tick}{else}3{/if}" min="1" max="10"></td>
</tr>
<tr>
	<td>Allow AI Attacks:</td>
	<td><input type="checkbox" name="ai_allow_attacks" value="1" {if isset($aiConfig.ai_allow_attacks) && $aiConfig.ai_allow_attacks == '1'}checked{/if}></td>
</tr>
<tr>
	<td>AI can Attack other AI:</td>
	<td><input type="checkbox" name="ai_attack_ai" value="1" {if isset($aiConfig.ai_attack_ai) && $aiConfig.ai_attack_ai == '1'}checked{/if}></td>
</tr>
<tr>
	<td>Log AI Actions:</td>
	<td><input type="checkbox" name="ai_log_actions" value="1" {if isset($aiConfig.ai_log_actions) && $aiConfig.ai_log_actions == '1'}checked{/if}></td>
</tr>
<tr>
	<td colspan="2" style="text-align:center;">
		<input type="submit" value="Save Configuration">
		<a href="?page=aiplayers" style="margin-left:10px;">Back</a>
	</td>
</tr>
</table>
</form>
{include file="overall_footer.tpl"}
