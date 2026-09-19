<?php

namespace Yggdrasil\Controllers;

use Log;
use Cache;
use App\Models\User;
use App\Models\Player;
use Yggdrasil\Utils\UUID;
use Yggdrasil\Models\Token;
use Illuminate\Http\Request;
use Yggdrasil\Models\Profile;
use Illuminate\Routing\Controller;
use Yggdrasil\Exceptions\NotFoundException;
use Yggdrasil\Exceptions\IllegalArgumentException;
use Yggdrasil\Exceptions\ForbiddenOperationException;

class AuthController extends Controller
{
    public function authenticate(Request $request)
    {
        /**
         * Note: in the new account verification flow, the `username` field actually holds the email,
         * only legacy users fill in a plain username (legacy = true).
         */
        $identification = strtolower($request->input('username'));
        Log::channel('ygg')->info("User [$identification] is try to authenticate with", [$request->except(['username', 'password'])]);
        $user = $this->checkUserCredentials($request);

        // clientToken is returned as-is; generate one for the client if it wasn't provided
        $clientToken = $request->input('clientToken', UUID::generate()->clearDashes());
        // Generate a new accessToken and format it as a UUID without dashes
        $accessToken = UUID::generate()->clearDashes();

        // Revoke this user's other tokens
        if ($cache = Cache::get("ID_$identification")) {
            $expiredAccessToken = unserialize($cache)->accessToken;

            Cache::forget("ID_$identification");
            Cache::forget("TOKEN_$expiredAccessToken");
        }

        // Instantiate and store the token
        $token = new Token($clientToken, $accessToken);
        $token->owner = $identification;

        // Prepare the response
        $availableProfiles = $this->getAvailableProfiles($user);

        $result = [
            'accessToken' => $token->accessToken,
            'clientToken' => $token->clientToken,
            'availableProfiles' => $availableProfiles
        ];

        if ($request->input('requestUser')) {
            // The user ID is generated from their email
            $result['user'] = [
                'id' => UUID::generate(5, $user->email, UUID::NS_DNS)->clearDashes(),
                'properties' => [],
            ];
        }

        // Automatically pick the character for the user when they only have one
        if (!empty($availableProfiles) && count($availableProfiles) === 1) {
            $result['selectedProfile'] = $availableProfiles[0];
            $token->profileId = $availableProfiles[0]['id'];
        }

        $this->storeToken($token, $identification);
        Log::channel('ygg')->info("New access token [$accessToken] generated for user [$identification]");

        Log::channel('ygg')->info("User [$identification] authenticated successfully", [compact('availableProfiles')]);

        ygg_log([
            'action' => 'authenticate',
            'user_id' => $user->uid,
            'parameters' => json_encode($request->except('username', 'password'))
        ]);

        return json($result);
    }

    public function refresh(Request $request)
    {
        $clientToken = $request->input('clientToken');
        $accessToken = $request->input('accessToken');

        Log::channel('ygg')->info("Try to refresh access token [$accessToken] with client token [$clientToken]");

        $token = Token::lookup($accessToken);
        if (! $token) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.invalid'));
        }

        if ($clientToken && $token->clientToken !== $clientToken) {
            Log::info("Expect client token to be [$token->clientToken]");
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.not-matched'));
        }

        $user = User::where('email', $token->owner)->first();

        if (!$user) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.not-existed'));
        }

        Log::channel('ygg')->info("The given access token is owned by user [$token->owner]");

        if ($user->permission == User::BANNED) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
        }

        $availableProfiles = $this->getAvailableProfiles($user);

        $result = [
            'accessToken' => $token->accessToken,
            'clientToken' => $token->clientToken, // Returned as-is
            'availableProfiles' => $availableProfiles
        ];

        if ($request->input('requestUser')) {
            $result['user'] = [
                'id' => UUID::generate(5, $user->email, UUID::NS_DNS)->clearDashes(),
                'properties' => [],
            ];
        }

        // When a selectedProfile is specified
        if ($selected = $request->get('selectedProfile')) {
            if (! Player::where('name', $selected['name'])->first()) {
                throw new IllegalArgumentException(trans('Yggdrasil::exceptions.player.not-existed'));
            }

            if ($token->profileId != '' && $selected != $token->profileId) {
                throw new IllegalArgumentException(trans('Yggdrasil::exceptions.player.not-matched'));
            }

            foreach ($availableProfiles as $profile) {
                if ($profile['id'] == $selected['id']) {
                    $result['selectedProfile'] = $profile;
                }
            }

            if (! isset($result['selectedProfile'])) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.player.owner'));
            }

            $token->profileId = $result['selectedProfile']['id'];
        } else {
            foreach ($availableProfiles as $profile) {
                if ($profile['id'] == $token->profileId) {
                    $result['selectedProfile'] = $profile;
                }
            }
        }

        // Now that all the checks above have passed, finally refresh the token
        Cache::forget("TOKEN_$accessToken");
        Log::channel('ygg')->info("The old access token [$accessToken] is now revoked");

        $token->accessToken = UUID::generate()->clearDashes();
        $token->createdAt = time();
        Log::channel('ygg')->info("New token [$token->accessToken] generated for user [$user->email]");
        $this->storeToken($token, $token->owner);

        Log::channel('ygg')->info("Access token refreshed [$accessToken] => [$token->accessToken]");

        ygg_log([
            'action' => 'refresh',
            'user_id' => $user->uid,
            'parameters' => json_encode($request->except('accessToken')),
        ]);

        $result['accessToken'] = $token->accessToken;
        return json($result);
    }

    public function validate(Request $request)
    {
        $clientToken = $request->input('clientToken');
        $accessToken = $request->input('accessToken');

        Log::channel('ygg')->info("Check if an access token is valid", compact('clientToken', 'accessToken'));

        $token = Token::lookup($accessToken);
        if ($token && $token->isValid()) {

            if ($clientToken && $clientToken !== $token->clientToken) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.not-matched'));
            }

            Log::info('Given access token is valid and matches the client token');

            $user = User::where('email', $token->owner)->first();

            if ($user->permission == User::BANNED) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
            }

            ygg_log([
                'action' => 'validate',
                'user_id' => $user->uid,
                'parameters' => json_encode($request->except('accessToken')),
            ]);

            return response('')->setStatusCode(204);
        } else {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.invalid'));
        }
    }

    public function signout(Request $request)
    {
        $identification = strtolower($request->input('username'));
        Log::channel('ygg')->info("User [$identification] is try to signout");
        $user = $this->checkUserCredentials($request, false);

        // Revoke all tokens
        if ($cache = Cache::get("ID_$identification")) {
            $accessToken = unserialize($cache)->accessToken;

            Cache::forget("ID_$identification");
            Cache::forget("TOKEN_$accessToken");
        }

        Log::channel('ygg')->info("User [$identification] signed out, all tokens revoked");

        ygg_log([
            'action' => 'signout',
            'user_id' => $user->uid,
        ]);

        return response('')->setStatusCode(204);
    }

    public function invalidate(Request $request)
    {
        $clientToken = $request->input('clientToken');
        $accessToken = $request->input('accessToken');

        Log::channel('ygg')->info("Try to invalidate an access token", compact('clientToken', 'accessToken'));

        // Reportedly there's no need to check whether clientToken matches accessToken here
        if ($cache = Cache::get("TOKEN_$accessToken")) {
            $token = unserialize($cache);
            $identification = strtolower($token->owner);

            Cache::forget("ID_$identification");
            Cache::forget("TOKEN_$accessToken");

            ygg_log([
                'action' => 'invalidate',
                'user_id' => User::where('email', $token->owner)->first()->uid,
                'parameters' => json_encode($request->json()->all()),
            ]);

            Log::channel('ygg')->info("Access token [$accessToken] was successfully revoked");
        } else {
            Log::channel('ygg')->error("Invalid access token [$accessToken], nothing to do");
        }

        // Reportedly a 204 should be returned regardless of whether the operation succeeded
        return response('')->setStatusCode(204);
    }

    protected function checkUserCredentials(Request $request, $checkBanned = true)
    {
        $identification = $request->input('username');
        $password = $request->input('password');

        if (is_null($identification) || is_null($password)) {
            throw new IllegalArgumentException(trans('Yggdrasil::exceptions.auth.empty'));
        }

        $user = User::where('email', $identification)->first();

        if (!$user) {
            throw new ForbiddenOperationException(
                trans('Yggdrasil::exceptions.auth.not-existed', compact('identification'))
            );
        }

        if (!$user->verifyPassword($password)) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.auth.not-matched'));
        }

        if ($checkBanned && $user->permission == User::BANNED) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
        }

        if (option('require_verification') && $user->verified === false) {
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.not-verified'));
        }

        return $user;
    }

    protected function getAvailableProfiles(User $user)
    {
        $profiles = [];

        foreach ($user->players as $player) {
            $uuid = Profile::getUuidFromName($player->name);

            $profiles[] = [
                'id' => $uuid,
                'name' => $player->name,
            ];
        }

        return $profiles;
    }

    // Redis is recommended as the cache driver
    protected function storeToken(Token $token, $identification)
    {
        $timeToFullyExpired = option('ygg_token_expire_2');
        // Use accessToken as the cache primary key
        Cache::put("TOKEN_{$token->accessToken}", serialize($token), $timeToFullyExpired);
        // TODO: allow a single user to issue multiple tokens
        Cache::put("ID_$identification", serialize($token), $timeToFullyExpired);

        Log::channel('ygg')->info("Serialized token stored to cache with expiry time $timeToFullyExpired minutes", [
            'keys' => ["TOKEN_{$token->accessToken}", "ID_$identification"],
            'token' => $token,
        ]);
    }
}
