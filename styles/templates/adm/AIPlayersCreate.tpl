{include file="overall_header.tpl"}
<h2>Create AI Player</h2>

<form method="post" action="?page=aiplayers&action=create">
<table width="450">
<tr>
	<th colspan="2">New AI Player</th>
</tr>
<tr>
	<td width="200">Name:</td>
	<td><input type="text" name="ai_name" value="" size="30" required></td>
</tr>
<tr>
	<td>Difficulty:</td>
	<td>
		<select name="ai_difficulty">
			<option value="1">Easy</option>
			<option value="2" selected>Medium</option>
			<option value="3">Hard</option>
		</select>
	</td>
</tr>
<tr>
	<td>Personality:</td>
	<td>
		<select name="ai_personality">
			<option value="balanced" selected>Balanced</option>
			<option value="aggressive">Aggressive</option>
			<option value="defensive">Defensive</option>
			<option value="trader">Trader</option>
			<option value="researcher">Researcher</option>
		</select>
	</td>
</tr>
<tr>
	<td>Position (optional):</td>
	<td>
		<input type="number" name="galaxy" value="0" size="3" min="0" max="{$maxGalaxy}" style="width:50px;"> :
		<input type="number" name="system" value="0" size="3" min="0" max="{$maxSystem}" style="width:50px;"> :
		<input type="number" name="planet" value="0" size="3" min="0" max="{$maxPlanets}" style="width:50px;">
		<br><small>Leave at 0:0:0 for random position</small>
	</td>
</tr>
<tr>
	<td colspan="2" style="text-align:center;">
		<input type="submit" value="Create AI Player">
		<a href="?page=aiplayers" style="margin-left:10px;">Cancel</a>
	</td>
</tr>
</table>
</form>
{include file="overall_footer.tpl"}
