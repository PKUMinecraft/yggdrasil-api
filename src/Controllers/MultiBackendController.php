<?php

namespace Yggdrasil\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Yggdrasil\Exceptions\IllegalArgumentException;

/**
 * Union multi-backend re-signing endpoint: takes an arbitrary profile and re-signs the `value`
 * of each of its properties with this site's (i.e. the union's shared) private key, then returns it.
 *
 * This is the fallback mechanism the MUA union uses to normalize signature versions when
 * exchanging profiles across sites.
 *
 * restore() blindly signs whatever is submitted (it doesn't verify that the profile corresponds to
 * a real player), so the route is restricted to the central server only via the UnionHostVerify
 * middleware, to prevent it from being abused as a signing oracle for arbitrary data.
 */
class MultiBackendController extends Controller
{
    public function hello()
    {
        return ['status' => 'success'];
    }

    public function restore(Request $request)
    {
        if (! filter_var(option('ygg_restore_api'), FILTER_VALIDATE_BOOLEAN)) {
            abort(403, trans('Yggdrasil::exceptions.restore.api_disabled'));
        }

        $key = openssl_pkey_get_private(option('ygg_private_key'));

        if (! $key) {
            throw new IllegalArgumentException(trans('Yggdrasil::config.rsa.invalid'));
        }

        $profile = $request->input();

        foreach ($profile['properties'] as &$prop) {
            openssl_sign($prop['value'], $signature, $key);
            $prop['signature'] = base64_encode($signature);
        }
        unset($prop);

        return $profile;
    }
}
