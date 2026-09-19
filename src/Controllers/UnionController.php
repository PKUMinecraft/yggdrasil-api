<?php

namespace Yggdrasil\Controllers;

use DB;
use Log;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Yggdrasil\Utils\UnionClient;

/**
 * MUA union authentication client — handles this site's pulls from the central server as well as
 * the central server's callbacks into this site.
 *
 * Trusted callbacks (updateList / updatePrivateKey / serverUpdatesBackendKey / triggerSync /
 * remapUUID / diagnose) are signature-verified by the UnionHostVerify middleware.
 */
class UnionController extends Controller
{
    public function hello()
    {
        return json([
            'yggdrasilApiVersion' => plugin('yggdrasil-api')->version,
            'serverListVersion' => option('union_server_list_version'),
            'privateKeyVersion' => option('union_private_key_version'),
            // This fork doesn't implement extensions such as unionBlacklist / unionOAuth2 yet
            'enabledFeatures' => [],
        ])->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Pushed by the central server: pull the latest union member site list.
     */
    public function updateList()
    {
        try {
            $response = UnionClient::request('get', option('union_api_root').'/serverlist');
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if ($response->failed()) {
            return ['status' => 'error', 'http' => $response->status(), 'body' => $response->body()];
        }

        option(['union_server_list' => json_encode($response['servers'])]);
        option(['union_server_list_version' => $response['version']]);

        Log::channel('ygg')->info('Updated union server list.', ['servers' => $response['servers']]);

        return [
            'status' => 'ok',
            'version' => $response['version'],
            'servers' => count($response['servers'] ?? []),
        ];
    }

    /**
     * Pushed by the central server: pull the latest union shared private key.
     *
     * Note: on success, this site's ygg_private_key is overwritten by the union's unified key.
     */
    public function updatePrivateKey()
    {
        try {
            $response = UnionClient::request('get', option('union_api_root').'/privatekey');
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if ($response->failed()) {
            return ['status' => 'error', 'http' => $response->status(), 'body' => $response->body()];
        }

        option(['ygg_private_key' => $response['privateKey']]);
        option(['union_private_key_version' => $response['privateKeyVersion']]);

        Log::channel('ygg')->info('Updated union private key.');

        return [
            'status' => 'ok',
            'privateKeyVersion' => $response['privateKeyVersion'],
        ];
    }

    /**
     * Pushed by the central server: issue a new member_key.
     */
    public function serverUpdatesBackendKey(Request $request)
    {
        option(['union_member_key' => $request->input('key')]);

        Log::channel('ygg')->info('Union member key rotated by upstream.');
    }

    /**
     * Syncs this site's `players` + `uuid` table (name → uuid) mappings to the central server.
     *
     * The central receiving end only cares about name → uuid; the `pid` field is meaningless to it,
     * so this fork's `(id, name, uuid)` schema works fine too.
     */
    public function triggerSync()
    {
        $names = Player::all()->pluck('name');
        $uuids = DB::table('uuid')->pluck('uuid', 'name');

        // Only sync entries that have both an existing character and a UUID mapping
        $profiles = $uuids->only($names->all())->flip();

        try {
            $response = UnionClient::request('post', option('union_api_root').'/sync', ['profileList' => $profiles], 15.0);
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if ($response->failed()) {
            return ['status' => 'error', 'http' => $response->status(), 'body' => $response->body()];
        }

        Log::channel('ygg')->info('Triggered union sync.', ['count' => $profiles->count()]);

        return [
            'status' => 'ok',
            'pushed' => $profiles->count(),
        ];
    }

    /**
     * Pushed by the central server: rewrite conflicting UUIDs in the local uuid table to the
     * new UUID decided by union arbitration.
     */
    public function remapUUID(Request $request)
    {
        $remapped = $request->input('remapped_uuid', []);

        foreach ($remapped as $uuid => $mappedUuid) {
            DB::table('uuid')->where('uuid', $uuid)->update(['uuid' => $mappedUuid]);
        }

        Log::channel('ygg')->info('Remapped union UUIDs.', ['count' => count($remapped)]);
    }

    /**
     * Pushed by the central server: echo back a nonce/timestamp, used to diagnose bidirectional
     * connectivity and clock skew.
     */
    public function diagnose(Request $request)
    {
        return [
            'nonce' => $request->input('nonce'),
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Manually triggered by an admin: asks the central server to ping this site back, and collects
     * the connectivity diagnostic result.
     */
    public function triggerDiagnose()
    {
        try {
            $response = UnionClient::request('post', option('union_api_root').'/diagnose', null, 10.0);

            if ($response->ok()) {
                return ['status' => 'ok', 'data' => $response->json()];
            }

            return ['status' => 'error', 'data' => [
                'status_code' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
            ]];
        } catch (\Exception $e) {
            return ['status' => 'error', 'data' => ['exception' => $e->getMessage()]];
        }
    }
}
