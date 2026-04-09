{include file="overall_header.tpl"}
<h2>AI Action Logs</h2>

<div style="margin-bottom: 10px;">
	<a href="?page=aiplayers">Back to Overview</a>
</div>

<table width="100%">
<tr>
	<th>ID</th>
	<th>Player</th>
	<th>Action</th>
	<th>Data</th>
	<th>Time</th>
	<th>Result</th>
</tr>
{if count($logs) > 0}
{foreach name=LogEntry item=log from=$logs}
<tr>
	<td>{$log.id}</td>
	<td>{if isset($log.username)}{$log.username}{else}#{$log.ai_user_id}{/if}</td>
	<td>{$log.action_type}</td>
	<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$log.action_data}">{$log.action_data}</td>
	<td>{$log.executed_at_formatted}</td>
	<td>{$log.result}</td>
</tr>
{/foreach}
{else}
<tr>
	<td colspan="6" style="text-align:center;">No logs found.</td>
</tr>
{/if}
</table>

{if $totalPages > 1}
<div style="margin-top:10px; text-align:center;">
	{if $currentPage > 1}<a href="?page=aiplayers&action=logs&p={$currentPage-1}">&laquo; Previous</a>{/if}
	&nbsp; Page {$currentPage} of {$totalPages} &nbsp;
	{if $currentPage < $totalPages}<a href="?page=aiplayers&action=logs&p={$currentPage+1}">Next &raquo;</a>{/if}
</div>
{/if}
{include file="overall_footer.tpl"}
