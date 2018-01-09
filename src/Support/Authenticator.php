<?php

namespace PragmaRX\Google2FALaravel\Support;

use Carbon\Carbon;
use Google2FA;
use Illuminate\Http\Request as IlluminateRequest;
use PragmaRX\Google2FA\Support\Constants as Google2FAConstants;
use PragmaRX\Google2FALaravel\Exceptions\InvalidSecretKey;

class Authenticator
{
    use Auth, Config, Request, Session;

    /**
     * Authenticator constructor.
     *
     * @param IlluminateRequest $request
     */
    public function __construct(IlluminateRequest $request)
    {
        $this->boot($request);
    }

    /**
     * Authenticator boot.
     *
     * @param $request
     *
     * @return Authenticator
     */
    public function boot($request)
    {
        $this->setRequest($request);
        return $this;
    }

    /**
     * Check if it is already logged in or passable without checking for an OTP.
     *
     * @return bool
     */
    protected function canPassWithoutCheckingOTP()
    {
        return
            !$this->isEnabled() ||
            $this->noUserIsAuthenticated() ||
            !$this->isActivated() ||
            $this->twoFactorAuthStillValid();
    }

    /**
     * Get the user Google2FA secret.
     *
     * @throws InvalidSecretKey
     *
     * @return mixed
     */
    protected function getGoogle2FASecretKey()
    {
        return $this->getUser()->{$this->config('otp_secret_column')};
    }

    /**
     * Check if the 2FA is activated for the user.
     * 
     * @return bool
     */
    public function isActivated()
    {
        $secret = $this->getGoogle2FASecretKey();

        return !is_null($secret) && !empty($secret);
    }

    /**
     * Store the old OTP timestamp.
     *
     * @param $key
     *
     * @return mixed
     */
    protected function storeOldTimestamp($key)
    {
        return $this->config('forbid_old_passwords') === true
            ? $this->sessionPut(Constants::SESSION_OTP_TIMESTAMP, $key)
            : $key;
    }

    /**
     * Get the previous OTP timestamp.
     *
     * @return null|void
     */
    protected function getOldTimestamp()
    {
        return $this->config('forbid_old_passwords') === true
            ? $this->sessionGet(Constants::SESSION_OTP_TIMESTAMP)
            : null;
    }

    /**
     * Keep this OTP session alive.
     */
    protected function keepAlive()
    {
        if ($this->config('keep_alive')) {
            $this->updateCurrentAuthTime();
        }
    }

    /**
     * Get minutes since last activity.
     *
     * @return int
     */
    protected function minutesSinceLastActivity()
    {
        return Carbon::now()->diffInMinutes(
            $this->sessionGet(Constants::SESSION_AUTH_TIME)
        );
    }

    /**
     * Check if no user is authenticated using OTP.
     *
     * @return bool
     */
    protected function noUserIsAuthenticated()
    {
        return is_null($this->getUser());
    }

    /**
     * Check if OTP has expired.
     *
     * @return bool
     */
    protected function passwordExpired()
    {
        if (($minutes = $this->config('lifetime')) !== 0 && $this->minutesSinceLastActivity() > $minutes) {
            $this->logout();

            return true;
        }

        $this->keepAlive();

        return false;
    }

    /**
     * Verifies, in the current session, if a 2fa check has already passed.
     *
     * @return bool
     */
    protected function twoFactorAuthStillValid()
    {
        return
            (bool) $this->sessionGet(Constants::SESSION_AUTH_PASSED, false) &&
            !$this->passwordExpired();
    }

    /**
     * Check if the module is enabled.
     *
     * @return mixed
     */
    protected function isEnabled()
    {
        return $this->config('enabled');
    }

    /**
     * Set current auth as valid.
     */
    public function login()
    {
        $this->sessionPut(Constants::SESSION_AUTH_PASSED, true);

        $this->updateCurrentAuthTime();
    }

    /**
     * OTP logout.
     */
    public function logout()
    {
        $this->sessionForget();
    }

    /**
     * Update the current auth time.
     */
    protected function updateCurrentAuthTime()
    {
        $this->sessionPut(Constants::SESSION_AUTH_TIME, Carbon::now());
    }

    /**
     * Verify the OTP.
     *
     * @return mixed
     */
    public function verifyGoogle2FA($secret, $one_time_password)
    {
        return Google2Fa::verifyKey(
                $secret,
                $one_time_password,
                $this->config('window'),
                null, // $timestamp
                $this->getOldTimestamp() ?: Google2FAConstants::ARGUMENT_NOT_SET
        );
    }

    /**
     * Verify the OTP and store the timestamp.
     *
     * @return mixed
     */
    protected function verifyAndStoreOneTimePassord($one_time_password)
    {
        return $this->storeOldTimeStamp(
            $this->verifyGoogle2FA(
                $this->getGoogle2FASecretKey(),
                $one_time_password
            )
        );
    }
}
