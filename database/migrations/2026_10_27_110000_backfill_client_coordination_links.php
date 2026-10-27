<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    protected $connection = 'omi_social_automation';
    public function up(): void
    {
        $db = DB::connection($this->connection);
        $db->table('sa_agents')->whereNull('installation_id')->whereNotNull('client_api_key_id')->orderBy('id')->chunkById(100, function ($agents) use ($db): void {
            foreach ($agents as $agent) {
                $installationId = $db->table('sa_client_api_keys')->where('id',$agent->client_api_key_id)->value('installation_id');
                if (is_string($installationId) && $installationId !== '') {
                    $db->table('sa_agents')->where('id',$agent->id)->update(['installation_id'=>$installationId]);
                }
            }
        });
        $db->table('sa_browser_sessions')->whereNull('account_id')->orderBy('id')->chunkById(100, function ($sessions) use ($db): void {
            foreach ($sessions as $session) {
                $accountId = $db->table('sa_session_accounts')->where('browser_session_id',$session->id)->where('is_active',true)->value('account_id');
                $db->table('sa_browser_sessions')->where('id',$session->id)->update($accountId
                    ? ['account_id'=>$accountId]
                    : ['dispatch_enabled'=>false,'dispatch_pause_reason'=>'legacy_unlinked']);
            }
        });
    }
    public function down(): void {}
};
